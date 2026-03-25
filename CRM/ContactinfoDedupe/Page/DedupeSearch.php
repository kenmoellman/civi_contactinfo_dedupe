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

    // Handle AJAX requests — use CRM_Utils_Request without $this to avoid session caching
    $action = CRM_Utils_Request::retrieve('action_type', 'String');
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
    if ($action === 'consensus') {
      $this->handleConsensus();
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
    $entityType = CRM_Utils_Request::retrieve('entity_type', 'String', CRM_Core_DAO::$_nullObject, true);
    $startId = CRM_Utils_Request::retrieve('start_id', 'Integer');
    $endId = CRM_Utils_Request::retrieve('end_id', 'Integer');
    $page = CRM_Utils_Request::retrieve('pg', 'Integer', CRM_Core_DAO::$_nullObject, false, 1);
    $pageSize = CRM_Utils_Request::retrieve('page_size', 'Integer', CRM_Core_DAO::$_nullObject, false, 25);
    $ignorePlus4 = (bool) CRM_Utils_Request::retrieve('ignore_plus4', 'Integer');
    $ignoreGeocode = (bool) CRM_Utils_Request::retrieve('ignore_geocode', 'Integer');

    $offset = ($page - 1) * $pageSize;

    $deduper = $this->getDeduper($entityType, $ignorePlus4, $ignoreGeocode);
    if (!$deduper) {
      $this->sendJson(['success' => false, 'error' => 'Invalid entity type']);
    }

    $result = $deduper->findDuplicates(
      $startId ?: null,
      $endId ?: null,
      $pageSize,
      $offset
    );

    $this->sendJson([
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
    $ignorePlus4 = !empty($data['ignore_plus4']);
    $ignoreGeocode = !empty($data['ignore_geocode']);

    $deduper = $this->getDeduper($entityType, $ignorePlus4, $ignoreGeocode);
    if (!$deduper) {
      $this->sendJson(['success' => false, 'error' => 'Invalid entity type']);
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

    $this->sendJson([
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
    $ignorePlus4 = !empty($data['ignore_plus4']);
    $ignoreGeocode = !empty($data['ignore_geocode']);

    $deduper = $this->getDeduper($entityType, $ignorePlus4, $ignoreGeocode);
    if (!$deduper) {
      $this->sendJson(['success' => false, 'error' => 'Invalid entity type']);
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

    $this->sendJson([
      'success' => true,
      'processed' => $processed,
      'skipped' => $skipped,
    ]);
  }

  /**
   * Handle AJAX consensus request — normalize field values across duplicate groups.
   */
  private function handleConsensus(): void {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    $startId = !empty($data['start_id']) ? (int) $data['start_id'] : null;
    $endId = !empty($data['end_id']) ? (int) $data['end_id'] : null;

    $deduper = new CRM_ContactinfoDedupe_Dedupe_AddressDeduper();
    $result = $deduper->applyConsensus($startId, $endId);

    $this->sendJson([
      'success' => true,
      'groups_processed' => $result['groups_processed'],
      'fields_updated' => $result['fields_updated'],
    ]);
  }

  /**
   * Send a JSON response and exit, bypassing CiviCRM page rendering.
   */
  private function sendJson(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    CRM_Utils_System::civiExit();
  }

  /**
   * Get the appropriate deduper for the entity type.
   */
  private function getDeduper(string $entityType, bool $ignorePlus4 = false, bool $ignoreGeocode = false): ?CRM_ContactinfoDedupe_Dedupe_AbstractDeduper {
    $deduper = match ($entityType) {
      'address' => new CRM_ContactinfoDedupe_Dedupe_AddressDeduper(),
      'email' => new CRM_ContactinfoDedupe_Dedupe_EmailDeduper(),
      'phone' => new CRM_ContactinfoDedupe_Dedupe_PhoneDeduper(),
      default => null,
    };
    if ($deduper instanceof CRM_ContactinfoDedupe_Dedupe_AddressDeduper) {
      if ($ignorePlus4) {
        $deduper->setIgnorePlus4(true);
      }
      if ($ignoreGeocode) {
        $deduper->setIgnoreGeocode(true);
      }
    }
    return $deduper;
  }

}
