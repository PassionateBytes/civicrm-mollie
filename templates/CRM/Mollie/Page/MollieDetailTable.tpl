<table class="crm-info-panel mollie-detail-panel">
  {foreach from=$fields key=label item=value}
    <tr>
      <td class="label">{$label|escape}</td>
      <td>
        {if is_array($value) && isset($value._json)}
          <pre><code>{$value._json|escape}</code></pre>
        {elseif is_array($value)}
          {include file="CRM/Mollie/Page/MollieDetailTable.tpl" fields=$value nested=true}
        {else}
          {$value|escape}
        {/if}
      </td>
    </tr>
  {/foreach}
  {if !empty($item) && ($item.dashboard_url || $item.related_links || $item.documentation_url)}
    <tr>
      <td class="label">{ts domain="com.passionate-bytes.mollie"}Links{/ts}</td>
      <td class="mollie-detail-item-links">
        {if $item.dashboard_url}
          <a href="{$item.dashboard_url|escape}" onclick="window.open(this.href); return false;"><i class="crm-i fa-external-link"></i> {ts domain="com.passionate-bytes.mollie"}Open in Mollie{/ts}</a>
        {/if}
        {foreach from=$item.related_links key=label item=href}
          <a href="{crmURL p='civicrm/admin/mollie/detail' q="api_path=`$href|escape`"}" class="open-inline-noreturn"><i class="crm-i fa-arrow-circle-right"></i> {$label|escape}</a>
        {/foreach}
        {if $item.documentation_url}
          <a href="{$item.documentation_url|escape}" onclick="window.open(this.href); return false;"><i class="crm-i fa-book"></i> {ts domain="com.passionate-bytes.mollie"}API Docs{/ts}</a>
        {/if}
      </td>
    </tr>
  {/if}
</table>
