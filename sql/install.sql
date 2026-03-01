CREATE TABLE IF NOT EXISTS `civicrm_mollie_customer` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` int unsigned NOT NULL COMMENT 'CiviCRM contact linked to this Mollie customer.',
  `payment_processor_id` int unsigned NOT NULL COMMENT 'Payment processor instance (live or test).',
  `mollie_customer_id` varchar(32) NOT NULL COMMENT 'Mollie-assigned customer ID (e.g. cst_8wmqcHMN4U).',
  `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this record was created.',
  PRIMARY KEY (`id`),
  UNIQUE INDEX `UI_contact_processor` (`contact_id`, `payment_processor_id`),
  INDEX `I_mollie_customer_id` (`mollie_customer_id`),
  CONSTRAINT `FK_civicrm_mollie_customer_contact_id`
    FOREIGN KEY (`contact_id`) REFERENCES `civicrm_contact` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_civicrm_mollie_customer_payment_processor_id`
    FOREIGN KEY (`payment_processor_id`) REFERENCES `civicrm_payment_processor` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
