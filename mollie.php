<?php

require_once 'mollie.civix.php';


/**
 * Implements hook_civicrm_config().
 */
function mollie_civicrm_config(&$config): void {
  _mollie_civix_civicrm_config($config);

  static $autoloaded = false;
  if (!$autoloaded) {
    $autoloaded = true;
    $autoloadFile = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoloadFile)) {
      require_once $autoloadFile;
    }
  }
}

/**
 * Implements hook_civicrm_install().
 */
function mollie_civicrm_install(): void {
  _mollie_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 */
function mollie_civicrm_enable(): void {
  _mollie_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_buildForm().
 */
function mollie_civicrm_buildForm(string $formName, CRM_Core_Form &$form): void {
  _mollie_buildForm_paymentProcessor($formName, $form);
  _mollie_buildForm_contributionView($formName, $form);
}

/**
 * Implements hook_civicrm_pageRun().
 */
function mollie_civicrm_pageRun(CRM_Core_Page &$page): void {
  _mollie_pageRun_contributionRecur($page);
}

/**
 * Hide unused URL fields on the Mollie payment processor config form.
 *
 * The SDK hardcodes the API endpoint, and test/live mode is determined by
 * the API key prefix, so these fields are irrelevant.
 */
function _mollie_buildForm_paymentProcessor(string $formName, CRM_Core_Form &$form): void {
  if ($formName !== 'CRM_Admin_Form_PaymentProcessor') {
    return;
  }
  if ($form->getTemplateVars('ppTypeName') !== 'mollie') {
    return;
  }
  $hide = ['url_site', 'url_recur', 'test_url_site', 'test_url_recur'];
  foreach ($hide as $field) {
    if ($form->elementExists($field)) {
      $form->removeElement($field);
    }
  }
}

/**
 * Add Mollie payment details and "Open in Mollie" button to ContributionView.
 *
 * Injects a "Mollie Payment" info row (linking to MollieDetail modal) and an
 * "Open in Mollie" button (linking directly to the Mollie dashboard) via jQuery.
 */
function _mollie_buildForm_contributionView(string $formName, CRM_Core_Form &$form): void {
  if ($formName !== 'CRM_Contribute_Form_ContributionView') {
    return;
  }

  $paymentProcessorId = $form->getTemplateVars('payment_processor_id');
  if (empty($paymentProcessorId) || !CRM_Mollie_Utils::isMollieProcessor((int) $paymentProcessorId)) {
    return;
  }

  $trxnId = $form->getTemplateVars('trxn_id');
  if (empty($trxnId)) {
    return;
  }

  $detailUrl = CRM_Utils_System::url('civicrm/admin/mollie/detail', 'api_path=payments/' . urlencode($trxnId));
  $dashboardUrl = CRM_Mollie_Utils::getPaymentDashboardUrl($trxnId);
  $label = CRM_Mollie_ExtensionUtil::ts('Mollie Payment');
  $buttonLabel = CRM_Mollie_ExtensionUtil::ts('Open in Mollie');

  $js = sprintf(
    'CRM.$(function($) {
      var $idRow = $("tr.crm-contribution-form-block-id");
      if ($idRow.length) {
        $idRow.after("<tr class=\"crm-contribution-form-block-mollie_payment_id\">"
          + "<td class=\"label\">%s</td>"
          + "<td><a href=\"%s\" class=\"crm-popup\"><i class=\"crm-i fa-external-link\"></i> %s</a></td>"
          + "</tr>");
      }

      $("div.crm-submit-buttons")
        .prepend("<a class=\"button\" href=\"%s\" onclick=\"window.open(this.href); return false;\">"
          + "<span><i class=\"crm-i fa-external-link\"></i> %s</span></a>");
    });',
    CRM_Utils_String::purifyHTML($label),
    CRM_Utils_String::purifyHTML($detailUrl),
    CRM_Utils_String::purifyHTML($trxnId),
    CRM_Utils_String::purifyHTML($dashboardUrl),
    CRM_Utils_String::purifyHTML($buttonLabel),
  );

  Civi::resources()->addScript($js);
}

