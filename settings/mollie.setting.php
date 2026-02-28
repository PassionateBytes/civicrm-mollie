<?php

use CRM_Mollie_ExtensionUtil as E;

return [
  'mollie_payment_description' => [
    'name' => 'mollie_payment_description',
    'type' => 'String',
    'default' => 'Donation #{contribution.id}',
    'html_type' => 'text',
    'title' => E::ts('Payment Description'),
    'description' => E::ts('Template for the payment description shown on Mollie and bank statements. Use {contribution.id} as placeholder.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => [
      'mollie' => [
        'weight' => 10,
      ],
    ],
  ],
  'mollie_reminder_enabled' => [
    'name' => 'mollie_reminder_enabled',
    'type' => 'Boolean',
    'default' => FALSE,
    'html_type' => 'checkbox',
    'title' => E::ts('Enable Pre-Payment Reminders'),
    'description' => E::ts('Send reminder emails before upcoming recurring charges.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => [
      'mollie' => [
        'weight' => 20,
      ],
    ],
  ],
  'mollie_reminder_days_before' => [
    'name' => 'mollie_reminder_days_before',
    'type' => 'Integer',
    'default' => 7,
    'html_type' => 'text',
    'title' => E::ts('Reminder Days Before Charge'),
    'description' => E::ts('Number of days before the charge date to send the reminder email.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => [
      'mollie' => [
        'weight' => 30,
      ],
    ],
  ],
  'mollie_debug_logging' => [
    'name' => 'mollie_debug_logging',
    'type' => 'Boolean',
    'default' => FALSE,
    'html_type' => 'checkbox',
    'title' => E::ts('Enable Debug Logging'),
    'description' => E::ts('Log verbose Mollie API request/response data for troubleshooting.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => [
      'mollie' => [
        'weight' => 40,
      ],
    ],
  ],
];
