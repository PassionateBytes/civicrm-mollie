{if $error}
  <div class="messages status no-popup">
    <div class="icon inform-icon"></div>
    {$error}
  </div>
{else}
  {include file="CRM/Mollie/Page/MollieDetailTable.tpl" fields=$fields nested=false}
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
  </style>
  {/literal}
  {if $dashboardUrl}
    <div style="margin-top: 1em; text-align: right;">
      <a href="{$dashboardUrl}" onclick="window.open(this.href); return false;" class="button">
        <span><i class="crm-i fa-external-link"></i> {ts domain="nl.stichtinggast.mollie"}View in Mollie Dashboard{/ts}</span>
      </a>
    </div>
  {/if}
{/if}
