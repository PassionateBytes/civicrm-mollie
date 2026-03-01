<?php

use CRM_Mollie_ExtensionUtil as E;

return [
  [
    'name' => 'PaymentProcessorType_Mollie',
    'entity' => 'PaymentProcessorType',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'mollie',
        'title' => E::ts('Mollie'),
        'description' => E::ts('Mollie Payment Processor for one-off and recurring donations'),
        'class_name' => 'Payment_Mollie',
        'user_name_label' => E::ts('Mollie API Key'),
        'billing_mode' => CRM_Core_Payment::BILLING_MODE_NOTIFY,
        'is_recur' => TRUE,
        'payment_type' => 1,
        'is_active' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
];
