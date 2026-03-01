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
            'fee_amount',
            'contribution_recur_id',
          ],
          'orderBy' => [
            'receive_date' => 'DESC',
          ],
          'where' => [
            ['trxn_id', 'LIKE', 'tr_%'],
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
            ['receive_date', 'DESC'],
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
              'key' => 'total_amount',
              'label' => E::ts('Amount'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'currency',
              'label' => E::ts('Currency'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'receive_date',
              'label' => E::ts('Date'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'trxn_id',
              'label' => E::ts('Mollie Payment ID'),
              'sortable' => TRUE,
              'link' => [
                'path' => 'https://my.mollie.com/dashboard/payments/[trxn_id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '_blank',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'contribution_status_id:label',
              'label' => E::ts('Status'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'fee_amount',
              'label' => E::ts('Fee'),
              'sortable' => TRUE,
            ],
          ],
        ],
        'acl_bypass' => FALSE,
      ],
      'match' => ['name', 'saved_search_id'],
    ],
  ],
];
