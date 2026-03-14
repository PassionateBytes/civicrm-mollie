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
    $apiPath = CRM_Utils_Request::retrieve('api_path', 'String', $this, true);

    try {
      $result = \Civi\Api4\MollieDetail::get(false)
        ->setApiPath($apiPath)
        ->execute()
        ->first();
    } catch (\CRM_Core_Exception $e) {
      $this->assign('error', $e->getMessage());
      parent::run();
      return;
    }

    $typeLabels = [
      'payment' => E::ts('Payment'),
      'subscription' => E::ts('Subscription'),
      'customer' => E::ts('Customer'),
    ];

    $isList = !empty($result['is_list']);
    $typeLabel = $typeLabels[$result['type']] ?? $result['type'];

    $this->assign('isList', $isList);
    $this->assign('resourceType', $typeLabel);
    $this->assign('mollieId', $result['mollie_id']);
    $this->assign('dashboardUrl', $result['dashboard_url']);
    $this->assign('relatedLinks', $result['related_links']);
    $this->assign('documentationUrl', $result['documentation_url']);
    $this->assign('fields', $result['fields']);

    if ($isList) {
      $this->assign('items', $result['items']);
      $this->assign('pagination', $result['pagination']);
      CRM_Utils_System::setTitle(E::ts('Mollie %1', [1 => $typeLabel]));
    } else {
      CRM_Utils_System::setTitle(E::ts('Mollie %1: %2', [
        1 => $typeLabel,
        2 => $result['mollie_id'],
      ]));
    }

    parent::run();
  }

}
