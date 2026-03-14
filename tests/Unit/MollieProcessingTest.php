<?php

namespace Tests\Unit;

use Mollie\Api\Endpoints\SubscriptionEndpoint;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Chargeback;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Refund;
use Mollie\Api\Resources\Subscription;
use Civi\Payment\Exception\PaymentProcessorException;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\Api4Mock;

/**
 * Test subclass that lets business logic methods run while stubbing
 * external dependencies (CiviCRM APIs, Mollie API client).
 */
class ProcessingTestableMollie extends \CRM_Core_Payment_Mollie {

  public bool $stubbedFinancialTrxnExists = FALSE;
  public int $stubbedPaymentTokenId = 99;
  public ?string $stubbedMollieCustomerId = 'cst_test123';
  public ?MollieApiClient $stubbedMollieClient = NULL;

  /** @var array Params passed to completeContribution (captured for assertions). */
  public array $completedContributionParams = [];

  /** @var string[] Track which internal methods were called. */
  public array $calledMethods = [];

  public function __construct() {
    $this->_paymentProcessor = ['id' => 1];
    $this->_mode = 'test';
  }

  // -- Expose protected methods for testing --

  public function exposedCompleteContribution(array $contribution, Payment $p): void {
    $this->completeContribution($contribution, $p);
  }

  public function exposedHandleChargeback(array $contribution, Payment $p): void {
    $this->handleChargeback($contribution, $p);
  }

  public function exposedHandleRefund(array $contribution, Payment $p): void {
    $this->handleRefund($contribution, $p);
  }

  public function exposedHandleFirstRecurringPaymentCompleted(array $contribution, Payment $p): void {
    $this->handleFirstRecurringPaymentCompleted($contribution, $p);
  }

  public function exposedCancelSubscription(&$message, $params): bool {
    return $this->cancelSubscription($message, $params);
  }

  public function exposedChangeSubscriptionAmount(&$message, $params): bool {
    return $this->changeSubscriptionAmount($message, $params);
  }

  // -- Stubs for external dependencies --

  protected function financialTrxnExists(string $trxnId): bool {
    return $this->stubbedFinancialTrxnExists;
  }

  protected function createPaymentToken(int $contactId, string $mandateId): int {
    $this->calledMethods[] = 'createPaymentToken';
    return $this->stubbedPaymentTokenId;
  }

  protected function findMollieCustomerByContactId(int $contactId): ?string {
    return $this->stubbedMollieCustomerId;
  }

  protected function getMollieApiClient(): MollieApiClient {
    return $this->stubbedMollieClient;
  }

  protected function recordChargebackNote(array $contribution, Payment $p, Chargeback $cb): void {
    $this->calledMethods[] = 'recordChargebackNote';
  }

  protected function recordRefundNote(array $contribution, Payment $p, Refund $r): void {
    $this->calledMethods[] = 'recordRefundNote';
  }

  protected function failContributionRecur(int $recurId, Payment $p): void {
    $this->calledMethods[] = 'failContributionRecur';
  }

  protected function buildPaymentDescription(int $contributionId): string {
    return "Test description #{$contributionId}";
  }

  protected function getNotifyUrl(): string {
    return 'https://example.com/webhook';
  }
}

/**
 * Payment subclass that stubs chargebacks() and refunds() to avoid API calls.
 */
class TestablePayment extends Payment {

  private array $stubbedChargebacks = [];
  private array $stubbedRefunds = [];

  public function setChargebacks(array $cbs): void {
    // Also set _links so hasChargebacks() returns true.
    if (!isset($this->_links)) {
      $this->_links = new \stdClass();
    }
    if (!empty($cbs)) {
      $this->_links->chargebacks = new \stdClass();
    }
    $this->stubbedChargebacks = $cbs;
  }

  public function setRefunds(array $refs): void {
    if (!isset($this->_links)) {
      $this->_links = new \stdClass();
    }
    if (!empty($refs)) {
      $this->_links->refunds = new \stdClass();
    }
    $this->stubbedRefunds = $refs;
  }

