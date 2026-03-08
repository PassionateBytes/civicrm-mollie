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
    $mollieId = CRM_Utils_Request::retrieve('mollie_id', 'String', $this, TRUE);
    $customerId = CRM_Utils_Request::retrieve('cid', 'String', $this, FALSE, '');
    try {
      $query = \Civi\Api4\MollieDetail::get(FALSE)
        ->setMollieId($mollieId);
      if (!empty($customerId)) {
        $query->setCustomerId($customerId);
      }
      $result = $query->execute()->first();
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
    $this->assign('fields', $result['fields']);

    CRM_Utils_System::setTitle(E::ts('Mollie %1: %2', [
      1 => $typeLabels[$result['type']] ?? $result['type'],
      2 => $mollieId,
    ]));

    parent::run();
  }

}
