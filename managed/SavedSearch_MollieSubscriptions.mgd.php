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
            'start_date' => 'DESC',
          ],
          // CiviCRM APIv4 automatically adds "WHERE is_test = 0" unless
          // is_test appears in the WHERE clause. The OR tautology bypasses
          // this so both test and live records are available for filtering.
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
          'actions' => true,
          'limit' => 50,
          'classes' => ['table', 'table-striped', 'crm-sticky-header'],
          'pager' => [
            'show_count' => true,
            'expose_limit' => true,
            'hide_single' => true,
          ],
          'placeholder' => 5,
          'sort' => [
            ['start_date', 'DESC'],
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
              'key' => 'processor_id',
              'label' => E::ts('Subscription ID'),
              'sortable' => true,
              'link' => [
                'path' => 'civicrm/admin/mollie/detail?api_path=customers/[mollie_customer.mollie_customer_id]/subscriptions/[processor_id]',
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
              'sortable' => true,
            ],
            [
              'type' => 'field',
              'key' => 'frequency_interval',
              'label' => E::ts('Every'),
              'sortable' => true,
              'rewrite' => '[frequency_interval] [frequency_unit:label]',
            ],
            [
              'type' => 'field',
              'key' => 'next_sched_contribution_date',
              'label' => E::ts('Next Charge'),
              'sortable' => true,
            ],
            [
              'type' => 'field',
              'key' => 'reminder_sent_date',
              'label' => E::ts('Reminder Sent'),
              'sortable' => true,
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
              'sortable' => true,
            ],
            [
              'type' => 'field',
              'key' => 'start_date',
              'label' => E::ts('Start Date'),
              'sortable' => true,
            ],
            [
              'type' => 'field',
              'key' => 'failure_count',
              'label' => E::ts('Failures'),
              'sortable' => true,
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
