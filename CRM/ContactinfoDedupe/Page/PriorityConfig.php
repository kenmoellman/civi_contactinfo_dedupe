<?php

use CRM_ContactinfoDedupe_ExtensionUtil as E;

/**
 * Page controller for the priority configuration screen.
 * Allows admins to rank location types by priority (lower number = higher priority).
 */
class CRM_ContactinfoDedupe_Page_PriorityConfig extends CRM_Core_Page {

  /**
   * Run the page.
   */
  public function run(): void {
    CRM_Utils_System::setTitle(E::ts('Contact Info Dedupe - Priority Configuration'));

    // Handle AJAX save request
    $action = CRM_Utils_Request::retrieve('action_type', 'String', $this, false);
    if ($action === 'save') {
      $this->handleSave();
      return;
    }

    // Load location types
    $locationTypes = CRM_Core_BAO_LocationType::buildOptions('location_type_id', 'get');

    // Load current priorities
    $priorities = [];
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT location_type_id, priority FROM civicrm_contactinfo_dedupe_priority ORDER BY priority ASC'
    );
    while ($dao->fetch()) {
      $priorities[(int) $dao->location_type_id] = (int) $dao->priority;
    }

    // Build ordered list: prioritized types first, then unranked types
    $orderedTypes = [];
    $rankedIds = [];

    // First, add ranked types in order
    asort($priorities);
    foreach ($priorities as $locTypeId => $priority) {
      if (isset($locationTypes[$locTypeId])) {
        $orderedTypes[] = [
          'id' => $locTypeId,
          'name' => $locationTypes[$locTypeId],
          'priority' => $priority,
          'ranked' => true,
        ];
        $rankedIds[] = $locTypeId;
      }
    }

    // Then add unranked types
    foreach ($locationTypes as $locTypeId => $locTypeName) {
      if (!in_array($locTypeId, $rankedIds)) {
        $orderedTypes[] = [
          'id' => $locTypeId,
          'name' => $locTypeName,
          'priority' => null,
          'ranked' => false,
        ];
      }
    }

    $this->assign('locationTypes', $orderedTypes);
    $this->assign('extensionUrl', E::url());

    parent::run();
  }

  /**
   * Handle AJAX save of priority configuration.
   */
  private function handleSave(): void {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!isset($data['priorities']) || !is_array($data['priorities'])) {
      CRM_Utils_JSON::output(['success' => false, 'error' => 'Invalid data']);
      return;
    }

    // Clear existing priorities
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_contactinfo_dedupe_priority');

    // Insert new priorities
    foreach ($data['priorities'] as $index => $locTypeId) {
      $locTypeId = (int) $locTypeId;
      if ($locTypeId > 0) {
        CRM_Core_DAO::executeQuery(
          'INSERT INTO civicrm_contactinfo_dedupe_priority (location_type_id, priority) VALUES (%1, %2)',
          [
            1 => [$locTypeId, 'Integer'],
            2 => [$index + 1, 'Integer'],
          ]
        );
      }
    }

    CRM_Utils_JSON::output(['success' => true]);
  }

}
