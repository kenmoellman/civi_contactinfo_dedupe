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
   * When true, postal_code_suffix conflicts are ignored and the suffix is
   * blanked on the kept record during merge.
   */
  protected bool $ignorePlus4 = false;

  /**
   * When true, geo_code conflicts are ignored and the geocodes are blanked
   * on the kept record during merge.
   */
  protected bool $ignoreGeocode = false;

  /**
   * Set whether to ignore conflicting +4 ZIP suffix.
   */
  public function setIgnorePlus4(bool $ignore): void {
    $this->ignorePlus4 = $ignore;
  }

  /**
   * Set whether to ignore conflicting geocodes.
   */
  public function setIgnoreGeocode(bool $ignore): void {
    $this->ignoreGeocode = $ignore;
  }

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

    // Special handling: treat postal_code_suffix '0000' as blank
    $keepSuffix = $keepRecord['postal_code_suffix'] ?? null;
    $removeSuffix = $removeRecord['postal_code_suffix'] ?? null;
    if ((string) $keepSuffix === '0000' && !$this->isFieldBlank($removeSuffix) && (string) $removeSuffix !== '0000') {
      $fieldChanges['postal_code_suffix'] = ['old' => $keepSuffix, 'new' => $removeSuffix];
      $keepRecord['postal_code_suffix'] = $removeSuffix;
    }

    // Ignore +4 mode: if both have different non-blank suffixes, blank it out
    if ($this->ignorePlus4) {
      $curSuffix = $keepRecord['postal_code_suffix'] ?? null;
      $remSuffix = $removeRecord['postal_code_suffix'] ?? null;
      if (!$this->isFieldBlank($curSuffix) && !$this->isFieldBlank($remSuffix)
          && (string) $curSuffix !== (string) $remSuffix
          && (string) $curSuffix !== '0000' && (string) $remSuffix !== '0000') {
        $fieldChanges['postal_code_suffix'] = ['old' => $curSuffix, 'new' => null];
        $keepRecord['postal_code_suffix'] = null;
      }
    }

    // Ignore geocode mode: blank out conflicting geo_code values
    if ($this->ignoreGeocode) {
      foreach (['geo_code_1', 'geo_code_2'] as $geoField) {
        $keepGeo = $keepRecord[$geoField] ?? null;
        $removeGeo = $removeRecord[$geoField] ?? null;
        if (!$this->isFieldBlank($keepGeo) && !$this->isFieldBlank($removeGeo)
            && (string) $keepGeo !== (string) $removeGeo
            && abs((float) $keepGeo - (float) $removeGeo) > 0.01) {
          $fieldChanges[$geoField] = ['old' => $keepGeo, 'new' => null];
          $keepRecord[$geoField] = null;
        }
      }
    }

    // Special handling: prefer properly zero-padded postal_code
    $keepZip = $keepRecord['postal_code'] ?? null;
    $removeZip = $removeRecord['postal_code'] ?? null;
    if (!$this->isFieldBlank($keepZip) && !$this->isFieldBlank($removeZip)) {
      $normKeep = self::normalizeZip($keepZip);
      $normRemove = self::normalizeZip($removeZip);
      if ($normKeep === $normRemove && $keepZip !== $removeZip) {
        // Same ZIP, different formatting — keep the properly padded one (longer string)
        $betterZip = (strlen($removeZip) > strlen($keepZip)) ? $removeZip : $keepZip;
        if ($betterZip !== $keepZip) {
          $fieldChanges['postal_code'] = ['old' => $keepZip, 'new' => $betterZip];
          $keepRecord['postal_code'] = $betterZip;
        }
      }
    }

    // Apply updates to the kept record if any fields were changed
    if (!empty($fieldChanges)) {
      $setClauses = [];
      $params = [1 => [$actualKeepId, 'Integer']];
      $i = 2;
      foreach ($fieldChanges as $field => $change) {
        if ($change['new'] === null) {
          $setClauses[] = "{$field} = NULL";
        }
        else {
          $setClauses[] = "{$field} = %{$i}";
          $type = in_array($field, ['county_id', 'state_province_id']) ? 'Integer' : 'String';
          $params[$i] = [$change['new'], $type];
          $i++;
        }
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
   * Apply consensus values across duplicate address groups.
   *
   * For each group of 3+ addresses that share the same contact + street + city + state,
   * find majority-agreed values for merge fields and update outliers to match.
   *
   * @return array{groups_processed: int, fields_updated: int}
   */
  public function applyConsensus(?int $startContactId, ?int $endContactId): array {
    $scope = $this->buildContactScope('a', $startContactId, $endContactId);

    // Find groups of 3+ duplicate addresses
    $sql = "
      SELECT a.contact_id,
             COALESCE(a.street_address, '') AS street_address,
             COALESCE(a.city, '') AS city,
             COALESCE(a.state_province_id, 0) AS state_province_id,
             COUNT(*) AS cnt
      FROM civicrm_address a
      INNER JOIN civicrm_contact c ON c.id = a.contact_id
      WHERE {$scope} AND c.is_deleted = 0
      GROUP BY a.contact_id, COALESCE(a.street_address, ''), COALESCE(a.city, ''), COALESCE(a.state_province_id, 0)
      HAVING COUNT(*) >= 3
    ";

    $groupDao = CRM_Core_DAO::executeQuery($sql);
    $groupsProcessed = 0;
    $fieldsUpdated = 0;

    $consensusFields = ['postal_code', 'postal_code_suffix', 'geo_code_1', 'geo_code_2', 'county_id'];

    while ($groupDao->fetch()) {
      // Load all records in this group
      $recordsSql = "
        SELECT * FROM civicrm_address
        WHERE contact_id = %1
          AND COALESCE(street_address, '') = %2
          AND COALESCE(city, '') = %3
          AND COALESCE(state_province_id, 0) = %4
      ";
      $recordsDao = CRM_Core_DAO::executeQuery($recordsSql, [
        1 => [(int) $groupDao->contact_id, 'Integer'],
        2 => [$groupDao->street_address, 'String'],
        3 => [$groupDao->city, 'String'],
        4 => [(int) $groupDao->state_province_id, 'Integer'],
      ]);

      $records = [];
      while ($recordsDao->fetch()) {
        $records[] = $recordsDao->toArray();
      }

      if (count($records) < 3) {
        continue;
      }

      $groupUpdated = false;

      foreach ($consensusFields as $field) {
        $isGeo = in_array($field, ['geo_code_1', 'geo_code_2']);
        $isZip = ($field === 'postal_code');

        // Collect non-blank values with their record IDs
        $values = [];
        foreach ($records as $rec) {
          $val = $rec[$field] ?? null;
          if (!$this->isFieldBlank($val) && (!($field === 'postal_code_suffix') || (string) $val !== '0000')) {
            $values[] = ['id' => (int) $rec['id'], 'val' => $val];
          }
        }

        if (count($values) < 2) {
          continue; // Not enough non-blank values to find consensus
        }

        // Group values into clusters
        $clusters = [];
        foreach ($values as $entry) {
          $matched = false;
          foreach ($clusters as &$cluster) {
            $representative = $cluster[0]['val'];
            if ($isGeo) {
              if (abs((float) $entry['val'] - (float) $representative) <= 0.01) {
                $cluster[] = $entry;
                $matched = true;
                break;
              }
            }
            elseif ($isZip) {
              if (self::normalizeZip($entry['val']) === self::normalizeZip($representative)) {
                $cluster[] = $entry;
                $matched = true;
                break;
              }
            }
            else {
              if ((string) $entry['val'] === (string) $representative) {
                $cluster[] = $entry;
                $matched = true;
                break;
              }
            }
          }
          unset($cluster);
          if (!$matched) {
            $clusters[] = [$entry];
          }
        }

        // Find the largest cluster
        usort($clusters, fn($a, $b) => count($b) - count($a));
        $majorityCluster = $clusters[0];

        // Need strict majority: majority cluster must be larger than all others combined
        $majorityCount = count($majorityCluster);
        $totalWithValues = count($values);
        if ($majorityCount <= $totalWithValues - $majorityCount) {
          continue; // No clear majority
        }

        // Pick the best representative value from the majority cluster
        $consensusVal = $majorityCluster[0]['val'];
        if ($isZip) {
          // Prefer the longest (best-padded) version
          foreach ($majorityCluster as $entry) {
            if (strlen($entry['val']) > strlen($consensusVal)) {
              $consensusVal = $entry['val'];
            }
          }
        }

        // Find IDs of records NOT in the majority cluster
        $majorityIds = array_column($majorityCluster, 'id');
        $outlierIds = [];
        foreach ($records as $rec) {
          $recId = (int) $rec['id'];
          if (!in_array($recId, $majorityIds)) {
            $recVal = $rec[$field] ?? null;
            // Only update if the outlier actually differs
            $dominated = false;
            if ($this->isFieldBlank($recVal)) {
              $dominated = true;
            }
            elseif ($isGeo && abs((float) $recVal - (float) $consensusVal) > 0.01) {
              $dominated = true;
            }
            elseif ($isZip && self::normalizeZip($recVal) !== self::normalizeZip($consensusVal)) {
              $dominated = true;
            }
            elseif (!$isGeo && !$isZip && (string) $recVal !== (string) $consensusVal) {
              $dominated = true;
            }
            if ($dominated) {
              $outlierIds[] = $recId;
            }
          }
        }

        if (empty($outlierIds)) {
          continue;
        }

        // Update outliers to the consensus value
        $idList = implode(',', $outlierIds);
        $type = in_array($field, ['county_id', 'state_province_id']) ? 'Integer' : 'String';
        CRM_Core_DAO::executeQuery(
          "UPDATE civicrm_address SET {$field} = %1 WHERE id IN ({$idList})",
          [1 => [$consensusVal, $type]]
        );

        $fieldsUpdated += count($outlierIds);
        $groupUpdated = true;

        // Log it
        foreach ($outlierIds as $outlierId) {
          $this->logAudit(self::ENTITY_TYPE, (int) $groupDao->contact_id, $outlierId, 0, 'consensus', [
            $field => ['old' => 'outlier', 'new' => $consensusVal],
          ]);
        }
      }

      if ($groupUpdated) {
        $groupsProcessed++;
      }
    }

    return ['groups_processed' => $groupsProcessed, 'fields_updated' => $fieldsUpdated];
  }

  /**
   * {@inheritdoc}
   */
  protected function hasFieldConflicts(array $record1, array $record2): bool {
    $geoFields = ['geo_code_1', 'geo_code_2'];
    foreach (self::MERGE_FIELDS as $field) {
      $v1 = $record1[$field] ?? null;
      $v2 = $record2[$field] ?? null;
      if (!$this->isFieldBlank($v1) && !$this->isFieldBlank($v2) && (string) $v1 !== (string) $v2) {
        // For geo_code fields, allow a tolerance of 0.01 degrees (~1.1 km),
        // or skip entirely if ignoreGeocode mode is active
        if (in_array($field, $geoFields)) {
          if ($this->ignoreGeocode || abs((float) $v1 - (float) $v2) <= 0.01) {
            continue; // Within tolerance or ignored, not a conflict
          }
        }
        // For postal_code_suffix, treat '0000' as effectively blank,
        // or skip entirely if ignorePlus4 mode is active
        if ($field === 'postal_code_suffix') {
          if ($this->ignorePlus4 || (string) $v1 === '0000' || (string) $v2 === '0000') {
            continue; // Not a real conflict
          }
        }
        // For postal_code, compare zero-padded to 5 digits
        if ($field === 'postal_code') {
          if (self::normalizeZip($v1) === self::normalizeZip($v2)) {
            continue; // Same ZIP, just different padding
          }
        }
        return true;
      }
    }
    return false;
  }

  /**
   * Normalize a ZIP code by zero-padding to 5 digits.
   */
  public static function normalizeZip(?string $zip): string {
    if ($zip === null || trim($zip) === '') {
      return '';
    }
    $zip = trim($zip);
    // Zero-pad to 5 digits if it's a short numeric ZIP
    if (preg_match('/^\d{1,4}$/', $zip)) {
      return str_pad($zip, 5, '0', STR_PAD_LEFT);
    }
    return $zip;
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
    $locTypes = CRM_Core_PseudoConstant::get('CRM_Core_DAO_Address', 'location_type_id');
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
