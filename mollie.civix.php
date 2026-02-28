<?php

// AUTO-GENERATED FILE -- This provides extension utility helpers.

/**
 * Provides small stubs for accessing resources of this extension.
 */
class CRM_Mollie_ExtensionUtil {

  const SHORT_NAME = 'mollie';
  const LONG_NAME = 'nl.stichtinggast.mollie';
  const CLASS_PREFIX = 'CRM_Mollie';

  /**
   * Translate a string using the extension's domain.
   *
   * @param string $text
   *   Canonical message text (generally en_US).
   * @param array $params
   * @return string
   *   Translated text.
   */
  public static function ts($text, $params = []): string {
    if (!array_key_exists('domain', $params)) {
      $params['domain'] = [self::LONG_NAME, NULL];
    }
    return ts($text, $params);
  }

  /**
   * Get the URL of a resource file (in this extension).
   *
   * @param string|null $file
   * @return string
   */
  public static function url($file = NULL): string {
    if ($file === NULL) {
      return rtrim(CRM_Core_Resources::singleton()->getUrl(self::LONG_NAME), '/');
    }
    return CRM_Core_Resources::singleton()->getUrl(self::LONG_NAME, $file);
  }

  /**
   * Get the path of a resource file (in this extension).
   *
   * @param string|null $file
   * @return string
   */
  public static function path($file = NULL): string {
    return __DIR__ . ($file === NULL ? '' : (DIRECTORY_SEPARATOR . $file));
  }

  /**
   * Get the name of a class within this extension.
   *
   * @param string $suffix
   * @return string
   */
  public static function findClass($suffix): string {
    return self::CLASS_PREFIX . '_' . str_replace('\\', '_', $suffix);
  }

}

use CRM_Mollie_ExtensionUtil as E;

/**
 * (Delegated) Implements hook_civicrm_config().
 */
function _mollie_civix_civicrm_config($config = NULL): void {
  static $configured = FALSE;
  if ($configured) {
    return;
  }
  $configured = TRUE;

  $extRoot = __DIR__ . DIRECTORY_SEPARATOR;
  $include_path = $extRoot . PATH_SEPARATOR . get_include_path();
  set_include_path($include_path);
}

/**
 * (Delegated) Implements hook_civicrm_install().
 */
function _mollie_civix_civicrm_install(): void {
  _mollie_civix_civicrm_config();
}

/**
 * (Delegated) Implements hook_civicrm_enable().
 */
function _mollie_civix_civicrm_enable(): void {
  _mollie_civix_civicrm_config();
}
