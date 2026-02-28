<?php

require_once 'mollie.civix.php';

use CRM_Mollie_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 */
function mollie_civicrm_config(&$config): void {
  _mollie_civix_civicrm_config($config);

  static $autoloaded = FALSE;
  if (!$autoloaded) {
    $autoloaded = TRUE;
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
