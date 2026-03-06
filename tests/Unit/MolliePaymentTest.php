<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Civi\Payment\Exception\PaymentProcessorException;

/**
 * Test subclass to expose protected methods.
 */
class TestableMolliePayment extends \CRM_Core_Payment_Mollie {

  public function __construct() {
    // Skip parent constructor.
  }

  public function exposedMapCiviCrmFrequencyToMollie(string $unit, int $interval): string {
    return $this->mapCiviCrmFrequencyToMollie($unit, $interval);
  }

  public function exposedCalculateNextScheduledDate(array $recur): ?string {
    return $this->calculateNextScheduledDate($recur);
  }
}

class MolliePaymentTest extends TestCase {

  private TestableMolliePayment $payment;

  protected function setUp(): void {
    $this->payment = new TestableMolliePayment();
  }

  // -----------------------------------------------------------------------
  // mapCiviCrmFrequencyToMollie
  // -----------------------------------------------------------------------

  public static function frequencyProvider(): array {
    return [
      'daily'          => ['day', 1, '1 days'],
      'every 7 days'   => ['day', 7, '7 days'],
      'weekly'         => ['week', 1, '1 weeks'],
      'biweekly'       => ['week', 2, '2 weeks'],
      'monthly'        => ['month', 1, '1 months'],
      'quarterly'      => ['month', 3, '3 months'],
      'yearly'         => ['year', 1, '12 months'],
      'every 2 years'  => ['year', 2, '24 months'],
    ];
  }

  #[DataProvider('frequencyProvider')]
  public function testMapCiviCrmFrequencyToMollie(string $unit, int $interval, string $expected): void {
    $this->assertSame($expected, $this->payment->exposedMapCiviCrmFrequencyToMollie($unit, $interval));
  }

  public function testMapCiviCrmFrequencyToMollieThrowsOnInvalid(): void {
    $this->expectException(PaymentProcessorException::class);
    $this->payment->exposedMapCiviCrmFrequencyToMollie('invalid', 1);
  }

  // -----------------------------------------------------------------------
  // calculateNextScheduledDate
  // -----------------------------------------------------------------------

  public static function nextDateProvider(): array {
    return [
      'monthly' => [
        ['next_sched_contribution_date' => '2026-01-15 00:00:00', 'frequency_interval' => 1, 'frequency_unit' => 'month'],
        '2026-02-15 00:00:00',
      ],
      'yearly' => [
        ['next_sched_contribution_date' => '2026-03-01 00:00:00', 'frequency_interval' => 1, 'frequency_unit' => 'year'],
        '2027-03-01 00:00:00',
      ],
      'biweekly' => [
        ['next_sched_contribution_date' => '2026-03-01 00:00:00', 'frequency_interval' => 2, 'frequency_unit' => 'week'],
        '2026-03-15 00:00:00',
      ],
      'daily' => [
        ['next_sched_contribution_date' => '2026-03-01 00:00:00', 'frequency_interval' => 1, 'frequency_unit' => 'day'],
        '2026-03-02 00:00:00',
      ],
      'null date' => [
        ['next_sched_contribution_date' => NULL],
        NULL,
      ],
      'missing key' => [
        [],
        NULL,
      ],
      'defaults to monthly' => [
        ['next_sched_contribution_date' => '2026-06-15 00:00:00'],
        '2026-07-15 00:00:00',
      ],
    ];
  }

  #[DataProvider('nextDateProvider')]
  public function testCalculateNextScheduledDate(array $recur, ?string $expected): void {
    $this->assertSame($expected, $this->payment->exposedCalculateNextScheduledDate($recur));
  }
}
