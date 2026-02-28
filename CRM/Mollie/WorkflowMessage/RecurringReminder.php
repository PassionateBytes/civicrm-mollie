<?php

use Civi\WorkflowMessage\GenericWorkflowMessage;
use CRM_Mollie_ExtensionUtil as E;

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
   * Recurring contribution amount (formatted).
   *
   * @var string
   * @scope tplParams
   */
  public $recurAmount;

  /**
   * Recurring contribution currency.
   *
   * @var string
   * @scope tplParams
   */
  public $recurCurrency;

  /**
   * Frequency description (e.g. "1 month").
   *
   * @var string
   * @scope tplParams
   */
  public $recurFrequency;

  /**
   * Next scheduled charge date (formatted for display).
   *
   * @var string
   * @scope tplParams
   */
  public $nextChargeDate;

  /**
   * ContributionRecur ID.
   *
   * @var int
   * @scope tplParams
   */
  public $contributionRecurId;

}
