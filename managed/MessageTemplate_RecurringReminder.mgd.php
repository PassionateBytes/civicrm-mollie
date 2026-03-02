<?php

use CRM_Mollie_ExtensionUtil as E;

$subject = E::ts('Upcoming donation charge');

$html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
</head>
<body>

<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="font-family:Arial,Verdana,sans-serif; max-width:700px; margin:0; padding:0; color:#333333;">

  <!-- Greeting -->
  <tr>
    <td style="font-family:Arial,Verdana,sans-serif; padding-bottom:12px;">
      {contact.email_greeting_display},
    </td>
  </tr>

  <!-- Intro -->
  <tr>
    <td style="font-family:Arial,Verdana,sans-serif; padding-bottom:20px;">
      {ts domain="nl.stichtinggast.mollie"}This is a courtesy reminder that your recurring donation will be charged soon. Below are the details of your upcoming payment.{/ts}
    </td>
  </tr>

  <!-- Section: Payment details -->
  <tr>
    <td style="font-family:Arial,Verdana,sans-serif; font-size:14px; font-weight:bold;
               color:#333333; padding-bottom:8px;">
      {ts domain="nl.stichtinggast.mollie"}Payment details{/ts}
    </td>
  </tr>
  <tr>
    <td style="padding-bottom:20px;">
      <table width="100%" cellpadding="0" cellspacing="0" border="0"
             style="font-family:Arial,Verdana,sans-serif; font-size:13px;
                    border:1px solid #dddddd; border-radius:4px;">
        <tr>
          <td style="padding:10px 16px; width:160px; color:#888888; border-bottom:1px solid #dddddd;
                     border-right:1px solid #dddddd;">{ts domain="nl.stichtinggast.mollie"}Amount{/ts}</td>
          <td style="padding:10px 16px; border-bottom:1px solid #dddddd;">
            {contribution_recur.amount} {contribution_recur.currency}
          </td>
        </tr>
        <tr>
          <td style="padding:10px 16px; color:#888888; border-bottom:1px solid #dddddd;
                     border-right:1px solid #dddddd;">{ts domain="nl.stichtinggast.mollie"}Frequency{/ts}</td>
          <td style="padding:10px 16px; border-bottom:1px solid #dddddd;">
            {ts domain="nl.stichtinggast.mollie" 1="{contribution_recur.frequency_interval}" 2="{contribution_recur.frequency_unit}"}Every %1 %2{/ts}
          </td>
        </tr>
        <tr>
          <td style="padding:10px 16px; color:#888888;
                     border-right:1px solid #dddddd;">{ts domain="nl.stichtinggast.mollie"}Next Charge Date{/ts}</td>
          <td style="padding:10px 16px;">
            {contribution_recur.next_sched_contribution_date}
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Questions -->
  <tr>
    <td style="font-family:Arial,Verdana,sans-serif; padding-bottom:20px;">
      {ts domain="nl.stichtinggast.mollie"}If you wish to modify or cancel your recurring donation, please contact us.{/ts}
    </td>
  </tr>

  <!-- Closing -->
  <tr>
    <td style="font-family:Arial,Verdana,sans-serif; padding-bottom:12px;">
      {ts domain="nl.stichtinggast.mollie"}Thank you for your continued support.{/ts}
    </td>
  </tr>
  <tr>
    <td style="font-family:Arial,Verdana,sans-serif;">
      {ts domain="nl.stichtinggast.mollie"}Kind regards,{/ts}<br />
      {domain.name}
    </td>
  </tr>

</table>

</body>
</html>
HTML;

$text = <<<'TEXT'
{contact.email_greeting_display},

{ts domain="nl.stichtinggast.mollie"}This is a courtesy reminder that your recurring donation will be charged soon. Below are the details of your upcoming payment.{/ts}

{ts domain="nl.stichtinggast.mollie"}Payment details{/ts}
---
{ts domain="nl.stichtinggast.mollie"}Amount{/ts}: {contribution_recur.amount} {contribution_recur.currency}
{ts domain="nl.stichtinggast.mollie"}Frequency{/ts}: {ts domain="nl.stichtinggast.mollie" 1="{contribution_recur.frequency_interval}" 2="{contribution_recur.frequency_unit}"}Every %1 %2{/ts}
{ts domain="nl.stichtinggast.mollie"}Next Charge Date{/ts}: {contribution_recur.next_sched_contribution_date}

{ts domain="nl.stichtinggast.mollie"}If you wish to modify or cancel your recurring donation, please contact us.{/ts}

{ts domain="nl.stichtinggast.mollie"}Thank you for your continued support.{/ts}

{ts domain="nl.stichtinggast.mollie"}Kind regards,{/ts}
{domain.name}
TEXT;

return [
  [
    'name' => 'MessageTemplate_MollieRecurringReminder_Reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'checkPermissions' => FALSE,
      'match' => ['workflow_name', 'is_reserved'],
      'values' => [
        'workflow_name' => 'mollie_recurring_reminder',
        'msg_title' => E::ts('Mollie - Recurring Donation Reminder (Reserved)'),
        'msg_subject' => $subject,
        'msg_text' => $text,
        'msg_html' => $html,
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
      ],
    ],
  ],
  [
    'name' => 'MessageTemplate_MollieRecurringReminder_Editable',
    'entity' => 'MessageTemplate',
    'cleanup' => 'unused',
    'update' => 'never',
    'params' => [
      'version' => 4,
      'checkPermissions' => FALSE,
      'match' => ['workflow_name', 'is_reserved'],
      'values' => [
        'workflow_name' => 'mollie_recurring_reminder',
        'msg_title' => E::ts('Mollie - Recurring Donation Reminder'),
        'msg_subject' => $subject,
        'msg_text' => $text,
        'msg_html' => $html,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
      ],
    ],
  ],
];
