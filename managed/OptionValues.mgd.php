<?php

use CRM_Mollie_ExtensionUtil as E;

return [
  [
    'name' => 'activity_type:mollie_reminder_sent',
    'entity' => 'OptionValue',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'name' => 'mollie_reminder_sent',
        'label' => E::ts('Mollie Reminder Sent'),
        'icon' => 'fa-bell',
        'is_reserved' => FALSE,
        'is_active' => TRUE,
      ],
      'match' => ['option_group_id', 'name'],
    ],
  ],
];
