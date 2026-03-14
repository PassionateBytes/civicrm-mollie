<?php

use CRM_Mollie_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_MolliePayments',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Mollie_Payments',
        'label' => E::ts('Mollie Payments'),
        'api_entity' => 'Contribution',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'contact_id.display_name',
            'total_amount',
            'currency',
            'receive_date',
            'trxn_id',
            'contribution_status_id:label',
            'cancel_reason',
            'fee_amount',
            'contribution_recur_id',
            'is_test',
          ],
          'orderBy' => [
            'receive_date' => 'DESC',
          ],
          // CiviCRM APIv4 automatically adds "WHERE is_test = 0" unless
          // is_test appears in the WHERE clause. The OR tautology bypasses
          // this so both test and live records are available for filtering.
          'where' => [
            ['trxn_id', 'LIKE', 'tr_%'],
            ['OR', [['is_test', '=', 0], ['is_test', '=', 1]]],
          ],
          'groupBy' => [],
          'join' => [],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_MolliePayments_Table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Mollie_Payments_Table',
        'label' => E::ts('Mollie Payments Table'),
        'saved_search_id.name' => 'Mollie_Payments',
        'type' => 'table',
        'settings' => [
          'actions' => false,
          'limit' => 50,
          'classes' => ['table', 'table-striped', 'crm-sticky-header'],
          'pager' => [
            'show_count' => true,
            'expose_limit' => true,
            'hide_single' => true,
          ],
          'placeholder' => 5,
          'sort' => [
            ['receive_date', 'DESC'],
          ],
          'columns' => [
            [
              'type' => 'field',
              'key' => 'contact_id.display_name',
              'label' => E::ts('Contact'),
              'sortable' => true,
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
              'key' => 'trxn_id',
              'label' => E::ts('Mollie Payment ID'),
              'sortable' => true,
              'link' => [
                'path' => 'civicrm/admin/mollie/detail?api_path=payments/[trxn_id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => 'crm-popup',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'total_amount',
              'label' => E::ts('Amount'),
              'sortable' => true,
            ],
            [
              'type' => 'field',
              'key' => 'currency',
              'label' => E::ts('Currency'),
              'sortable' => true,
            ],
            [
              'type' => 'field',
              'key' => 'receive_date',
              'label' => E::ts('Date'),
              'sortable' => true,
            ],
            [
              'type' => 'field',
              'key' => 'contribution_status_id:label',
              'label' => E::ts('Status'),
              'sortable' => true,
              'title' => '[cancel_reason]',
            ],
            [
              'type' => 'field',
              'key' => 'fee_amount',
              'label' => E::ts('Fee'),
              'sortable' => true,
            ],
          ],
          'cssRules' => [
            ['alert-info font-italic', 'is_test', '=', true],
          ],
          'filters' => [
            ['key' => 'is_test', 'default' => false],
          ],
        ],
        'acl_bypass' => false,
      ],
      'match' => ['name', 'saved_search_id'],
    ],
  ],
];
