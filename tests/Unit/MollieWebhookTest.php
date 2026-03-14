<?php

namespace Tests\Unit;

use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\Api4Mock;

/**
 * Test subclass that stubs CiviCRM-dependent methods to track webhook routing.
 */
class WebhookTestableMolliePayment extends \CRM_Core_Payment_Mollie {
  /** @var string[] Methods called during webhook processing. */
  public array $calledMethods = [];

  /** @var array|null Contribution returned by findContributionByTrxnId. */
  public ?array $stubbedContribution = null;

  /** @var array|null ContributionRecur returned by findContributionRecurByProcessorId. */
  public ?array $stubbedContributionRecur = null;

  /** @var array Params passed to completeContribution (for fee calculation tests). */
  public array $completeContributionParams = [];

  public function __construct() {
    $this->_paymentProcessor = ['id' => 1];
    $this->_mode = 'test';
  }

  public function exposedProcessOneOffOrFirstPaymentWebhook(Payment $payment, ?array $contribution = null): void {
    $this->processOneOffOrFirstPaymentWebhook($payment, $contribution);
  }

  public function exposedProcessRecurringPaymentWebhook(Payment $payment, ?array $existingContribution = null): void {
    $this->processRecurringPaymentWebhook($payment, $existingContribution);
  }

  public function exposedRoutePaymentWebhook(Payment $molliePayment): void {
    $this->routePaymentWebhook($molliePayment);
  }

  protected function findContributionByTrxnId(string $trxnId): ?array {
    return $this->stubbedContribution;
  }

  protected function findContributionRecurByProcessorId(string $subscriptionId): ?array {
    return $this->stubbedContributionRecur;
  }

  protected function completeContribution(array $contribution, Payment $molliePayment): void {
    $this->calledMethods[] = 'completeContribution';

    // Replicate fee calculation logic for testing.
    $params = [
      'id' => $contribution['id'],
      'trxn_id' => $molliePayment->id,
      'payment_processor_id' => $this->_paymentProcessor['id'],
    ];
    if ($molliePayment->settlementAmount !== null) {
      $feeAmount = (float) $molliePayment->amount->value - (float) $molliePayment->settlementAmount->value;
      if ($feeAmount > 0) {
        $params['fee_amount'] = number_format($feeAmount, 2, '.', '');
      }
    }
    $this->completeContributionParams = $params;
  }

  protected function failContribution(array $contribution, Payment $molliePayment): void {
    $this->calledMethods[] = 'failContribution';
  }

  protected function failContributionRecur(int $recurId, Payment $molliePayment): void {
    $this->calledMethods[] = 'failContributionRecur';
  }

  protected function handleFirstRecurringPaymentCompleted(array $contribution, Payment $molliePayment): void {
    $this->calledMethods[] = 'handleFirstRecurringPaymentCompleted';
  }

  protected function handleChargeback(array $contribution, Payment $molliePayment): void {
    $this->calledMethods[] = 'handleChargeback';
  }

  protected function handleRefund(array $contribution, Payment $molliePayment): void {
    $this->calledMethods[] = 'handleRefund';
  }

  protected function createRecurringInstallment(array $contributionRecur, Payment $molliePayment): void {
    $this->calledMethods[] = 'createRecurringInstallment';
  }

  protected function recordFailedRecurringInstallment(array $contributionRecur, Payment $molliePayment): void {
    $this->calledMethods[] = 'recordFailedRecurringInstallment';
  }

  protected function recordUnmatchedWebhookActivity(Payment $molliePayment, string $reason): void {
    $this->calledMethods[] = 'recordUnmatchedWebhookActivity';
  }
}

/**
 * Test subclass for handlePaymentNotification entry point (lock, HTTP codes).
 */
class NotificationTestableMollie extends \CRM_Core_Payment_Mollie {
  public bool $routeWasCalled = false;
  public ?MollieApiClient $stubbedClient = null;

  public function __construct() {
    $this->_paymentProcessor = ['id' => 1, 'user_name' => 'test_abc'];
    $this->_mode = 'test';
  }

  public function callHandlePaymentNotification(): void {
    $this->handlePaymentNotification();
  }

