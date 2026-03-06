<?php

namespace Tests\Unit;

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

  public function testMapFrequencyMonthly(): void {
    $payment = new TestableMolliePayment();
    $this->assertSame('5 months', $payment->exposedMapCiviCrmFrequencyToMollie('month', 5));
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
}
