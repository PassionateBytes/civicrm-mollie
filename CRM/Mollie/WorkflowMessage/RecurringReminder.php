<?php

use Civi\WorkflowMessage\GenericWorkflowMessage;

/**
 * Workflow message for pre-payment recurring donation reminders.
 *
 * Sends a configurable email to donors before their next recurring charge.
 *
 * @support full
 */
class CRM_Mollie_WorkflowMessage_RecurringReminder extends GenericWorkflowMessage {
  public const WORKFLOW = 'mollie_recurring_reminder';

  /**
   * ContributionRecur ID — provides {contribution_recur.*} tokens.
   *
   * @var int
   * @scope tokenContext as contributionRecurId
   */
  public $contributionRecurId;

}
