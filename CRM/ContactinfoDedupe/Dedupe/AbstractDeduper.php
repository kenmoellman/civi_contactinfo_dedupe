<?php

use CRM_ContactinfoDedupe_ExtensionUtil as E;

/**
 * Base class for entity-specific deduplication logic.
 */
abstract class CRM_ContactinfoDedupe_Dedupe_AbstractDeduper {

  /**
   * @var array Location type priority map: location_type_id => priority (lower = higher priority)
   */
  protected array $priorityMap = [];

  /**
   * @var int|null Contact ID of the user performing the action.
   */
  protected ?int $performedBy = null;

  public function __construct() {
    $this->loadPriorityMap();
    $session = CRM_Core_Session::singleton();
    $this->performedBy = $session->getLoggedInContactID();
  }

  /**
   * Load location type priorities from the database.
   */
  protected function loadPriorityMap(): void {
    $this->priorityMap = [];
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT location_type_id, priority FROM civicrm_contactinfo_dedupe_priority ORDER BY priority ASC'
    );
    while ($dao->fetch()) {
      $this->priorityMap[(int) $dao->location_type_id] = (int) $dao->priority;
    }
  }

  /**
   * Get the priority for a location type. Higher number = lower priority.
   * Unranked types get a very high number (effectively lowest priority).
   */
  protected function getPriority(int $locationTypeId): int {
    return $this->priorityMap[$locationTypeId] ?? 999999;
  }

  /**
   * Find duplicates for a range of contacts.
   *
   * @param int|null $startContactId
   * @param int|null $endContactId
   * @param int $limit
   * @param int $offset
   * @return array{duplicates: array, total: int}
   */
  abstract public function findDuplicates(?int $startContactId, ?int $endContactId, int $limit = 25, int $offset = 0): array;

  /**
   * Get the total count of duplicate groups for given scope.
   *
   * @param int|null $startContactId
   * @param int|null $endContactId
   * @return int
   */
  abstract public function countDuplicates(?int $startContactId, ?int $endContactId): int;

  /**
   * Process (merge/delete) a specific duplicate pair.
   *
   * @param int $keepId ID of the record to keep
   * @param int $removeId ID of the record to remove
   * @return bool Success
   */
  abstract public function processDuplicate(int $keepId, int $removeId): bool;

  /**
   * Given two records, determine which to keep and which to remove.
   * Returns [keepId, removeId] or null if they should not be merged (priority tie with conflict).
   *
   * @param array $record1
   * @param array $record2
   * @return array|null [keepId, removeId]
   */
  protected function determineKeepAndRemove(array $record1, array $record2): ?array {
    $p1 = $this->getPriority((int) $record1['location_type_id']);
    $p2 = $this->getPriority((int) $record2['location_type_id']);

    if ($p1 < $p2) {
      return [(int) $record1['id'], (int) $record2['id']];
    }
    elseif ($p2 < $p1) {
      return [(int) $record2['id'], (int) $record1['id']];
    }

    // Same priority — check if there are field conflicts
    if ($this->hasFieldConflicts($record1, $record2)) {
      // Priority tie with conflict: leave both
      return null;
    }

    // Exact data tie or no conflicts — keep the one with lower id
    $id1 = (int) $record1['id'];
    $id2 = (int) $record2['id'];
    return $id1 <= $id2 ? [$id1, $id2] : [$id2, $id1];
  }

  /**
   * Check if two records have field-level conflicts (both non-blank, different values)
   * on the entity-specific merge fields.
   *
   * @param array $record1
   * @param array $record2
   * @return bool
   */
  abstract protected function hasFieldConflicts(array $record1, array $record2): bool;

  /**
   * Log an audit entry.
   *
   * @param string $entityType
   * @param int $contactId
   * @param int $keptId
   * @param int $removedId
   * @param string $status 'changed' or 'removed'
   * @param array $fieldChanges
   */
  protected function logAudit(string $entityType, int $contactId, int $keptId, int $removedId, string $status, array $fieldChanges = []): void {
    CRM_Core_DAO::executeQuery(
      'INSERT INTO civicrm_contactinfo_dedupe_audit_log
        (entity_type, contact_id, kept_entity_id, removed_entity_id, status, field_changes, created_date, created_by)
       VALUES (%1, %2, %3, %4, %5, %6, NOW(), %7)',
      [
        1 => [$entityType, 'String'],
        2 => [$contactId, 'Integer'],
        3 => [$keptId, 'Integer'],
        4 => [$removedId, 'Integer'],
        5 => [$status, 'String'],
        6 => [json_encode($fieldChanges), 'String'],
        7 => [$this->performedBy ?? 0, 'Integer'],
      ]
    );
  }

  /**
   * Transfer is_primary and is_billing flags from the removed record to the kept record
   * if they were set on the removed record.
   *
   * @param string $table CiviCRM table name
   * @param int $keepId
   * @param array $removedRecord
   */
  protected function transferFlags(string $table, int $keepId, array $removedRecord): void {
    $updates = [];
    if (!empty($removedRecord['is_primary'])) {
      $updates[] = 'is_primary = 1';
    }
    if (!empty($removedRecord['is_billing'])) {
      $updates[] = 'is_billing = 1';
    }
    if (!empty($updates)) {
      CRM_Core_DAO::executeQuery(
        "UPDATE {$table} SET " . implode(', ', $updates) . " WHERE id = %1",
        [1 => [$keepId, 'Integer']]
      );
    }
  }

  /**
   * Merge blank fields from source into target, returning the list of fields changed.
   *
   * @param array $keepRecord The record being kept (will be modified)
   * @param array $removeRecord The record being removed (source of fill values)
   * @param array $mergeFields List of field names to consider for merge
   * @return array Field changes as ['field' => ['old' => x, 'new' => y]]
   */
  protected function mergeBlankFields(array &$keepRecord, array $removeRecord, array $mergeFields): array {
    $changes = [];
    foreach ($mergeFields as $field) {
      $keepVal = $keepRecord[$field] ?? null;
      $removeVal = $removeRecord[$field] ?? null;

      if ($this->isBlank($keepVal) && !$this->isBlank($removeVal)) {
        $changes[$field] = ['old' => $keepVal, 'new' => $removeVal];
        $keepRecord[$field] = $removeVal;
      }
    }
    return $changes;
  }

  /**
   * Check if a value is considered blank.
   */
  protected function isBlank(mixed $value): bool {
    return $value === null || $value === '' || $value === '0' && false;
  }

  /**
   * Check if a value is truly blank (null or empty string).
   */
  protected function isFieldBlank(mixed $value): bool {
    return $value === null || (is_string($value) && trim($value) === '');
  }

  /**
   * Build contact scope WHERE clause.
   *
   * @param string $alias Table alias
   * @param int|null $startContactId
   * @param int|null $endContactId
   * @return string SQL WHERE fragment
   */
  protected function buildContactScope(string $alias, ?int $startContactId, ?int $endContactId): string {
    $where = '1=1';
    if ($startContactId !== null) {
      $where .= " AND {$alias}.contact_id >= " . (int) $startContactId;
    }
    if ($endContactId !== null) {
      $where .= " AND {$alias}.contact_id <= " . (int) $endContactId;
    }
    return $where;
  }

}
