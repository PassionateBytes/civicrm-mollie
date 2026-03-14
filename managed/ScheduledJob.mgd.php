<?php

use CRM_Mollie_ExtensionUtil as E;

return [
  [
    'name' => 'Job_MollieSync',
    'entity' => 'Job',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Mollie Subscription Sync',
        'description' => E::ts('Synchronize Mollie subscription statuses with CiviCRM recurring contributions. Detects auto-cancellations, completions, and suspensions.'),
        'api_entity' => 'MollieSync',
        'api_action' => 'run',
        'parameters' => 'version=4',
        'run_frequency' => 'Daily',
        'is_active' => true,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'Job_MollieRecurringReminder',
    'entity' => 'Job',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Mollie Recurring Reminder',
        'description' => E::ts('Send reminder emails before upcoming Mollie recurring charges.'),
        'api_entity' => 'MollieRecurringReminder',
        'api_action' => 'run',
        'parameters' => 'version=4',
        'run_frequency' => 'Daily',
        'is_active' => true,
      ],
      'match' => ['name'],
    ],
  ],
];
