<?php

namespace Tests\Unit;

use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use PHPUnit\Framework\TestCase;
use Civi\Payment\Exception\PaymentProcessorException;

/**
 * Test subclass to expose protected methods and control processor config.
 */
class TestableMolliePayment extends \CRM_Core_Payment_Mollie {

  public function __construct(array $processorConfig = []) {
    $this->_paymentProcessor = $processorConfig;
    $this->_mode = $processorConfig['is_test'] ?? FALSE ? 'test' : 'live';
  }

  public function exposedCheckConfig(): ?string {
    return $this->checkConfig();
  }

  public function exposedMapCiviCrmFrequencyToMollie(string $unit, int $interval): string {
    return $this->mapCiviCrmFrequencyToMollie($unit, $interval);
  }

  public function exposedCalculateNextScheduledDate(array $recur): ?string {
    return $this->calculateNextScheduledDate($recur);
  }

  public function exposedBuildCancelReason(Payment $molliePayment): string {
    return $this->buildCancelReason($molliePayment);
  }

  public function exposedComputeSubscriptionStartDate(array $recur): string {
    return $this->computeSubscriptionStartDate($recur);
  }
}

class MolliePaymentTest extends TestCase {

  // -----------------------------------------------------------------------
  // checkConfig
  // -----------------------------------------------------------------------

  public function testCheckConfigValidTestKey(): void {
    $payment = new TestableMolliePayment([
      'user_name' => 'test_abc123',
      'is_test' => TRUE,
    ]);
    $this->assertNull($payment->exposedCheckConfig());
  }

  public function testCheckConfigEmptyKey(): void {
    $payment = new TestableMolliePayment([
      'user_name' => '',
      'is_test' => TRUE,
    ]);
    $result = $payment->exposedCheckConfig();
    $this->assertNotNull($result);
    $this->assertStringContainsString('not configured', $result);
  }

  public function testCheckConfigTestKeyInLiveMode(): void {
    $payment = new TestableMolliePayment([
      'user_name' => 'test_abc123',
      'is_test' => FALSE,
    ]);
    $result = $payment->exposedCheckConfig();
    $this->assertNotNull($result);
    $this->assertStringContainsString('does not match', $result);
  }

  // -----------------------------------------------------------------------
  // mapCiviCrmFrequencyToMollie
  // -----------------------------------------------------------------------

  public function testMapFrequencyMonthlySingular(): void {
    $payment = new TestableMolliePayment();
    $this->assertSame('1 month', $payment->exposedMapCiviCrmFrequencyToMollie('month', 1));
  }

  public function testMapFrequencyMonthlyPlural(): void {
    $payment = new TestableMolliePayment();
    $this->assertSame('5 months', $payment->exposedMapCiviCrmFrequencyToMollie('month', 5));
  }

  public function testMapFrequencyWeeklySingular(): void {
    $payment = new TestableMolliePayment();
    $this->assertSame('1 week', $payment->exposedMapCiviCrmFrequencyToMollie('week', 1));
  }

  public function testMapFrequencyYearSingular(): void {
    $payment = new TestableMolliePayment();
    $this->assertSame('12 months', $payment->exposedMapCiviCrmFrequencyToMollie('year', 1));
  }

  public function testMapFrequencyYearToMonths(): void {
    $payment = new TestableMolliePayment();
    $this->assertSame('24 months', $payment->exposedMapCiviCrmFrequencyToMollie('year', 2));
  }

  public function testMapFrequencyInvalidUnitThrows(): void {
    $payment = new TestableMolliePayment();
    $this->expectException(PaymentProcessorException::class);
    $payment->exposedMapCiviCrmFrequencyToMollie('invalid', 1);
  }

  // -----------------------------------------------------------------------
  // calculateNextScheduledDate
  // -----------------------------------------------------------------------

  public function testCalculateNextDateMonthly(): void {
    $payment = new TestableMolliePayment();
    $result = $payment->exposedCalculateNextScheduledDate([
      'next_sched_contribution_date' => '2026-01-15 00:00:00',
      'frequency_interval' => 1,
      'frequency_unit' => 'month',
    ]);
    $this->assertSame('2026-02-15 00:00:00', $result);
  }

  public function testCalculateNextDateNullDate(): void {
    $payment = new TestableMolliePayment();
    $this->assertNull($payment->exposedCalculateNextScheduledDate([
      'next_sched_contribution_date' => NULL,
    ]));
  }

  public function testCalculateNextDateEmptyArray(): void {
    $payment = new TestableMolliePayment();
    $this->assertNull($payment->exposedCalculateNextScheduledDate([]));
  }

  // -----------------------------------------------------------------------
  // computeSubscriptionStartDate
  // -----------------------------------------------------------------------

