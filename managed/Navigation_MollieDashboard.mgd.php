<?php

use CRM_Mollie_ExtensionUtil as E;

return [
  [
    'name' => 'Navigation_MollieDashboard',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'mollie_dashboard',
        'label' => E::ts('Mollie Payments'),
        'permission' => [
          'administer CiviCRM',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Contributions',
        'weight' => 90,
        'url' => 'civicrm/admin/mollie',
        'is_active' => TRUE,
        'icon' => 'crm-i fa-credit-card',
      ],
      'match' => ['domain_id', 'name'],
    ],
  ],
];
