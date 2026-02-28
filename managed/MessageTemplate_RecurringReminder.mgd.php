<?php

use CRM_Mollie_ExtensionUtil as E;

$subject = E::ts('Upcoming donation charge');
$html = '<p>' . E::ts('Dear {contact.display_name},') . '</p>'
  . '<p>' . E::ts('This is a reminder that your recurring donation of {$recurCurrency} {$recurAmount} will be charged on {$nextChargeDate}.') . '</p>'
  . '<p>' . E::ts('If you wish to modify or cancel your recurring donation, please contact us.') . '</p>'
  . '<p>' . E::ts('Thank you for your continued support.') . '</p>';
$text = E::ts('Dear {contact.display_name},') . "\n\n"
  . E::ts('This is a reminder that your recurring donation of {$recurCurrency} {$recurAmount} will be charged on {$nextChargeDate}.') . "\n\n"
  . E::ts('If you wish to modify or cancel your recurring donation, please contact us.') . "\n\n"
  . E::ts('Thank you for your continued support.');

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