  public function chargebacks(): array {
    return $this->stubbedChargebacks;
  }

  public function refunds(): array {
    return $this->stubbedRefunds;
  }
}

// ===========================================================================

class MollieProcessingTest extends TestCase {

  protected function setUp(): void {
    Api4Mock::reset();
    \Api3Mock::reset();
  }

  // -----------------------------------------------------------------------
  // Helpers
  // -----------------------------------------------------------------------

  private function makePayment(array $props = []): TestablePayment {
    $client = $this->createMock(MollieApiClient::class);
    $p = new TestablePayment($client);
    $p->id = $props['id'] ?? 'tr_test123';
    $p->status = $props['status'] ?? 'paid';
    $p->paidAt = ($props['status'] ?? 'paid') === 'paid' ? '2026-03-06T12:00:00+00:00' : NULL;
    $p->method = $props['method'] ?? 'ideal';
    $p->mandateId = $props['mandateId'] ?? NULL;
    $p->customerId = $props['customerId'] ?? 'cst_test123';
    $p->subscriptionId = $props['subscriptionId'] ?? NULL;

    $p->amount = $this->makeAmount($props['amountValue'] ?? '25.00');
    $p->settlementAmount = isset($props['settlementValue'])
      ? $this->makeAmount($props['settlementValue'], $props['settlementCurrency'] ?? 'EUR')
      : NULL;

    return $p;
  }

  private function makeAmount(string $value, string $currency = 'EUR'): \stdClass {
    $obj = new \stdClass();
    $obj->value = $value;
    $obj->currency = $currency;
    return $obj;
  }

  private function makeContribution(int $id = 1, ?int $recurId = NULL): array {
    return [
      'id' => $id,
      'contribution_status_id:name' => 'Pending',
      'contact_id' => 100,
      'contribution_recur_id' => $recurId,
    ];
  }

  private function makeChargeback(string $id, string $amount, ?object $reason = NULL): Chargeback {
    $client = $this->createMock(MollieApiClient::class);
    $cb = new Chargeback($client);
    $cb->id = $id;
    $cb->amount = $this->makeAmount($amount);
    $cb->createdAt = '2026-03-06T12:00:00+00:00';
    $cb->reason = $reason;
    $cb->reversedAt = NULL;
    return $cb;
  }

  private function makeRefund(string $id, string $amount, string $status = 'refunded'): Refund {
    $client = $this->createMock(MollieApiClient::class);
    $r = new Refund($client);
    $r->id = $id;
    $r->amount = $this->makeAmount($amount);
    $r->status = $status;
    $r->createdAt = '2026-03-06T12:00:00+00:00';
    return $r;
  }

  private function makeProcessor(): ProcessingTestableMollie {
    return new ProcessingTestableMollie();
  }

  private function getApi3Calls(string $entity, string $action): array {
    return array_filter(\Api3Mock::$calls, fn($c) => $c['entity'] === $entity && $c['action'] === $action);
  }

  private function getApi4Calls(string $entity, string $action): array {
    return array_filter(Api4Mock::$calls, fn($c) => $c['entity'] === $entity && $c['action'] === $action);
  }

  // -----------------------------------------------------------------------
  // completeContribution
  // -----------------------------------------------------------------------

  public function testCompleteContributionSkipsWhenTrxnExists(): void {
    $proc = $this->makeProcessor();
    $proc->stubbedFinancialTrxnExists = TRUE;

    $proc->exposedCompleteContribution($this->makeContribution(), $this->makePayment());

    $this->assertEmpty($this->getApi3Calls('Payment', 'create'));
  }

  public function testCompleteContributionCreatesPaymentWithFee(): void {
    $proc = $this->makeProcessor();
    $payment = $this->makePayment([
      'amountValue' => '25.00',
      'settlementValue' => '24.71',
    ]);

    $proc->exposedCompleteContribution($this->makeContribution(42), $payment);

    $calls = array_values($this->getApi3Calls('Payment', 'create'));
    $this->assertCount(1, $calls);
    $this->assertSame(42, $calls[0]['params']['contribution_id']);
    $this->assertSame('tr_test123', $calls[0]['params']['trxn_id']);
    $this->assertSame('0.29', $calls[0]['params']['fee_amount']);
  }

