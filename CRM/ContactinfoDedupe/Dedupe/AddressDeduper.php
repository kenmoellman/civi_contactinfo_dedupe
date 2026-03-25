<?php

use CRM_ContactinfoDedupe_ExtensionUtil as E;

/**
 * Address deduplication logic.
 *
 * Match on: street_address + city + state_province_id
 * Merge fields: postal_code, postal_code_suffix, supplemental_address_1,
 *   supplemental_address_2, geo_code_1, geo_code_2, county_id
 */
class CRM_ContactinfoDedupe_Dedupe_AddressDeduper extends CRM_ContactinfoDedupe_Dedupe_AbstractDeduper {

  const ENTITY_TYPE = 'address';
  const TABLE = 'civicrm_address';

  /**
   * Fields that are merged (fill blanks / resolve conflicts by priority).
   */
  const MERGE_FIELDS = [
    'postal_code',
    'postal_code_suffix',
    'supplemental_address_1',
    'supplemental_address_2',
    'geo_code_1',
    'geo_code_2',
    'county_id',
  ];

  /**
   * {@inheritdoc}
   */
  public function findDuplicates(?int $startContactId, ?int $endContactId, int $limit = 25, int $offset = 0): array {
    $scope = $this->buildContactScope('a1', $startContactId, $endContactId);

    $sql = "
      SELECT
        a1.id AS id1, a2.id AS id2,
        a1.contact_id,
        a1.street_address, a1.city, a1.state_province_id,
        a1.location_type_id AS loc1, a2.location_type_id AS loc2,
        a1.postal_code AS pc1, a2.postal_code AS pc2,
        c.display_name
      FROM civicrm_address a1
      INNER JOIN civicrm_address a2
        ON a1.contact_id = a2.contact_id
        AND a1.id < a2.id
        AND COALESCE(a1.street_address, '') = COALESCE(a2.street_address, '')
        AND COALESCE(a1.city, '') = COALESCE(a2.city, '')
        AND COALESCE(a1.state_province_id, 0) = COALESCE(a2.state_province_id, 0)
      INNER JOIN civicrm_contact c ON c.id = a1.contact_id
      WHERE {$scope}
        AND c.is_deleted = 0
      ORDER BY a1.contact_id, a1.id
      LIMIT %1 OFFSET %2
    ";

    $dao = CRM_Core_DAO::executeQuery($sql, [
      1 => [$limit, 'Integer'],
      2 => [$offset, 'Integer'],
    ]);

    $duplicates = [];
    while ($dao->fetch()) {
      $duplicates[] = $this->buildDuplicateRow($dao);
    }

    $total = $this->countDuplicates($startContactId, $endContactId);

    return ['duplicates' => $duplicates, 'total' => $total];
  }

  /**
   * {@inheritdoc}
   */
  public function countDuplicates(?int $startContactId, ?int $endContactId): int {
    $scope = $this->buildContactScope('a1', $startContactId, $endContactId);

    $sql = "
      SELECT COUNT(*) as cnt
      FROM civicrm_address a1
      INNER JOIN civicrm_address a2
        ON a1.contact_id = a2.contact_id
        AND a1.id < a2.id
        AND COALESCE(a1.street_address, '') = COALESCE(a2.street_address, '')
        AND COALESCE(a1.city, '') = COALESCE(a2.city, '')
        AND COALESCE(a1.state_province_id, 0) = COALESCE(a2.state_province_id, 0)
      INNER JOIN civicrm_contact c ON c.id = a1.contact_id
      WHERE {$scope}
        AND c.is_deleted = 0
    ";

    return (int) CRM_Core_DAO::singleValueQuery($sql);
  }

