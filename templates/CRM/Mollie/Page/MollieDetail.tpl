{if $error}
  <div class="messages status no-popup">
    <div class="icon inform-icon"></div>
    {$error}
  </div>
{else}
  <table class="crm-info-panel mollie-detail-panel">
    {foreach from=$fields key=label item=value}
      <tr>
        <td class="label">{$label}</td>
        <td>{if $value|substr:0:1 == '{'}<pre><code>{$value|escape}</code></pre>{else}{$value|escape}{/if}</td>
      </tr>
    {/foreach}
  </table>
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
      width: 160px;
      vertical-align: top;
      text-align: right;
      border-right: 1px solid #e0e0e0;
    }
    table.mollie-detail-panel td {
      padding: 6px 10px;
      border-bottom: 1px solid #e0e0e0 !important;
      word-break: break-all;
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
