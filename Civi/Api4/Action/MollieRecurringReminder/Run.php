<?php

namespace Civi\Api4\Action\MollieRecurringReminder;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use CRM_Mollie_ExtensionUtil as E;

/**
 * Run the Mollie recurring reminder job.
 *
 * Sends reminder emails to donors before their next recurring charge date.
 * Only runs if reminders are enabled in extension settings.
 */
class Run extends AbstractAction {

  /**
   * @param Result $result
   */
  public function _run(Result $result): void {
    $stats = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0];

    if (!\Civi::settings()->get('mollie_reminder_enabled')) {
      \CRM_Mollie_Log::info('MollieRecurringReminder: reminders disabled, skipping');
      $result[] = $stats;
      return;
    }

    $daysBefore = (int) (\Civi::settings()->get('mollie_reminder_days_before') ?? 7);
    $windowStart = new \DateTime('now');
    $windowEnd = (new \DateTime('now'))->modify("+{$daysBefore} days");

    $activeRecurs = ContributionRecur::get(FALSE)
      ->addSelect('id', 'contact_id', 'amount', 'currency', 'frequency_interval', 'frequency_unit', 'next_sched_contribution_date')
      ->addWhere('processor_id', 'LIKE', 'sub_%')
      ->addWhere('contribution_status_id:name', 'IN', ['In Progress', 'Pending'])
      ->addWhere('is_test', 'IN', [0, 1])
      ->addWhere('next_sched_contribution_date', '>=', $windowStart->format('Y-m-d'))
      ->addWhere('next_sched_contribution_date', '<=', $windowEnd->format('Y-m-d 23:59:59'))
      ->addJoin('PaymentProcessorType AS ppt', 'INNER',
        ['payment_processor_id.payment_processor_type_id', '=', 'ppt.id'],
        ['ppt.name', '=', '"mollie"']
      )
      ->execute();

    $activityTypeId = self::getReminderActivityTypeId();

    foreach ($activeRecurs as $recur) {
      $stats['checked']++;

      try {
        if (self::reminderAlreadySent($recur['id'], $recur['next_sched_contribution_date'], $activityTypeId)) {
          $stats['skipped']++;
          continue;
        }

        $contact = Contact::get(FALSE)
          ->addSelect('display_name', 'first_name', 'email_primary.email')
          ->addWhere('id', '=', $recur['contact_id'])
          ->setLimit(1)
          ->execute()
          ->first();

        if (empty($contact['email_primary.email'])) {
          \CRM_Mollie_Log::warning("Reminder: Contact #{$recur['contact_id']} has no email (ContributionRecur #{$recur['id']})", [
            'contact_id' => $recur['contact_id'],
            'contribution_recur_id' => $recur['id'],
          ]);
          $stats['skipped']++;
          continue;
        }

        self::sendReminder($recur, $contact);
        self::recordReminderActivity($recur, $activityTypeId);

        $stats['sent']++;
      }
      catch (\Exception $e) {
        $stats['errors']++;
        \CRM_Mollie_Log::error("Reminder: failed to send for ContributionRecur #{$recur['id']}: {$e->getMessage()}", [
          'contribution_recur_id' => $recur['id'],
          'error' => $e->getMessage(),
        ]);
      }
    }

    \CRM_Mollie_Log::info('MollieRecurringReminder completed', $stats);

    $result[] = $stats;
  }

  /**
   * Send the reminder email using the workflow message template.
   *
   * @param array $recur
   * @param array $contact
   */
  protected static function sendReminder(array $recur, array $contact): void {
    $message = new \CRM_Mollie_WorkflowMessage_RecurringReminder([
      'modelProps' => [
        'contactID' => $recur['contact_id'],
        'contributionRecurId' => $recur['id'],
      ],
    ]);

    $result = $message->sendTemplate([
      'contactId' => $recur['contact_id'],
      'toEmail' => $contact['email_primary.email'],
      'toName' => $contact['display_name'] ?? '',
    ]);

    $sent = $result[0] ?? FALSE;
    $errorMsg = $result[4] ?? NULL;

    if (!$sent) {
      throw new \RuntimeException('Failed to send reminder email: ' . ($errorMsg ?? 'unknown error'));
    }

    \CRM_Mollie_Log::info("Reminder sent for ContributionRecur #{$recur['id']} to Contact #{$recur['contact_id']} (next charge: {$recur['next_sched_contribution_date']})", [
      'contribution_recur_id' => $recur['id'],
      'contact_id' => $recur['contact_id'],
      'next_charge_date' => $recur['next_sched_contribution_date'],
    ]);
  }

  /**
   * Check if a reminder has already been sent for this billing cycle.
   *
   * @param int $recurId
   * @param string $nextDate
   * @param int $activityTypeId
   *
   * @return bool
   */
  protected static function reminderAlreadySent(int $recurId, string $nextDate, int $activityTypeId): bool {
    $chargeDate = (new \DateTime($nextDate))->format('Y-m-d');

    $count = Activity::get(FALSE)
      ->selectRowCount()
      ->addWhere('activity_type_id', '=', $activityTypeId)
      ->addWhere('source_record_id', '=', $recurId)
      ->addWhere('activity_date_time', '>=', $chargeDate . ' 00:00:00')
      ->addWhere('activity_date_time', '<=', $chargeDate . ' 23:59:59')
      ->execute()
      ->count();

    return $count > 0;
  }

  /**
   * Record an Activity indicating the reminder was sent.
   *
   * @param array $recur
   * @param int $activityTypeId
   */
  protected static function recordReminderActivity(array $recur, int $activityTypeId): void {
    $chargeDate = (new \DateTime($recur['next_sched_contribution_date']))->format('Y-m-d');

    Activity::create(FALSE)
      ->addValue('activity_type_id', $activityTypeId)
      ->addValue('source_record_id', $recur['id'])
      ->addValue('source_contact_id', $recur['contact_id'])
      ->addValue('target_contact_id', $recur['contact_id'])
      ->addValue('activity_date_time', $chargeDate . ' 00:00:00')
      ->addValue('subject', E::ts('Recurring donation reminder sent for %1', [
        1 => \CRM_Utils_Date::customFormat($chargeDate),
      ]))
      ->addValue('status_id:name', 'Completed')
      ->execute();
  }

  /**
   * Get the activity type ID for "Mollie Reminder Sent".
   *
   * @return int
   *
   * @throws \RuntimeException
   */
  protected static function getReminderActivityTypeId(): int {
    $result = \Civi\Api4\OptionValue::get(FALSE)
      ->addSelect('value')
      ->addWhere('option_group_id.name', '=', 'activity_type')
      ->addWhere('name', '=', 'mollie_reminder_sent')
      ->setLimit(1)
      ->execute();

    if ($result->count() === 0) {
      throw new \RuntimeException('Mollie Reminder Sent activity type not found. Is the extension installed correctly?');
    }

    return (int) $result->first()['value'];
  }

}
