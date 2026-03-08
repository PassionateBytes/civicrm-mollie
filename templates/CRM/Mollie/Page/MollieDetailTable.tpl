<table class="crm-info-panel mollie-detail-panel">
  {foreach from=$fields key=label item=value}
    <tr>
      <td class="label">{$label}</td>
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
</table>
