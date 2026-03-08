<?php

use CRM_Mollie_ExtensionUtil as E;

return [
  [
    'name' => 'SavedSearch_MollieSubscriptions',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Mollie_Subscriptions',
        'label' => E::ts('Mollie Subscriptions'),
        'api_entity' => 'ContributionRecur',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'contact_id.display_name',
            'amount',
            'currency',
            'frequency_interval',
            'frequency_unit:label',
            'next_sched_contribution_date',
            'contribution_status_id:label',
            'processor_id',
            'start_date',
            'failure_count',
            'is_test',
            'MAX(reminder_activity.activity_date_time) AS reminder_sent_date',
            'MAX(reminder_activity.id) AS reminder_activity_id',
            'mollie_customer.mollie_customer_id',
          ],
          'orderBy' => [
            'next_sched_contribution_date' => 'ASC',
          ],
          'where' => [
            ['processor_id', 'IS NOT NULL'],
            ['processor_id', '!=', ''],
            ['OR', [['is_test', '=', 0], ['is_test', '=', 1]]],
          ],
          'groupBy' => ['id'],
          'join' => [
            [
              'PaymentProcessorType AS ppt',
              'INNER',
              ['payment_processor_id.payment_processor_type_id', '=', 'ppt.id'],
              ['ppt.name', '=', '"mollie"'],
            ],
            [
              'Activity AS reminder_activity',
              'LEFT',
              ['id', '=', 'reminder_activity.source_record_id'],
              ['reminder_activity.activity_type_id:name', '=', '"mollie_reminder_sent"'],
              ['reminder_activity.status_id:name', '=', '"Completed"'],
            ],
            [
              'MollieCustomer AS mollie_customer',
              'LEFT',
              ['contact_id', '=', 'mollie_customer.contact_id'],
              ['payment_processor_id', '=', 'mollie_customer.payment_processor_id'],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_MollieSubscriptions_Table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Mollie_Subscriptions_Table',
        'label' => E::ts('Mollie Subscriptions Table'),
        'saved_search_id.name' => 'Mollie_Subscriptions',
        'type' => 'table',
        'settings' => [
          'actions' => TRUE,
          'limit' => 50,
          'classes' => ['table', 'table-striped', 'crm-sticky-header'],
          'pager' => [
            'show_count' => TRUE,
            'expose_limit' => TRUE,
            'hide_single' => TRUE,
          ],
          'placeholder' => 5,
          'sort' => [
            ['next_sched_contribution_date', 'ASC'],
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
              'key' => 'processor_id',
              'label' => E::ts('Subscription ID'),
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/admin/mollie/detail?mollie_id=[processor_id]&cid=[mollie_customer.mollie_customer_id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => 'crm-popup',
              ],
            ],
            [
              'type' => 'field',
              'key' => 'amount',
              'label' => E::ts('Amount'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'frequency_interval',
              'label' => E::ts('Every'),
              'sortable' => TRUE,
              'rewrite' => '[frequency_interval] [frequency_unit:label]',
            ],
            [
              'type' => 'field',
              'key' => 'next_sched_contribution_date',
              'label' => E::ts('Next Charge'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'reminder_sent_date',
              'label' => E::ts('Reminder Sent'),
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/activity/view?reset=1&action=view&id=[reminder_activity_id]&cid=[contact_id]',
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
              'key' => 'start_date',
              'label' => E::ts('Start Date'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'failure_count',
              'label' => E::ts('Failures'),
              'sortable' => TRUE,
            ],
            [
              'size' => 'btn-xs',
              'links' => [
                [
                  'entity' => 'ContributionRecur',
                  'action' => 'update',
                  'join' => '',
                  'icon' => 'fa-pencil',
                  'text' => E::ts('Edit'),
                  'style' => 'default',
                  'path' => '',
                  'condition' => [],
                ],
              ],
              'type' => 'menu',
              'icon' => 'fa-bars',
              'alignment' => 'text-right',
            ],
          ],
          'cssRules' => [
            [
              'disabled',
              'contribution_status_id:label',
              'IN',
              ['Cancelled', 'Failed', 'Completed'],
            ],
            ['alert-warning', 'is_test', '=', TRUE],
          ],
          'filters' => [
            ['key' => 'is_test', 'default' => FALSE],
          ],
        ],
        'acl_bypass' => FALSE,
      ],
      'match' => ['name', 'saved_search_id'],
    ],
  ],
];