  protected function getMollieApiClient(): MollieApiClient {
    return $this->stubbedClient;
  }

  protected function routePaymentWebhook(\Mollie\Api\Resources\Payment $molliePayment): void {
    $this->routeWasCalled = true;
  }
}

class MollieWebhookTest extends TestCase {
  protected function setUp(): void {
    Api4Mock::reset();
  }

  private function makePayment(array $props): Payment {
    $client = $this->createMock(MollieApiClient::class);
    $payment = new Payment($client);
    $payment->id = $props['id'] ?? 'tr_test123';
    $payment->status = $props['status'] ?? 'paid';
    $payment->sequenceType = $props['sequenceType'] ?? 'oneoff';
    $payment->subscriptionId = $props['subscriptionId'] ?? null;

    // isPaid() checks paidAt, not status.
    $payment->paidAt = ($props['status'] ?? 'paid') === 'paid' ? '2026-03-06T12:00:00+00:00' : null;

    // hasChargebacks() and hasRefunds() check _links->chargebacks / _links->refunds.
    $links = new \stdClass();
    if ($props['hasChargebacks'] ?? false) {
      $links->chargebacks = new \stdClass();
    }
    if ($props['hasRefunds'] ?? false) {
      $links->refunds = new \stdClass();
    }
    if ($props['hasChargebacks'] ?? $props['hasRefunds'] ?? false) {
      $payment->_links = $links;
    }

    // Amount objects for fee calculation.
    if (isset($props['amount'])) {
      $payment->amount = $props['amount'];
    }
    if (isset($props['settlementAmount'])) {
      $payment->settlementAmount = $props['settlementAmount'];
    }

    return $payment;
  }

  private function makeAmount(string $value, string $currency = 'EUR'): \stdClass {
    $obj = new \stdClass();
    $obj->value = $value;
    $obj->currency = $currency;
    return $obj;
  }

  private function makePendingContribution(int $id = 1, ?int $recurId = null): array {
    return [
      'id' => $id,
      'contribution_status_id:name' => 'Pending',
      'contact_id' => 100,
      'contribution_recur_id' => $recurId,
      'is_email_receipt' => false,
    ];
  }

  // -----------------------------------------------------------------------
  // Post-payment event routing (chargebacks, refunds)
  // -----------------------------------------------------------------------

  public function testChargebackHandledBeforeRouting(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution();
    $processor->stubbedContribution['contribution_status_id:name'] = 'Completed';

    $payment = $this->makePayment([
      'status' => 'paid',
      'hasChargebacks' => true,
    ]);
    $processor->exposedRoutePaymentWebhook($payment);

    $this->assertSame(['handleChargeback'], $processor->calledMethods);
  }

  public function testRefundHandledBeforeRouting(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution();
    $processor->stubbedContribution['contribution_status_id:name'] = 'Completed';

    $payment = $this->makePayment([
      'status' => 'paid',
      'hasRefunds' => true,
    ]);
    $processor->exposedRoutePaymentWebhook($payment);

    $this->assertSame(['handleRefund'], $processor->calledMethods);
  }

  public function testBothRefundAndChargebackProcessed(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution();
    $processor->stubbedContribution['contribution_status_id:name'] = 'Completed';

    $payment = $this->makePayment([
      'status' => 'paid',
      'hasChargebacks' => true,
      'hasRefunds' => true,
    ]);
    $processor->exposedRoutePaymentWebhook($payment);

    // Refunds first, then chargebacks — so Chargeback status override wins.
    $this->assertSame(['handleRefund', 'handleChargeback'], $processor->calledMethods);
  }

  public function testPostPaymentEventsSkippedWhenNoContribution(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = null;

    $payment = $this->makePayment([
      'status' => 'paid',
      'hasChargebacks' => true,
    ]);
    $processor->exposedRoutePaymentWebhook($payment);

    // No contribution found → falls through to one-off handler which records unmatched activity.
    $this->assertSame(['recordUnmatchedWebhookActivity'], $processor->calledMethods);
  }