/**
 * Add Mollie details and "Open in Mollie" button to ContributionRecur view.
 *
 * Enhances the Processor ID row to link to the subscription MollieDetail modal,
 * adds a Mollie Customer row linking to the customer MollieDetail modal, and
 * prepends an "Open in Mollie" button linking to the customer's Mollie dashboard.
 */
function _mollie_pageRun_contributionRecur(CRM_Core_Page &$page): void {
  if (!($page instanceof CRM_Contribute_Page_ContributionRecur)) {
    return;
  }

  $recur = $page->getTemplateVars('recur');
  if (empty($recur['payment_processor_id']) || !CRM_Mollie_Utils::isMollieProcessor((int) $recur['payment_processor_id'])) {
    return;
  }

  $processorId = $recur['processor_id'] ?? '';
  if (empty($processorId)) {
    return;
  }

  $contactId = (int) ($recur['contact_id'] ?? 0);
  $mollieCustomerId = CRM_Mollie_Utils::getMollieCustomerId($contactId, (int) $recur['payment_processor_id']);
  if (empty($mollieCustomerId)) {
    return;
  }

  $subscriptionDetailUrl = CRM_Utils_System::url(
    'civicrm/admin/mollie/detail',
    'api_path=customers/' . urlencode($mollieCustomerId) . '/subscriptions/' . urlencode($processorId),
  );
  $customerDetailUrl = CRM_Utils_System::url(
    'civicrm/admin/mollie/detail',
    'api_path=customers/' . urlencode($mollieCustomerId),
  );
  $customerDashboardUrl = CRM_Mollie_Utils::getCustomerDashboardUrl($mollieCustomerId);

  $subscriptionLabel = CRM_Mollie_ExtensionUtil::ts('Mollie Subscription');
  $customerLabel = CRM_Mollie_ExtensionUtil::ts('Mollie Customer');
  $buttonLabel = CRM_Mollie_ExtensionUtil::ts('Open in Mollie');

  $js = sprintf(
    'CRM.$(function($) {
      var $pidRow = $("td.label").filter(function() { return $.trim($(this).text()) === "Processor ID"; }).closest("tr");
      $pidRow.hide();

      var $anchor = $("td.label").filter(function() { return $.trim($(this).text()) === "Modified Date"; }).closest("tr");
      if (!$anchor.length) { $anchor = $("td.label").filter(function() { return $.trim($(this).text()) === "Created Date"; }).closest("tr"); }

      if ($anchor.length) {
        $anchor.after("<tr><td class=\"label\">%s</td>"
          + "<td><a href=\"%s\" class=\"crm-popup\"><i class=\"crm-i fa-external-link\"></i> %s</a></td></tr>");
        $anchor.next().after("<tr><td class=\"label\">%s</td>"
          + "<td><a href=\"%s\" class=\"crm-popup\"><i class=\"crm-i fa-external-link\"></i> %s</a></td></tr>");
      }

      $("div.crm-submit-buttons")
        .prepend("<a class=\"button\" href=\"%s\" onclick=\"window.open(this.href); return false;\">"
          + "<span><i class=\"crm-i fa-external-link\"></i> %s</span></a> ");
    });',
    CRM_Utils_String::purifyHTML($customerLabel),
    CRM_Utils_String::purifyHTML($customerDetailUrl),
    CRM_Utils_String::purifyHTML($mollieCustomerId),
    CRM_Utils_String::purifyHTML($subscriptionLabel),
    CRM_Utils_String::purifyHTML($subscriptionDetailUrl),
    CRM_Utils_String::purifyHTML($processorId),
    CRM_Utils_String::purifyHTML($customerDashboardUrl),
    CRM_Utils_String::purifyHTML($buttonLabel),
  );

  Civi::resources()->addScript($js);
}
