<?php

/**
 * Centralized logging helper for the Mollie extension.
 *
 * Prefixes all messages with [Mollie] so they stand out in the shared
 * CiviCRM log file, and routes to the 'mollie' log channel.
 */
class CRM_Mollie_Log {

  private const PREFIX = '[Mollie] ';

  /**
   * Log a debug message (only when debug logging is enabled).
   *
   * @param string $message
   * @param array $context
   */
  public static function debug(string $message, array $context = []): void {
    if (\Civi::settings()->get('mollie_debug_logging')) {
      \Civi::log('mollie')->debug(self::PREFIX . $message, $context);
    }
  }

  /**
   * Log an info-level message.
   *
   * @param string $message
   * @param array $context
   */
  public static function info(string $message, array $context = []): void {
    \Civi::log('mollie')->info(self::PREFIX . $message, $context);
  }

  /**
   * Log a warning-level message.
   *
   * @param string $message
   * @param array $context
   */
  public static function warning(string $message, array $context = []): void {
    \Civi::log('mollie')->warning(self::PREFIX . $message, $context);
  }

  /**
   * Log an error-level message.
   *
   * @param string $message
   * @param array $context
   */
  public static function error(string $message, array $context = []): void {
    \Civi::log('mollie')->error(self::PREFIX . $message, $context);
  }

}
