<?php

namespace Civi\Api4;

/**
 * MollieCustomer entity.
 *
 * Maps CiviCRM contacts to Mollie customer IDs, required for
 * Mollie's recurring payment flow (Customer -> Mandate -> Subscription).
 *
 * @package Civi\Api4
 */
class MollieCustomer extends Generic\DAOEntity {
  /**
   * @return array
   */
  public static function permissions(): array {
    return [
      'meta' => ['access CiviCRM'],
      'default' => ['administer payment processors'],
      'get' => ['access CiviContribute'],
    ];
  }

}
