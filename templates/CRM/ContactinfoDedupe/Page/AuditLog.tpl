<div id="contactinfo-dedupe-audit" class="crm-block crm-content-block">

  <div class="crm-accordion-wrapper crm-search-form-block">
    <div class="crm-accordion-header">{ts}Filters{/ts}</div>
    <div class="crm-accordion-body">
      <table class="form-layout-compressed">
        <tr>
          <td class="label">{ts}Entity Type{/ts}</td>
          <td>
            <select id="audit-entity-type" class="crm-form-select">
              <option value="">{ts}- Any -{/ts}</option>
              <option value="address">{ts}Address{/ts}</option>
              <option value="email">{ts}Email{/ts}</option>
              <option value="phone">{ts}Phone{/ts}</option>
            </select>
          </td>
        </tr>
        <tr>
          <td class="label">{ts}Status{/ts}</td>
          <td>
            <select id="audit-status" class="crm-form-select">
              <option value="">{ts}- Any -{/ts}</option>
              <option value="changed">{ts}Changed{/ts}</option>
              <option value="removed">{ts}Removed{/ts}</option>
            </select>
          </td>
        </tr>
        <tr>
          <td class="label">{ts}Contact ID{/ts}</td>
          <td>
            <input type="number" id="audit-contact-id" class="crm-form-text" placeholder="{ts}Filter by contact ID{/ts}" min="1" />
          </td>
        </tr>
      </table>

      <div class="crm-submit-buttons">
        <button id="btn-filter-audit" class="crm-button crm-form-submit">
          <span class="crm-i fa-filter"></span> {ts}Apply Filters{/ts}
        </button>
      </div>
    </div>
  </div>

  <div id="audit-results-wrapper">
    <table id="audit-results-table" class="display">
      <thead>
        <tr>
          <th>{ts}Date{/ts}</th>
          <th>{ts}Type{/ts}</th>
          <th>{ts}Contact{/ts}</th>
          <th>{ts}Status{/ts}</th>
          <th>{ts}Kept ID{/ts}</th>
          <th>{ts}Removed ID{/ts}</th>
          <th>{ts}Changes{/ts}</th>
          <th>{ts}Performed By{/ts}</th>
        </tr>
      </thead>
      <tbody id="audit-results-body">
        <tr><td colspan="8" style="text-align: center;">{ts}Click "Apply Filters" to load audit log entries.{/ts}</td></tr>
      </tbody>
    </table>

    <div id="audit-pagination" class="crm-pager">
      <button id="btn-audit-prev" class="crm-button" disabled="disabled">&laquo; {ts}Previous{/ts}</button>
      <span id="audit-page-info"></span>
      <button id="btn-audit-next" class="crm-button" disabled="disabled">{ts}Next{/ts} &raquo;</button>
    </div>
  </div>

  <div id="audit-processing" style="display: none;">
    <div class="crm-loading-element"></div>
    <p>{ts}Loading...{/ts}</p>
  </div>
</div>

<style>
  #audit-results-table {ldelim}
    width: 100%;
    border-collapse: collapse;
  {rdelim}
  #audit-results-table th,
  #audit-results-table td {ldelim}
    padding: 6px 10px;
    border-bottom: 1px solid #ddd;
    text-align: left;
    font-size: 0.9em;
  {rdelim}
  #audit-results-table tr:hover {ldelim}
    background: #f0f6ff;
  {rdelim}
  #audit-pagination {ldelim}
    margin-top: 10px;
    text-align: center;
  {rdelim}
  #audit-pagination #audit-page-info {ldelim}
    margin: 0 15px;
  {rdelim}
  .crm-loading-element {ldelim}
    width: 32px;
    height: 32px;
    margin: 10px auto;
    border: 3px solid #ddd;
    border-top: 3px solid #0071bd;
    border-radius: 50%;
    animation: spin 1s linear infinite;
  {rdelim}
  @keyframes spin {ldelim}
    to {ldelim} transform: rotate(360deg); {rdelim}
  {rdelim}
  #audit-processing {ldelim}
    text-align: center;
    padding: 20px;
  {rdelim}
  .audit-status-changed {ldelim}
    color: #0071bd;
    font-weight: bold;
  {rdelim}
  .audit-status-removed {ldelim}
    color: #c00;
    font-weight: bold;
  {rdelim}
  .field-changes-detail {ldelim}
    font-size: 0.85em;
    max-width: 300px;
    word-wrap: break-word;
  {rdelim}