  public function testCompleteContributionSkipsFeeOnCurrencyMismatch(): void {
    $proc = $this->makeProcessor();
    $payment = $this->makePayment([
      'amountValue' => '25.00',
      'settlementValue' => '28.50',
      'settlementCurrency' => 'USD',
    ]);

    $proc->exposedCompleteContribution($this->makeContribution(), $payment);

    $calls = array_values($this->getApi3Calls('Payment', 'create'));
    $this->assertCount(1, $calls);
    $this->assertArrayNotHasKey('fee_amount', $calls[0]['params']);
  }

  // -----------------------------------------------------------------------
  // handleChargeback
  // -----------------------------------------------------------------------

  public function testHandleChargebackRecordsNegativePaymentAndOverridesStatus(): void {
    $proc = $this->makeProcessor();
    $payment = $this->makePayment();
    $payment->setChargebacks([$this->makeChargeback('chb_test1', '25.00')]);

    $proc->exposedHandleChargeback($this->makeContribution(5), $payment);

    // Negative payment recorded via api3.
    $calls = array_values($this->getApi3Calls('Payment', 'create'));
    $this->assertCount(1, $calls);
    $this->assertEquals(-25.00, $calls[0]['params']['total_amount']);
    $this->assertSame('chb_test1', $calls[0]['params']['trxn_id']);

    // Status overridden to Chargeback via Api4.
    $statusCalls = array_values($this->getApi4Calls('Contribution', 'update'));
    $this->assertCount(1, $statusCalls);
    $this->assertSame('Chargeback', $statusCalls[0]['values']['contribution_status_id:name']);

    // Note recorded.
    $this->assertContains('recordChargebackNote', $proc->calledMethods);
  }

  public function testHandleChargebackSkipsExistingTrxn(): void {
    $proc = $this->makeProcessor();
    $proc->stubbedFinancialTrxnExists = TRUE;
    $payment = $this->makePayment();
    $payment->setChargebacks([$this->makeChargeback('chb_test1', '25.00')]);

    $proc->exposedHandleChargeback($this->makeContribution(), $payment);

    // No Payment.create call — idempotency guard skipped it.
    $this->assertEmpty($this->getApi3Calls('Payment', 'create'));
    // No status override since nothing was recorded.
    $this->assertEmpty($this->getApi4Calls('Contribution', 'update'));
  }

  // -----------------------------------------------------------------------
  // handleRefund
  // -----------------------------------------------------------------------

  public function testHandleRefundOnlyProcessesRefundedStatus(): void {
    $proc = $this->makeProcessor();
    $payment = $this->makePayment();
    $payment->setRefunds([
      $this->makeRefund('re_queued', '10.00', 'queued'),
      $this->makeRefund('re_processing', '10.00', 'processing'),
      $this->makeRefund('re_failed', '10.00', 'failed'),
      $this->makeRefund('re_refunded', '15.00', 'refunded'),
    ]);

    $proc->exposedHandleRefund($this->makeContribution(5), $payment);

    // Only the 'refunded' one should create a Payment.
    $calls = array_values($this->getApi3Calls('Payment', 'create'));
    $this->assertCount(1, $calls);
    $this->assertSame('re_refunded', $calls[0]['params']['trxn_id']);
    $this->assertEquals(-15.00, $calls[0]['params']['total_amount']);
  }

  public function testHandleRefundSkipsExistingTrxn(): void {
    $proc = $this->makeProcessor();
    $proc->stubbedFinancialTrxnExists = TRUE;
    $payment = $this->makePayment();
    $payment->setRefunds([$this->makeRefund('re_done', '25.00')]);

    $proc->exposedHandleRefund($this->makeContribution(), $payment);

    $this->assertEmpty($this->getApi3Calls('Payment', 'create'));
  }

