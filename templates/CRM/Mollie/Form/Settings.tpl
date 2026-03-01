<div class="crm-block crm-form-block crm-mollie-settings-form-block">

  <h3>{ts domain="nl.stichtinggast.mollie"}Payments{/ts}</h3>
  <table class="form-layout-compressed">
    <tr>
      <td class="label">{$form.mollie_payment_description.label}</td>
      <td>{$form.mollie_payment_description.html}
        <p class="description">{ts domain="nl.stichtinggast.mollie"}Template for the payment description shown on Mollie and bank statements. Use {literal}{contribution.id}{/literal} as placeholder.{/ts}</p>
      </td>
    </tr>
  </table>

  <h3>{ts domain="nl.stichtinggast.mollie"}Pre-Payment Reminders{/ts}</h3>
  <table class="form-layout-compressed">
    <tr>
      <td class="label">{$form.mollie_reminder_enabled.label}</td>
      <td>{$form.mollie_reminder_enabled.html}
        <p class="description">{ts domain="nl.stichtinggast.mollie"}Send a courtesy email to donors before their next recurring charge. Requires the Mollie Recurring Reminder scheduled job to be active.{/ts}</p>
      </td>
    </tr>
    <tr>
      <td class="label">{$form.mollie_reminder_days_before.label}</td>
      <td>{$form.mollie_reminder_days_before.html}
        <p class="description">{ts domain="nl.stichtinggast.mollie"}Number of days before the charge date to send the reminder email.{/ts}</p>
      </td>
    </tr>
    {if $reminderTemplateUrl}
    <tr>
      <td class="label">{ts domain="nl.stichtinggast.mollie"}Email Template{/ts}</td>
      <td>
        <a href="{$reminderTemplateUrl}"><i class="crm-i fa-chevron-right"></i> {ts domain="nl.stichtinggast.mollie"}Edit reminder email template{/ts}</a>
      </td>
    </tr>
    {/if}
  </table>

  <h3>{ts domain="nl.stichtinggast.mollie"}Debugging{/ts}</h3>
  <table class="form-layout-compressed">
    <tr>
      <td class="label">{$form.mollie_debug_logging.label}</td>
      <td>{$form.mollie_debug_logging.html}
        <p class="description">{ts domain="nl.stichtinggast.mollie"}Log verbose Mollie API request/response data for troubleshooting. Disable in production.{/ts}</p>
      </td>
    </tr>
  </table>

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>

</div>
