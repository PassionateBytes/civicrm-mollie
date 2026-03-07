<?php

namespace Tests\Unit;

use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use PHPUnit\Framework\TestCase;

/**
 * Test subclass that stubs CiviCRM-dependent methods to track webhook routing.
 */
class WebhookTestableMolliePayment extends \CRM_Core_Payment_Mollie {

  /** @var string[] Methods called during webhook processing. */
  public array $calledMethods = [];

  /** @var array|null Contribution returned by findContributionByTrxnId. */
  public ?array $stubbedContribution = NULL;

  /** @var array|null ContributionRecur returned by findContributionRecurByProcessorId. */
  public ?array $stubbedContributionRecur = NULL;

  /** @var array Params passed to completeContribution (for fee calculation tests). */
  public array $completeContributionParams = [];

  public function __construct() {
    $this->_paymentProcessor = ['id' => 1];
    $this->_mode = 'test';
  }

  public function exposedProcessOneOffOrFirstPaymentWebhook(Payment $payment): void {
    $this->processOneOffOrFirstPaymentWebhook($payment);
  }

  public function exposedProcessRecurringPaymentWebhook(Payment $payment): void {
    $this->processRecurringPaymentWebhook($payment);
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
    if ($molliePayment->settlementAmount !== NULL) {
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

class MollieWebhookTest extends TestCase {

  private function makePayment(array $props): Payment {
    $client = $this->createMock(MollieApiClient::class);
    $payment = new Payment($client);
    $payment->id = $props['id'] ?? 'tr_test123';
    $payment->status = $props['status'] ?? 'paid';
    $payment->sequenceType = $props['sequenceType'] ?? 'oneoff';
    $payment->subscriptionId = $props['subscriptionId'] ?? NULL;

    // isPaid() checks paidAt, not status.
    $payment->paidAt = ($props['status'] ?? 'paid') === 'paid' ? '2026-03-06T12:00:00+00:00' : NULL;

    // hasChargebacks() checks _links->chargebacks.
    if ($props['hasChargebacks'] ?? FALSE) {
      $links = new \stdClass();
      $links->chargebacks = new \stdClass();
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

  private function makePendingContribution(int $id = 1, ?int $recurId = NULL): array {
    return [
      'id' => $id,
      'contribution_status_id:name' => 'Pending',
      'contact_id' => 100,
      'contribution_recur_id' => $recurId,
      'is_email_receipt' => FALSE,
    ];
  }

  // -----------------------------------------------------------------------
  // processOneOffOrFirstPaymentWebhook
  // -----------------------------------------------------------------------

  public function testOneOffPaidCompletesContribution(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution();

    $payment = $this->makePayment(['status' => 'paid']);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment);

    $this->assertSame(['completeContribution'], $processor->calledMethods);
  }

  public function testFirstRecurringPaidCompletesAndSetsUpSubscription(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution(1, recurId: 10);

    $payment = $this->makePayment([
      'status' => 'paid',
      'sequenceType' => 'first',
    ]);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment);

    $this->assertSame([
      'completeContribution',
      'handleFirstRecurringPaymentCompleted',
    ], $processor->calledMethods);
  }

  public function testOneOffFailedFailsContribution(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution();

    $payment = $this->makePayment(['status' => 'failed']);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment);

    $this->assertSame(['failContribution'], $processor->calledMethods);
  }

  public function testFirstRecurringFailedFailsBoth(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution(1, recurId: 10);

    $payment = $this->makePayment([
      'status' => 'failed',
      'sequenceType' => 'first',
    ]);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment);

    $this->assertSame([
      'failContribution',
      'failContributionRecur',
    ], $processor->calledMethods);
  }

  public function testChargebackHandledBeforeIdempotency(): void {
    $processor = new WebhookTestableMolliePayment();
    // Contribution is already Completed — idempotency would normally skip.
    $contribution = $this->makePendingContribution();
    $contribution['contribution_status_id:name'] = 'Completed';
    $processor->stubbedContribution = $contribution;

    $payment = $this->makePayment([
      'status' => 'paid',
      'hasChargebacks' => TRUE,
    ]);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment);

    // Chargeback is processed even though contribution is already Completed.
    $this->assertSame(['handleChargeback'], $processor->calledMethods);
  }

