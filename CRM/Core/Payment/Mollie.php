<?php

use Civi\Payment\Exception\PaymentProcessorException;
use CRM_Mollie_ExtensionUtil as E;

/**
 * Mollie payment processor for CiviCRM.
 *
 * Supports one-off and recurring donations via Mollie's Payments,
 * Customers, Mandates, and Subscriptions APIs.
 */
class CRM_Core_Payment_Mollie extends CRM_Core_Payment {

  /**
   * Mollie API client instance.
   */
  protected ?\Mollie\Api\MollieApiClient $mollieClient = NULL;

  /**
   * @param string $mode
   *   'live' or 'test'.
   * @param array $paymentProcessor
   *   Payment processor configuration.
   */
  public function __construct($mode, &$paymentProcessor) {
    parent::__construct($mode, $paymentProcessor);
  }

  /**
   * Validate that the payment processor is configured correctly.
   *
   * @return string|null
   *   Error message string if misconfigured, null if valid.
   */
  public function checkConfig(): ?string {
    $apiKey = $this->getApiKey();
    if (empty($apiKey)) {
      return E::ts('Mollie API key is not configured.');
    }

    $prefix = $this->isTestMode() ? 'test_' : 'live_';
    if (!str_starts_with($apiKey, $prefix)) {
      return E::ts('Mollie API key does not match the expected mode (%1). Key should start with "%2".', [
        1 => $this->isTestMode() ? 'test' : 'live',
        2 => $prefix,
      ]);
    }

    return NULL;
  }

  /**
   * Initialize and return the Mollie API client.
   *
   * @return \Mollie\Api\MollieApiClient
   *
   * @throws PaymentProcessorException
   *   If the API key is missing or the SDK cannot be initialized.
   */
  protected function getMollieApiClient(): \Mollie\Api\MollieApiClient {
    if ($this->mollieClient !== NULL) {
      return $this->mollieClient;
    }

    $apiKey = $this->getApiKey();
    if (empty($apiKey)) {
      throw new PaymentProcessorException(E::ts('Mollie API key is not configured.'));
    }

    try {
      $this->mollieClient = new \Mollie\Api\MollieApiClient();
      $this->mollieClient->setApiKey($apiKey);
    }
    catch (\Mollie\Api\Exceptions\ApiException $e) {
      $this->logError('Failed to initialize Mollie API client', [
        'error' => $e->getMessage(),
      ]);
      throw new PaymentProcessorException(
        E::ts('Could not connect to Mollie. Please check your API key configuration.')
      );
    }

    return $this->mollieClient;
  }

  /**
   * Get the active Mollie API key based on test/live mode.
   *
   * @return string
   *   The API key.
   */
  protected function getApiKey(): string {
    if ($this->isTestMode()) {
      return $this->_paymentProcessor['password'] ?? '';
    }
    return $this->_paymentProcessor['user_name'] ?? '';
  }

  /**
   * Whether the processor is running in test mode.
   *
   * @return bool
   */
  protected function isTestMode(): bool {
    return !empty($this->_paymentProcessor['is_test']);
  }

  /**
   * This processor supports recurring contributions.
   *
   * @return bool
   */
  public function supportsRecurring(): bool {
    return TRUE;
  }

  /**
   * This processor supports cancelling recurring contributions.
   *
   * @return bool
   */
  protected function supportsCancelRecurring(): bool {
    return TRUE;
  }

  /**
   * This processor supports editing recurring contributions.
   *
   * @return bool
   */
  public function supportsEditRecurringContribution(): bool {
    return TRUE;
  }

  /**
   * Fields that can be edited on a recurring contribution.
   *
   * @return array
   */
  public function getEditableRecurringScheduleFields(): array {
    return ['amount'];
  }

  /**
   * No on-site payment form fields — Mollie handles payment collection.
   *
   * @return array
   */
  public function getPaymentFormFields(): array {
    return [];
  }

  /**
   * No billing address fields needed — address is collected on Mollie side.
   *
   * @param int $bltID
   * @return array
   */
  public function getBillingAddressFields($bltID = NULL): array {
    return [];
  }

  /**
   * Log an info-level message to the Mollie channel.
   *
   * @param string $message
   * @param array $context
   */
  protected function logInfo(string $message, array $context = []): void {
    \Civi::log('mollie')->info($message, $context);
  }

  /**
   * Log a warning-level message to the Mollie channel.
   *
   * @param string $message
   * @param array $context
   */
  protected function logWarning(string $message, array $context = []): void {
    \Civi::log('mollie')->warning($message, $context);
  }

  /**
   * Log an error-level message to the Mollie channel.
   *
   * @param string $message
   * @param array $context
   */
  protected function logError(string $message, array $context = []): void {
    \Civi::log('mollie')->error($message, $context);
  }

  /**
   * Log a debug-level message (only when debug logging is enabled).
   *
   * @param string $message
   * @param array $context
   */
  protected function logDebug(string $message, array $context = []): void {
    if (\Civi::settings()->get('mollie_debug_logging')) {
      \Civi::log('mollie')->debug($message, $context);
    }
  }

  /**
   * Get a safe representation of the API key for logging (last 4 chars only).
   *
   * @return string
   */
  protected function getApiKeyForLog(): string {
    $key = $this->getApiKey();
    if (strlen($key) <= 4) {
      return '****';
    }
    return '****' . substr($key, -4);
  }

}
