<?php

use CRM_ContactinfoDedupe_ExtensionUtil as E;

/**
 * Page controller for the dedupe search and cleanup screen.
 */
class CRM_ContactinfoDedupe_Page_DedupeSearch extends CRM_Core_Page {

  /**
   * Run the page.
   */
  public function run(): void {
    CRM_Utils_System::setTitle(E::ts('Contact Info Dedupe - Cleanup'));

    // Handle AJAX requests
    $action = CRM_Utils_Request::retrieve('action_type', 'String', $this, false);
    if ($action === 'find') {
      $this->handleFind();
      return;
    }
    if ($action === 'process') {
      $this->handleProcess();
      return;
    }
    if ($action === 'process_all') {
      $this->handleProcessAll();
      return;
    }

    // Check if priorities are configured
    $priorityCount = (int) CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM civicrm_contactinfo_dedupe_priority'
    );

    $this->assign('hasPriorities', $priorityCount > 0);
    $this->assign('extensionUrl', E::url());

    parent::run();
  }

  /**
   * Handle AJAX find duplicates request.
   */
  private function handleFind(): void {
    $entityType = CRM_Utils_Request::retrieve('entity_type', 'String', $this, true);
    $startId = CRM_Utils_Request::retrieve('start_id', 'Integer', $this, false);
    $endId = CRM_Utils_Request::retrieve('end_id', 'Integer', $this, false);
    $page = CRM_Utils_Request::retrieve('page', 'Integer', $this, false, 1);
    $pageSize = CRM_Utils_Request::retrieve('page_size', 'Integer', $this, false, 25);

    $offset = ($page - 1) * $pageSize;

    $deduper = $this->getDeduper($entityType);
    if (!$deduper) {
      CRM_Utils_JSON::output(['success' => false, 'error' => 'Invalid entity type']);
      return;
    }

    $result = $deduper->findDuplicates(
      $startId ?: null,
      $endId ?: null,
      $pageSize,
      $offset
    );

    CRM_Utils_JSON::output([
      'success' => true,
      'duplicates' => $result['duplicates'],
      'total' => $result['total'],
      'page' => $page,
      'page_size' => $pageSize,
      'total_pages' => ceil($result['total'] / $pageSize),
    ]);
  }

  /**
   * Handle AJAX process (merge/delete) request for specific pairs.
   */
  private function handleProcess(): void {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    $entityType = $data['entity_type'] ?? '';
    $pairs = $data['pairs'] ?? [];

    $deduper = $this->getDeduper($entityType);
    if (!$deduper) {
      CRM_Utils_JSON::output(['success' => false, 'error' => 'Invalid entity type']);
      return;
    }

    $processed = 0;
    $skipped = 0;
    $errors = [];

    foreach ($pairs as $pair) {
      $keepId = (int) ($pair['keep_id'] ?? $pair['id1'] ?? 0);
      $removeId = (int) ($pair['remove_id'] ?? $pair['id2'] ?? 0);
      if ($keepId && $removeId) {
        try {
          if ($deduper->processDuplicate($keepId, $removeId)) {
            $processed++;
          }
          else {
            $skipped++;
          }
        }
        catch (\Exception $e) {
          $errors[] = "Pair {$keepId}/{$removeId}: " . $e->getMessage();
        }
      }
    }

    CRM_Utils_JSON::output([
      'success' => true,
      'processed' => $processed,
      'skipped' => $skipped,
      'errors' => $errors,
    ]);
  }

  /**
   * Handle AJAX process all duplicates in scope.
   */
  private function handleProcessAll(): void {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    $entityType = $data['entity_type'] ?? '';
    $startId = !empty($data['start_id']) ? (int) $data['start_id'] : null;
    $endId = !empty($data['end_id']) ? (int) $data['end_id'] : null;

    $deduper = $this->getDeduper($entityType);
    if (!$deduper) {
      CRM_Utils_JSON::output(['success' => false, 'error' => 'Invalid entity type']);
      return;
    }

    $processed = 0;
    $skipped = 0;
    $batchSize = 100;

    // Process in batches to avoid timeout
    do {
      $result = $deduper->findDuplicates($startId, $endId, $batchSize, 0);
      $batchProcessed = 0;

      foreach ($result['duplicates'] as $dup) {
        $id1 = (int) $dup['id1'];
        $id2 = (int) $dup['id2'];
        try {
          if ($deduper->processDuplicate($id1, $id2)) {
            $processed++;
            $batchProcessed++;
          }
          else {
            $skipped++;
          }
        }
        catch (\Exception $e) {
          $skipped++;
        }
      }
    } while ($batchProcessed > 0 && $result['total'] > 0);

    CRM_Utils_JSON::output([
      'success' => true,
      'processed' => $processed,
      'skipped' => $skipped,
    ]);
  }

  /**
   * Get the appropriate deduper for the entity type.
   */
  private function getDeduper(string $entityType): ?CRM_ContactinfoDedupe_Dedupe_AbstractDeduper {
    return match ($entityType) {
      'address' => new CRM_ContactinfoDedupe_Dedupe_AddressDeduper(),
      'email' => new CRM_ContactinfoDedupe_Dedupe_EmailDeduper(),
      'phone' => new CRM_ContactinfoDedupe_Dedupe_PhoneDeduper(),
      default => null,
    };
  }

}
