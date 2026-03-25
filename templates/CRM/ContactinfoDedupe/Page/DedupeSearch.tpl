<div id="contactinfo-dedupe-search" class="crm-block crm-content-block">

  {if !$hasPriorities}
    <div class="messages status no-popup">
      <div class="icon inform-icon"></div>
      {ts}You must configure location type priorities before running deduplication.{/ts}
      <a href="{crmURL p='civicrm/admin/contactinfo-dedupe/priority'}">{ts}Configure Priorities{/ts}</a>
    </div>
  {/if}

  <div class="crm-accordion-wrapper crm-search-form-block">
    <div class="crm-accordion-header">{ts}Search Criteria{/ts}</div>
    <div class="crm-accordion-body">
      <table class="form-layout-compressed">
        <tr>
          <td class="label">{ts}Entity Type{/ts}</td>
          <td>
            <select id="dedupe-entity-type" class="crm-form-select">
              <option value="address">{ts}Address{/ts}</option>
              <option value="email">{ts}Email{/ts}</option>
              <option value="phone">{ts}Phone{/ts}</option>
            </select>
          </td>
        </tr>
        <tr>
          <td class="label">{ts}Scope{/ts}</td>
          <td>
            <label>
              <input type="radio" name="dedupe-scope" value="all" checked="checked" />
              {ts}All Contacts{/ts}
            </label>
            <label style="margin-left: 15px;">
              <input type="radio" name="dedupe-scope" value="range" />
              {ts}Contact ID Range{/ts}
            </label>
          </td>
        </tr>
        <tr id="contact-range-row" style="display: none;">
          <td class="label">{ts}Contact ID Range{/ts}</td>
          <td>
            <input type="number" id="start-contact-id" class="crm-form-text" placeholder="{ts}From{/ts}" min="1" />
            &nbsp;{ts}to{/ts}&nbsp;
            <input type="number" id="end-contact-id" class="crm-form-text" placeholder="{ts}To{/ts}" min="1" />
          </td>
        </tr>
      </table>

      <div class="crm-submit-buttons">
        <button id="btn-find-duplicates" class="crm-button crm-form-submit" {if !$hasPriorities}disabled="disabled"{/if}>
          <span class="crm-i fa-search"></span> {ts}Find Duplicates{/ts}
        </button>
      </div>
    </div>
  </div>

  <div id="dedupe-results-wrapper" style="display: none;">
    <div class="crm-block crm-results-block">
      <h3>
        {ts}Duplicate Records Found{/ts}:
        <span id="total-count">0</span>
      </h3>

      <div class="crm-submit-buttons" style="margin-bottom: 10px;">
        <label id="select-all-label" style="margin-right: 15px;">
          <input type="checkbox" id="select-all-duplicates" />
          {ts}Select All{/ts} <span id="select-all-count"></span> {ts}duplicates{/ts}
        </label>
        <button id="btn-process-selected" class="crm-button" disabled="disabled">
          <span class="crm-i fa-compress"></span> {ts}Merge Selected{/ts}
        </button>
        <button id="btn-process-all" class="crm-button">
          <span class="crm-i fa-compress"></span> {ts}Merge All Duplicates{/ts}
        </button>
      </div>

      <table id="dedupe-results-table" class="display crm-sortable-table">
        <thead>
          <tr>
            <th class="crm-dedupe-select"><input type="checkbox" id="select-page" /></th>
            <th>{ts}Contact{/ts}</th>
            <th>{ts}Details{/ts}</th>
            <th>{ts}Location Type 1{/ts}</th>
            <th>{ts}Location Type 2{/ts}</th>
            <th>{ts}Extra Info{/ts}</th>
          </tr>
        </thead>
        <tbody id="dedupe-results-body">
        </tbody>
      </table>

      <div id="dedupe-pagination" class="crm-pager">
        <button id="btn-prev-page" class="crm-button" disabled="disabled">&laquo; {ts}Previous{/ts}</button>
        <span id="page-info"></span>
        <button id="btn-next-page" class="crm-button" disabled="disabled">{ts}Next{/ts} &raquo;</button>
      </div>
    </div>
  </div>

  <div id="dedupe-status" class="messages" style="display: none;"></div>
  <div id="dedupe-processing" style="display: none;">
    <div class="crm-loading-element"></div>
    <p>{ts}Processing duplicates... Please wait.{/ts}</p>
  </div>
</div>

