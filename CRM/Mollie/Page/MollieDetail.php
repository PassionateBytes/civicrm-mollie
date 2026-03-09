<?php

use CRM_Mollie_ExtensionUtil as E;

/**
 * Read-only detail page for a Mollie resource (payment, subscription, customer).
 *
 * Fetches live data from the Mollie API and renders it as a key-value
 * definition list. Designed to be opened as a CiviCRM modal popup from
 * the Mollie Payment Dashboard.
 */
class CRM_Mollie_Page_MollieDetail extends CRM_Core_Page {

  /**
   * Render the Mollie resource detail page.
   */
  public function run(): void {
    $apiPath = CRM_Utils_Request::retrieve('api_path', 'String', $this, FALSE, '');

    if (empty($apiPath)) {
      $apiPath = self::buildApiPath(
        CRM_Utils_Request::retrieve('mollie_id', 'String', $this, TRUE),
        CRM_Utils_Request::retrieve('cid', 'String', $this, FALSE, '') ?? '',
      );
    }

    try {
      $result = \Civi\Api4\MollieDetail::get(FALSE)
        ->setApiPath($apiPath)
        ->execute()
        ->first();
    }
    catch (\CRM_Core_Exception $e) {
      $this->assign('error', $e->getMessage());
      parent::run();
      return;
    }

    $typeLabels = [
      'payment' => E::ts('Payment'),
      'subscription' => E::ts('Subscription'),
      'customer' => E::ts('Customer'),
    ];

    $this->assign('resourceType', $typeLabels[$result['type']] ?? $result['type']);
    $this->assign('mollieId', $result['mollie_id']);
    $this->assign('dashboardUrl', $result['dashboard_url']);
    $this->assign('relatedLinks', $result['related_links']);
    $this->assign('documentationUrl', $result['documentation_url']);
    $this->assign('fields', $result['fields']);

    CRM_Utils_System::setTitle(E::ts('Mollie %1: %2', [
      1 => $typeLabels[$result['type']] ?? $result['type'],
      2 => $result['mollie_id'],
    ]));

    parent::run();
  }

  /**
   * Build a Mollie API URL from a resource ID.
   *
   * @param string $mollieId
   * @param string $customerId
   *
   * @return string
   */
  protected static function buildApiPath(string $mollieId, string $customerId = ''): string {
    if (str_starts_with($mollieId, 'tr_')) {
      return "payments/$mollieId";
    }
    if (str_starts_with($mollieId, 'cst_')) {
      return "customers/$mollieId";
    }
    if (str_starts_with($mollieId, 'sub_') && !empty($customerId)) {
      return "customers/$customerId/subscriptions/$mollieId";
    }

    throw new \CRM_Core_Exception(E::ts('Cannot construct API path for: %1', [1 => $mollieId]));
  }

}
