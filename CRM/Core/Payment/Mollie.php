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
      throw new PaymentProcessorException(E::ts('No Mollie subscription ID found for this recurring contribution.'));
    }

    $mollieCustomer = $this->findMollieCustomerByContactId($recur['contact_id']);
    if ($mollieCustomer === NULL) {
      throw new PaymentProcessorException(E::ts('No Mollie customer found for this contact.'));
    }

    try {
      $this->getMollieApiClient()->subscriptions->cancelForId($mollieCustomer, $recur['processor_id']);

      $this->logInfo("Mollie subscription {$recur['processor_id']} cancelled (ContributionRecur #{$recurId})", [
        'subscription_id' => $recur['processor_id'],
        'contribution_recur_id' => $recurId,
      ]);

      $message = E::ts('Mollie subscription has been cancelled.');
      return TRUE;
    }
    catch (\Mollie\Api\Exceptions\ApiException $e) {
      // 410 Gone means the subscription was already canceled or deleted on
      // Mollie. Treat as success — the desired outcome is achieved.
      if ($e->getCode() === 410) {
        $this->logInfo("Mollie subscription {$recur['processor_id']} already canceled on Mollie (ContributionRecur #{$recurId})", [
          'subscription_id' => $recur['processor_id'],
          'contribution_recur_id' => $recurId,
        ]);
        $message = E::ts('Mollie subscription was already cancelled.');
        return TRUE;
      }

      $this->logError("Failed to cancel Mollie subscription {$recur['processor_id']}: {$e->getMessage()}", [
        'subscription_id' => $recur['processor_id'],
        'error' => $e->getMessage(),
      ]);
      throw new PaymentProcessorException(E::ts('Failed to cancel the subscription on Mollie: %1', [1 => $e->getMessage()]));
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
      throw new PaymentProcessorException(E::ts('Missing required parameters for amount change.'));
    }

    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('processor_id', 'contact_id', 'currency', 'installments')
      ->addWhere('id', '=', $recurId)
      ->setLimit(1)
      ->execute()
      ->first();

    // Mollie does not support changing the number of installments on an
    // existing subscription. Block the update if installments changed.
    $newInstallments = $params['installments'] ?? NULL;
    if (($newInstallments ?? NULL) != ($recur['installments'] ?? NULL)) {
      throw new PaymentProcessorException(E::ts('The number of installments cannot be changed on a Mollie subscription. Cancel this subscription and create a new one instead.'));
    }

    if (empty($recur['processor_id'])) {
      throw new PaymentProcessorException(E::ts('No Mollie subscription ID found for this recurring contribution.'));
    }

    $mollieCustomer = $this->findMollieCustomerByContactId($recur['contact_id']);
    if ($mollieCustomer === NULL) {
      throw new PaymentProcessorException(E::ts('No Mollie customer found for this contact.'));
    }

    try {
      $this->getMollieApiClient()->subscriptions->update($mollieCustomer, $recur['processor_id'], [
        'amount' => [
          'currency' => $recur['currency'],
          'value' => number_format((float) $newAmount, 2, '.', ''),
        ],
      ]);

      $this->logInfo("Mollie subscription {$recur['processor_id']} amount updated to {$newAmount} (ContributionRecur #{$recurId})", [
        'subscription_id' => $recur['processor_id'],
        'contribution_recur_id' => $recurId,
        'new_amount' => $newAmount,
      ]);

      $message = E::ts('Subscription amount updated on Mollie.');
      return TRUE;
    }
    catch (\Mollie\Api\Exceptions\ApiException $e) {
      $this->logError("Failed to update Mollie subscription {$recur['processor_id']} amount: {$e->getMessage()}", [
        'subscription_id' => $recur['processor_id'],
        'error' => $e->getMessage(),
      ]);
      throw new PaymentProcessorException(E::ts('Failed to update subscription amount on Mollie: %1', [1 => $e->getMessage()]));
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
      $this->logError("Failed to create Mollie payment for Contribution #{$contributionId}: {$e->getMessage()}", [
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

    $this->logInfo("Mollie payment {$molliePayment->id} created for Contribution #{$contributionId} ({$paymentParams['amount']['value']} {$paymentParams['amount']['currency']})", [
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

    $this->logDebug("Webhook received for payment {$paymentId}", ['mollie_payment_id' => $paymentId]);

    try {
      $molliePayment = $this->getMollieApiClient()->payments->get($paymentId);
    }
    catch (\Mollie\Api\Exceptions\ApiException $e) {
      $this->logError("Failed to fetch Mollie payment {$paymentId} in webhook: {$e->getMessage()}", [
        'mollie_payment_id' => $paymentId,
        'error' => $e->getMessage(),
        'http_code' => $e->getCode(),
      ]);

      // Mollie docs require HTTP 200 for all webhook responses except when a
      // retry would be useful. Since Mollie itself sent us this payment ID,
      // a fetch failure is abnormal. We classify by Mollie's documented error
      // codes (see https://docs.mollie.com/reference/handling-errors):
      //
      // Transient (return 500 → Mollie retries up to 10× over 26 hours):
      //   0   - network/connection failure (SDK could not reach Mollie)
      //   429 - rate limited; retry after backoff
      //   500 - Mollie internal server error
      //   502 - Mollie bad gateway / maintenance
      //   503 - Mollie service unavailable
      //   504 - Mollie gateway timeout
      //
      // Permanent (return 200 → stop retries, requires admin intervention):
      //   401 - invalid or revoked API key
      //   403 - API key lacks access to this resource
      //   404 - payment does not exist on this API key (test/live mismatch)
      //   410 - payment was previously deleted
      //   422 - unprocessable entity
      //
      // Codes like 400, 405, 409, 415 should not occur for a simple GET-by-ID
      // but are equally permanent, so they fall through to the default (200).
      $httpCode = $e->getCode();
      $isTransient = match ($httpCode) {
        0, 429, 500, 502, 503, 504 => TRUE,
        default => FALSE,
      };
      http_response_code($isTransient ? 500 : 200);
      return;
    }

    $subscriptionId = $molliePayment->subscriptionId ?? NULL;
    $this->logDebug("Mollie payment {$paymentId} fetched: status={$molliePayment->status}" . ($subscriptionId ? " subscription={$subscriptionId}" : ''), [
      'mollie_payment_id' => $paymentId,
      'status' => $molliePayment->status,
      'subscription_id' => $subscriptionId,
    ]);

    // Post-payment events (chargebacks, refunds): Mollie re-sends the same
    // payment ID webhook when a chargeback or refund occurs. The payment
    // stays "paid" but gains chargeback/refund data. These events are
    // orthogonal to whether the payment is one-off or recurring, so we
    // handle them here before routing. Must be checked before idempotency
    // skips in the per-type handlers.
    $existingContribution = $this->findContributionByTrxnId($molliePayment->id);
    if ($existingContribution !== NULL && $molliePayment->isPaid()) {
      if ($molliePayment->hasChargebacks()) {
        $this->handleChargeback($existingContribution, $molliePayment);
        http_response_code(200);
        return;
      }
      if ($molliePayment->hasRefunds()) {
        $this->handleRefund($existingContribution, $molliePayment);
        http_response_code(200);
        return;
      }
    }

    if (!empty($molliePayment->subscriptionId)) {
      $this->processRecurringPaymentWebhook($molliePayment, $existingContribution);
    }
    else {
      $this->processOneOffOrFirstPaymentWebhook($molliePayment, $existingContribution);
    }

    http_response_code(200);
  }

  /**
   * Process a webhook for a one-off payment or the first payment of a recurring series.
   *
   * @param \Mollie\Api\Resources\Payment $molliePayment
   * @param array|null $contribution
   *   Existing contribution looked up by trxn_id, or NULL if not found.
   */
  protected function processOneOffOrFirstPaymentWebhook(\Mollie\Api\Resources\Payment $molliePayment, ?array $contribution = NULL): void {
    if ($contribution === NULL) {
      $this->logWarning("Webhook for unknown Contribution (mollie: {$molliePayment->id})", [
        'mollie_payment_id' => $molliePayment->id,
      ]);
      $this->recordUnmatchedWebhookActivity($molliePayment, E::ts(
        'No contribution found with trxn_id %1. The contribution may have been deleted or the database restored from a backup.',
        [1 => $molliePayment->id]
      ));
      return;
    }

    // Idempotency: skip if already completed.
    if ($contribution['contribution_status_id:name'] === 'Completed') {
      $this->logDebug("Contribution #{$contribution['id']} already completed, skipping (mollie: {$molliePayment->id})", [
        'mollie_payment_id' => $molliePayment->id,
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
    else {
      $this->logInfo("Webhook for Contribution #{$contribution['id']} has unhandled Mollie status '{$molliePayment->status}', no action taken (mollie: {$molliePayment->id})", [
        'mollie_payment_id' => $molliePayment->id,
        'contribution_id' => $contribution['id'],
        'mollie_status' => $molliePayment->status,
      ]);
    }
  }

  /**
   * Process a webhook for a recurring installment payment (Mollie-initiated).
   *
   * Mollie creates these payments automatically per the subscription schedule.
   * Each triggers a webhook that we use to create a CiviCRM contribution.
   *
   * @param \Mollie\Api\Resources\Payment $molliePayment
   * @param array|null $existingContribution
   *   Existing contribution looked up by trxn_id, or NULL if this is a new payment.
   */
  protected function processRecurringPaymentWebhook(\Mollie\Api\Resources\Payment $molliePayment, ?array $existingContribution = NULL): void {
    $subscriptionId = $molliePayment->subscriptionId;

    // Idempotency: skip if we already recorded this payment.
    if ($existingContribution !== NULL) {
      $this->logDebug("Recurring payment {$molliePayment->id} already recorded as Contribution #{$existingContribution['id']}, skipping", [
        'mollie_payment_id' => $molliePayment->id,
        'contribution_id' => $existingContribution['id'],
      ]);
      return;
    }

    $contributionRecur = $this->findContributionRecurByProcessorId($subscriptionId);
    if ($contributionRecur === NULL) {
      $this->logWarning("Webhook for unknown subscription {$subscriptionId} (mollie: {$molliePayment->id})", [
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
    else {
      $this->logInfo("Recurring webhook for ContributionRecur #{$contributionRecur['id']} has unhandled Mollie status '{$molliePayment->status}', no action taken (mollie: {$molliePayment->id})", [
        'mollie_payment_id' => $molliePayment->id,
        'contribution_recur_id' => $contributionRecur['id'],
        'subscription_id' => $subscriptionId,
        'mollie_status' => $molliePayment->status,
      ]);
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

      $method = $molliePayment->method ?? 'unknown';
      $this->logInfo("Contribution #{$contribution['id']} completed via {$method} (mollie: {$molliePayment->id})", [
        'contribution_id' => $contribution['id'],
        'mollie_payment_id' => $molliePayment->id,
        'payment_method' => $method,
      ]);
    }
    catch (\Exception $e) {
      $this->logError("Failed to complete Contribution #{$contribution['id']} (mollie: {$molliePayment->id}): {$e->getMessage()}", [
        'mollie_payment_id' => $molliePayment->id,
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

      $this->logInfo("Contribution #{$contribution['id']} marked as {$statusName} (mollie: {$molliePayment->id})", [
        'contribution_id' => $contribution['id'],
        'mollie_payment_id' => $molliePayment->id,
        'mollie_status' => $molliePayment->status,
      ]);
    }
    catch (\Exception $e) {
      $this->logError("Failed to update Contribution #{$contribution['id']} status (mollie: {$molliePayment->id}): {$e->getMessage()}", [
        'mollie_payment_id' => $molliePayment->id,
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

      $this->logInfo("ContributionRecur #{$recurId} marked as {$statusName} (mollie: {$molliePayment->id})", [
        'contribution_recur_id' => $recurId,
        'mollie_payment_id' => $molliePayment->id,
        'reason' => 'first payment or subscription setup failed',
      ]);
    }
    catch (\Exception $e) {
      $this->logError("Failed to update ContributionRecur #{$recurId} status (mollie: {$molliePayment->id}): {$e->getMessage()}", [
        'mollie_payment_id' => $molliePayment->id,
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
   * Handle chargebacks on a payment.
   *
   * Mollie re-sends the payment webhook when a chargeback is filed. The
   * payment stays "paid" but gains chargeback data. Each chargeback is
   * recorded as a negative payment via Payment.create for proper financial
   * bookkeeping. CiviCRM handles partial vs full status transitions
   * automatically, then we override to Chargeback status. A Note is
   * created for each new chargeback for staff reference.
   *
   * Idempotency is per-chargeback: each chargeback's Mollie ID is used
   * as trxn_id on the FinancialTrxn, so duplicates are skipped.
   *
   * @param array $contribution
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function handleChargeback(array $contribution, \Mollie\Api\Resources\Payment $molliePayment): void {
    try {
      $chargebacks = $molliePayment->chargebacks();
    }
    catch (\Exception $e) {
      $this->logError("Failed to fetch chargebacks for Contribution #{$contribution['id']} (mollie: {$molliePayment->id}): {$e->getMessage()}", [
        'mollie_payment_id' => $molliePayment->id,
        'contribution_id' => $contribution['id'],
        'error' => $e->getMessage(),
      ]);
      return;
    }

    $recorded = 0;
    foreach ($chargebacks as $chargeback) {
      if ($this->financialTrxnExists($chargeback->id)) {
        continue;
      }

      try {
        $amount = $chargeback->amount->value;
        $currency = $chargeback->amount->currency ?? 'EUR';

        civicrm_api3('Payment', 'create', [
          'contribution_id' => $contribution['id'],
          'total_amount' => -$amount,
          'trxn_id' => $chargeback->id,
          'trxn_date' => $chargeback->createdAt ?? date('Y-m-d H:i:s'),
          'payment_processor_id' => $this->_paymentProcessor['id'],
        ]);

        $this->recordChargebackNote($contribution, $molliePayment, $chargeback);
        $recorded++;

        $this->logWarning("Chargeback {$chargeback->id} recorded on Contribution #{$contribution['id']}: -{$amount} {$currency} (mollie: {$molliePayment->id})", [
          'contribution_id' => $contribution['id'],
          'mollie_payment_id' => $molliePayment->id,
          'chargeback_id' => $chargeback->id,
          'chargeback_amount' => $amount,
        ]);
      }
      catch (\Exception $e) {
        $this->logError("Failed to record chargeback {$chargeback->id} on Contribution #{$contribution['id']} (mollie: {$molliePayment->id}): {$e->getMessage()}", [
          'mollie_payment_id' => $molliePayment->id,
          'contribution_id' => $contribution['id'],
          'chargeback_id' => $chargeback->id,
          'error' => $e->getMessage(),
        ]);
      }
    }

    // Payment.create sets status to Refunded/Partially paid, but chargebacks
    // are bank-initiated disputes — override to Chargeback for correct
    // business semantics.
    if ($recorded > 0) {
      try {
        \Civi\Api4\Contribution::update(FALSE)
          ->addWhere('id', '=', $contribution['id'])
          ->addValue('contribution_status_id:name', 'Chargeback')
          ->execute();
      }
      catch (\Exception $e) {
        $this->logError("Failed to set Chargeback status on Contribution #{$contribution['id']}: {$e->getMessage()}", [
          'contribution_id' => $contribution['id'],
          'error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Attach a Note to the contribution with details about a single chargeback.
   *
   * @param array $contribution
   * @param \Mollie\Api\Resources\Payment $molliePayment
   * @param \Mollie\Api\Resources\Chargeback $chargeback
   */
  protected function recordChargebackNote(
    array $contribution,
    \Mollie\Api\Resources\Payment $molliePayment,
    \Mollie\Api\Resources\Chargeback $chargeback,
  ): void {
    $currency = $chargeback->amount->currency ?? 'EUR';
    $lines = [
      E::ts('A chargeback was received on this contribution.'),
      '',
      E::ts('Chargeback ID: %1', [1 => $chargeback->id]),
      E::ts('Amount: %1 %2', [1 => $chargeback->amount->value, 2 => $currency]),
      E::ts('Date: %1', [1 => $chargeback->createdAt ?? 'unknown']),
      E::ts('Mollie payment ID: %1', [1 => $molliePayment->id]),
      E::ts('Original payment amount: %1 %2', [1 => $molliePayment->amount->value, 2 => $currency]),
    ];

    if ($molliePayment->amountChargedBack !== NULL) {
      $lines[] = E::ts('Total charged back: %1 %2', [1 => $molliePayment->amountChargedBack->value, 2 => $currency]);
    }

    if ($chargeback->reason !== NULL) {
      $reasonCode = $chargeback->reason->code ?? '';
      $reasonDesc = $chargeback->reason->description ?? '';
      $lines[] = E::ts('Reason: %1 — %2', [1 => $reasonCode, 2 => $reasonDesc]);
    }

    if ($chargeback->reversedAt !== NULL) {
      $lines[] = E::ts('Reversed at: %1', [1 => $chargeback->reversedAt]);
    }

    try {
      \Civi\Api4\Note::create(FALSE)
        ->addValue('entity_table', 'civicrm_contribution')
        ->addValue('entity_id', $contribution['id'])
        ->addValue('subject', E::ts('Mollie Chargeback: %1', [1 => $chargeback->id]))
        ->addValue('note', implode("\n", $lines))
        ->addValue('contact_id', $contribution['contact_id'])
        ->execute();
    }
    catch (\Exception $e) {
      $this->logError("Failed to create chargeback note for Contribution #{$contribution['id']} ({$chargeback->id}): {$e->getMessage()}", [
        'contribution_id' => $contribution['id'],
        'chargeback_id' => $chargeback->id,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Handle refunds on a payment.
   *
   * Mollie re-sends the payment webhook when a refund reaches processing,
   * refunded, or failed. The payment stays "paid" but gains refund data.
   * Each refund is recorded as a negative payment via Payment.create for
   * proper financial bookkeeping. CiviCRM automatically handles the
   * contribution status transition: full refund → Refunded, partial →
   * Partially paid.
   *
   * Idempotency is per-refund: each refund's Mollie ID is used as trxn_id
   * on the FinancialTrxn, so duplicates are skipped.
   *
   * @param array $contribution
   * @param \Mollie\Api\Resources\Payment $molliePayment
   */
  protected function handleRefund(array $contribution, \Mollie\Api\Resources\Payment $molliePayment): void {
    try {
      $refunds = $molliePayment->refunds();
    }
    catch (\Exception $e) {
      $this->logError("Failed to fetch refunds for Contribution #{$contribution['id']} (mollie: {$molliePayment->id}): {$e->getMessage()}", [
        'mollie_payment_id' => $molliePayment->id,
        'contribution_id' => $contribution['id'],
        'error' => $e->getMessage(),
      ]);
      return;
    }

    foreach ($refunds as $refund) {
      // Only record refunds that have actually moved money.
      if (!in_array($refund->status, ['processing', 'refunded'], TRUE)) {
        continue;
      }

      if ($this->financialTrxnExists($refund->id)) {
        continue;
      }

      try {
        $amount = $refund->amount->value;
        $currency = $refund->amount->currency ?? 'EUR';

        civicrm_api3('Payment', 'create', [
          'contribution_id' => $contribution['id'],
          'total_amount' => -$amount,
          'trxn_id' => $refund->id,
          'trxn_date' => $refund->createdAt ?? date('Y-m-d H:i:s'),
          'payment_processor_id' => $this->_paymentProcessor['id'],
        ]);

        $this->recordRefundNote($contribution, $molliePayment, $refund);

        $this->logWarning("Refund {$refund->id} recorded on Contribution #{$contribution['id']}: -{$amount} {$currency} (mollie: {$molliePayment->id})", [
          'contribution_id' => $contribution['id'],
          'mollie_payment_id' => $molliePayment->id,
          'refund_id' => $refund->id,
          'refund_amount' => $amount,
        ]);
      }
      catch (\Exception $e) {
        $this->logError("Failed to record refund {$refund->id} on Contribution #{$contribution['id']} (mollie: {$molliePayment->id}): {$e->getMessage()}", [
          'mollie_payment_id' => $molliePayment->id,
          'contribution_id' => $contribution['id'],
          'refund_id' => $refund->id,
          'error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Attach a Note to the contribution with details about a single refund.
   *
   * @param array $contribution
   * @param \Mollie\Api\Resources\Payment $molliePayment
   * @param \Mollie\Api\Resources\Refund $refund
   */
  protected function recordRefundNote(
    array $contribution,
    \Mollie\Api\Resources\Payment $molliePayment,
    \Mollie\Api\Resources\Refund $refund,
  ): void {
    $currency = $refund->amount->currency ?? 'EUR';
    $lines = [
      E::ts('A refund was issued on this contribution.'),
      '',
      E::ts('Refund ID: %1', [1 => $refund->id]),
      E::ts('Amount: %1 %2', [1 => $refund->amount->value, 2 => $currency]),
      E::ts('Status: %1', [1 => $refund->status]),
      E::ts('Date: %1', [1 => $refund->createdAt ?? 'unknown']),
      E::ts('Mollie payment ID: %1', [1 => $molliePayment->id]),
      E::ts('Original payment amount: %1 %2', [1 => $molliePayment->amount->value, 2 => $currency]),
    ];

    if ($molliePayment->amountRefunded !== NULL) {
      $lines[] = E::ts('Total refunded: %1 %2', [1 => $molliePayment->amountRefunded->value, 2 => $currency]);
    }
    if ($molliePayment->amountRemaining !== NULL) {
      $lines[] = E::ts('Remaining: %1 %2', [1 => $molliePayment->amountRemaining->value, 2 => $currency]);
    }

    if ($refund->description !== NULL) {
      $lines[] = E::ts('Description: %1', [1 => $refund->description]);
    }

    try {
      \Civi\Api4\Note::create(FALSE)
        ->addValue('entity_table', 'civicrm_contribution')
        ->addValue('entity_id', $contribution['id'])
        ->addValue('subject', E::ts('Mollie Refund: %1', [1 => $refund->id]))
        ->addValue('note', implode("\n", $lines))
        ->addValue('contact_id', $contribution['contact_id'])
        ->execute();
    }
    catch (\Exception $e) {
      $this->logError("Failed to create refund note for Contribution #{$contribution['id']} ({$refund->id}): {$e->getMessage()}", [
        'contribution_id' => $contribution['id'],
        'refund_id' => $refund->id,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Check if a FinancialTrxn with the given trxn_id already exists.
   *
   * Used for idempotency when recording refunds and chargebacks — each
   * Mollie refund/chargeback ID is stored as the trxn_id on the
   * FinancialTrxn created by Payment.create.
   *
   * @param string $trxnId
   *
   * @return bool
   */
  protected function financialTrxnExists(string $trxnId): bool {
    $result = \Civi\Api4\FinancialTrxn::get(FALSE)
      ->addSelect('id')
      ->addWhere('trxn_id', '=', $trxnId)
      ->setLimit(1)
      ->execute();

    return $result->count() > 0;
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
      // The payment object carries the mandateId that Mollie created from
      // this first payment. Using it directly is more precise than listing
      // all mandates (which could return a different one) and saves an API call.
      $mandateId = $molliePayment->mandateId;
      if ($mandateId === NULL) {
        $this->logError("No mandate ID on first recurring payment for customer {$customerId} (mollie: {$molliePayment->id}, Contribution #{$contribution['id']})", [
          'mollie_payment_id' => $molliePayment->id,
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

        $this->logInfo("Single-installment ContributionRecur #{$recurId} completed, no subscription needed (mollie: {$molliePayment->id}, customer: {$customerId})", [
          'mollie_payment_id' => $molliePayment->id,
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

      $this->logInfo("Mollie subscription {$subscriptionId} created for ContributionRecur #{$recurId} (customer: {$customerId}, mandate: {$mandateId})", [
        'subscription_id' => $subscriptionId,
        'contribution_recur_id' => $recurId,
        'mollie_customer_id' => $customerId,
        'mandate_id' => $mandateId,
      ]);
    }
    catch (\Exception $e) {
      $this->logError("Failed to set up recurring subscription for ContributionRecur #{$recurId} after first payment (mollie: {$molliePayment->id}): {$e->getMessage()}", [
        'mollie_payment_id' => $molliePayment->id,
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

    $subscriptionParams['startDate'] = $this->computeSubscriptionStartDate($recur);

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

      $amount = $molliePayment->amount->value;
      $this->logInfo("Recurring installment recorded for ContributionRecur #{$contributionRecur['id']}: {$amount} (mollie: {$molliePayment->id})", [
        'contribution_recur_id' => $contributionRecur['id'],
        'mollie_payment_id' => $molliePayment->id,
        'amount' => $amount,
      ]);
    }
    catch (\Exception $e) {
      $this->logError("Failed to record recurring installment for ContributionRecur #{$contributionRecur['id']} (mollie: {$molliePayment->id}): {$e->getMessage()}", [
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

      $this->logWarning("Recurring installment failed for ContributionRecur #{$contributionRecur['id']} (mollie: {$molliePayment->id})", [
        'contribution_recur_id' => $contributionRecur['id'],
        'mollie_payment_id' => $molliePayment->id,
      ]);
    }
    catch (\Exception $e) {
      $this->logError("Failed to record failed recurring installment for ContributionRecur #{$contributionRecur['id']} (mollie: {$molliePayment->id}): {$e->getMessage()}", [
        'mollie_payment_id' => $molliePayment->id,
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
      $this->logError("Failed to create Mollie customer for Contact #{$contactId}: {$e->getMessage()}", [
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

    $this->logInfo("Mollie customer {$mollieCustomer->id} created for Contact #{$contactId}", [
      'mollie_customer_id' => $mollieCustomer->id,
      'contact_id' => $contactId,
    ]);

    return $mollieCustomer->id;
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
   * Compute the subscription start date from a ContributionRecur.
   *
   * Uses the scheduled next contribution date if available, otherwise
   * falls back to today + one billing interval to avoid charging on the
   * same day as the first payment.
   *
   * @param array $recur
   *
   * @return string
   *   Start date in Y-m-d format.
   */
  protected function computeSubscriptionStartDate(array $recur): string {
    if (!empty($recur['next_sched_contribution_date'])) {
      return date('Y-m-d', strtotime($recur['next_sched_contribution_date']));
    }

    $interval = $recur['frequency_interval'] ?? 1;
    $unit = $recur['frequency_unit'] ?? 'month';

    return date('Y-m-d', strtotime("+{$interval} {$unit}"));
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
      $this->logError("Failed to initialize Mollie API client: {$e->getMessage()}", [
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

      $activityId = $activity->first()['id'] ?? NULL;
      $mollieId = $molliePayment->id ?? 'unknown';
      $this->logWarning("Unmatched webhook Activity #{$activityId} created for payment {$mollieId}", [
        'activity_id' => $activityId,
        'mollie_payment_id' => $mollieId,
        'reason' => $reason,
      ]);
    }
    catch (\Exception $e) {
      $mollieId = $molliePayment->id ?? 'unknown';
      $this->logError("Failed to create unmatched webhook activity for payment {$mollieId}: {$e->getMessage()}", [
        'mollie_payment_id' => $mollieId,
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