<style>
  #dedupe-results-table {ldelim}
    width: 100%;
    border-collapse: collapse;
  {rdelim}
  #dedupe-results-table th,
  #dedupe-results-table td {ldelim}
    padding: 6px 10px;
    border-bottom: 1px solid #ddd;
    text-align: left;
  {rdelim}
  #dedupe-results-table tr:hover {ldelim}
    background: #f0f6ff;
  {rdelim}
  #dedupe-pagination {ldelim}
    margin-top: 10px;
    text-align: center;
  {rdelim}
  #dedupe-pagination #page-info {ldelim}
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
  #dedupe-processing {ldelim}
    text-align: center;
    padding: 20px;
  {rdelim}
  #select-all-label {ldelim}
    font-weight: bold;
  {rdelim}
</style>

{literal}
<script type="text/javascript">
  CRM.$(function($) {
    var currentPage = 1;
    var totalPages = 0;
    var totalCount = 0;
    var selectAllMode = false;

    // Toggle contact range fields
    $('input[name="dedupe-scope"]').on('change', function() {
      if ($(this).val() === 'range') {
        $('#contact-range-row').show();
      } else {
        $('#contact-range-row').hide();
      }
    });

    // Find duplicates
    $('#btn-find-duplicates').on('click', function() {
      currentPage = 1;
      selectAllMode = false;
      $('#select-all-duplicates').prop('checked', false);
      loadDuplicates();
    });

    function getSearchParams() {
      var params = {
        action_type: 'find',
        entity_type: $('#dedupe-entity-type').val(),
        page: currentPage,
        page_size: 25
      };
      if ($('input[name="dedupe-scope"]:checked').val() === 'range') {
        var startId = $('#start-contact-id').val();
        var endId = $('#end-contact-id').val();
        if (startId) params.start_id = startId;
        if (endId) params.end_id = endId;
      }
      return params;
    }

    function loadDuplicates() {
      var params = getSearchParams();
      $('#dedupe-processing').show();
      $('#dedupe-results-wrapper').hide();
      $('#dedupe-status').hide();

      $.ajax({
        url: CRM.url('civicrm/admin/contactinfo-dedupe/search', params),
        method: 'GET',
        dataType: 'json',
        success: function(response) {
          $('#dedupe-processing').hide();
          if (response.success) {
            totalCount = response.total;
            totalPages = response.total_pages;
            currentPage = response.page;
            renderResults(response);
            $('#dedupe-results-wrapper').show();
          } else {
            showStatus('error', response.error || 'Unknown error');
          }
        },
        error: function() {
          $('#dedupe-processing').hide();
          showStatus('error', 'Error communicating with server.');
        }
      });
    }

    function renderResults(response) {
      var $tbody = $('#dedupe-results-body');
      $tbody.empty();
      $('#total-count').text(response.total);
      $('#select-all-count').text(response.total);

      var entityType = $('#dedupe-entity-type').val();

      if (response.duplicates.length === 0) {
        $tbody.append('<tr><td colspan="6" style="text-align:center;">' +
          'No duplicates found.</td></tr>');
        return;
      }

      $.each(response.duplicates, function(i, dup) {
        var details = '';
        var extra = '';

        if (entityType === 'address') {
          details = (dup.street_address || '(no street)') + ', ' +
                    (dup.city || '') + ', ' + (dup.state_province || '');
          extra = 'ZIP: ' + (dup.postal_code_1 || 'N/A') + ' / ' + (dup.postal_code_2 || 'N/A');
        } else if (entityType === 'email') {
          details = dup.email || '';
          extra = 'Hold: ' + (dup.on_hold_1 ? 'Yes' : 'No') + ' / ' + (dup.on_hold_2 ? 'Yes' : 'No');
        } else if (entityType === 'phone') {
          details = (dup.phone || '') + ' (' + (dup.phone_type || '') + ')';
          extra = 'Ext: ' + (dup.ext_1 || 'N/A') + ' / ' + (dup.ext_2 || 'N/A');
        }

        var contactUrl = CRM.url('civicrm/contact/view', {reset: 1, cid: dup.contact_id});

        var row = '<tr>' +
          '<td><input type="checkbox" class="dup-checkbox" data-id1="' + dup.id1 + '" data-id2="' + dup.id2 + '" /></td>' +
          '<td><a href="' + contactUrl + '" target="_blank">' + dup.display_name + '</a> (ID: ' + dup.contact_id + ')</td>' +
          '<td>' + details + '</td>' +
          '<td>ID ' + dup.id1 + ': ' + dup.location_type_1 + '</td>' +
          '<td>ID ' + dup.id2 + ': ' + dup.location_type_2 + '</td>' +
          '<td>' + extra + '</td>' +
          '</tr>';
        $tbody.append(row);
      });

      // Update pagination
      $('#page-info').text('Page ' + currentPage + ' of ' + totalPages);
      $('#btn-prev-page').prop('disabled', currentPage <= 1);
      $('#btn-next-page').prop('disabled', currentPage >= totalPages);

      updateMergeButton();
    }

    // Pagination
    $('#btn-prev-page').on('click', function() {
      if (currentPage > 1) {
        currentPage--;
        loadDuplicates();
      }
    });

    $('#btn-next-page').on('click', function() {
      if (currentPage < totalPages) {
        currentPage++;
        loadDuplicates();
      }
    });

    // Select page checkbox
    $('#select-page').on('change', function() {
      $('.dup-checkbox').prop('checked', $(this).is(':checked'));
      updateMergeButton();
    });

    // Select all duplicates (across all pages)
    $('#select-all-duplicates').on('change', function() {
      selectAllMode = $(this).is(':checked');
      if (selectAllMode) {
        $('.dup-checkbox').prop('checked', true);
        $('#select-page').prop('checked', true);
      }
      updateMergeButton();
    });

    // Individual checkbox
    $(document).on('change', '.dup-checkbox', function() {
      if (!$(this).is(':checked')) {
        selectAllMode = false;
        $('#select-all-duplicates').prop('checked', false);
      }
      updateMergeButton();
    });

    function updateMergeButton() {
      var hasSelection = selectAllMode || $('.dup-checkbox:checked').length > 0;
      $('#btn-process-selected').prop('disabled', !hasSelection);
    }

    // Merge selected
    $('#btn-process-selected').on('click', function() {
      if (selectAllMode) {
        // Process all — same as the "Merge All" button
        processAll();
        return;
      }

      var pairs = [];
      $('.dup-checkbox:checked').each(function() {
        pairs.push({
          id1: parseInt($(this).data('id1')),
          id2: parseInt($(this).data('id2'))
        });
      });

      if (pairs.length === 0) return;

      if (!confirm('Merge ' + pairs.length + ' duplicate pair(s)? This action cannot be undone.')) return;

      processSelected(pairs);
    });

    // Merge all
    $('#btn-process-all').on('click', function() {
      if (!confirm('Merge ALL ' + totalCount + ' duplicate pair(s)? This action cannot be undone.')) return;
      processAll();
    });

    function processSelected(pairs) {
      $('#dedupe-processing').show();
      $.ajax({
        url: CRM.url('civicrm/admin/contactinfo-dedupe/search', {action_type: 'process'}),
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
          entity_type: $('#dedupe-entity-type').val(),
          pairs: pairs
        }),
        dataType: 'json',
        success: function(response) {
          $('#dedupe-processing').hide();
          if (response.success) {
            showStatus('ok', 'Processed: ' + response.processed + ', Skipped: ' + response.skipped +
              (response.errors && response.errors.length ? ', Errors: ' + response.errors.length : ''));
            loadDuplicates(); // Refresh
          } else {
            showStatus('error', response.error || 'Processing failed');
          }
        },
        error: function() {
          $('#dedupe-processing').hide();
          showStatus('error', 'Error communicating with server.');
        }
      });
    }

    function processAll() {
      $('#dedupe-processing').show();
      var params = {
        entity_type: $('#dedupe-entity-type').val()
      };
      if ($('input[name="dedupe-scope"]:checked').val() === 'range') {
        var startId = $('#start-contact-id').val();
        var endId = $('#end-contact-id').val();
        if (startId) params.start_id = parseInt(startId);
        if (endId) params.end_id = parseInt(endId);
      }

      $.ajax({
        url: CRM.url('civicrm/admin/contactinfo-dedupe/search', {action_type: 'process_all'}),
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(params),
        dataType: 'json',
        success: function(response) {
          $('#dedupe-processing').hide();
          if (response.success) {
            showStatus('ok', 'Processed: ' + response.processed + ', Skipped: ' + response.skipped);
            loadDuplicates(); // Refresh
          } else {
            showStatus('error', response.error || 'Processing failed');
          }
        },
        error: function() {
          $('#dedupe-processing').hide();
          showStatus('error', 'Error communicating with server.');
        }
      });
    }

    function showStatus(type, message) {
      var $status = $('#dedupe-status');
      $status.removeClass('crm-error crm-ok status')
        .addClass('status ' + (type === 'error' ? 'crm-error' : 'crm-ok'))
        .text(message)
        .show();
    }
  });
</script>
{/literal}