  // -----------------------------------------------------------------------
  // handleFirstRecurringPaymentCompleted
  // -----------------------------------------------------------------------

  public function testFirstRecurringNullMandateFailsRecur(): void {
    $proc = $this->makeProcessor();
    $payment = $this->makePayment(['mandateId' => NULL]);
    $contribution = $this->makeContribution(1, recurId: 10);

    $proc->exposedHandleFirstRecurringPaymentCompleted($contribution, $payment);

    $this->assertContains('failContributionRecur', $proc->calledMethods);
    $this->assertNotContains('createPaymentToken', $proc->calledMethods);
  }

  public function testFirstRecurringSingleInstallmentCompletesWithoutSubscription(): void {
    $proc = $this->makeProcessor();
    $payment = $this->makePayment(['mandateId' => 'mdt_test']);
    $contribution = $this->makeContribution(1, recurId: 10);

    // ContributionRecur::get returns installments=1.
    Api4Mock::setResult('ContributionRecur.get', [[
      'id' => 10, 'installments' => 1, 'contact_id' => 100,
      'frequency_unit' => 'month', 'frequency_interval' => 1,
      'currency' => 'EUR', 'amount' => '25.00',
    ]]);

    $proc->exposedHandleFirstRecurringPaymentCompleted($contribution, $payment);

    $this->assertContains('createPaymentToken', $proc->calledMethods);
    // Should set recur to Completed.
    $updateCalls = array_values($this->getApi4Calls('ContributionRecur', 'update'));
    $completedCall = array_filter($updateCalls, fn($c) => ($c['values']['contribution_status_id:name'] ?? '') === 'Completed');
    $this->assertNotEmpty($completedCall);
  }

  public function testFirstRecurringCreatesSubscription(): void {
    $proc = $this->makeProcessor();
    $payment = $this->makePayment(['mandateId' => 'mdt_test']);
    $contribution = $this->makeContribution(1, recurId: 10);

    Api4Mock::setResult('ContributionRecur.get', [[
      'id' => 10, 'installments' => 6, 'contact_id' => 100,
      'frequency_unit' => 'month', 'frequency_interval' => 1,
      'currency' => 'EUR', 'amount' => '25.00',
      'next_sched_contribution_date' => '2026-04-15 00:00:00',
    ]]);

    // Mock Mollie subscription creation.
    $mockSub = new Subscription($this->createMock(MollieApiClient::class));
    $mockSub->id = 'sub_created';

    $mockSubEndpoint = $this->createMock(SubscriptionEndpoint::class);
    $mockSubEndpoint->method('createForId')->willReturn($mockSub);

    $mockClient = $this->createMock(MollieApiClient::class);
    $mockClient->subscriptions = $mockSubEndpoint;
    $proc->stubbedMollieClient = $mockClient;

    $mockSubEndpoint->expects($this->never())->method('cancelForId');

    $proc->exposedHandleFirstRecurringPaymentCompleted($contribution, $payment);

    $this->assertContains('createPaymentToken', $proc->calledMethods);

    // Recur updated to In Progress with subscription ID.
    $updateCalls = array_values($this->getApi4Calls('ContributionRecur', 'update'));
    $inProgressCall = array_filter($updateCalls, fn($c) => ($c['values']['contribution_status_id:name'] ?? '') === 'In Progress');
    $this->assertNotEmpty($inProgressCall);
    $subIdCall = array_filter($updateCalls, fn($c) => ($c['values']['processor_id'] ?? '') === 'sub_created');
    $this->assertNotEmpty($subIdCall);
  }

  // -----------------------------------------------------------------------
  // cancelSubscription
  // -----------------------------------------------------------------------

  public function testCancelSubscriptionSuccess(): void {
    $proc = $this->makeProcessor();

    Api4Mock::setResult('ContributionRecur.get', [[
      'processor_id' => 'sub_abc', 'contact_id' => 100,
    ]]);

    $mockSubEndpoint = $this->createMock(SubscriptionEndpoint::class);
    $mockSubEndpoint->method('cancelForId')->willReturn(new Subscription($this->createMock(MollieApiClient::class)));

    $mockClient = $this->createMock(MollieApiClient::class);
    $mockClient->subscriptions = $mockSubEndpoint;
    $proc->stubbedMollieClient = $mockClient;

    $message = NULL;
    $result = $proc->exposedCancelSubscription($message, ['contributionRecurID' => 10]);

    $this->assertTrue($result);
    $this->assertNotNull($message);
  }

