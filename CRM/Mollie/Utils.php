<?php

use CRM_Mollie_ExtensionUtil as E;

/**
 * Utility helpers for the Mollie extension.
 */
class CRM_Mollie_Utils {
  private const MOLLIE_DASHBOARD_BASE = 'https://my.mollie.com/dashboard';

  /**
   * Static cache for isMollieProcessor() lookups.
   *
   * @var array<int, bool>
   */
  private static array $mollieProcessorCache = [];

  /**
   * Check whether a payment processor is a Mollie processor.
   *
   * @param int $paymentProcessorId
   *
   * @return bool
   */
  public static function isMollieProcessor(int $paymentProcessorId): bool {
    if (isset(self::$mollieProcessorCache[$paymentProcessorId])) {
      return self::$mollieProcessorCache[$paymentProcessorId];
    }

    $count = \Civi\Api4\PaymentProcessor::get(false)
      ->selectRowCount()
      ->addJoin(
        'PaymentProcessorType AS ppt',
        'INNER',
        ['payment_processor_type_id', '=', 'ppt.id'],
        ['ppt.name', '=', '"mollie"'],
      )
      ->addWhere('id', '=', $paymentProcessorId)
      ->execute()
      ->count();

    self::$mollieProcessorCache[$paymentProcessorId] = $count > 0;

    return self::$mollieProcessorCache[$paymentProcessorId];
  }

  /**
   * Look up the Mollie customer ID for a contact and payment processor.
   *
   * @param int $contactId
   * @param int $paymentProcessorId
   *
   * @return string|null
   *   The Mollie customer ID (e.g. "cst_..."), or null if not found.
   */
  public static function getMollieCustomerId(int $contactId, int $paymentProcessorId): ?string {
    $record = \Civi\Api4\MollieCustomer::get(false)
      ->addSelect('mollie_customer_id')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('payment_processor_id', '=', $paymentProcessorId)
      ->execute()
      ->first();

    return $record['mollie_customer_id'] ?? null;
  }

  private const CLIENT_CACHE_TTL = 300;

  /** @var array<int, array{client: \Mollie\Api\MollieApiClient, expires: int}> */
  private static array $clientCache = [];

  /**
   * Get an authenticated Mollie API client for a payment processor.
   *
   * Results are cached for 5 minutes to avoid repeatedly loading
   * processor config during batch operations.
   *
   * @param int $processorId
   *
   * @return \Mollie\Api\MollieApiClient
   */
  public static function getClientForProcessor(int $processorId): \Mollie\Api\MollieApiClient {
    if (isset(self::$clientCache[$processorId])
        && self::$clientCache[$processorId]['expires'] > time()) {
      return self::$clientCache[$processorId]['client'];
    }

    $processor = \Civi\Payment\System::singleton()->getById($processorId);
    $processorConfig = $processor->getPaymentProcessor();
    $apiKey = $processorConfig['user_name'] ?? '';

    $client = new \Mollie\Api\MollieApiClient();
    $client->setApiKey($apiKey);
    self::$clientCache[$processorId] = [
      'client' => $client,
      'expires' => time() + self::CLIENT_CACHE_TTL,
    ];

    return $client;
  }

  /**
   * Reset internal caches (for testing).
   */
  public static function resetCache(): void {
    self::$mollieProcessorCache = [];
    self::$clientCache = [];
  }

  /**
   * Build a Mollie dashboard URL for a payment.
   *
   * @param string $paymentId
   *   The Mollie payment ID (e.g. "tr_WDqYK6vllg").
   *
   * @return string
   */
  public static function getPaymentDashboardUrl(string $paymentId): string {
    return self::MOLLIE_DASHBOARD_BASE . '/payments/' . urlencode($paymentId);
  }

  /**
   * Build a Mollie dashboard URL for a customer.
   *
   * @param string $customerId
   *   The Mollie customer ID (e.g. "cst_...").
   *
   * @return string
   */
  public static function getCustomerDashboardUrl(string $customerId): string {
    return self::MOLLIE_DASHBOARD_BASE . '/customers/' . urlencode($customerId);
  }

}
