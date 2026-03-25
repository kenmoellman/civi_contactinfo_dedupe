<?php

use CRM_ContactinfoDedupe_ExtensionUtil as E;

/**
 * Email deduplication logic.
 *
 * Match on: LOWER(email) exact match within same contact.
 * Merge rules:
 *   - on_hold: if either has it set, keep it set
 *   - hold_date: if both set, keep earliest
 *   - signature_text/signature_html: keep value from highest ID on conflict
 */
class CRM_ContactinfoDedupe_Dedupe_EmailDeduper extends CRM_ContactinfoDedupe_Dedupe_AbstractDeduper {

  const ENTITY_TYPE = 'email';
  const TABLE = 'civicrm_email';

  /**
   * {@inheritdoc}
   */
  public function findDuplicates(?int $startContactId, ?int $endContactId, int $limit = 25, int $offset = 0): array {
    $scope = $this->buildContactScope('e1', $startContactId, $endContactId);

    $sql = "
      SELECT
        e1.id AS id1, e2.id AS id2,
        e1.contact_id,
        e1.email AS email1, e2.email AS email2,
        e1.location_type_id AS loc1, e2.location_type_id AS loc2,
        e1.on_hold AS hold1, e2.on_hold AS hold2,
        c.display_name
      FROM civicrm_email e1
      INNER JOIN civicrm_email e2
        ON e1.contact_id = e2.contact_id
        AND e1.id < e2.id
        AND LOWER(e1.email) = LOWER(e2.email)
      INNER JOIN civicrm_contact c ON c.id = e1.contact_id
      WHERE {$scope}
        AND c.is_deleted = 0
      ORDER BY e1.contact_id, e1.id
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
    $scope = $this->buildContactScope('e1', $startContactId, $endContactId);

    $sql = "
      SELECT COUNT(*) as cnt
      FROM civicrm_email e1
      INNER JOIN civicrm_email e2
        ON e1.contact_id = e2.contact_id
        AND e1.id < e2.id
        AND LOWER(e1.email) = LOWER(e2.email)
      INNER JOIN civicrm_contact c ON c.id = e1.contact_id
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

    // Re-determine keep/remove based on priority
    $decision = $this->determineKeepAndRemove($keepRecord, $removeRecord);
    if ($decision === null) {
      return false;
    }

    [$actualKeepId, $actualRemoveId] = $decision;

    if ($actualKeepId !== $keepId) {
      $temp = $keepRecord;
      $keepRecord = $removeRecord;
      $removeRecord = $temp;
    }

    $fieldChanges = [];

    // Normalize email to lowercase on the kept record
    $normalizedEmail = strtolower(trim($keepRecord['email']));
    if ($keepRecord['email'] !== $normalizedEmail) {
      $fieldChanges['email'] = ['old' => $keepRecord['email'], 'new' => $normalizedEmail];
      $keepRecord['email'] = $normalizedEmail;
    }

    // on_hold: keep the most restrictive value (0=none, 1=bounce, 2=unsubscribe)
    $keepHold = (int) ($keepRecord['on_hold'] ?? 0);
    $removeHold = (int) ($removeRecord['on_hold'] ?? 0);
    if ($removeHold > $keepHold) {
      $fieldChanges['on_hold'] = ['old' => $keepHold, 'new' => $removeHold];
      $keepRecord['on_hold'] = $removeHold;
    }

    // hold_date: if both set, keep earliest
    if (!$this->isFieldBlank($removeRecord['hold_date'] ?? null)) {
      if ($this->isFieldBlank($keepRecord['hold_date'] ?? null)) {
        $fieldChanges['hold_date'] = ['old' => null, 'new' => $removeRecord['hold_date']];
        $keepRecord['hold_date'] = $removeRecord['hold_date'];
      }
      elseif (strtotime($removeRecord['hold_date']) < strtotime($keepRecord['hold_date'])) {
        $fieldChanges['hold_date'] = ['old' => $keepRecord['hold_date'], 'new' => $removeRecord['hold_date']];
        $keepRecord['hold_date'] = $removeRecord['hold_date'];
      }
    }

    // reset_date: keep latest, but only if it's after the final hold_date
    $finalHoldDate = $keepRecord['hold_date'] ?? null;
    $bestResetDate = $keepRecord['reset_date'] ?? null;
    $removeReset = $removeRecord['reset_date'] ?? null;
    if (!$this->isFieldBlank($removeReset)) {
      if ($this->isFieldBlank($bestResetDate) || strtotime($removeReset) > strtotime($bestResetDate)) {
        $bestResetDate = $removeReset;
      }
    }
    // Only keep reset_date if it's after the hold_date (otherwise the hold came after the reset)
    if (!$this->isFieldBlank($bestResetDate)) {
      if (!$this->isFieldBlank($finalHoldDate) && strtotime($bestResetDate) <= strtotime($finalHoldDate)) {
        // Reset is older than hold — clear it
        if (!$this->isFieldBlank($keepRecord['reset_date'] ?? null)) {
          $fieldChanges['reset_date'] = ['old' => $keepRecord['reset_date'], 'new' => null];
          $keepRecord['reset_date'] = null;
        }
      }
      elseif (($keepRecord['reset_date'] ?? null) !== $bestResetDate) {
        $fieldChanges['reset_date'] = ['old' => $keepRecord['reset_date'] ?? null, 'new' => $bestResetDate];
        $keepRecord['reset_date'] = $bestResetDate;
      }
    }

    // signature_text: on conflict, keep from highest ID
    $this->mergeSignatureField($keepRecord, $removeRecord, 'signature_text', $fieldChanges);

    // signature_html: on conflict, keep from highest ID
    $this->mergeSignatureField($keepRecord, $removeRecord, 'signature_html', $fieldChanges);

    // Apply updates
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
          $params[$i] = [$change['new'], 'String'];
          $i++;
        }
      }
      CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_email SET " . implode(', ', $setClauses) . " WHERE id = %1",
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
      "DELETE FROM civicrm_email WHERE id = %1",
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
    // on_hold and hold_date have explicit merge strategies (keep hold if either set,
    // keep earliest date) so they are never conflicts.
    // signature fields use highest-ID-wins, so also not conflicts.
    return false;
  }

  /**
   * Merge a signature field using highest-ID-wins conflict resolution.
   */
  private function mergeSignatureField(array &$keepRecord, array $removeRecord, string $field, array &$fieldChanges): void {
    $keepVal = $keepRecord[$field] ?? null;
    $removeVal = $removeRecord[$field] ?? null;

    if ($this->isFieldBlank($keepVal) && !$this->isFieldBlank($removeVal)) {
      // Fill blank
      $fieldChanges[$field] = ['old' => $keepVal, 'new' => $removeVal];
      $keepRecord[$field] = $removeVal;
    }
    elseif (!$this->isFieldBlank($keepVal) && !$this->isFieldBlank($removeVal) && $keepVal !== $removeVal) {
      // Conflict: keep from highest ID
      $keepId = (int) $keepRecord['id'];
      $removeId = (int) $removeRecord['id'];
      if ($removeId > $keepId) {
        $fieldChanges[$field] = ['old' => $keepVal, 'new' => $removeVal];
        $keepRecord[$field] = $removeVal;
      }
      // else keep the existing value (keepRecord already has the higher ID value, or same value)
    }
  }

  private function loadRecord(int $id): ?array {
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT * FROM civicrm_email WHERE id = %1",
      [1 => [$id, 'Integer']]
    );
    if ($dao->fetch()) {
      return $dao->toArray();
    }
    return null;
  }

  private function buildDuplicateRow(object $dao): array {
    $locTypes = CRM_Core_PseudoConstant::get('CRM_Core_DAO_Address', 'location_type_id');

    return [
      'id1' => (int) $dao->id1,
      'id2' => (int) $dao->id2,
      'contact_id' => (int) $dao->contact_id,
      'display_name' => $dao->display_name,
      'email' => strtolower($dao->email1),
      'location_type_1' => $locTypes[$dao->loc1] ?? "Type {$dao->loc1}",
      'location_type_2' => $locTypes[$dao->loc2] ?? "Type {$dao->loc2}",
      'on_hold_1' => $dao->hold1,
      'on_hold_2' => $dao->hold2,
    ];
  }

}
