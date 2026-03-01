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
