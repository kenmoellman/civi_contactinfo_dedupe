<?php

require_once 'contactinfo_dedupe.civix.php';

use CRM_ContactinfoDedupe_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 */
function contactinfo_dedupe_civicrm_config(&$config): void {
  _contactinfo_dedupe_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 */
function contactinfo_dedupe_civicrm_install(): void {
  _contactinfo_dedupe_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 */
function contactinfo_dedupe_civicrm_enable(): void {
  _contactinfo_dedupe_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_permission().
 */
function contactinfo_dedupe_civicrm_permission(array &$permissions): void {
  $permissions['administer contactinfo dedupe'] = [
    'label' => E::ts('Administer Contact Info Dedupe'),
    'description' => E::ts('Access the Contact Info Dedupe extension settings and tools.'),
  ];
}

/**
 * Implements hook_civicrm_navigationMenu().
 */
function contactinfo_dedupe_civicrm_navigationMenu(array &$menu): void {
  _contactinfo_dedupe_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => E::ts('Contact Info Dedupe'),
    'name' => 'contactinfo_dedupe',
    'url' => null,
    'permission' => 'administer CiviCRM',
    'operator' => null,
    'separator' => 0,
  ]);
  _contactinfo_dedupe_civix_insert_navigation_menu($menu, 'Administer/System Settings/contactinfo_dedupe', [
    'label' => E::ts('Priority Configuration'),
    'name' => 'contactinfo_dedupe_priority',
    'url' => 'civicrm/admin/contactinfo-dedupe/priority',
    'permission' => 'administer CiviCRM',
    'operator' => null,
    'separator' => 0,
  ]);
  _contactinfo_dedupe_civix_insert_navigation_menu($menu, 'Administer/System Settings/contactinfo_dedupe', [
    'label' => E::ts('Dedupe Search'),
    'name' => 'contactinfo_dedupe_search',
    'url' => 'civicrm/admin/contactinfo-dedupe/search',
    'permission' => 'administer CiviCRM',
    'operator' => null,
    'separator' => 0,
  ]);
  _contactinfo_dedupe_civix_insert_navigation_menu($menu, 'Administer/System Settings/contactinfo_dedupe', [
    'label' => E::ts('Audit Log'),
    'name' => 'contactinfo_dedupe_audit',
    'url' => 'civicrm/admin/contactinfo-dedupe/audit',
    'permission' => 'administer CiviCRM',
    'operator' => null,
    'separator' => 0,
  ]);
}