  public function testIdempotencySkipsAlreadyCompleted(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution();
    $contribution['contribution_status_id:name'] = 'Completed';
    $processor->stubbedContribution = $contribution;

    $payment = $this->makePayment(['status' => 'paid']);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment);

    $this->assertSame([], $processor->calledMethods);
  }

  // -----------------------------------------------------------------------
  // processRecurringPaymentWebhook
  // -----------------------------------------------------------------------

  public function testRecurringPaidCreatesInstallment(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = NULL; // New payment, not yet recorded.
    $processor->stubbedContributionRecur = ['id' => 10, 'payment_processor_id' => 1];

    $payment = $this->makePayment([
      'status' => 'paid',
      'subscriptionId' => 'sub_test',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment);

    $this->assertSame(['createRecurringInstallment'], $processor->calledMethods);
  }

  public function testRecurringFailedRecordsFailure(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = NULL;
    $processor->stubbedContributionRecur = ['id' => 10, 'payment_processor_id' => 1];

    $payment = $this->makePayment([
      'status' => 'failed',
      'subscriptionId' => 'sub_test',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment);

    $this->assertSame(['recordFailedRecurringInstallment'], $processor->calledMethods);
  }

  public function testRecurringIdempotencySkipsDuplicate(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution(); // Already recorded.

    $payment = $this->makePayment([
      'status' => 'paid',
      'subscriptionId' => 'sub_test',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment);

    $this->assertSame([], $processor->calledMethods);
  }

  public function testRecurringChargebackOnExisting(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution();

    $payment = $this->makePayment([
      'status' => 'paid',
      'subscriptionId' => 'sub_test',
      'hasChargebacks' => TRUE,
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment);

    $this->assertSame(['handleChargeback'], $processor->calledMethods);
  }

  public function testRecurringUnknownSubscriptionSkips(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = NULL;
    $processor->stubbedContributionRecur = NULL; // Unknown subscription.

    $payment = $this->makePayment([
      'status' => 'paid',
      'subscriptionId' => 'sub_unknown',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment);

    $this->assertSame(['recordUnmatchedWebhookActivity'], $processor->calledMethods);
  }

  public function testOneOffUnknownContributionRecordsActivity(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = NULL; // Unknown contribution.

    $payment = $this->makePayment(['status' => 'paid']);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment);

    $this->assertSame(['recordUnmatchedWebhookActivity'], $processor->calledMethods);
  }

  // -----------------------------------------------------------------------
  // completeContribution fee calculation
  // -----------------------------------------------------------------------

  public function testFeeCalculationWithSettlement(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution();

    $payment = $this->makePayment([
      'status' => 'paid',
      'amount' => $this->makeAmount('25.00'),
      'settlementAmount' => $this->makeAmount('24.71'),
    ]);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment);

    $this->assertSame('0.29', $processor->completeContributionParams['fee_amount']);
  }

  public function testFeeCalculationWithoutSettlement(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution();

    $payment = $this->makePayment(['status' => 'paid']);
    // No settlementAmount set on payment.
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment);

    $this->assertArrayNotHasKey('fee_amount', $processor->completeContributionParams);
  }

  public function testFeeCalculationZeroFee(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContribution = $this->makePendingContribution();

    $payment = $this->makePayment([
      'status' => 'paid',
      'amount' => $this->makeAmount('25.00'),
      'settlementAmount' => $this->makeAmount('25.00'),
    ]);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment);

    $this->assertArrayNotHasKey('fee_amount', $processor->completeContributionParams);
  }
}