</style>

{literal}
<script type="text/javascript">
  CRM.$(function($) {
    var auditPage = 1;
    var auditTotalPages = 0;

    $('#btn-filter-audit').on('click', function() {
      auditPage = 1;
      loadAuditLog();
    });

    // Load on page load
    loadAuditLog();

    function loadAuditLog() {
      var params = {
        action_type: 'fetch',
        pg: auditPage,
        page_size: 25
      };

      var entityType = $('#audit-entity-type').val();
      var status = $('#audit-status').val();
      var contactId = $('#audit-contact-id').val();

      if (entityType) params.entity_type = entityType;
      if (status) params.status = status;
      if (contactId) params.contact_id = contactId;

      $('#audit-processing').show();

      $.ajax({
        url: CRM.url('civicrm/admin/contactinfo-dedupe/audit', params),
        method: 'GET',
        dataType: 'json',
        success: function(response) {
          $('#audit-processing').hide();
          if (response.success) {
            auditTotalPages = response.total_pages;
            renderAuditResults(response);
          }
        },
        error: function() {
          $('#audit-processing').hide();
        }
      });
    }

    function renderAuditResults(response) {
      var $tbody = $('#audit-results-body');
      $tbody.empty();

      if (response.entries.length === 0) {
        $tbody.append('<tr><td colspan="8" style="text-align: center;">No audit log entries found.</td></tr>');
        $('#audit-page-info').text('');
        return;
      }

      $.each(response.entries, function(i, entry) {
        var contactUrl = CRM.url('civicrm/contact/view', {reset: 1, cid: entry.contact_id});
        var statusClass = entry.status === 'changed' ? 'audit-status-changed' : 'audit-status-removed';

        var changesHtml = '';
        if (entry.field_changes) {
          if (entry.status === 'removed' && entry.field_changes.removed_record) {
            changesHtml = '<em>Full record removed</em>';
          } else {
            var parts = [];
            $.each(entry.field_changes, function(field, change) {
              if (typeof change === 'object' && change !== null) {
                parts.push('<strong>' + field + '</strong>: ' +
                  (change.old || '<em>empty</em>') + ' &rarr; ' + (change.new || '<em>empty</em>'));
              }
            });
            changesHtml = parts.join('<br>');
          }
        }

        var row = '<tr>' +
          '<td>' + entry.created_date + '</td>' +
          '<td>' + entry.entity_type + '</td>' +
          '<td><a href="' + contactUrl + '" target="_blank">' +
            (entry.contact_display_name || 'Contact ' + entry.contact_id) + '</a></td>' +
          '<td><span class="' + statusClass + '">' + entry.status + '</span></td>' +
          '<td>' + entry.kept_entity_id + '</td>' +
          '<td>' + entry.removed_entity_id + '</td>' +
          '<td class="field-changes-detail">' + changesHtml + '</td>' +
          '<td>' + (entry.created_by_name || 'System') + '</td>' +
          '</tr>';
        $tbody.append(row);
      });

      $('#audit-page-info').text('Page ' + response.page + ' of ' + response.total_pages +
        ' (' + response.total + ' entries)');
      $('#btn-audit-prev').prop('disabled', auditPage <= 1);
      $('#btn-audit-next').prop('disabled', auditPage >= auditTotalPages);
    }

    $('#btn-audit-prev').on('click', function() {
      if (auditPage > 1) { auditPage--; loadAuditLog(); }
    });

    $('#btn-audit-next').on('click', function() {
      if (auditPage < auditTotalPages) { auditPage++; loadAuditLog(); }
    });
  });
</script>
{/literal}
