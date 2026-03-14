<?php

namespace Civi\Api4;

/**
 * Mollie recurring donation reminder utilities.
 *
 * Provides the `run` action used by the MollieRecurringReminder
 * scheduled job to send pre-payment reminder emails.
 *
 * @searchable none
 * @package Civi\Api4
 */
class MollieRecurringReminder extends Generic\AbstractEntity {
  /**
   * Run the recurring reminder job.
   *
   * @param bool $checkPermissions
   *
   * @return Action\MollieRecurringReminder\Run
   */
  public static function run(bool $checkPermissions = true): Action\MollieRecurringReminder\Run {
    return (new Action\MollieRecurringReminder\Run(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   *
   * @return Generic\BasicGetFieldsAction
   */
  public static function getFields(bool $checkPermissions = true): Generic\BasicGetFieldsAction {
    return (new Generic\BasicGetFieldsAction(__CLASS__, __FUNCTION__, function () {
      return [];
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * @return array
   */
  public static function permissions(): array {
    return [
      'default' => ['administer CiviCRM'],
    ];
  }

}
