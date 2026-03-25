<?php

use CRM_ContactinfoDedupe_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_ContactinfoDedupe_Upgrader extends CRM_Extension_Upgrader_Base {

  /**
   * Run the auto_install.sql on extension install.
   */
  public function install(): void {
    $this->executeSqlFile('sql/auto_install.sql');
  }

  /**
   * Run the auto_uninstall.sql on extension uninstall.
   */
  public function uninstall(): void {
    $this->executeSqlFile('sql/auto_uninstall.sql');
  }

}
