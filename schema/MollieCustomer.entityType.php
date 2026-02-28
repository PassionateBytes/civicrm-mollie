<?php

use CRM_Mollie_ExtensionUtil as E;

return [
  'name' => 'MollieCustomer',
  'table' => 'civicrm_mollie_customer',
  'class' => 'CRM_Mollie_DAO_MollieCustomer',
  'getInfo' => fn() => [
    'title' => E::ts('Mollie Customer'),
    'title_plural' => E::ts('Mollie Customers'),
    'description' => E::ts('Maps CiviCRM contacts to Mollie customer IDs for recurring payment support.'),
    'log' => TRUE,
    'label_field' => 'mollie_customer_id',
  ],
  'getIndices' => fn() => [
    'UI_contact_processor' => [
      'fields' => [
        'contact_id' => TRUE,
        'payment_processor_id' => TRUE,
      ],
      'unique' => TRUE,
    ],
    'I_mollie_customer_id' => [
      'fields' => [
        'mollie_customer_id' => TRUE,
      ],
    ],
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'contact_id' => [
      'title' => E::ts('Contact ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'description' => E::ts('CiviCRM contact linked to this Mollie customer.'),
      'input_attrs' => [
        'label' => E::ts('Contact'),
      ],
      'entity_reference' => [
        'entity' => 'Contact',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'payment_processor_id' => [
      'title' => E::ts('Payment Processor ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'description' => E::ts('Payment processor instance (live or test).'),
      'input_attrs' => [
        'label' => E::ts('Payment Processor'),
      ],
      'entity_reference' => [
        'entity' => 'PaymentProcessor',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'mollie_customer_id' => [
      'title' => E::ts('Mollie Customer ID'),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Mollie-assigned customer ID (e.g. cst_8wmqcHMN4U).'),
      'input_attrs' => [
        'maxlength' => 32,
      ],
    ],
    'created_date' => [
      'title' => E::ts('Created Date'),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'required' => TRUE,
      'default' => 'CURRENT_TIMESTAMP',
      'description' => E::ts('When this record was created.'),
    ],
  ],
];