  public function testStartDateFromScheduledDate(): void {
    $payment = new TestableMolliePayment();
    $result = $payment->exposedComputeSubscriptionStartDate([
      'next_sched_contribution_date' => '2026-04-15 00:00:00',
      'frequency_interval' => 1,
      'frequency_unit' => 'month',
    ]);
    $this->assertSame('2026-04-15', $result);
  }

  public function testStartDateFallbackMonthly(): void {
    $payment = new TestableMolliePayment();
    $result = $payment->exposedComputeSubscriptionStartDate([
      'frequency_interval' => 1,
      'frequency_unit' => 'month',
    ]);
    $expected = date('Y-m-d', strtotime('+1 month'));
    $this->assertSame($expected, $result);
  }

  public function testStartDateFallbackQuarterly(): void {
    $payment = new TestableMolliePayment();
    $result = $payment->exposedComputeSubscriptionStartDate([
      'frequency_interval' => 3,
      'frequency_unit' => 'month',
    ]);
    $expected = date('Y-m-d', strtotime('+3 month'));
    $this->assertSame($expected, $result);
  }

  public function testStartDateFallbackYearly(): void {
    $payment = new TestableMolliePayment();
    $result = $payment->exposedComputeSubscriptionStartDate([
      'frequency_interval' => 1,
      'frequency_unit' => 'year',
    ]);
    $expected = date('Y-m-d', strtotime('+1 year'));
    $this->assertSame($expected, $result);
  }

  public function testStartDateTodayBumpedToTomorrow(): void {
    $payment = new TestableMolliePayment();
    $result = $payment->exposedComputeSubscriptionStartDate([
      'next_sched_contribution_date' => date('Y-m-d') . ' 00:00:00',
      'frequency_interval' => 1,
      'frequency_unit' => 'month',
    ]);
    $this->assertSame(date('Y-m-d', strtotime('+1 day')), $result);
  }

  public function testStartDatePastBumpedToTomorrow(): void {
    $payment = new TestableMolliePayment();
    $result = $payment->exposedComputeSubscriptionStartDate([
      'next_sched_contribution_date' => '2020-01-01 00:00:00',
      'frequency_interval' => 1,
      'frequency_unit' => 'month',
    ]);
    $this->assertSame(date('Y-m-d', strtotime('+1 day')), $result);
  }

  // -----------------------------------------------------------------------
  // buildCancelReason
  // -----------------------------------------------------------------------

  private function makeMolliePayment(array $props): Payment {
    $client = $this->createMock(MollieApiClient::class);
    $payment = new Payment($client);
    $payment->id = $props['id'] ?? 'tr_test123';
    $payment->status = $props['status'] ?? 'failed';
    $payment->method = $props['method'] ?? NULL;
    $payment->details = $props['details'] ?? NULL;
    return $payment;
  }

  public function testBuildCancelReasonBasic(): void {
    $processor = new TestableMolliePayment();
    $molliePayment = $this->makeMolliePayment([
      'id' => 'tr_abc123',
      'status' => 'failed',
    ]);

    $result = $processor->exposedBuildCancelReason($molliePayment);

    $this->assertStringContainsString('tr_abc123', $result);
    $this->assertStringContainsString('failed', $result);
  }

  public function testBuildCancelReasonWithMethod(): void {
    $processor = new TestableMolliePayment();
    $molliePayment = $this->makeMolliePayment([
      'id' => 'tr_abc123',
      'status' => 'expired',
      'method' => 'ideal',
    ]);

    $result = $processor->exposedBuildCancelReason($molliePayment);

    $this->assertStringContainsString('ideal', $result);
    $this->assertStringContainsString('expired', $result);
  }

  public function testBuildCancelReasonWithFailureDetails(): void {
    $processor = new TestableMolliePayment();
    $details = new \stdClass();
    $details->failureReason = 'insufficient_funds';
    $details->failureMessage = 'Card declined';
    $molliePayment = $this->makeMolliePayment([
      'id' => 'tr_abc123',
      'status' => 'failed',
      'method' => 'creditcard',
      'details' => $details,
    ]);

    $result = $processor->exposedBuildCancelReason($molliePayment);

    $this->assertStringContainsString('insufficient_funds', $result);
    $this->assertStringContainsString('Card declined', $result);
    $this->assertStringContainsString('creditcard', $result);
  }

  public function testBuildCancelReasonWithoutDetails(): void {
    $processor = new TestableMolliePayment();
    $molliePayment = $this->makeMolliePayment([
      'id' => 'tr_abc123',
      'status' => 'canceled',
    ]);

    $result = $processor->exposedBuildCancelReason($molliePayment);

    // Should not contain "Reason" label when no details present.
    $this->assertStringNotContainsString('Reason', $result);
    $this->assertStringContainsString('canceled', $result);
  }
}
