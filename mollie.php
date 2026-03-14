<?php

require_once 'mollie.civix.php';


/**
 * Implements hook_civicrm_config().
 */
function mollie_civicrm_config(&$config): void {
  _mollie_civix_civicrm_config($config);

  static $autoloaded = false;
  if (!$autoloaded) {
    $autoloaded = true;
    $autoloadFile = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoloadFile)) {
      require_once $autoloadFile;
    }
  }
}

/**
 * Implements hook_civicrm_install().
 */
function mollie_civicrm_install(): void {
  _mollie_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 */
function mollie_civicrm_enable(): void {
  _mollie_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_buildForm().
 *
 * Hides the Site URL and Recurring Payments URL fields from the payment
 * processor configuration form. These fields are not used by Mollie — the
 * SDK hardcodes the API endpoint, and test/live mode is determined by the
 * API key prefix.
 */
function mollie_civicrm_buildForm(string $formName, CRM_Core_Form &$form): void {
  if ($formName !== 'CRM_Admin_Form_PaymentProcessor') {
    return;
  }
  if ($form->getTemplateVars('ppTypeName') !== 'mollie') {
    return;
  }
  $hide = ['url_site', 'url_recur', 'test_url_site', 'test_url_recur'];
  foreach ($hide as $field) {
    if ($form->elementExists($field)) {
      $form->removeElement($field);
    }
  }
}
