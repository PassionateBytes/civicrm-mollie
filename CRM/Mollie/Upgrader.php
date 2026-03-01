<?php

use CRM_Mollie_ExtensionUtil as E;

/**
 * Collection of upgrade steps for the Mollie extension.
 */
class CRM_Mollie_Upgrader extends CRM_Extension_Upgrader_Base {

  public function install(): void {
    $this->executeSqlFile('sql/install.sql');
  }

  public function uninstall(): void {
    $this->executeSqlFile('sql/uninstall.sql');
  }

}
