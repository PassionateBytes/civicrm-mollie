<?php

namespace Tests\Unit;

use Civi\Api4\Action\MollieRecurringReminder\Run;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\Api4Mock;

/**
 * Testable subclass that stubs only the email sending (which requires
 * the full CiviCRM message template system).
 *
 * All other static methods (getReminderActivityTypeId, reminderAlreadySent,
 * recordReminderActivity) use self:: and run against Api4Mock stubs.
 */
class TestableReminderRun extends Run {

  /** @var bool Whether sendReminder should throw. */
  public static bool $sendShouldFail = FALSE;

  /** @var array Captured sendReminder calls. */
  public static array $sendCalls = [];

  public function __construct() {
    parent::__construct('MollieRecurringReminder', 'run');
  }

  public function callRun(): array {
    $result = new \Civi\Api4\Generic\Result();
    $this->_run($result);
    return $result[0] ?? [];
  }

  protected static function sendReminder(array $recur, array $contact): void {
    if (self::$sendShouldFail) {
      throw new \RuntimeException('Send failed');
    }
    self::$sendCalls[] = ['recur' => $recur, 'contact' => $contact];
  }

  public static function reset(): void {
    self::$sendShouldFail = FALSE;
    self::$sendCalls = [];
  }
}

class MollieReminderTest extends TestCase {

  private TestableReminderRun $runner;

  protected function setUp(): void {
    Api4Mock::reset();
    \CiviSettingsMock::reset();
    TestableReminderRun::reset();

    // Stub: activity type lookup returns ID 99.
    Api4Mock::setResult('OptionValue.get', [['value' => '99']]);
    // Stub: no existing reminder activities (dedup check returns empty).
    Api4Mock::setResult('Activity.get', []);

    $this->runner = new TestableReminderRun();
  }

  // -----------------------------------------------------------------------
  // Disabled setting
  // -----------------------------------------------------------------------

  public function testDisabledSettingSkipsEverything(): void {
    \CiviSettingsMock::$values['mollie_reminder_enabled'] = FALSE;

    $stats = $this->runner->callRun();

    $this->assertEquals(0, $stats['checked']);
    $this->assertEquals(0, $stats['sent']);
    $this->assertEmpty(TestableReminderRun::$sendCalls);
  }

  // -----------------------------------------------------------------------
  // Happy path
  // -----------------------------------------------------------------------

  public function testSendsReminderForUpcomingCharge(): void {
    \CiviSettingsMock::$values['mollie_reminder_enabled'] = TRUE;
    \CiviSettingsMock::$values['mollie_reminder_days_before'] = 7;

    $nextDate = (new \DateTime('+3 days'))->format('Y-m-d');

    Api4Mock::setResult('ContributionRecur.get', [
      [
        'id' => 10,
        'contact_id' => 200,
        'amount' => '25.00',
        'currency' => 'EUR',
        'frequency_interval' => 1,
        'frequency_unit' => 'month',
        'next_sched_contribution_date' => $nextDate,
      ],
    ]);
    Api4Mock::setResult('Contact.get', [
      ['display_name' => 'Test Donor', 'email_primary.email' => 'donor@example.com'],
    ]);

    $stats = $this->runner->callRun();

    $this->assertEquals(1, $stats['checked']);
    $this->assertEquals(1, $stats['sent']);
    $this->assertCount(1, TestableReminderRun::$sendCalls);
    $this->assertEquals(10, TestableReminderRun::$sendCalls[0]['recur']['id']);
    $this->assertEquals('donor@example.com', TestableReminderRun::$sendCalls[0]['contact']['email_primary.email']);

    // Activity should be recorded.
    $activityCreates = array_filter(Api4Mock::$calls, fn($c) =>
      $c['entity'] === 'Activity' && $c['action'] === 'create'
    );
    $this->assertNotEmpty($activityCreates);
  }

  // -----------------------------------------------------------------------
  // Dedup: already sent
  // -----------------------------------------------------------------------

  public function testSkipsWhenReminderAlreadySent(): void {
    \CiviSettingsMock::$values['mollie_reminder_enabled'] = TRUE;
    \CiviSettingsMock::$values['mollie_reminder_days_before'] = 7;

    // Stub: existing activity means reminder already sent.
    Api4Mock::setResult('Activity.get', [['id' => 1]]);

    $nextDate = (new \DateTime('+3 days'))->format('Y-m-d');
    Api4Mock::setResult('ContributionRecur.get', [
      ['id' => 10, 'contact_id' => 200, 'next_sched_contribution_date' => $nextDate],
    ]);

    $stats = $this->runner->callRun();

    $this->assertEquals(1, $stats['checked']);
    $this->assertEquals(1, $stats['skipped']);
    $this->assertEquals(0, $stats['sent']);
    $this->assertEmpty(TestableReminderRun::$sendCalls);
  }

  // -----------------------------------------------------------------------
  // No email
  // -----------------------------------------------------------------------

  public function testSkipsContactWithoutEmail(): void {
    \CiviSettingsMock::$values['mollie_reminder_enabled'] = TRUE;
    \CiviSettingsMock::$values['mollie_reminder_days_before'] = 7;

    $nextDate = (new \DateTime('+3 days'))->format('Y-m-d');
    Api4Mock::setResult('ContributionRecur.get', [
      ['id' => 10, 'contact_id' => 200, 'next_sched_contribution_date' => $nextDate],
    ]);
    Api4Mock::setResult('Contact.get', [
      ['display_name' => 'No Email', 'email_primary.email' => ''],
    ]);

    $stats = $this->runner->callRun();

    $this->assertEquals(1, $stats['checked']);
    $this->assertEquals(1, $stats['skipped']);
    $this->assertEquals(0, $stats['sent']);
  }

  // -----------------------------------------------------------------------
  // Send failure
  // -----------------------------------------------------------------------

  public function testSendFailureCountsAsError(): void {
    \CiviSettingsMock::$values['mollie_reminder_enabled'] = TRUE;
    \CiviSettingsMock::$values['mollie_reminder_days_before'] = 7;
    TestableReminderRun::$sendShouldFail = TRUE;

    $nextDate = (new \DateTime('+3 days'))->format('Y-m-d');
    Api4Mock::setResult('ContributionRecur.get', [
      ['id' => 10, 'contact_id' => 200, 'next_sched_contribution_date' => $nextDate],
    ]);
    Api4Mock::setResult('Contact.get', [
      ['display_name' => 'Test', 'email_primary.email' => 'test@example.com'],
    ]);

    $stats = $this->runner->callRun();

    $this->assertEquals(1, $stats['checked']);
    $this->assertEquals(0, $stats['sent']);
    $this->assertEquals(1, $stats['errors']);

    // Activity should NOT be recorded on failure.
    $activityCreates = array_filter(Api4Mock::$calls, fn($c) =>
      $c['entity'] === 'Activity' && $c['action'] === 'create'
    );
    $this->assertEmpty($activityCreates);
  }
}
