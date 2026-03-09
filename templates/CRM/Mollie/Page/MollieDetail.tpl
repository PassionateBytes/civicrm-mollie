{if $error}
  <div class="messages status no-popup">
    <div class="icon inform-icon"></div>
    {$error}
  </div>
{else}
  {include file="CRM/Mollie/Page/MollieDetailTable.tpl" fields=$fields nested=false}

  {if $isList}
    {foreach from=$items item=item name=items}
      <h4 style="margin: 1em 0 0.3em;">{ts domain="nl.stichtinggast.mollie"}Record{/ts} #{$smarty.foreach.items.iteration}:</h4>
      {include file="CRM/Mollie/Page/MollieDetailTable.tpl" fields=$item.fields nested=false item=$item}
    {/foreach}

    {if $pagination.previous || $pagination.next}
      <div class="mollie-detail-pagination">
        {if $pagination.previous}
          <a href="{crmURL p='civicrm/admin/mollie/detail' q="api_path=`$pagination.previous`"}" class="open-inline-noreturn button">
            <span><i class="crm-i fa-chevron-left"></i> {ts domain="nl.stichtinggast.mollie"}Previous{/ts}</span>
          </a>
        {/if}
        {if $pagination.next}
          <a href="{crmURL p='civicrm/admin/mollie/detail' q="api_path=`$pagination.next`"}" class="open-inline-noreturn button">
            <span><i class="crm-i fa-chevron-right"></i> {ts domain="nl.stichtinggast.mollie"}Next{/ts}</span>
          </a>
        {/if}
      </div>
    {/if}
  {/if}

  {literal}
  <style>
    .mollie-detail-panel {
      border-collapse: collapse;
      width: 100%;
    }
    .mollie-detail-panel td.label {
      background-color: #f5f5f5;
      color: #6e6e6e;
      font-weight: bold;
      width: 180px;
      vertical-align: top;
      text-align: right;
      border-right: 1px solid #e0e0e0;
      overflow-wrap: break-word;
    }
    table.mollie-detail-panel td {
      padding: 6px 10px;
      border-bottom: 1px solid #e0e0e0 !important;
      word-break: break-all;
    }
    .mollie-detail-panel td:has(> .mollie-detail-panel),
    .mollie-detail-panel td:has(> pre) {
      padding: 0 !important;
    }
    .mollie-detail-panel pre {
      margin: 0 !important;
    }
    .mollie-detail-panel .mollie-detail-panel {
      box-shadow: none !important;
      margin: 0 !important;
      border: none !important;
    }
    .mollie-detail-panel .mollie-detail-panel td.label {
      background-color: #fafafa;
    }
    .mollie-detail-item-links {
      display: flex;
      gap: 1em;
    }
    .mollie-detail-pagination {
      display: flex;
      justify-content: space-between;
      margin-top: 1em;
    }
    .mollie-detail-footer {
      display: flex;
      align-items: center;
      margin-top: 1em;
      gap: 1em;
    }
    .mollie-detail-footer .mollie-related-links {
      display: flex;
      gap: 1em;
      margin-left: auto;
    }
  </style>
  {/literal}
  {if $dashboardUrl || $relatedLinks || $documentationUrl}
    <div class="mollie-detail-footer">
      {if $dashboardUrl}
        <a href="{$dashboardUrl}" onclick="window.open(this.href); return false;" class="button">
          <span><i class="crm-i fa-external-link"></i> {ts domain="nl.stichtinggast.mollie"}Open in Mollie{/ts}</span>
        </a>
      {/if}
      <div class="mollie-related-links">
        {foreach from=$relatedLinks key=label item=href}
          <a href="{crmURL p='civicrm/admin/mollie/detail' q="api_path=$href"}" class="open-inline-noreturn"><i class="crm-i fa-arrow-circle-right"></i> {$label}</a>
        {/foreach}
        {if $documentationUrl}
          <a href="{$documentationUrl}" onclick="window.open(this.href); return false;"><i class="crm-i fa-book"></i> {ts domain="nl.stichtinggast.mollie"}API Docs{/ts}</a>
        {/if}
      </div>
    </div>
  {/if}
{/if}
