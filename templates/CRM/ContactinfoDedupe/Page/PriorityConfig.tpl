<div id="contactinfo-dedupe-priority" class="crm-block crm-content-block">
  <div class="help">
    <p>{ts}Drag and drop location types to set their priority. Higher position = higher priority. When duplicate records are found within a contact, the record with the higher-priority location type is kept.{/ts}</p>
    <p>{ts}Only ranked location types participate in deduplication. Unranked types at the bottom will not be considered.{/ts}</p>
  </div>

  <h3>{ts}Ranked Location Types{/ts}</h3>
  <p class="description">{ts}Drag to reorder. Position 1 is highest priority.{/ts}</p>
  <ul id="ranked-types" class="crm-sortable-list">
    {foreach from=$locationTypes item=locType}
      {if $locType.ranked}
        <li data-id="{$locType.id}" class="crm-sortable-item ranked">
          <span class="crm-i fa-arrows-v"></span>
          <span class="loc-type-name">{$locType.name}</span>
          <span class="priority-badge">#{$locType.priority}</span>
          <a href="#" class="unrank-btn" title="{ts}Remove from ranking{/ts}"><span class="crm-i fa-times"></span></a>
        </li>
      {/if}
    {/foreach}
  </ul>

  <h3>{ts}Unranked Location Types{/ts}</h3>
  <p class="description">{ts}Click the + button to add a type to the ranking.{/ts}</p>
  <ul id="unranked-types">
    {foreach from=$locationTypes item=locType}
      {if !$locType.ranked}
        <li data-id="{$locType.id}" class="crm-sortable-item unranked">
          <span class="loc-type-name">{$locType.name}</span>
          <a href="#" class="rank-btn" title="{ts}Add to ranking{/ts}"><span class="crm-i fa-plus"></span></a>
        </li>
      {/if}
    {/foreach}
  </ul>

  <div class="crm-submit-buttons">
    <button id="save-priorities" class="crm-button crm-form-submit">
      <span class="crm-i fa-check"></span> {ts}Save Priority Configuration{/ts}
    </button>
  </div>

  <div id="save-status" class="messages" style="display: none;"></div>
</div>

<style>
  .crm-sortable-list {ldelim}
    list-style: none;
    padding: 0;
    min-height: 40px;
    border: 1px dashed #ccc;
    padding: 5px;
  {rdelim}
  .crm-sortable-item {ldelim}
    padding: 8px 12px;
    margin: 4px 0;
    background: #f5f5f5;
    border: 1px solid #ddd;
    cursor: move;
    display: flex;
    align-items: center;
    gap: 10px;
  {rdelim}
  .crm-sortable-item.ranked {ldelim}
    background: #e8f4e8;
  {rdelim}
  .crm-sortable-item .loc-type-name {ldelim}
    flex: 1;
    font-weight: bold;
  {rdelim}
  .crm-sortable-item .priority-badge {ldelim}
    background: #0071bd;
    color: white;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 0.85em;
  {rdelim}
  .crm-sortable-item .unrank-btn,
  .crm-sortable-item .rank-btn {ldelim}
    color: #666;
    text-decoration: none;
  {rdelim}
  .crm-sortable-item .unrank-btn:hover {ldelim}
    color: #c00;
  {rdelim}
  .crm-sortable-item .rank-btn:hover {ldelim}
    color: #0a0;
  {rdelim}
  .crm-sortable-item.unranked {ldelim}
    cursor: default;
    background: #fafafa;
  {rdelim}
  .crm-sortable-item.ui-sortable-helper {ldelim}
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
  {rdelim}
  #ranked-types .crm-sortable-item.ui-sortable-placeholder {ldelim}
    visibility: visible !important;
    background: #d4edda;
    border: 2px dashed #28a745;
    height: 36px;
  {rdelim}
</style>

{literal}
<script type="text/javascript">
  CRM.$(function($) {
    // Make ranked list sortable
    $('#ranked-types').sortable({
      placeholder: 'ui-sortable-placeholder',
      update: function() {
        updateBadges();
      }
    });

    // Move type to ranked list
    $(document).on('click', '.rank-btn', function(e) {
      e.preventDefault();
      var $li = $(this).closest('li');
      var id = $li.data('id');
      var name = $li.find('.loc-type-name').text();

      $li.remove();

      var newLi = $('<li>')
        .attr('data-id', id)
        .addClass('crm-sortable-item ranked')
        .html(
          '<span class="crm-i fa-arrows-v"></span>' +
          '<span class="loc-type-name">' + name + '</span>' +
          '<span class="priority-badge"></span>' +
          '<a href="#" class="unrank-btn" title="Remove from ranking"><span class="crm-i fa-times"></span></a>'
        );
      $('#ranked-types').append(newLi);
      updateBadges();
    });

    // Move type to unranked list
    $(document).on('click', '.unrank-btn', function(e) {
      e.preventDefault();
      var $li = $(this).closest('li');
      var id = $li.data('id');
      var name = $li.find('.loc-type-name').text();

      $li.remove();

      var newLi = $('<li>')
        .attr('data-id', id)
        .addClass('crm-sortable-item unranked')
        .html(
          '<span class="loc-type-name">' + name + '</span>' +
          '<a href="#" class="rank-btn" title="Add to ranking"><span class="crm-i fa-plus"></span></a>'
        );
      $('#unranked-types').append(newLi);
      updateBadges();
    });

    function updateBadges() {
      $('#ranked-types .crm-sortable-item').each(function(i) {
        $(this).find('.priority-badge').text('#' + (i + 1));
      });
    }

    // Save
    $('#save-priorities').on('click', function() {
      var priorities = [];
      $('#ranked-types .crm-sortable-item').each(function() {
        priorities.push($(this).data('id'));
      });

      var $status = $('#save-status');
      $status.hide();

      CRM.api3('Ajax', 'invoke', {
        // We'll use a direct AJAX call instead
      });

      // Direct AJAX post
      $.ajax({
        url: CRM.url('civicrm/admin/contactinfo-dedupe/priority', {action_type: 'save'}),
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({priorities: priorities}),
        dataType: 'json',
        success: function(response) {
          if (response.success) {
            $status.removeClass('crm-error').addClass('status crm-ok')
              .text('Priority configuration saved successfully.')
              .show();
          } else {
            $status.removeClass('crm-ok').addClass('status crm-error')
              .text('Error: ' + (response.error || 'Unknown error'))
              .show();
          }
        },
        error: function() {
          $status.removeClass('crm-ok').addClass('status crm-error')
            .text('Error saving configuration. Please try again.')
            .show();
        }
      });
    });
  });
</script>
{/literal}