  public function testCancelSubscription410TreatedAsSuccess(): void {
    $proc = $this->makeProcessor();

    Api4Mock::setResult('ContributionRecur.get', [[
      'processor_id' => 'sub_abc', 'contact_id' => 100,
    ]]);

    $mockSubEndpoint = $this->createMock(SubscriptionEndpoint::class);
    $mockSubEndpoint->method('cancelForId')->willThrowException(new ApiException('Gone', 410));

    $mockClient = $this->createMock(MollieApiClient::class);
    $mockClient->subscriptions = $mockSubEndpoint;
    $proc->stubbedMollieClient = $mockClient;

    $message = NULL;
    $result = $proc->exposedCancelSubscription($message, ['contributionRecurID' => 10]);

    $this->assertTrue($result);
    $this->assertStringContainsString('already', $message);
  }

  public function testCancelSubscriptionMissingProcessorIdThrows(): void {
    $proc = $this->makeProcessor();

    Api4Mock::setResult('ContributionRecur.get', [[
      'processor_id' => NULL, 'contact_id' => 100,
    ]]);

    $this->expectException(PaymentProcessorException::class);
    $this->expectExceptionMessageMatches('/No Mollie subscription ID/');

    $message = NULL;
    $proc->exposedCancelSubscription($message, ['contributionRecurID' => 10]);
  }

  // -----------------------------------------------------------------------
  // changeSubscriptionAmount
  // -----------------------------------------------------------------------

  public function testChangeAmountOnlyNoTimesInPatch(): void {
    $proc = $this->makeProcessor();

    Api4Mock::setResult('ContributionRecur.get', [[
      'processor_id' => 'sub_abc', 'contact_id' => 100,
      'currency' => 'EUR', 'installments' => 6,
    ]]);

    $mockSubEndpoint = $this->createMock(SubscriptionEndpoint::class);
    $mockSubEndpoint->expects($this->once())
      ->method('update')
      ->with('cst_test123', 'sub_abc', $this->callback(function ($data) {
        // Amount is present, times is NOT.
        return isset($data['amount']) && !isset($data['times']);
      }))
      ->willReturn(new Subscription($this->createMock(MollieApiClient::class)));

    $mockClient = $this->createMock(MollieApiClient::class);
    $mockClient->subscriptions = $mockSubEndpoint;
    $proc->stubbedMollieClient = $mockClient;

    $message = NULL;
    $result = $proc->exposedChangeSubscriptionAmount($message, [
      'contributionRecurID' => 10,
      'amount' => '30.00',
      'installments' => 6, // Same as current — no change.
    ]);

    $this->assertTrue($result);
  }

  public function testChangeInstallmentsSendsTimesInPatch(): void {
    $proc = $this->makeProcessor();

    Api4Mock::setResult('ContributionRecur.get', [[
      'processor_id' => 'sub_abc', 'contact_id' => 100,
      'currency' => 'EUR', 'installments' => 6,
    ]]);

    $existingSub = new Subscription($this->createMock(MollieApiClient::class));
    $existingSub->times = 5;
    $existingSub->timesRemaining = 3;

    $mockSubEndpoint = $this->createMock(SubscriptionEndpoint::class);
    $mockSubEndpoint->method('getForId')
      ->willReturn($existingSub);
    $mockSubEndpoint->expects($this->once())
      ->method('update')
      ->with('cst_test123', 'sub_abc', $this->callback(function ($data) {
        // times = 10 - 1 = 9
        return $data['times'] === 9;
      }))
      ->willReturn(new Subscription($this->createMock(MollieApiClient::class)));

    $mockClient = $this->createMock(MollieApiClient::class);
    $mockClient->subscriptions = $mockSubEndpoint;
    $proc->stubbedMollieClient = $mockClient;

    $message = NULL;
    $proc->exposedChangeSubscriptionAmount($message, [
      'contributionRecurID' => 10,
      'amount' => '25.00',
      'installments' => 10,
    ]);
  }

