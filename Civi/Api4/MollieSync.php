<?php

namespace Civi\Api4;

/**
 * Mollie subscription synchronization utilities.
 *
 * Provides the `run` action used by the MollieSync scheduled job
 * to reconcile Mollie subscription statuses with CiviCRM.
 *
 * @searchable none
 * @package Civi\Api4
 */
class MollieSync extends Generic\AbstractEntity {
  /**
   * Run the Mollie subscription sync job.
   *
   * @param bool $checkPermissions
   *
   * @return Action\MollieSync\Run
   */
  public static function run(bool $checkPermissions = true): Action\MollieSync\Run {
    return (new Action\MollieSync\Run(__CLASS__, __FUNCTION__))
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
