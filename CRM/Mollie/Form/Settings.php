<?php

use CRM_Mollie_ExtensionUtil as E;

/**
 * Administration form for Mollie Payment Processor settings.
 *
 * Accessible at Administer > CiviContribute > Mollie Payments > Settings.
 */
class CRM_Mollie_Form_Settings extends CRM_Core_Form {

  private const BOOLEAN_SETTINGS = [
    'mollie_reminder_enabled',
    'mollie_debug_logging',
  ];

  private const ALL_SETTINGS = [
    'mollie_payment_description',
    'mollie_reminder_enabled',
    'mollie_reminder_days_before',
    'mollie_debug_logging',
  ];

  public function buildQuickForm(): void {
    CRM_Utils_System::setTitle(E::ts('Mollie Payment Processor Settings'));

    $this->add('text', 'mollie_payment_description', E::ts('Payment Description'), ['class' => 'huge'], FALSE);
    $this->addElement('checkbox', 'mollie_reminder_enabled', E::ts('Enable Pre-Payment Reminders'));
    $this->add('text', 'mollie_reminder_days_before', E::ts('Reminder Days Before Charge'), ['size' => 4], FALSE);
    $this->addElement('checkbox', 'mollie_debug_logging', E::ts('Enable Debug Logging'));

    $this->assign('reminderTemplateUrl', $this->getReminderTemplateUrl());

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Save'),
        'isDefault' => TRUE,
      ],
      [
        'type' => 'cancel',
        'name' => E::ts('Cancel'),
      ],
    ]);
  }

  /**
   * Get the URL to edit the editable recurring reminder message template.
   *
   * @return string|null
   *   Edit URL, or null if the template doesn't exist yet.
   */
  private function getReminderTemplateUrl(): ?string {
    $template = \Civi\Api4\MessageTemplate::get(FALSE)
      ->addSelect('id')
      ->addWhere('workflow_name', '=', 'mollie_recurring_reminder')
      ->addWhere('is_reserved', '=', FALSE)
      ->setLimit(1)
      ->execute()
      ->first();

    if ($template) {
      return \CRM_Utils_System::url('civicrm/admin/messageTemplates', [
        'action' => 'browse',
        'reset' => 1,
      ]) . '#/edit?id=' . $template['id'];
    }

    return NULL;
  }

  public function setDefaultValues(): array {
    $defaults = [];
    $settings = \Civi::settings();
    foreach (self::ALL_SETTINGS as $name) {
      $defaults[$name] = $settings->get($name);
    }
    return $defaults;
  }

  public function postProcess(): void {
    $values = $this->exportValues();
    $settings = \Civi::settings();

    foreach (self::ALL_SETTINGS as $name) {
      if (in_array($name, self::BOOLEAN_SETTINGS, TRUE)) {
        $settings->set($name, !empty($values[$name]));
      }
      elseif ($name === 'mollie_reminder_days_before') {
        $settings->set($name, (int) ($values[$name] ?? 7));
      }
      else {
        $settings->set($name, $values[$name] ?? '');
      }
    }

    CRM_Core_Session::setStatus(E::ts('Settings saved.'), E::ts('Mollie'), 'success');
  }

}
