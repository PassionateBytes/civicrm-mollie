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
          'access CiviContribute',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Contributions',
        'weight' => 90,
        'url' => 'civicrm/admin/mollie',
        'is_active' => true,
        'icon' => '',
      ],
      'match' => ['domain_id', 'name'],
    ],
  ],
  [
    'name' => 'Navigation_MollieSettings',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'mollie_settings',
        'label' => E::ts('Mollie Settings'),
        'permission' => [
          'administer payment processors',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'CiviContribute',
        'weight' => 900,
        'url' => 'civicrm/admin/mollie/settings',
        'is_active' => true,
        'icon' => '',
      ],
      'match' => ['domain_id', 'name'],
    ],
  ],
];
