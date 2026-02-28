<?php

use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\PropertyBag;
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
   * Initiate a one-off or first recurring payment via Mollie.
   *
   * Creates a Mollie payment, stores the payment ID on the contribution,
   * and redirects the donor to Mollie's checkout page.
   *
   * @param array|PropertyBag $params
   * @param string $component
   *   'contribute' or 'event'.
   *
   * @return array
   *   Result array with payment_status_id.
   *
   * @throws PaymentProcessorException
   */
  public function doPayment(&$params, $component = 'contribute'): array {
    $propertyBag = PropertyBag::cast($params);
    $this->_component = $component;

    if ($propertyBag->getAmount() == 0) {
      return $this->setStatusPaymentCompleted([]);
    }

    $isRecur = !empty($params['is_recur']);
    $contributionId = $propertyBag->getContributionID();
    $contactId = $propertyBag->getContactID();

    $paymentParams = [
      'amount' => [
        'currency' => $propertyBag->getCurrency(),
        'value' => number_format((float) $propertyBag->getAmount(), 2, '.', ''),
      ],
      'description' => $this->buildPaymentDescription($contributionId),
      'redirectUrl' => $this->getReturnSuccessUrl($params['qfKey']),
      'webhookUrl' => $this->getNotifyUrl(),
      'metadata' => [
        'contribution_id' => $contributionId,
        'contact_id' => $contactId,
      ],
    ];

    $locale = $this->getMollieLocale($contactId);
    if ($locale !== NULL) {
      $paymentParams['locale'] = $locale;
    }

    if ($isRecur) {
      $paymentParams = $this->addRecurringPaymentParams($paymentParams, $params);
    }

    try {
      $molliePayment = $this->getMollieApiClient()->payments->create($paymentParams);
    }
    catch (\Mollie\Api\Exceptions\ApiException $e) {
      $this->logError('Failed to create Mollie payment', [
        'error' => $e->getMessage(),
        'contribution_id' => $contributionId,
        'api_key' => $this->getApiKeyForLog(),
      ]);
      throw new PaymentProcessorException(
        E::ts('Payment could not be initiated. Please try again or choose a different payment method.')
      );
    }

    // Store the Mollie payment ID on the contribution for webhook lookup.
    \Civi\Api4\Contribution::update(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addValue('trxn_id', $molliePayment->id)
      ->execute();

    $this->logInfo('Mollie payment created', [
      'mollie_id' => $molliePayment->id,
      'contribution_id' => $contributionId,
      'amount' => $paymentParams['amount']['value'],
      'is_recur' => $isRecur,
    ]);

    // Redirect the donor to Mollie's checkout page.
    CRM_Utils_System::redirect($molliePayment->getCheckoutUrl());

    // Unreachable — redirect exits PHP. Return for static analysis.
    return $this->setStatusPaymentPending(['trxn_id' => $molliePayment->id]);
  }

  /**
   * Handle incoming Mollie webhook notification.
   *
   * Called by CiviCRM's core IPN route dispatcher. Mollie POSTs
   * form-encoded data with `id=<payment_id>`.
   */
  public function handlePaymentNotification(): void {
    $paymentId = $_POST['id'] ?? NULL;
    if (empty($paymentId)) {
      $this->logWarning('Webhook received without payment ID');
      http_response_code(200);
      return;
    }

    $this->logDebug('Webhook received', ['mollie_payment_id' => $paymentId]);

    try {
      $molliePayment = $this->getMollieApiClient()->payments->get($paymentId);
    }
    catch (\Mollie\Api\Exceptions\ApiException $e) {
      $this->logError('Failed to fetch Mollie payment in webhook', [
        'mollie_payment_id' => $paymentId,
        'error' => $e->getMessage(),
      ]);
      http_response_code(500);
      return;
    }

    $this->logDebug('Mollie payment fetched', [
      'mollie_payment_id' => $paymentId,
      'status' => $molliePayment->status,
      'has_subscription' => !empty($molliePayment->subscriptionId),
    ]);

    if (!empty($molliePayment->subscriptionId)) {
      $this->processRecurringPaymentWebhook($molliePayment);
    }
    else {
      $this->processOneOffOrFirstPaymentWebhook($molliePayment);
    }

    http_response_code(200);
  }

  /**
   * Process a webhook for a one-off payment or the first payment of a recurring series.
   *
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function processOneOffOrFirstPaymentWebhook(\Mollie\Api\Resources\Payment $molliePayment): void {
    $contribution = $this->findContributionByTrxnId($molliePayment->id);
    if ($contribution === NULL) {
      $this->logWarning('Webhook for unknown contribution', [
        'mollie_payment_id' => $molliePayment->id,
      ]);
      return;
    }

    // Idempotency: skip if already completed.
    if ($contribution['contribution_status_id:name'] === 'Completed') {
      $this->logDebug('Contribution already completed, skipping', [
        'contribution_id' => $contribution['id'],
      ]);
      return;
    }

    if ($molliePayment->isPaid()) {
      $this->completeContribution($contribution, $molliePayment);

      // If this was the first payment of a recurring series, set up the subscription.
      if ($molliePayment->sequenceType === 'first' && !empty($contribution['contribution_recur_id'])) {
        $this->handleFirstRecurringPaymentCompleted($contribution, $molliePayment);
      }
    }
    elseif ($molliePayment->isFailed() || $molliePayment->isCanceled() || $molliePayment->isExpired()) {
      $this->failContribution($contribution, $molliePayment);
    }
  }

  /**
   * Process a webhook for a recurring installment payment (Mollie-initiated).
   *
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function processRecurringPaymentWebhook(\Mollie\Api\Resources\Payment $molliePayment): void {
    // Recurring installments are handled in Stage 5.
    $this->logWarning('Recurring payment webhook received but not yet implemented', [
      'mollie_payment_id' => $molliePayment->id,
      'subscription_id' => $molliePayment->subscriptionId,
    ]);
  }

  /**
   * Complete a contribution after a successful Mollie payment.
   *
   * @param array $contribution
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function completeContribution(array $contribution, \Mollie\Api\Resources\Payment $molliePayment): void {
    $params = [
      'id' => $contribution['id'],
      'trxn_id' => $molliePayment->id,
      'is_email_receipt' => $contribution['is_email_receipt'] ?? FALSE,
    ];

    if ($molliePayment->settlementAmount !== NULL) {
      $feeAmount = (float) $molliePayment->amount->value - (float) $molliePayment->settlementAmount->value;
      if ($feeAmount > 0) {
        $params['fee_amount'] = number_format($feeAmount, 2, '.', '');
      }
    }

    try {
      civicrm_api3('Contribution', 'completetransaction', $params);

      $this->logInfo('Contribution completed', [
        'contribution_id' => $contribution['id'],
        'mollie_payment_id' => $molliePayment->id,
        'payment_method' => $molliePayment->method ?? 'unknown',
      ]);
    }
    catch (\Exception $e) {
      $this->logError('Failed to complete contribution', [
        'contribution_id' => $contribution['id'],
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Mark a contribution as failed or cancelled.
   *
   * @param array $contribution
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function failContribution(array $contribution, \Mollie\Api\Resources\Payment $molliePayment): void {
    $statusName = $molliePayment->isCanceled() ? 'Cancelled' : 'Failed';

    try {
      \Civi\Api4\Contribution::update(FALSE)
        ->addWhere('id', '=', $contribution['id'])
        ->addValue('contribution_status_id:name', $statusName)
        ->addValue('cancel_date', date('Y-m-d H:i:s'))
        ->execute();

      $this->logInfo('Contribution marked as ' . $statusName, [
        'contribution_id' => $contribution['id'],
        'mollie_payment_id' => $molliePayment->id,
        'mollie_status' => $molliePayment->status,
      ]);
    }
    catch (\Exception $e) {
      $this->logError('Failed to update contribution status', [
        'contribution_id' => $contribution['id'],
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Handle the completion of the first payment in a recurring series.
   *
   * Verifies the mandate and creates the Mollie subscription.
   * Implemented in Stage 5.
   *
   * @param array $contribution
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function handleFirstRecurringPaymentCompleted(array $contribution, \Mollie\Api\Resources\Payment $molliePayment): void {
    // Stub — full implementation in Stage 5.
    $this->logInfo('First recurring payment completed, subscription setup pending', [
      'contribution_id' => $contribution['id'],
      'mollie_payment_id' => $molliePayment->id,
    ]);
  }

  /**
   * Add recurring-specific parameters to a Mollie payment request.
   *
   * Implemented in Stage 5.
   *
   * @param array $paymentParams
   *   Base Mollie payment parameters.
   * @param array $params
   *   CiviCRM payment parameters.
   *
   * @return array
   *   Modified payment parameters with recurring fields.
   */
  protected function addRecurringPaymentParams(array $paymentParams, array $params): array {
    // Stub — full implementation in Stage 5.
    return $paymentParams;
  }

  /**
   * Find a contribution by its Mollie payment ID (trxn_id).
   *
   * @param string $trxnId
   *
   * @return array|null
   *   Contribution record or null if not found.
   */
  protected function findContributionByTrxnId(string $trxnId): ?array {
    $contributions = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('*')
      ->addSelect('contribution_status_id:name')
      ->addWhere('trxn_id', '=', $trxnId)
      ->setLimit(1)
      ->execute();

    return $contributions->count() > 0 ? $contributions->first() : NULL;
  }

  /**
   * Build the payment description from the configured template.
   *
   * @param int $contributionId
   *
   * @return string
   */
  protected function buildPaymentDescription(int $contributionId): string {
    $template = \Civi::settings()->get('mollie_payment_description') ?? 'Donation #{contribution.id}';
    return str_replace('{contribution.id}', (string) $contributionId, $template);
  }

  /**
   * Get the Mollie locale for a contact, if available.
   *
   * @param int $contactId
   *
   * @return string|null
   *   Mollie-compatible locale string (e.g. 'nl_NL') or null.
   */
  protected function getMollieLocale(int $contactId): ?string {
    $mollieLocales = [
      'en_US', 'en_GB', 'nl_NL', 'nl_BE', 'fr_FR', 'fr_BE',
      'de_DE', 'de_AT', 'de_CH', 'es_ES', 'ca_ES', 'pt_PT',
      'it_IT', 'nb_NO', 'sv_SE', 'fi_FI', 'da_DK', 'is_IS',
      'hu_HU', 'pl_PL', 'lv_LV', 'lt_LT',
    ];

    try {
      $contacts = \Civi\Api4\Contact::get(FALSE)
        ->addSelect('preferred_language')
        ->addWhere('id', '=', $contactId)
        ->setLimit(1)
        ->execute();

      $lang = $contacts->first()['preferred_language'] ?? NULL;
      if ($lang !== NULL && in_array($lang, $mollieLocales, TRUE)) {
        return $lang;
      }
    }
    catch (\Exception $e) {
      // Non-critical — proceed without locale.
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
