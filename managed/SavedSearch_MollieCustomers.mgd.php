<?php

use CRM_Mollie_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_MollieCustomers',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Mollie_Customers',
        'label' => E::ts('Mollie Customers'),
        'api_entity' => 'MollieCustomer',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'contact_id.display_name',
            'mollie_customer_id',
            'payment_processor_id.title',
            'created_date',
            'COUNT(ContributionRecur_MollieCustomer_contact_id_01.id) AS active_subscriptions',
            'payment_processor_id.is_test',
          ],
          'orderBy' => [
            'created_date' => 'DESC',
          ],
          'where' => [],
          'groupBy' => [
            'id',
          ],
          'join' => [
            [
              'ContributionRecur AS ContributionRecur_MollieCustomer_contact_id_01',
              'LEFT',
              ['contact_id', '=', 'ContributionRecur_MollieCustomer_contact_id_01.contact_id'],
              ['ContributionRecur_MollieCustomer_contact_id_01.contribution_status_id:name', 'IN', ['In Progress', 'Pending']],
              ['ContributionRecur_MollieCustomer_contact_id_01.processor_id', 'IS NOT NULL'],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_MollieCustomers_Table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Mollie_Customers_Table',
        'label' => E::ts('Mollie Customers Table'),
        'saved_search_id.name' => 'Mollie_Customers',
        'type' => 'table',
        'settings' => [
          'actions' => FALSE,
          'limit' => 50,
          'classes' => ['table', 'table-striped', 'crm-sticky-header'],
          'pager' => [
            'show_count' => TRUE,
            'expose_limit' => TRUE,
            'hide_single' => TRUE,
          ],
          'placeholder' => 5,
          'sort' => [
            ['created_date', 'DESC'],
          ],
          'columns' => [
            [
              'type' => 'field',
              'key' => 'contact_id.display_name',
              'label' => E::ts('Contact'),
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/contact/view?reset=1&cid=[contact_id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '_blank',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'mollie_customer_id',
              'label' => E::ts('Mollie Customer ID'),
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/admin/mollie/detail?api_path=customers/[mollie_customer_id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => 'crm-popup',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'payment_processor_id.title',
              'label' => E::ts('Payment Processor'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'active_subscriptions',
              'label' => E::ts('Active Subscriptions'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'created_date',
              'label' => E::ts('Created'),
              'sortable' => TRUE,
            ],
          ],
          'cssRules' => [
            ['alert-info font-italic', 'payment_processor_id.is_test', '=', TRUE],
          ],
          'filters' => [
            ['key' => 'payment_processor_id.is_test', 'default' => FALSE],
          ],
        ],
        'acl_bypass' => FALSE,
      ],
      'match' => ['name', 'saved_search_id'],
    ],
  ],
];