  /**
   * {@inheritdoc}
   */
  public function processDuplicate(int $keepId, int $removeId): bool {
    // Load both full records
    $keepRecord = $this->loadRecord($keepId);
    $removeRecord = $this->loadRecord($removeId);

    if (!$keepRecord || !$removeRecord) {
      return false;
    }

    // Verify they are actually duplicates (same contact, same match keys)
    if ($keepRecord['contact_id'] !== $removeRecord['contact_id']) {
      return false;
    }

    // Re-determine keep/remove based on priority
    $decision = $this->determineKeepAndRemove($keepRecord, $removeRecord);
    if ($decision === null) {
      // Priority tie with conflict — do not merge
      return false;
    }

    [$actualKeepId, $actualRemoveId] = $decision;

    // Reload in correct order if needed
    if ($actualKeepId !== $keepId) {
      $temp = $keepRecord;
      $keepRecord = $removeRecord;
      $removeRecord = $temp;
    }

    // Merge blank fields from removed into kept
    $fieldChanges = $this->mergeBlankFields($keepRecord, $removeRecord, self::MERGE_FIELDS);

    // Apply updates to the kept record if any fields were changed
    if (!empty($fieldChanges)) {
      $setClauses = [];
      $params = [1 => [$actualKeepId, 'Integer']];
      $i = 2;
      foreach ($fieldChanges as $field => $change) {
        $setClauses[] = "{$field} = %{$i}";
        $type = in_array($field, ['county_id', 'state_province_id']) ? 'Integer' : 'String';
        $params[$i] = [$change['new'], $type];
        $i++;
      }
      CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_address SET " . implode(', ', $setClauses) . " WHERE id = %1",
        $params
      );
    }

    // Transfer is_primary / is_billing flags
    $this->transferFlags(self::TABLE, $actualKeepId, $removeRecord);

    // Log the changes on the kept record
    if (!empty($fieldChanges)) {
      $this->logAudit(self::ENTITY_TYPE, (int) $keepRecord['contact_id'], $actualKeepId, $actualRemoveId, 'changed', $fieldChanges);
    }

    // Delete the removed record
    CRM_Core_DAO::executeQuery(
      "DELETE FROM civicrm_address WHERE id = %1",
      [1 => [$actualRemoveId, 'Integer']]
    );

    // Log the removal
    $this->logAudit(self::ENTITY_TYPE, (int) $keepRecord['contact_id'], $actualKeepId, $actualRemoveId, 'removed', [
      'removed_record' => $removeRecord,
    ]);

    return true;
  }

  /**
   * {@inheritdoc}
   */
  protected function hasFieldConflicts(array $record1, array $record2): bool {
    foreach (self::MERGE_FIELDS as $field) {
      $v1 = $record1[$field] ?? null;
      $v2 = $record2[$field] ?? null;
      if (!$this->isFieldBlank($v1) && !$this->isFieldBlank($v2) && (string) $v1 !== (string) $v2) {
        return true;
      }
    }
    return false;
  }

  /**
   * Load a full address record by ID.
   */
  private function loadRecord(int $id): ?array {
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT * FROM civicrm_address WHERE id = %1",
      [1 => [$id, 'Integer']]
    );
    if ($dao->fetch()) {
      return $dao->toArray();
    }
    return null;
  }

  /**
   * Build a display row for the duplicate search results.
   */
  private function buildDuplicateRow(object $dao): array {
    $locTypes = CRM_Core_BAO_Address::buildOptions('location_type_id', 'get');
    $stateProvince = CRM_Core_PseudoConstant::stateProvince();

    return [
      'id1' => (int) $dao->id1,
      'id2' => (int) $dao->id2,
      'contact_id' => (int) $dao->contact_id,
      'display_name' => $dao->display_name,
      'street_address' => $dao->street_address,
      'city' => $dao->city,
      'state_province' => $stateProvince[$dao->state_province_id] ?? '',
      'location_type_1' => $locTypes[$dao->loc1] ?? "Type {$dao->loc1}",
      'location_type_2' => $locTypes[$dao->loc2] ?? "Type {$dao->loc2}",
      'postal_code_1' => $dao->pc1,
      'postal_code_2' => $dao->pc2,
      'keep_id' => null, // Will be determined during processing
      'remove_id' => null,
    ];
  }

}