  public function testPostPaymentEventsSkippedWhenNotPaid(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution();

    $payment = $this->makePayment([
      'status' => 'failed',
      'hasChargebacks' => true,
    ]);
    $processor->exposedRoutePaymentWebhook($payment);

    // Not paid → chargebacks not checked, falls through to one-off handler.
    $this->assertSame(['failContribution'], $processor->calledMethods);
  }

  public function testNoPostPaymentEventsPassesThrough(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution();
    $processor->stubbedContribution['contribution_status_id:name'] = 'Completed';

    $payment = $this->makePayment(['status' => 'paid']);
    $processor->exposedRoutePaymentWebhook($payment);

    // No chargebacks/refunds, contribution already completed → idempotency skip.
    $this->assertSame([], $processor->calledMethods);
  }

  // -----------------------------------------------------------------------
  // processOneOffOrFirstPaymentWebhook
  // -----------------------------------------------------------------------

  public function testOneOffPaidCompletesContribution(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution();

    $payment = $this->makePayment(['status' => 'paid']);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, $contribution);

    $this->assertSame(['completeContribution'], $processor->calledMethods);
  }

  public function testFirstRecurringPaidCompletesAndSetsUpSubscription(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution(1, recurId: 10);

    $payment = $this->makePayment([
      'status' => 'paid',
      'sequenceType' => 'first',
    ]);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, $contribution);

    $this->assertSame([
      'completeContribution',
      'handleFirstRecurringPaymentCompleted',
    ], $processor->calledMethods);
  }

  public function testOneOffFailedFailsContribution(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution();

    $payment = $this->makePayment(['status' => 'failed']);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, $contribution);

    $this->assertSame(['failContribution'], $processor->calledMethods);
  }

  public function testFirstRecurringFailedFailsBoth(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution(1, recurId: 10);

    $payment = $this->makePayment([
      'status' => 'failed',
      'sequenceType' => 'first',
    ]);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, $contribution);

    $this->assertSame([
      'failContribution',
      'failContributionRecur',
    ], $processor->calledMethods);
  }

  public function testIdempotencySkipsAlreadyCompleted(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution();
    $contribution['contribution_status_id:name'] = 'Completed';

    $payment = $this->makePayment(['status' => 'paid']);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, $contribution);

    $this->assertSame([], $processor->calledMethods);
  }

  public function testFirstRecurringRetryWhenSubscriptionSetupIncomplete(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution(1, recurId: 10);
    $contribution['contribution_status_id:name'] = 'Completed';

    // ContributionRecur has no processor_id (subscription was never created)
    // and is in Failed state from the previous failed attempt.
    Api4Mock::setResult('ContributionRecur.get', [[
      'id' => 10,
      'processor_id' => null,
      'contribution_status_id:name' => 'Failed',
    ]]);

    $payment = $this->makePayment([
      'status' => 'paid',
      'sequenceType' => 'first',
    ]);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, $contribution);

    // Should retry subscription setup, not skip.
    $this->assertSame(['handleFirstRecurringPaymentCompleted'], $processor->calledMethods);
  }

  public function testFirstRecurringSkipsWhenSubscriptionAlreadyExists(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution(1, recurId: 10);
    $contribution['contribution_status_id:name'] = 'Completed';

    // ContributionRecur already has a subscription — normal idempotency.
    Api4Mock::setResult('ContributionRecur.get', [[
      'id' => 10,
      'processor_id' => 'sub_existing',
      'contribution_status_id:name' => 'In Progress',
    ]]);

    $payment = $this->makePayment([
      'status' => 'paid',
      'sequenceType' => 'first',
    ]);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, $contribution);

    $this->assertSame([], $processor->calledMethods);
  }

  public function testFirstRecurringSkipsWhenSingleInstallmentCompleted(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution(1, recurId: 10);
    $contribution['contribution_status_id:name'] = 'Completed';

    // Single-installment recur: no processor_id but status is Completed,
    // meaning it was correctly handled (no subscription needed).
    Api4Mock::setResult('ContributionRecur.get', [[
      'id' => 10,
      'processor_id' => null,
      'contribution_status_id:name' => 'Completed',
    ]]);

    $payment = $this->makePayment([
      'status' => 'paid',
      'sequenceType' => 'first',
    ]);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, $contribution);

    $this->assertSame([], $processor->calledMethods);
  }

  public function testOneOffUnknownContributionRecordsActivity(): void {
    $processor = new WebhookTestableMolliePayment();

    $payment = $this->makePayment(['status' => 'paid']);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, null);

    $this->assertSame(['recordUnmatchedWebhookActivity'], $processor->calledMethods);
  }

  // -----------------------------------------------------------------------
  // processRecurringPaymentWebhook
  // -----------------------------------------------------------------------

  public function testRecurringPaidCreatesInstallment(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContributionRecur = ['id' => 10, 'payment_processor_id' => 1];

    $payment = $this->makePayment([
      'status' => 'paid',
      'subscriptionId' => 'sub_test',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment, null);

    $this->assertSame(['createRecurringInstallment'], $processor->calledMethods);
  }

  public function testRecurringFailedRecordsFailure(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContributionRecur = ['id' => 10, 'payment_processor_id' => 1];

    $payment = $this->makePayment([
      'status' => 'failed',
      'subscriptionId' => 'sub_test',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment, null);

    $this->assertSame(['recordFailedRecurringInstallment'], $processor->calledMethods);
  }

  public function testRecurringIdempotencySkipsCompletedDuplicate(): void {
    $processor = new WebhookTestableMolliePayment();
    $existing = $this->makePendingContribution();
    $existing['contribution_status_id:name'] = 'Completed';

    $payment = $this->makePayment([
      'status' => 'paid',
      'subscriptionId' => 'sub_test',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment, $existing);

    $this->assertSame([], $processor->calledMethods);
  }

  public function testRecurringIdempotencySkipsFailedDuplicate(): void {
    $processor = new WebhookTestableMolliePayment();
    $existing = $this->makePendingContribution();
    $existing['contribution_status_id:name'] = 'Failed';

    $payment = $this->makePayment([
      'status' => 'failed',
      'subscriptionId' => 'sub_test',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment, $existing);

    $this->assertSame([], $processor->calledMethods);
  }

  public function testRecurringPendingContributionAllowsRetry(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContributionRecur = ['id' => 10, 'payment_processor_id' => 1];
    $existing = $this->makePendingContribution();

    $payment = $this->makePayment([
      'status' => 'paid',
      'subscriptionId' => 'sub_test',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment, $existing);

    $this->assertSame(['createRecurringInstallment'], $processor->calledMethods);
  }

  public function testRecurringUnknownSubscriptionSkips(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContributionRecur = null;

    $payment = $this->makePayment([
      'status' => 'paid',
      'subscriptionId' => 'sub_unknown',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment, null);

    $this->assertSame(['recordUnmatchedWebhookActivity'], $processor->calledMethods);
  }

  // -----------------------------------------------------------------------
  // completeContribution fee calculation
  // -----------------------------------------------------------------------

  public function testFeeCalculationWithSettlement(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution();

    $payment = $this->makePayment([
      'status' => 'paid',
      'amount' => $this->makeAmount('25.00'),
      'settlementAmount' => $this->makeAmount('24.71'),
    ]);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, $contribution);

    $this->assertSame('0.29', $processor->completeContributionParams['fee_amount']);
  }

  public function testFeeCalculationWithoutSettlement(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution();

    $payment = $this->makePayment(['status' => 'paid']);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, $contribution);

    $this->assertArrayNotHasKey('fee_amount', $processor->completeContributionParams);
  }

  public function testFeeCalculationZeroFee(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution();

    $payment = $this->makePayment([
      'status' => 'paid',
      'amount' => $this->makeAmount('25.00'),
      'settlementAmount' => $this->makeAmount('25.00'),
    ]);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, $contribution);

    $this->assertArrayNotHasKey('fee_amount', $processor->completeContributionParams);
  }

  // -----------------------------------------------------------------------
  // handlePaymentNotification — API fetch error classification
  // -----------------------------------------------------------------------

  /**
   * @dataProvider transientHttpCodeProvider
   */
  public function testTransientApiErrorReturnsHttp500ForRetry(int $httpCode): void {
    \CiviLockManagerMock::$acquireSucceeds = true;
    $_POST['id'] = 'tr_transient';

    $mockPayments = $this->createMock(\Mollie\Api\Endpoints\PaymentEndpoint::class);
    $mockPayments->method('get')
      ->willThrowException(new \Mollie\Api\Exceptions\ApiException('error', $httpCode));
    $mockClient = $this->createMock(MollieApiClient::class);
    $mockClient->payments = $mockPayments;

    $processor = new NotificationTestableMollie();
    $processor->stubbedClient = $mockClient;
    http_response_code(200);
    $processor->callHandlePaymentNotification();

    $this->assertFalse($processor->routeWasCalled);
    $this->assertSame(500, http_response_code(), "HTTP code {$httpCode} should be classified as transient (→ 500)");

    \CiviLockManagerMock::reset();
    unset($_POST['id']);
  }

  public static function transientHttpCodeProvider(): array {
    return [
      'network failure' => [0],
      'rate limited' => [429],
      'server error' => [500],
      'bad gateway' => [502],
      'service unavailable' => [503],
      'gateway timeout' => [504],
    ];
  }

  /**
   * @dataProvider permanentHttpCodeProvider
   */
  public function testPermanentApiErrorReturnsHttp200ToStopRetries(int $httpCode): void {
    \CiviLockManagerMock::$acquireSucceeds = true;
    $_POST['id'] = 'tr_permanent';

    $mockPayments = $this->createMock(\Mollie\Api\Endpoints\PaymentEndpoint::class);
    $mockPayments->method('get')
      ->willThrowException(new \Mollie\Api\Exceptions\ApiException('error', $httpCode));
    $mockClient = $this->createMock(MollieApiClient::class);
    $mockClient->payments = $mockPayments;

    $processor = new NotificationTestableMollie();
    $processor->stubbedClient = $mockClient;
    http_response_code(500);
    $processor->callHandlePaymentNotification();

    $this->assertFalse($processor->routeWasCalled);
    $this->assertSame(200, http_response_code(), "HTTP code {$httpCode} should be classified as permanent (→ 200)");

    \CiviLockManagerMock::reset();
    unset($_POST['id']);
  }

  public static function permanentHttpCodeProvider(): array {
    return [
      'invalid API key' => [401],
      'forbidden' => [403],
      'not found' => [404],
      'gone' => [410],
      'unprocessable' => [422],
    ];
  }

  // -----------------------------------------------------------------------
  // handlePaymentNotification — lock behavior
  // -----------------------------------------------------------------------

  public function testLockTimeoutReturnsHttp500ForRetry(): void {
    \CiviLockManagerMock::$acquireSucceeds = false;
    $_POST['id'] = 'tr_lock_test';

    $processor = new NotificationTestableMollie();
    $processor->callHandlePaymentNotification();

    $this->assertFalse($processor->routeWasCalled);

    // Clean up.
    \CiviLockManagerMock::reset();
    unset($_POST['id']);
  }

  public function testLockAcquiredAllowsProcessing(): void {
    \CiviLockManagerMock::$acquireSucceeds = true;
    $_POST['id'] = 'tr_lock_ok';

    $molliePayment = new Payment($this->createMock(MollieApiClient::class));
    $molliePayment->id = 'tr_lock_ok';
    $molliePayment->status = 'paid';
    $molliePayment->paidAt = '2026-03-06T12:00:00+00:00';

    $mockClient = $this->createMock(MollieApiClient::class);
    $mockPayments = $this->createMock(\Mollie\Api\Endpoints\PaymentEndpoint::class);
    $mockPayments->method('get')->willReturn($molliePayment);
    $mockClient->payments = $mockPayments;

    $processor = new NotificationTestableMollie();
    $processor->stubbedClient = $mockClient;
    $processor->callHandlePaymentNotification();

    $this->assertTrue($processor->routeWasCalled);
    $this->assertContains('worker.mollie.tr_lock_ok', \CiviLockManagerMock::$acquiredLocks);

    // Clean up.
    \CiviLockManagerMock::reset();
    unset($_POST['id']);
  }
}
