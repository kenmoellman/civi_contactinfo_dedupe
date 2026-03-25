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
        <tr id="address-options-row">
          <td class="label">{ts}Address Options{/ts}</td>
          <td>
            <label>
              <input type="checkbox" id="ignore-plus4" />
              {ts}Ignore conflicting +4 ZIP suffix{/ts}
            </label>
            <p class="description">{ts}When checked, records that only differ by postal code suffix (+4) will be merged and the suffix will be cleared on the kept record.{/ts}</p>
            <label style="margin-top: 5px; display: block;">
              <input type="checkbox" id="ignore-geocode" />
              {ts}Ignore conflicting geocodes{/ts}
            </label>
            <p class="description">{ts}When checked, records that only differ by latitude/longitude will be merged and the geocodes will be cleared on the kept record.{/ts}</p>
          </td>
        </tr>
      </table>

      <div class="crm-submit-buttons">
        <button id="btn-find-duplicates" class="crm-button crm-form-submit" {if !$hasPriorities}disabled="disabled"{/if}>
          <span class="crm-i fa-search"></span> {ts}Find Duplicates{/ts}
        </button>
        <button id="btn-apply-consensus" class="crm-button" {if !$hasPriorities}disabled="disabled"{/if} style="display: none;">
          <span class="crm-i fa-balance-scale"></span> {ts}Apply Consensus{/ts}
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
    <div class="dedupe-overlay"></div>
    <div class="dedupe-modal">
      <div class="crm-loading-element"></div>
      <p>{ts}Processing duplicates... Please wait.{/ts}</p>
      <p class="dedupe-modal-sub">{ts}This may take a while for large datasets. Do not close this page.{/ts}</p>
    </div>
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
    width: 48px;
    height: 48px;
    margin: 0 auto 15px;
    border: 4px solid #ddd;
    border-top: 4px solid #0071bd;
    border-radius: 50%;
    animation: spin 1s linear infinite;
  {rdelim}
  @keyframes spin {ldelim}
    to {ldelim} transform: rotate(360deg); {rdelim}
  {rdelim}
  .dedupe-overlay {ldelim}
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 99999;
  {rdelim}
  .dedupe-modal {ldelim}
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    padding: 40px 50px;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    z-index: 100000;
    text-align: center;
    min-width: 350px;
  {rdelim}
  .dedupe-modal p {ldelim}
    font-size: 16px;
    font-weight: bold;
    margin: 10px 0 5px;
  {rdelim}
  .dedupe-modal .dedupe-modal-sub {ldelim}
    font-size: 13px;
    font-weight: normal;
    color: #666;
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

    // Toggle address options based on entity type
    function updateAddressOptions() {
      if ($('#dedupe-entity-type').val() === 'address') {
        $('#address-options-row').show();
        $('#btn-apply-consensus').show();
      } else {
        $('#address-options-row').hide();
        $('#btn-apply-consensus').hide();
      }
    }
    $('#dedupe-entity-type').on('change', updateAddressOptions);
    updateAddressOptions();

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
        pg: currentPage,
        page_size: 25
      };
      if ($('input[name="dedupe-scope"]:checked').val() === 'range') {
        var startId = $('#start-contact-id').val();
        var endId = $('#end-contact-id').val();
        if (startId) params.start_id = startId;
        if (endId) params.end_id = endId;
      }
      if ($('#ignore-plus4').is(':checked')) {
        params.ignore_plus4 = 1;
      }
      if ($('#ignore-geocode').is(':checked')) {
        params.ignore_geocode = 1;
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
      var postData = {
        entity_type: $('#dedupe-entity-type').val(),
        pairs: pairs
      };
      if ($('#ignore-plus4').is(':checked')) {
        postData.ignore_plus4 = 1;
      }
      if ($('#ignore-geocode').is(':checked')) {
        postData.ignore_geocode = 1;
      }
      $.ajax({
        url: CRM.url('civicrm/admin/contactinfo-dedupe/search', {action_type: 'process'}),
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(postData),
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
      if ($('#ignore-plus4').is(':checked')) {
        params.ignore_plus4 = 1;
      }
      if ($('#ignore-geocode').is(':checked')) {
        params.ignore_geocode = 1;
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

    // Apply Consensus
    $('#btn-apply-consensus').on('click', function() {
      var scopeParams = {};
      if ($('input[name="dedupe-scope"]:checked').val() === 'range') {
        var startId = $('#start-contact-id').val();
        var endId = $('#end-contact-id').val();
        if (startId) scopeParams.start_id = parseInt(startId);
        if (endId) scopeParams.end_id = parseInt(endId);
      }
      if (!confirm('Apply consensus values to address groups with 3+ duplicates' +
        (scopeParams.start_id ? ' (contacts ' + scopeParams.start_id + '-' + (scopeParams.end_id || '...') + ')' : '') +
        '? This will update outlier records to match the majority. This action cannot be undone.')) return;

      $('#dedupe-processing').show();
      $.ajax({
        url: CRM.url('civicrm/admin/contactinfo-dedupe/search', {action_type: 'consensus'}),
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(scopeParams),
        dataType: 'json',
        success: function(response) {
          $('#dedupe-processing').hide();
          if (response.success) {
            showStatus('ok', 'Consensus applied: ' + response.groups_processed + ' group(s), ' +
              response.fields_updated + ' field(s) updated.');
            if (totalCount > 0) loadDuplicates();
          } else {
            showStatus('error', response.error || 'Consensus failed');
          }
        },
        error: function() {
          $('#dedupe-processing').hide();
          showStatus('error', 'Error communicating with server.');
        }
      });
    });

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
