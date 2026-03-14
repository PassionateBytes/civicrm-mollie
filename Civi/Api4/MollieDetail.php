<?php

namespace Civi\Api4;

use CRM_Mollie_ExtensionUtil as E;

/**
 * Fetch resource details from the Mollie API.
 *
 * Provides a read-only lookup for payments, subscriptions, and customers
 * by their Mollie ID prefix (tr_, sub_, cst_).
 *
 * Security: this endpoint uses Mollie API keys (not OAuth access tokens),
 * which limits access to payment-profile-level resources (payments,
 * customers, subscriptions, mandates, refunds). Organization-level
 * resources (settlements, balances, invoices, organizations) require OAuth
 * tokens and are rejected server-side by Mollie with 401/403. The proxy
 * is read-only (GET only), so no mutations are possible.
 *
 * @searchable none
 * @package Civi\Api4
 */
class MollieDetail extends Generic\AbstractEntity {

  /**
   * Fetch a single Mollie resource by its ID.
   *
   * @param bool $checkPermissions
   *
   * @return Action\MollieDetail\Get
   */
  public static function get(bool $checkPermissions = TRUE): Action\MollieDetail\Get {
    return (new Action\MollieDetail\Get(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   *
   * @return Generic\BasicGetFieldsAction
   */
  public static function getFields(bool $checkPermissions = TRUE): Generic\BasicGetFieldsAction {
    return (new Generic\BasicGetFieldsAction(__CLASS__, __FUNCTION__, function () {
      return [];
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * @return array
   */
  public static function permissions(): array {
    return [
      'default' => ['access CiviContribute'],
    ];
  }

}
