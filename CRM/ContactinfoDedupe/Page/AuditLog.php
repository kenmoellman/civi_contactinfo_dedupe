<?php

use CRM_ContactinfoDedupe_ExtensionUtil as E;

/**
 * Page controller for the audit log viewer.
 */
class CRM_ContactinfoDedupe_Page_AuditLog extends CRM_Core_Page {

  /**
   * Run the page.
   */
  public function run(): void {
    CRM_Utils_System::setTitle(E::ts('Contact Info Dedupe - Audit Log'));

    // Handle AJAX request for data
    $action = CRM_Utils_Request::retrieve('action_type', 'String', $this, false);
    if ($action === 'fetch') {
      $this->handleFetch();
      return;
    }

    $this->assign('extensionUrl', E::url());

    parent::run();
  }

  /**
   * Handle AJAX fetch of audit log entries.
   */
  private function handleFetch(): void {
    $page = CRM_Utils_Request::retrieve('page', 'Integer', $this, false, 1);
    $pageSize = CRM_Utils_Request::retrieve('page_size', 'Integer', $this, false, 25);
    $entityType = CRM_Utils_Request::retrieve('entity_type', 'String', $this, false);
    $contactId = CRM_Utils_Request::retrieve('contact_id', 'Integer', $this, false);
    $status = CRM_Utils_Request::retrieve('status', 'String', $this, false);

    $offset = ($page - 1) * $pageSize;

    $whereClauses = ['1=1'];
    $params = [];
    $paramIdx = 1;

    if ($entityType) {
      $whereClauses[] = "al.entity_type = %{$paramIdx}";
      $params[$paramIdx] = [$entityType, 'String'];
      $paramIdx++;
    }

    if ($contactId) {
      $whereClauses[] = "al.contact_id = %{$paramIdx}";
      $params[$paramIdx] = [$contactId, 'Integer'];
      $paramIdx++;
    }

    if ($status) {
      $whereClauses[] = "al.status = %{$paramIdx}";
      $params[$paramIdx] = [$status, 'String'];
      $paramIdx++;
    }

    $where = implode(' AND ', $whereClauses);

    // Get total count
    $total = (int) CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM civicrm_contactinfo_dedupe_audit_log al WHERE {$where}",
      $params
    );

    // Get entries
    $params[$paramIdx] = [$pageSize, 'Integer'];
    $limitParam = $paramIdx;
    $paramIdx++;
    $params[$paramIdx] = [$offset, 'Integer'];
    $offsetParam = $paramIdx;

    $sql = "
      SELECT
        al.*,
        c.display_name AS contact_display_name,
        cb.display_name AS created_by_name
      FROM civicrm_contactinfo_dedupe_audit_log al
      LEFT JOIN civicrm_contact c ON c.id = al.contact_id
      LEFT JOIN civicrm_contact cb ON cb.id = al.created_by
      WHERE {$where}
      ORDER BY al.created_date DESC
      LIMIT %{$limitParam} OFFSET %{$offsetParam}
    ";

    $dao = CRM_Core_DAO::executeQuery($sql, $params);

    $entries = [];
    while ($dao->fetch()) {
      $entries[] = [
        'id' => (int) $dao->id,
        'entity_type' => $dao->entity_type,
        'contact_id' => (int) $dao->contact_id,
        'contact_display_name' => $dao->contact_display_name,
        'kept_entity_id' => (int) $dao->kept_entity_id,
        'removed_entity_id' => (int) $dao->removed_entity_id,
        'status' => $dao->status,
        'field_changes' => json_decode($dao->field_changes, true),
        'created_date' => $dao->created_date,
        'created_by' => (int) $dao->created_by,
        'created_by_name' => $dao->created_by_name,
      ];
    }

    CRM_Utils_JSON::output([
      'success' => true,
      'entries' => $entries,
      'total' => $total,
      'page' => $page,
      'page_size' => $pageSize,
      'total_pages' => $pageSize > 0 ? ceil($total / $pageSize) : 0,
    ]);
  }

}
