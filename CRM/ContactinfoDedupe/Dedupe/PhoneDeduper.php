<?php

use CRM_ContactinfoDedupe_ExtensionUtil as E;

/**
 * Phone deduplication logic.
 *
 * Match on: normalized digits of phone number + same phone_type_id within same contact.
 * Merge rules:
 *   - phone_ext: if one has extension and other doesn't, keep the extension
 *   - phone_numeric: recalculate after merge
 */
class CRM_ContactinfoDedupe_Dedupe_PhoneDeduper extends CRM_ContactinfoDedupe_Dedupe_AbstractDeduper {

  const ENTITY_TYPE = 'phone';
  const TABLE = 'civicrm_phone';

  /**
   * Normalize a phone number to just digits, stripping country prefix (+1).
   */
  public static function normalizePhone(?string $phone): string {
    if ($phone === null || trim($phone) === '') {
      return '';
    }
    // Strip all non-digit characters
    $digits = preg_replace('/[^0-9]/', '', $phone);
    // Strip leading '1' if it's a US number (11 digits starting with 1)
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
      $digits = substr($digits, 1);
    }
    return $digits;
  }

  /**
   * {@inheritdoc}
   */
  /**
   * SQL expression to normalize a phone column to digits only, stripping leading 1 from 11-digit numbers.
   */
  private static function phoneNormSql(string $col): string {
    $digits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$col},''), '-', ''), '(', ''), ')', ''), ' ', ''), '+', ''), '.', '')";
    // Strip leading 1 from 11-digit US numbers
    return "IF(LENGTH({$digits}) = 11 AND {$digits} LIKE '1%', SUBSTRING({$digits}, 2), {$digits})";
  }

  public function findDuplicates(?int $startContactId, ?int $endContactId, int $limit = 25, int $offset = 0): array {
    $scope = $this->buildContactScope('p1', $startContactId, $endContactId);

    $norm1 = self::phoneNormSql('p1.phone');
    $norm2 = self::phoneNormSql('p2.phone');

    $sql = "
      SELECT
        p1.id AS id1, p2.id AS id2,
        p1.contact_id,
        p1.phone AS phone1, p2.phone AS phone2,
        p1.phone_type_id,
        p1.location_type_id AS loc1, p2.location_type_id AS loc2,
        p1.phone_ext AS ext1, p2.phone_ext AS ext2,
        c.display_name
      FROM civicrm_phone p1
      INNER JOIN civicrm_phone p2
        ON p1.contact_id = p2.contact_id
        AND p1.id < p2.id
        AND (COALESCE(p1.phone_type_id, 0) = COALESCE(p2.phone_type_id, 0)
             OR p1.phone_type_id IS NULL
             OR p2.phone_type_id IS NULL)
        AND ({$norm1}) = ({$norm2})
        AND COALESCE(p1.phone, '') != ''
      INNER JOIN civicrm_contact c ON c.id = p1.contact_id
      WHERE {$scope}
        AND c.is_deleted = 0
      ORDER BY p1.contact_id, p1.id
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
    $scope = $this->buildContactScope('p1', $startContactId, $endContactId);

    $norm1 = self::phoneNormSql('p1.phone');
    $norm2 = self::phoneNormSql('p2.phone');

    $sql = "
      SELECT COUNT(*) as cnt
      FROM civicrm_phone p1
      INNER JOIN civicrm_phone p2
        ON p1.contact_id = p2.contact_id
        AND p1.id < p2.id
        AND (COALESCE(p1.phone_type_id, 0) = COALESCE(p2.phone_type_id, 0)
             OR p1.phone_type_id IS NULL
             OR p2.phone_type_id IS NULL)
        AND ({$norm1}) = ({$norm2})
        AND COALESCE(p1.phone, '') != ''
      INNER JOIN civicrm_contact c ON c.id = p1.contact_id
      WHERE {$scope}
        AND c.is_deleted = 0
    ";

    return (int) CRM_Core_DAO::singleValueQuery($sql);
  }

  /**
   * {@inheritdoc}
   */
  public function processDuplicate(int $keepId, int $removeId): bool {
    $keepRecord = $this->loadRecord($keepId);
    $removeRecord = $this->loadRecord($removeId);

    if (!$keepRecord || !$removeRecord) {
      return false;
    }

    if ($keepRecord['contact_id'] !== $removeRecord['contact_id']) {
      return false;
    }

    // Verify phone numbers actually match
    $norm1 = self::normalizePhone($keepRecord['phone']);
    $norm2 = self::normalizePhone($removeRecord['phone']);
    if ($norm1 !== $norm2) {
      return false;
    }

    // If one has a phone_type_id and the other doesn't, always keep the typed one
    $keepType = $keepRecord['phone_type_id'] ?? null;
    $removeType = $removeRecord['phone_type_id'] ?? null;
    $keepHasType = !$this->isFieldBlank($keepType);
    $removeHasType = !$this->isFieldBlank($removeType);

    if ($keepHasType && !$removeHasType) {
      $actualKeepId = (int) $keepRecord['id'];
      $actualRemoveId = (int) $removeRecord['id'];
    }
    elseif (!$keepHasType && $removeHasType) {
      $actualKeepId = (int) $removeRecord['id'];
      $actualRemoveId = (int) $keepRecord['id'];
      $temp = $keepRecord;
      $keepRecord = $removeRecord;
      $removeRecord = $temp;
    }
    else {
      $decision = $this->determineKeepAndRemove($keepRecord, $removeRecord);
      if ($decision === null) {
        return false;
      }
      [$actualKeepId, $actualRemoveId] = $decision;

      if ($actualKeepId !== (int) $keepRecord['id']) {
        $temp = $keepRecord;
        $keepRecord = $removeRecord;
        $removeRecord = $temp;
      }
    }

    $fieldChanges = [];

    // phone_ext: if one has it and other doesn't, keep the extension
    $keepExt = $keepRecord['phone_ext'] ?? null;
    $removeExt = $removeRecord['phone_ext'] ?? null;
    if ($this->isFieldBlank($keepExt) && !$this->isFieldBlank($removeExt)) {
      $fieldChanges['phone_ext'] = ['old' => $keepExt, 'new' => $removeExt];
      $keepRecord['phone_ext'] = $removeExt;
    }

    // Fix phone_numeric field
    $correctNumeric = self::normalizePhone($keepRecord['phone']);
    if (($keepRecord['phone_numeric'] ?? '') !== $correctNumeric) {
      $fieldChanges['phone_numeric'] = ['old' => $keepRecord['phone_numeric'] ?? '', 'new' => $correctNumeric];
      $keepRecord['phone_numeric'] = $correctNumeric;
    }

    // Apply updates
    if (!empty($fieldChanges)) {
      $setClauses = [];
      $params = [1 => [$actualKeepId, 'Integer']];
      $i = 2;
      foreach ($fieldChanges as $field => $change) {
        $setClauses[] = "{$field} = %{$i}";
        $params[$i] = [$change['new'] ?? '', 'String'];
        $i++;
      }
      CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_phone SET " . implode(', ', $setClauses) . " WHERE id = %1",
        $params
      );
    }

    // Transfer flags
    $this->transferFlags(self::TABLE, $actualKeepId, $removeRecord);

    if (!empty($fieldChanges)) {
      $this->logAudit(self::ENTITY_TYPE, (int) $keepRecord['contact_id'], $actualKeepId, $actualRemoveId, 'changed', $fieldChanges);
    }

    // Delete
    CRM_Core_DAO::executeQuery(
      "DELETE FROM civicrm_phone WHERE id = %1",
      [1 => [$actualRemoveId, 'Integer']]
    );

    $this->logAudit(self::ENTITY_TYPE, (int) $keepRecord['contact_id'], $actualKeepId, $actualRemoveId, 'removed', [
      'removed_record' => $removeRecord,
    ]);

    return true;
  }

  /**
   * {@inheritdoc}
   */
  protected function hasFieldConflicts(array $record1, array $record2): bool {
    // phone_ext is the only mergeable field for phone
    $ext1 = $record1['phone_ext'] ?? null;
    $ext2 = $record2['phone_ext'] ?? null;
    if (!$this->isFieldBlank($ext1) && !$this->isFieldBlank($ext2) && (string) $ext1 !== (string) $ext2) {
      return true;
    }
    return false;
  }

  private function loadRecord(int $id): ?array {
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT * FROM civicrm_phone WHERE id = %1",
      [1 => [$id, 'Integer']]
    );
    if ($dao->fetch()) {
      return $dao->toArray();
    }
    return null;
  }

  private function buildDuplicateRow(object $dao): array {
    $locTypes = CRM_Core_PseudoConstant::get('CRM_Core_DAO_Address', 'location_type_id');
    $phoneTypes = CRM_Core_PseudoConstant::get('CRM_Core_DAO_Phone', 'phone_type_id');

    return [
      'id1' => (int) $dao->id1,
      'id2' => (int) $dao->id2,
      'contact_id' => (int) $dao->contact_id,
      'display_name' => $dao->display_name,
      'phone' => $dao->phone1,
      'phone_type' => $phoneTypes[$dao->phone_type_id] ?? "Type {$dao->phone_type_id}",
      'location_type_1' => $locTypes[$dao->loc1] ?? "Type {$dao->loc1}",
      'location_type_2' => $locTypes[$dao->loc2] ?? "Type {$dao->loc2}",
      'ext_1' => $dao->ext1,
      'ext_2' => $dao->ext2,
    ];
  }

}
