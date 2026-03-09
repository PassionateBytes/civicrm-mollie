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
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
  }

  // ---------------------------------------------------------------------------
  // Configuration & capabilities
  // ---------------------------------------------------------------------------

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

  // ---------------------------------------------------------------------------
  // Recurring lifecycle: cancel & amount change
  // ---------------------------------------------------------------------------

  /**
   * Cancel a Mollie subscription.
   *
   * Called by CiviCRM's doCancelRecurring() when staff cancels a recurring contribution.
   *
   * @param string|null $message
   *   Feedback message (passed by reference).
   * @param \Civi\Payment\PropertyBag|array $params
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function cancelSubscription(&$message = NULL, $params = []): bool {
    $propertyBag = PropertyBag::cast($params);
    $recurId = $propertyBag->getContributionRecurID();

    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('processor_id', 'contact_id')
      ->addWhere('id', '=', $recurId)
      ->setLimit(1)
      ->execute()
      ->first();

    if (empty($recur['processor_id'])) {
      $message = E::ts('No Mollie subscription ID found for this recurring contribution.');
      return FALSE;
    }

    $mollieCustomer = $this->findMollieCustomerByContactId($recur['contact_id']);
    if ($mollieCustomer === NULL) {
      $message = E::ts('No Mollie customer found for this contact.');
      return FALSE;
    }

    try {
      $this->getMollieApiClient()->subscriptions->cancelForId($mollieCustomer, $recur['processor_id']);

      $this->logInfo('Mollie subscription cancelled', [
        'subscription_id' => $recur['processor_id'],
        'contribution_recur_id' => $recurId,
      ]);

      $message = E::ts('Mollie subscription has been cancelled.');
      return TRUE;
    }
    catch (\Mollie\Api\Exceptions\ApiException $e) {
      $this->logError('Failed to cancel Mollie subscription', [
        'subscription_id' => $recur['processor_id'],
        'error' => $e->getMessage(),
      ]);
      $message = E::ts('Failed to cancel the subscription on Mollie: %1', [1 => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Update the amount of a Mollie subscription.
   *
   * @param string|null $message
   *   Feedback message (passed by reference).
   * @param array $params
   *   Must include 'amount' and 'contributionRecurID'.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function changeSubscriptionAmount(&$message = NULL, $params = []): bool {
    $recurId = $params['contributionRecurID'] ?? ($params['id'] ?? NULL);
    $newAmount = $params['amount'] ?? NULL;

    if ($recurId === NULL || $newAmount === NULL) {
      $message = E::ts('Missing required parameters for amount change.');
      return FALSE;
    }

    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('processor_id', 'contact_id', 'currency')
      ->addWhere('id', '=', $recurId)
      ->setLimit(1)
      ->execute()
      ->first();

    if (empty($recur['processor_id'])) {
      $message = E::ts('No Mollie subscription ID found for this recurring contribution.');
      return FALSE;
    }

    $mollieCustomer = $this->findMollieCustomerByContactId($recur['contact_id']);
    if ($mollieCustomer === NULL) {
      $message = E::ts('No Mollie customer found for this contact.');
      return FALSE;
    }

    try {
      $this->getMollieApiClient()->subscriptions->update($mollieCustomer, $recur['processor_id'], [
        'amount' => [
          'currency' => $recur['currency'],
          'value' => number_format((float) $newAmount, 2, '.', ''),
        ],
      ]);

      $this->logInfo('Mollie subscription amount updated', [
        'subscription_id' => $recur['processor_id'],
        'contribution_recur_id' => $recurId,
        'new_amount' => $newAmount,
      ]);

      $message = E::ts('Subscription amount updated on Mollie.');
      return TRUE;
    }
    catch (\Mollie\Api\Exceptions\ApiException $e) {
      $this->logError('Failed to update Mollie subscription amount', [
        'subscription_id' => $recur['processor_id'],
        'error' => $e->getMessage(),
      ]);
      $message = E::ts('Failed to update subscription amount on Mollie: %1', [1 => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Find the Mollie customer ID for a CiviCRM contact.
   *
   * @param int $contactId
   *
   * @return string|null
   *   Mollie customer ID or null if not found.
   */
  protected function findMollieCustomerByContactId(int $contactId): ?string {
    $result = \Civi\Api4\MollieCustomer::get(FALSE)
      ->addSelect('mollie_customer_id')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('payment_processor_id', '=', $this->_paymentProcessor['id'])
      ->setLimit(1)
      ->execute();

    return $result->count() > 0 ? $result->first()['mollie_customer_id'] : NULL;
  }

  // ---------------------------------------------------------------------------
  // Payment initiation
  // ---------------------------------------------------------------------------

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
        'civicrm' => [
          'contribution_id' => $contributionId,
          'contact_id' => $contactId,
        ],
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
   * Add recurring-specific parameters to a Mollie payment request.
   *
   * Finds or creates a Mollie customer for the contact and sets
   * sequenceType to "first" so Mollie creates a mandate.
   *
   * @param array $paymentParams
   *   Base Mollie payment parameters.
   * @param array $params
   *   CiviCRM payment parameters.
   *
   * @return array
   *   Modified payment parameters with recurring fields.
   *
   * @throws PaymentProcessorException
   */
  protected function addRecurringPaymentParams(array $paymentParams, array $params): array {
    $propertyBag = PropertyBag::cast($params);
    $contactId = $propertyBag->getContactID();

    $mollieCustomerId = $this->findOrCreateMollieCustomer($contactId);

    $paymentParams['sequenceType'] = 'first';
    $paymentParams['customerId'] = $mollieCustomerId;
    $paymentParams['metadata']['civicrm']['contribution_recur_id'] = $params['contributionRecurID'] ?? NULL;

    return $paymentParams;
  }

  // ---------------------------------------------------------------------------
  // Webhook handling
  // ---------------------------------------------------------------------------

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
      $this->recordUnmatchedWebhookActivity($molliePayment, E::ts(
        'No contribution found with trxn_id %1. The contribution may have been deleted or the database restored from a backup.',
        [1 => $molliePayment->id]
      ));
      return;
    }

    // Chargebacks: Mollie re-sends the same payment ID webhook when a
    // chargeback is filed. The payment stays "paid" but gains chargeback data.
    // Must be checked before the idempotency skip.
    if ($molliePayment->isPaid() && $molliePayment->hasChargebacks()) {
      $this->handleChargeback($contribution, $molliePayment);
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

      // If this was the first payment of a recurring series, fail the recur too —
      // no mandate will be created, so no subscription is possible.
      if ($molliePayment->sequenceType === 'first' && !empty($contribution['contribution_recur_id'])) {
        $this->failContributionRecur($contribution['contribution_recur_id'], $molliePayment);
      }
    }
  }

  /**
   * Process a webhook for a recurring installment payment (Mollie-initiated).
   *
   * Mollie creates these payments automatically per the subscription schedule.
   * Each triggers a webhook that we use to create a CiviCRM contribution.
   *
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function processRecurringPaymentWebhook(\Mollie\Api\Resources\Payment $molliePayment): void {
    $subscriptionId = $molliePayment->subscriptionId;

    // Chargebacks on recurring installments — same logic as one-off payments.
    $existing = $this->findContributionByTrxnId($molliePayment->id);
    if ($existing !== NULL && $molliePayment->isPaid() && $molliePayment->hasChargebacks()) {
      $this->handleChargeback($existing, $molliePayment);
      return;
    }

    // Idempotency: skip if we already recorded this payment.
    if ($existing !== NULL) {
      $this->logDebug('Recurring payment already recorded, skipping', [
        'mollie_payment_id' => $molliePayment->id,
        'contribution_id' => $existing['id'],
      ]);
      return;
    }

    $contributionRecur = $this->findContributionRecurByProcessorId($subscriptionId);
    if ($contributionRecur === NULL) {
      $this->logWarning('Webhook for unknown subscription', [
        'mollie_payment_id' => $molliePayment->id,
        'subscription_id' => $subscriptionId,
      ]);
      $this->recordUnmatchedWebhookActivity($molliePayment, E::ts(
        'No recurring contribution found for Mollie subscription %1. The recurring contribution may have been deleted or was never created.',
        [1 => $subscriptionId]
      ));
      return;
    }

    if ($molliePayment->isPaid()) {
      $this->createRecurringInstallment($contributionRecur, $molliePayment);
    }
    elseif ($molliePayment->isFailed() || $molliePayment->isExpired() || $molliePayment->isCanceled()) {
      $this->recordFailedRecurringInstallment($contributionRecur, $molliePayment);
    }
  }

  // ---------------------------------------------------------------------------
  // Contribution status updates
  // ---------------------------------------------------------------------------

  /**
   * Complete a contribution after a successful Mollie payment.
   *
   * Uses Payment.create to record the payment against the pending contribution.
   * This handles financial bookkeeping (FinancialTrxn, FinancialItem) and
   * transitions the contribution to Completed via completeOrder().
   *
   * @param array $contribution
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function completeContribution(array $contribution, \Mollie\Api\Resources\Payment $molliePayment): void {
    $params = [
      'contribution_id' => $contribution['id'],
      'total_amount' => $molliePayment->amount->value,
      'trxn_id' => $molliePayment->id,
      'trxn_date' => $molliePayment->paidAt ?? date('Y-m-d H:i:s'),
      'payment_processor_id' => $this->_paymentProcessor['id'],
      'is_send_contribution_notification' => $contribution['is_email_receipt'] ?? FALSE,
    ];

    if ($molliePayment->settlementAmount !== NULL) {
      $feeAmount = (float) $molliePayment->amount->value - (float) $molliePayment->settlementAmount->value;
      if ($feeAmount > 0) {
        $params['fee_amount'] = number_format($feeAmount, 2, '.', '');
      }
    }

    try {
      civicrm_api3('Payment', 'create', $params);

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
    $cancelReason = $this->buildCancelReason($molliePayment);

    try {
      \Civi\Api4\Contribution::update(FALSE)
        ->addWhere('id', '=', $contribution['id'])
        ->addValue('contribution_status_id:name', $statusName)
        ->addValue('cancel_date', date('Y-m-d H:i:s'))
        ->addValue('cancel_reason', $cancelReason)
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
   * Mark a ContributionRecur as failed.
   *
   * Used when the first recurring payment fails or when mandate/subscription
   * setup fails after a successful first payment — the recurring series
   * cannot proceed.
   *
   * @param int $recurId
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function failContributionRecur(int $recurId, \Mollie\Api\Resources\Payment $molliePayment): void {
    $statusName = $molliePayment->isCanceled() ? 'Cancelled' : 'Failed';
    $cancelReason = $this->buildCancelReason($molliePayment);

    try {
      \Civi\Api4\ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $recurId)
        ->addValue('contribution_status_id:name', $statusName)
        ->addValue('cancel_date', date('Y-m-d H:i:s'))
        ->addValue('cancel_reason', $cancelReason)
        ->addValue('end_date', date('Y-m-d H:i:s'))
        ->addValue('next_sched_contribution_date', NULL)
        ->execute();

      $this->logInfo('ContributionRecur marked as ' . $statusName, [
        'contribution_recur_id' => $recurId,
        'mollie_payment_id' => $molliePayment->id,
        'reason' => 'first payment or subscription setup failed',
      ]);
    }
    catch (\Exception $e) {
      $this->logError('Failed to update ContributionRecur status', [
        'contribution_recur_id' => $recurId,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Build a human-readable cancel reason from a Mollie payment.
   *
   * Assembles status, payment method, and any failure details provided by
   * Mollie into a concise string for the cancel_reason field.
   *
   * @param \Mollie\Api\Resources\Payment $molliePayment
   *
   * @return string
   */
  protected function buildCancelReason(\Mollie\Api\Resources\Payment $molliePayment): string {
    $status = $molliePayment->status ?? 'unknown';
    $parts = [
      E::ts('Mollie payment') . " {$status}: {$molliePayment->id}",
    ];

    if (!empty($molliePayment->method)) {
      $parts[] = E::ts('Method') . ": {$molliePayment->method}";
    }

    // Some payment methods (e.g. credit card) include failure details.
    $failureReason = $molliePayment->details->failureReason ?? NULL;
    $failureMessage = $molliePayment->details->failureMessage ?? NULL;
    if ($failureReason !== NULL || $failureMessage !== NULL) {
      $detail = implode(' — ', array_filter([$failureReason, $failureMessage]));
      $parts[] = E::ts('Reason') . ": {$detail}";
    }

    return implode('. ', $parts);
  }

  /**
   * Handle a chargeback on a payment.
   *
   * Mollie re-sends the payment webhook when a chargeback is filed. The
   * payment stays "paid" but gains chargeback data. We mark the CiviCRM
   * contribution with the Chargeback status and log full details for staff.
   *
   * @param array $contribution
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function handleChargeback(array $contribution, \Mollie\Api\Resources\Payment $molliePayment): void {
    // Already marked as chargeback — nothing to do.
    if ($contribution['contribution_status_id:name'] === 'Chargeback') {
      $this->logDebug('Contribution already marked as Chargeback, skipping', [
        'contribution_id' => $contribution['id'],
      ]);
      return;
    }

    try {
      $chargebackAmount = $molliePayment->amountChargedBack !== NULL
        ? $molliePayment->amountChargedBack->value
        : 'unknown';
      $currency = $molliePayment->amountChargedBack !== NULL
        ? ($molliePayment->amountChargedBack->currency ?? 'EUR')
        : ($molliePayment->amount->currency ?? 'EUR');

      \Civi\Api4\Contribution::update(FALSE)
        ->addWhere('id', '=', $contribution['id'])
        ->addValue('contribution_status_id:name', 'Chargeback')
        ->addValue('cancel_date', date('Y-m-d H:i:s'))
        ->execute();

      // Fetch chargeback details from Mollie for the paper trail.
      $this->recordChargebackNote($contribution, $molliePayment, $chargebackAmount, $currency);

      $this->logWarning('Chargeback received', [
        'contribution_id' => $contribution['id'],
        'mollie_payment_id' => $molliePayment->id,
        'chargeback_amount' => $chargebackAmount,
        'original_amount' => $molliePayment->amount->value ?? 'unknown',
      ]);
    }
    catch (\Exception $e) {
      $this->logError('Failed to process chargeback', [
        'contribution_id' => $contribution['id'],
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Attach a Note to the contribution with chargeback details from Mollie.
   *
   * Fetches chargeback records from Mollie's API and records the amount,
   * date, and reason (if provided) as a CiviCRM Note on the contribution
   * for staff reference.
   *
   * @param array $contribution
   * @param \Mollie\Api\Resources\Payment $molliePayment
   * @param string $chargebackAmount
   * @param string $currency
   */
  protected function recordChargebackNote(
    array $contribution,
    \Mollie\Api\Resources\Payment $molliePayment,
    string $chargebackAmount,
    string $currency,
  ): void {
    $lines = [
      E::ts('A chargeback was received on this contribution.'),
      '',
      E::ts('Chargeback amount: %1 %2', [1 => $chargebackAmount, 2 => $currency]),
      E::ts('Original amount: %1 %2', [1 => $molliePayment->amount->value, 2 => $currency]),
      E::ts('Mollie payment ID: %1', [1 => $molliePayment->id]),
    ];

    try {
      $chargebacks = $molliePayment->chargebacks();
      foreach ($chargebacks as $chargeback) {
        $lines[] = '';
        $lines[] = E::ts('Chargeback ID: %1', [1 => $chargeback->id]);
        $lines[] = E::ts('Amount: %1 %2', [1 => $chargeback->amount->value, 2 => $chargeback->amount->currency]);
        $lines[] = E::ts('Date: %1', [1 => $chargeback->createdAt ?? 'unknown']);

        if ($chargeback->reason !== NULL) {
          $reasonCode = $chargeback->reason->code ?? '';
          $reasonDesc = $chargeback->reason->description ?? '';
          $lines[] = E::ts('Reason: %1 — %2', [1 => $reasonCode, 2 => $reasonDesc]);
        }

        if ($chargeback->reversedAt !== NULL) {
          $lines[] = E::ts('Reversed at: %1', [1 => $chargeback->reversedAt]);
        }
      }
    }
    catch (\Exception $e) {
      $lines[] = '';
      $lines[] = E::ts('(Could not fetch chargeback details from Mollie: %1)', [1 => $e->getMessage()]);
    }

    try {
      \Civi\Api4\Note::create(FALSE)
        ->addValue('entity_table', 'civicrm_contribution')
        ->addValue('entity_id', $contribution['id'])
        ->addValue('subject', E::ts('Mollie Chargeback'))
        ->addValue('note', implode("\n", $lines))
        ->addValue('contact_id', $contribution['contact_id'])
        ->execute();
    }
    catch (\Exception $e) {
      $this->logError('Failed to create chargeback note', [
        'contribution_id' => $contribution['id'],
        'error' => $e->getMessage(),
      ]);
    }
  }

  // ---------------------------------------------------------------------------
  // Recurring: first payment → subscription setup
  // ---------------------------------------------------------------------------

  /**
   * Handle the completion of the first payment in a recurring series.
   *
   * Verifies the mandate exists, stores it as a PaymentToken, creates
   * the Mollie subscription, and updates the ContributionRecur.
   *
   * @param array $contribution
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function handleFirstRecurringPaymentCompleted(array $contribution, \Mollie\Api\Resources\Payment $molliePayment): void {
    $recurId = $contribution['contribution_recur_id'];
    $customerId = $molliePayment->customerId;

    try {
      $mandateId = $this->verifyMandate($customerId);
      if ($mandateId === NULL) {
        $this->logError('No valid mandate found after first recurring payment', [
          'contribution_id' => $contribution['id'],
          'mollie_customer_id' => $customerId,
        ]);
        $this->failContributionRecur($recurId, $molliePayment);
        return;
      }

      $paymentTokenId = $this->createPaymentToken($contribution['contact_id'], $mandateId);

      $recur = \Civi\Api4\ContributionRecur::get(FALSE)
        ->addSelect('*')
        ->addWhere('id', '=', $recurId)
        ->setLimit(1)
        ->execute()
        ->first();

      // If installments = 1, the first payment was the only payment.
      // No subscription needed — mark the recurring series as complete.
      if (!empty($recur['installments']) && (int) $recur['installments'] <= 1) {
        \Civi\Api4\ContributionRecur::update(FALSE)
          ->addWhere('id', '=', $recurId)
          ->addValue('payment_token_id', $paymentTokenId)
          ->addValue('contribution_status_id:name', 'Completed')
          ->execute();

        $this->logInfo('Single-installment recurring contribution completed (no subscription needed)', [
          'contribution_recur_id' => $recurId,
          'mollie_customer_id' => $customerId,
        ]);
        return;
      }

      \Civi\Api4\ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $recurId)
        ->addValue('payment_token_id', $paymentTokenId)
        ->execute();

      $subscriptionId = $this->createMollieSubscription($customerId, $mandateId, $recur);

      \Civi\Api4\ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $recurId)
        ->addValue('processor_id', $subscriptionId)
        ->addValue('contribution_status_id:name', 'In Progress')
        ->execute();

      $this->logInfo('Mollie subscription created', [
        'subscription_id' => $subscriptionId,
        'contribution_recur_id' => $recurId,
        'mollie_customer_id' => $customerId,
        'mandate_id' => $mandateId,
      ]);
    }
    catch (\Exception $e) {
      $this->logError('Failed to set up recurring subscription after first payment', [
        'contribution_id' => $contribution['id'],
        'contribution_recur_id' => $recurId,
        'error' => $e->getMessage(),
      ]);
      $this->failContributionRecur($recurId, $molliePayment);
    }
  }

  /**
   * Create a Mollie subscription for recurring payments.
   *
   * @param string $customerId
   * @param string $mandateId
   * @param array $recur
   *   ContributionRecur record.
   *
   * @return string
   *   The Mollie subscription ID.
   *
   * @throws \Mollie\Api\Exceptions\ApiException
   */
  protected function createMollieSubscription(string $customerId, string $mandateId, array $recur): string {
    $interval = $this->mapCiviCrmFrequencyToMollie(
      $recur['frequency_unit'],
      $recur['frequency_interval']
    );

    $subscriptionParams = [
      'amount' => [
        'currency' => $recur['currency'],
        'value' => number_format((float) $recur['amount'], 2, '.', ''),
      ],
      'interval' => $interval,
      'description' => $this->buildPaymentDescription($recur['id']),
      'webhookUrl' => $this->getNotifyUrl(),
      'mandateId' => $mandateId,
      'metadata' => [
        'civicrm' => [
          'contribution_recur_id' => $recur['id'],
          'contact_id' => $recur['contact_id'],
        ],
      ],
    ];

    if (!empty($recur['next_sched_contribution_date'])) {
      $subscriptionParams['startDate'] = date('Y-m-d', strtotime($recur['next_sched_contribution_date']));
    }

    // Subtract 1 from installments since the first payment was already made.
    if (!empty($recur['installments']) && $recur['installments'] > 1) {
      $subscriptionParams['times'] = $recur['installments'] - 1;
    }

    $subscription = $this->getMollieApiClient()->subscriptions->createForId($customerId, $subscriptionParams);

    return $subscription->id;
  }

  // ---------------------------------------------------------------------------
  // Recurring: subsequent installment handling
  // ---------------------------------------------------------------------------

  /**
   * Create a CiviCRM contribution for a successful recurring installment.
   *
   * @param array $contributionRecur
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function createRecurringInstallment(array $contributionRecur, \Mollie\Api\Resources\Payment $molliePayment): void {
    $feeAmount = NULL;
    if ($molliePayment->settlementAmount !== NULL) {
      $fee = (float) $molliePayment->amount->value - (float) $molliePayment->settlementAmount->value;
      if ($fee > 0) {
        $feeAmount = number_format($fee, 2, '.', '');
      }
    }

    try {
      // Create contribution as Pending via repeattransaction (handles template
      // cloning, line items, soft credits, custom fields), then record the
      // payment via Payment.create for proper financial bookkeeping.
      $result = civicrm_api3('Contribution', 'repeattransaction', [
        'contribution_recur_id' => $contributionRecur['id'],
        'trxn_id' => $molliePayment->id,
        'payment_processor_id' => $contributionRecur['payment_processor_id'],
        'receive_date' => $molliePayment->paidAt ?? date('Y-m-d H:i:s'),
        'total_amount' => (float) $molliePayment->amount->value,
      ]);

      $contributionId = $result['id'];

      $paymentParams = [
        'contribution_id' => $contributionId,
        'total_amount' => $molliePayment->amount->value,
        'trxn_id' => $molliePayment->id,
        'trxn_date' => $molliePayment->paidAt ?? date('Y-m-d H:i:s'),
        'payment_processor_id' => $contributionRecur['payment_processor_id'],
        'is_send_contribution_notification' => TRUE,
      ];
      if ($feeAmount !== NULL) {
        $paymentParams['fee_amount'] = $feeAmount;
      }

      civicrm_api3('Payment', 'create', $paymentParams);

      $nextDate = $this->calculateNextScheduledDate($contributionRecur);
      if ($nextDate !== NULL) {
        \Civi\Api4\ContributionRecur::update(FALSE)
          ->addWhere('id', '=', $contributionRecur['id'])
          ->addValue('next_sched_contribution_date', $nextDate)
          ->execute();
      }

      $this->logInfo('Recurring installment recorded', [
        'contribution_recur_id' => $contributionRecur['id'],
        'mollie_payment_id' => $molliePayment->id,
        'amount' => $molliePayment->amount->value,
      ]);
    }
    catch (\Exception $e) {
      $this->logError('Failed to record recurring installment', [
        'contribution_recur_id' => $contributionRecur['id'],
        'mollie_payment_id' => $molliePayment->id,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Record a failed recurring installment for audit trail.
   *
   * @param array $contributionRecur
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function recordFailedRecurringInstallment(array $contributionRecur, \Mollie\Api\Resources\Payment $molliePayment): void {
    try {
      // repeattransaction creates contributions in Pending status and only
      // transitions to Completed via completeOrder(). For failed payments we
      // create as Pending, then use failContribution() to set the correct status.
      $result = civicrm_api3('Contribution', 'repeattransaction', [
        'contribution_recur_id' => $contributionRecur['id'],
        'trxn_id' => $molliePayment->id,
        'payment_processor_id' => $contributionRecur['payment_processor_id'],
        'receive_date' => date('Y-m-d H:i:s'),
        'total_amount' => (float) $molliePayment->amount->value,
      ]);

      $contributionId = $result['id'] ?? NULL;
      if ($contributionId) {
        $this->failContribution(['id' => $contributionId], $molliePayment);
      }

      \Civi\Api4\ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $contributionRecur['id'])
        ->addValue('failure_count', ($contributionRecur['failure_count'] ?? 0) + 1)
        ->execute();

      $this->logWarning('Recurring installment failed', [
        'contribution_recur_id' => $contributionRecur['id'],
        'mollie_payment_id' => $molliePayment->id,
      ]);
    }
    catch (\Exception $e) {
      $this->logError('Failed to record failed recurring installment', [
        'contribution_recur_id' => $contributionRecur['id'],
        'error' => $e->getMessage(),
      ]);
    }
  }

  // ---------------------------------------------------------------------------
  // Mollie customer & mandate management
  // ---------------------------------------------------------------------------

  /**
   * Find or create a Mollie customer for a CiviCRM contact.
   *
   * @param int $contactId
   *
   * @return string
   *   The Mollie customer ID.
   *
   * @throws PaymentProcessorException
   */
  protected function findOrCreateMollieCustomer(int $contactId): string {
    $processorId = $this->_paymentProcessor['id'];

    $existing = \Civi\Api4\MollieCustomer::get(FALSE)
      ->addSelect('mollie_customer_id')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('payment_processor_id', '=', $processorId)
      ->setLimit(1)
      ->execute();

    if ($existing->count() > 0) {
      return $existing->first()['mollie_customer_id'];
    }

    $contact = \Civi\Api4\Contact::get(FALSE)
      ->addSelect('display_name', 'email_primary.email')
      ->addWhere('id', '=', $contactId)
      ->setLimit(1)
      ->execute()
      ->first();

    try {
      $mollieCustomer = $this->getMollieApiClient()->customers->create([
        'name' => $contact['display_name'] ?? '',
        'email' => $contact['email_primary.email'] ?? '',
        'metadata' => ['civicrm' => ['contact_id' => $contactId]],
      ]);
    }
    catch (\Mollie\Api\Exceptions\ApiException $e) {
      $this->logError('Failed to create Mollie customer', [
        'contact_id' => $contactId,
        'error' => $e->getMessage(),
      ]);
      throw new PaymentProcessorException(
        E::ts('Could not set up recurring payment. Please try again.')
      );
    }

    \Civi\Api4\MollieCustomer::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('payment_processor_id', $processorId)
      ->addValue('mollie_customer_id', $mollieCustomer->id)
      ->execute();

    $this->logInfo('Mollie customer created', [
      'mollie_customer_id' => $mollieCustomer->id,
      'contact_id' => $contactId,
    ]);

    return $mollieCustomer->id;
  }

  /**
   * Verify that a valid mandate exists for a Mollie customer.
   *
   * @param string $customerId
   *
   * @return string|null
   *   The mandate ID if valid, null otherwise.
   */
  protected function verifyMandate(string $customerId): ?string {
    try {
      $mandates = $this->getMollieApiClient()->mandates->listForId($customerId);
      // Accept both valid and pending mandates. SEPA mandates from iDEAL
      // can briefly be "pending" before transitioning to "valid".
      // Mollie allows subscription creation with pending mandates.
      foreach ($mandates as $mandate) {
        if ($mandate->isValid() || $mandate->isPending()) {
          return $mandate->id;
        }
      }
    }
    catch (\Mollie\Api\Exceptions\ApiException $e) {
      $this->logError('Failed to verify mandate', [
        'mollie_customer_id' => $customerId,
        'error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Create a PaymentToken record for a Mollie mandate.
   *
   * @param int $contactId
   * @param string $mandateId
   *
   * @return int
   *   The PaymentToken ID.
   */
  protected function createPaymentToken(int $contactId, string $mandateId): int {
    $result = \Civi\Api4\PaymentToken::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('payment_processor_id', $this->_paymentProcessor['id'])
      ->addValue('token', $mandateId)
      ->addValue('created_date', date('Y-m-d H:i:s'))
      ->execute()
      ->first();

    return $result['id'];
  }

  // ---------------------------------------------------------------------------
  // Lookup helpers
  // ---------------------------------------------------------------------------

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
   * Find a ContributionRecur by its Mollie subscription ID (processor_id).
   *
   * @param string $subscriptionId
   *
   * @return array|null
   */
  protected function findContributionRecurByProcessorId(string $subscriptionId): ?array {
    $results = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('*')
      ->addWhere('processor_id', '=', $subscriptionId)
      ->setLimit(1)
      ->execute();

    return $results->count() > 0 ? $results->first() : NULL;
  }

  // ---------------------------------------------------------------------------
  // Formatting & mapping helpers
  // ---------------------------------------------------------------------------

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
   * Map CiviCRM frequency settings to a Mollie interval string.
   *
   * @param string $frequencyUnit
   *   CiviCRM frequency unit: 'day', 'week', 'month', 'year'.
   * @param int $frequencyInterval
   *   Number of units between payments.
   *
   * @return string
   *   Mollie interval string (e.g. "1 months", "2 weeks").
   *
   * @throws PaymentProcessorException
   */
  protected function mapCiviCrmFrequencyToMollie(string $frequencyUnit, int $frequencyInterval): string {
    return match ($frequencyUnit) {
      'day' => "$frequencyInterval days",
      'week' => "$frequencyInterval weeks",
      'month' => "$frequencyInterval months",
      'year' => ($frequencyInterval * 12) . ' months',
      default => throw new PaymentProcessorException(
        E::ts('Unsupported recurring frequency: %1', [1 => $frequencyUnit])
      ),
    };
  }

  /**
   * Calculate the next scheduled contribution date based on frequency.
   *
   * @param array $recur
   *
   * @return string|null
   *   Next date in Y-m-d H:i:s format.
   */
  protected function calculateNextScheduledDate(array $recur): ?string {
    $currentDate = $recur['next_sched_contribution_date'] ?? NULL;
    if ($currentDate === NULL) {
      return NULL;
    }

    $interval = $recur['frequency_interval'] ?? 1;
    $unit = $recur['frequency_unit'] ?? 'month';

    $next = new \DateTime($currentDate);
    $next->modify("+{$interval} {$unit}");

    return $next->format('Y-m-d H:i:s');
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

  // ---------------------------------------------------------------------------
  // Mollie API client & credentials
  // ---------------------------------------------------------------------------

  /**
   * Initialize and return the Mollie API client.
   *
   * @return \Mollie\Api\MollieApiClient
   *
   * @throws PaymentProcessorException
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
   */
  protected function getApiKey(): string {
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

  // ---------------------------------------------------------------------------
  // Unmatched webhook handling
  // ---------------------------------------------------------------------------

  /**
   * Record an activity for a webhook that could not be matched to CiviCRM records.
   *
   * Creates a "Mollie Unmatched Payment" activity with detailed information
   * from the Mollie payment object so staff can investigate. The activity is
   * linked to the donor contact if identifiable, otherwise to the domain contact.
   *
   * @param \Mollie\Api\Resources\Payment $molliePayment
   * @param string $reason
   *   Human-readable reason why the webhook could not be matched.
   */
  protected function recordUnmatchedWebhookActivity(\Mollie\Api\Resources\Payment $molliePayment, string $reason): void {
    try {
      $contactId = $this->resolveContactFromPayment($molliePayment);
      $domainContactId = \CRM_Core_BAO_Domain::getDomain()->contact_id;

      $sourceContactId = $contactId ?? $domainContactId;
      $targetContactId = $domainContactId;

      $amount = $molliePayment->amount->value ?? NULL;
      $currency = $molliePayment->amount->currency ?? NULL;
      $amountDisplay = ($amount !== NULL && $currency !== NULL)
        ? "{$currency} {$amount}"
        : E::ts('unknown');

      $subject = E::ts('Unmatched Mollie payment: %1 (%2, %3)', [
        1 => $molliePayment->id ?? E::ts('unknown'),
        2 => $amountDisplay,
        3 => $molliePayment->status ?? E::ts('unknown'),
      ]);

      $details = $this->buildUnmatchedPaymentDetails($molliePayment, $reason, $contactId);

      $activityTypeId = $this->getUnmatchedPaymentActivityTypeId();

      $activity = \Civi\Api4\Activity::create(FALSE)
        ->addValue('activity_type_id', $activityTypeId)
        ->addValue('source_contact_id', $sourceContactId)
        ->addValue('target_contact_id', $targetContactId)
        ->addValue('activity_date_time', date('Y-m-d H:i:s'))
        ->addValue('subject', $subject)
        ->addValue('details', $details)
        ->addValue('status_id:name', 'Completed')
        ->addValue('priority_id:name', 'Urgent')
        ->execute();

      $this->logWarning('Unmatched webhook activity created', [
        'activity_id' => $activity->first()['id'] ?? NULL,
        'mollie_payment_id' => $molliePayment->id ?? NULL,
        'reason' => $reason,
      ]);
    }
    catch (\Exception $e) {
      $this->logError('Failed to create unmatched webhook activity', [
        'mollie_payment_id' => $molliePayment->id ?? NULL,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Attempt to resolve a CiviCRM contact ID from a Mollie payment.
   *
   * Tries metadata first, then falls back to MollieCustomer lookup.
   *
   * @param \Mollie\Api\Resources\Payment $molliePayment
   *
   * @return int|null
   */
  protected function resolveContactFromPayment(\Mollie\Api\Resources\Payment $molliePayment): ?int {
    // Try metadata (set during payment creation).
    $metadata = $molliePayment->metadata ?? NULL;
    if ($metadata !== NULL) {
      $contactId = $metadata->civicrm->contact_id ?? NULL;
      if ($contactId !== NULL) {
        return (int) $contactId;
      }
    }

    // Fall back to MollieCustomer lookup via Mollie customer ID.
    $customerId = $molliePayment->customerId ?? NULL;
    if ($customerId !== NULL) {
      $result = \Civi\Api4\MollieCustomer::get(FALSE)
        ->addSelect('contact_id')
        ->addWhere('mollie_customer_id', '=', $customerId)
        ->setLimit(1)
        ->execute();

      if ($result->count() > 0) {
        return (int) $result->first()['contact_id'];
      }
    }

    return NULL;
  }

  /**
   * Build HTML details for an unmatched payment activity.
   *
   * @param \Mollie\Api\Resources\Payment $molliePayment
   * @param string $reason
   * @param int|null $contactId
   *
   * @return string
   */
  protected function buildUnmatchedPaymentDetails(\Mollie\Api\Resources\Payment $molliePayment, string $reason, ?int $contactId): string {
    $paymentId = $molliePayment->id ?? NULL;
    $status = $molliePayment->status ?? NULL;
    $amount = $molliePayment->amount->value ?? NULL;
    $currency = $molliePayment->amount->currency ?? NULL;
    $method = $molliePayment->method ?? NULL;
    $customerId = $molliePayment->customerId ?? NULL;
    $subscriptionId = $molliePayment->subscriptionId ?? NULL;
    $createdAt = $molliePayment->createdAt ?? NULL;
    $paidAt = $molliePayment->paidAt ?? NULL;
    $mode = $molliePayment->mode ?? NULL;

    $metadata = $molliePayment->metadata ?? NULL;
    $metaContributionId = $metadata->civicrm->contribution_id ?? NULL;
    $metaContactId = $metadata->civicrm->contact_id ?? NULL;
    $metaRecurId = $metadata->civicrm->contribution_recur_id ?? NULL;

    $rows = [];
    $rows[] = $this->detailRow(E::ts('Reason'), '<strong>' . htmlspecialchars($reason) . '</strong>');

    // Mollie payment details.
    if ($paymentId !== NULL) {
      $paymentUrl = "https://my.mollie.com/dashboard/payments/{$paymentId}";
      $rows[] = $this->detailRow(E::ts('Payment ID'), "<a href=\"{$paymentUrl}\" target=\"_blank\">{$paymentId}</a>");
    }
    $rows[] = $this->detailRow(E::ts('Status'), $status);
    if ($amount !== NULL && $currency !== NULL) {
      $rows[] = $this->detailRow(E::ts('Amount'), "{$currency} {$amount}");
    }
    $rows[] = $this->detailRow(E::ts('Payment Method'), $method);
    $rows[] = $this->detailRow(E::ts('Mode'), $mode);

    if ($customerId !== NULL) {
      $customerUrl = "https://my.mollie.com/dashboard/customers/{$customerId}";
      $rows[] = $this->detailRow(E::ts('Mollie Customer'), "<a href=\"{$customerUrl}\" target=\"_blank\">{$customerId}</a>");
    }
    $rows[] = $this->detailRow(E::ts('Subscription ID'), $subscriptionId);
    $rows[] = $this->detailRow(E::ts('Created'), $createdAt);
    $rows[] = $this->detailRow(E::ts('Paid'), $paidAt);

    // CiviCRM metadata from the payment.
    if ($metaContributionId !== NULL || $metaContactId !== NULL || $metaRecurId !== NULL) {
      $rows[] = '<tr><td colspan="2"><strong>' . E::ts('CiviCRM Metadata (from Mollie)') . '</strong></td></tr>';
      $rows[] = $this->detailRow(E::ts('Contribution ID'), $metaContributionId);
      $rows[] = $this->detailRow(E::ts('Contact ID'), $metaContactId);
      $rows[] = $this->detailRow(E::ts('ContributionRecur ID'), $metaRecurId);
    }

    // Contact resolution.
    if ($contactId !== NULL) {
      $rows[] = $this->detailRow(E::ts('Resolved Contact'), $contactId);
    }
    else {
      $rows[] = $this->detailRow(E::ts('Resolved Contact'), '<em>' . E::ts('Could not identify contact') . '</em>');
    }

    $rowsHtml = implode("\n", array_filter($rows));

    return "<table border=\"1\" cellpadding=\"4\" cellspacing=\"0\">\n{$rowsHtml}\n</table>";
  }

  /**
   * Build a single HTML table row, skipping null values.
   *
   * @param string $label
   * @param mixed $value
   *
   * @return string|null
   */
  protected function detailRow(string $label, mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    $safeLabel = htmlspecialchars($label);
    // Value may contain intentional HTML (links, emphasis).
    return "<tr><td><strong>{$safeLabel}</strong></td><td>{$value}</td></tr>";
  }

  /**
   * Get the activity type ID for "Mollie Unmatched Payment".
   *
   * @return int
   *
   * @throws \RuntimeException
   */
  protected function getUnmatchedPaymentActivityTypeId(): int {
    $result = \Civi\Api4\OptionValue::get(FALSE)
      ->addSelect('value')
      ->addWhere('option_group_id.name', '=', 'activity_type')
      ->addWhere('name', '=', 'mollie_unmatched_payment')
      ->setLimit(1)
      ->execute();

    if ($result->count() === 0) {
      throw new \RuntimeException('Mollie Unmatched Payment activity type not found. Is the extension installed correctly?');
    }

    return (int) $result->first()['value'];
  }

  // ---------------------------------------------------------------------------
  // Logging
  // ---------------------------------------------------------------------------

  /**
   * Log an info-level message to the Mollie channel.
   *
   * @param string $message
   * @param array $context
   */
  protected function logInfo(string $message, array $context = []): void {
    \CRM_Mollie_Log::info($message, $context);
  }

  /**
   * Log a warning-level message to the Mollie channel.
   *
   * @param string $message
   * @param array $context
   */
  protected function logWarning(string $message, array $context = []): void {
    \CRM_Mollie_Log::warning($message, $context);
  }

  /**
   * Log an error-level message to the Mollie channel.
   *
   * @param string $message
   * @param array $context
   */
  protected function logError(string $message, array $context = []): void {
    \CRM_Mollie_Log::error($message, $context);
  }

  /**
   * Log a debug-level message (only when debug logging is enabled).
   *
   * @param string $message
   * @param array $context
   */
  protected function logDebug(string $message, array $context = []): void {
    \CRM_Mollie_Log::debug($message, $context);
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
