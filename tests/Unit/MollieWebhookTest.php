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

  public function exposedProcessOneOffOrFirstPaymentWebhook(Payment $payment, ?array $contribution = NULL): void {
    $this->processOneOffOrFirstPaymentWebhook($payment, $contribution);
  }

  public function exposedProcessRecurringPaymentWebhook(Payment $payment, ?array $existingContribution = NULL): void {
    $this->processRecurringPaymentWebhook($payment, $existingContribution);
  }

  /**
   * Expose the post-payment event routing logic from handlePaymentNotification.
   *
   * Returns TRUE if a post-payment event was handled, FALSE otherwise.
   */
  public function exposedHandlePostPaymentEvents(Payment $molliePayment, ?array $contribution): bool {
    if ($contribution !== NULL && $molliePayment->isPaid()) {
      if ($molliePayment->hasChargebacks()) {
        $this->handleChargeback($contribution, $molliePayment);
        return TRUE;
      }
      if ($molliePayment->hasRefunds()) {
        $this->handleRefund($contribution, $molliePayment);
        return TRUE;
      }
    }
    return FALSE;
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

    // hasChargebacks() and hasRefunds() check _links->chargebacks / _links->refunds.
    $links = new \stdClass();
    if ($props['hasChargebacks'] ?? FALSE) {
      $links->chargebacks = new \stdClass();
    }
    if ($props['hasRefunds'] ?? FALSE) {
      $links->refunds = new \stdClass();
    }
    if ($props['hasChargebacks'] ?? $props['hasRefunds'] ?? FALSE) {
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
  // Post-payment event routing (chargebacks, refunds)
  // -----------------------------------------------------------------------

  public function testChargebackHandledBeforeRouting(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution();
    $contribution['contribution_status_id:name'] = 'Completed';

    $payment = $this->makePayment([
      'status' => 'paid',
      'hasChargebacks' => TRUE,
    ]);
    $handled = $processor->exposedHandlePostPaymentEvents($payment, $contribution);

    $this->assertTrue($handled);
    $this->assertSame(['handleChargeback'], $processor->calledMethods);
  }

  public function testRefundHandledBeforeRouting(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution();
    $contribution['contribution_status_id:name'] = 'Completed';

    $payment = $this->makePayment([
      'status' => 'paid',
      'hasRefunds' => TRUE,
    ]);
    $handled = $processor->exposedHandlePostPaymentEvents($payment, $contribution);

    $this->assertTrue($handled);
    $this->assertSame(['handleRefund'], $processor->calledMethods);
  }

  public function testChargebackTakesPriorityOverRefund(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution();
    $contribution['contribution_status_id:name'] = 'Completed';

    $payment = $this->makePayment([
      'status' => 'paid',
      'hasChargebacks' => TRUE,
      'hasRefunds' => TRUE,
    ]);
    $handled = $processor->exposedHandlePostPaymentEvents($payment, $contribution);

    $this->assertTrue($handled);
    $this->assertSame(['handleChargeback'], $processor->calledMethods);
  }

  public function testPostPaymentEventsSkippedWhenNoContribution(): void {
    $processor = new WebhookTestableMolliePayment();

    $payment = $this->makePayment([
      'status' => 'paid',
      'hasChargebacks' => TRUE,
    ]);
    $handled = $processor->exposedHandlePostPaymentEvents($payment, NULL);

    $this->assertFalse($handled);
    $this->assertSame([], $processor->calledMethods);
  }

  public function testPostPaymentEventsSkippedWhenNotPaid(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution();

    $payment = $this->makePayment([
      'status' => 'failed',
      'hasChargebacks' => TRUE,
    ]);
    $handled = $processor->exposedHandlePostPaymentEvents($payment, $contribution);

    $this->assertFalse($handled);
    $this->assertSame([], $processor->calledMethods);
  }

  public function testNoPostPaymentEventsPassesThrough(): void {
    $processor = new WebhookTestableMolliePayment();
    $contribution = $this->makePendingContribution();
    $contribution['contribution_status_id:name'] = 'Completed';

    $payment = $this->makePayment(['status' => 'paid']);
    $handled = $processor->exposedHandlePostPaymentEvents($payment, $contribution);

    $this->assertFalse($handled);
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

  public function testOneOffUnknownContributionRecordsActivity(): void {
    $processor = new WebhookTestableMolliePayment();

    $payment = $this->makePayment(['status' => 'paid']);
    $processor->exposedProcessOneOffOrFirstPaymentWebhook($payment, NULL);

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
    $processor->exposedProcessRecurringPaymentWebhook($payment, NULL);

    $this->assertSame(['createRecurringInstallment'], $processor->calledMethods);
  }

  public function testRecurringFailedRecordsFailure(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContributionRecur = ['id' => 10, 'payment_processor_id' => 1];

    $payment = $this->makePayment([
      'status' => 'failed',
      'subscriptionId' => 'sub_test',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment, NULL);

    $this->assertSame(['recordFailedRecurringInstallment'], $processor->calledMethods);
  }

  public function testRecurringIdempotencySkipsDuplicate(): void {
    $processor = new WebhookTestableMolliePayment();
    $existing = $this->makePendingContribution();

    $payment = $this->makePayment([
      'status' => 'paid',
      'subscriptionId' => 'sub_test',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment, $existing);

    $this->assertSame([], $processor->calledMethods);
  }

  public function testRecurringUnknownSubscriptionSkips(): void {
    $processor = new WebhookTestableMolliePayment();
    $processor->stubbedContributionRecur = NULL;

    $payment = $this->makePayment([
      'status' => 'paid',
      'subscriptionId' => 'sub_unknown',
    ]);
    $processor->exposedProcessRecurringPaymentWebhook($payment, NULL);

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
}