  public function testChangeInstallmentsFiniteToOpenEndedBlocked(): void {
    $proc = $this->makeProcessor();

    Api4Mock::setResult('ContributionRecur.get', [[
      'processor_id' => 'sub_abc', 'contact_id' => 100,
      'currency' => 'EUR', 'installments' => 6,
    ]]);

    $this->expectException(PaymentProcessorException::class);
    $this->expectExceptionMessageMatches('/open-ended/');

    $message = NULL;
    $proc->exposedChangeSubscriptionAmount($message, [
      'contributionRecurID' => 10,
      'amount' => '25.00',
      // installments not set → cast to NULL → open-ended
    ]);
  }

  public function testChangeInstallmentsToOneBlocked(): void {
    $proc = $this->makeProcessor();

    Api4Mock::setResult('ContributionRecur.get', [[
      'processor_id' => 'sub_abc', 'contact_id' => 100,
      'currency' => 'EUR', 'installments' => 6,
    ]]);

    $this->expectException(PaymentProcessorException::class);
    $this->expectExceptionMessageMatches('/at least 2/');

    $message = NULL;
    $proc->exposedChangeSubscriptionAmount($message, [
      'contributionRecurID' => 10,
      'amount' => '25.00',
      'installments' => 1,
    ]);
  }

  public function testChangeInstallmentsOpenEndedToFiniteSendsTimes(): void {
    $proc = $this->makeProcessor();

    Api4Mock::setResult('ContributionRecur.get', [[
      'processor_id' => 'sub_abc', 'contact_id' => 100,
      'currency' => 'EUR', 'installments' => NULL,
    ]]);

    $existingSub = new Subscription($this->createMock(MollieApiClient::class));
    $existingSub->times = NULL;
    $existingSub->timesRemaining = NULL;

    $mockSubEndpoint = $this->createMock(SubscriptionEndpoint::class);
    $mockSubEndpoint->method('getForId')
      ->willReturn($existingSub);
    $mockSubEndpoint->expects($this->once())
      ->method('update')
      ->with('cst_test123', 'sub_abc', $this->callback(function ($data) {
        // times = 12 - 1 = 11
        return $data['times'] === 11;
      }))
      ->willReturn(new Subscription($this->createMock(MollieApiClient::class)));

    $mockClient = $this->createMock(MollieApiClient::class);
    $mockClient->subscriptions = $mockSubEndpoint;
    $proc->stubbedMollieClient = $mockClient;

    $message = NULL;
    $result = $proc->exposedChangeSubscriptionAmount($message, [
      'contributionRecurID' => 10,
      'amount' => '25.00',
      'installments' => 12,
    ]);

    $this->assertTrue($result);
  }

  // -----------------------------------------------------------------------
  // cancelSubscription — error handling
  // -----------------------------------------------------------------------

  public function testCancelSubscriptionApiErrorThrowsPaymentProcessorException(): void {
    $proc = $this->makeProcessor();

    Api4Mock::setResult('ContributionRecur.get', [[
      'processor_id' => 'sub_abc', 'contact_id' => 100,
    ]]);

    $mockSubEndpoint = $this->createMock(SubscriptionEndpoint::class);
    $mockSubEndpoint->method('cancelForId')
      ->willThrowException(new ApiException('Server error', 500));

    $mockClient = $this->createMock(MollieApiClient::class);
    $mockClient->subscriptions = $mockSubEndpoint;
    $proc->stubbedMollieClient = $mockClient;

    $this->expectException(PaymentProcessorException::class);
    $this->expectExceptionMessageMatches('/Failed to cancel/');

    $message = NULL;
    $proc->exposedCancelSubscription($message, ['contributionRecurID' => 10]);
  }
}
