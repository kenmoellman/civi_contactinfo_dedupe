<?php

// AUTO-GENERATED FILE -- Civix may overwrite any changes made to this file

/**
 * The ExtensionUtil class provides small stubs for accessing resources of this
 * extension.
 */
class CRM_ContactinfoDedupe_ExtensionUtil {
  const SHORT_NAME = 'contactinfo_dedupe';
  const LONG_NAME = 'contactinfo_dedupe';
  const CLASS_PREFIX = 'CRM_ContactinfoDedupe';

  /**
   * Translate a string using the extension's domain.
   *
   * @param string $text
   *   Canonical message text (generally en_US).
   * @param array $params
   * @return string
   *   Translated text.
   * @see ts
   */
  public static function ts($text, $params = []): string {
    if (!array_key_exists('domain', $params)) {
      $params['domain'] = [self::LONG_NAME, NULL];
    }
    return ts($text, $params);
  }

  /**
   * Get the URL of a resource file (in this extension).
   *
   * @param string|NULL $file
   * @return string
   */
  public static function url($file = NULL): string {
    if ($file === NULL) {
      return rtrim(CRM_Core_Resources::singleton()->getUrl(self::LONG_NAME), '/');
    }
    return CRM_Core_Resources::singleton()->getUrl(self::LONG_NAME, $file);
  }

  /**
   * Get the path of a resource file (in this extension).
   *
   * @param string|NULL $file
   * @return string
   */
  public static function path($file = NULL) {
    return __DIR__ . ($file === NULL ? '' : (DIRECTORY_SEPARATOR . $file));
  }

  /**
   * Get the name of a class within this extension.
   *
   * @param string $suffix
   * @return string
   */
  public static function findClass($suffix) {
    return self::CLASS_PREFIX . '_' . str_replace('\\', '_', $suffix);
  }

  /**
   * @return \CiviMix\Schema\SchemaHelperInterface
   */
  public static function schema() {
    if (!isset($GLOBALS['CiviMixSchema'])) {
      pathload()->loadPackage('civimix-schema@5', TRUE);
    }
    return $GLOBALS['CiviMixSchema']->getHelper(static::LONG_NAME);
  }

}

use CRM_ContactinfoDedupe_ExtensionUtil as E;

pathload()->addSearchDir(__DIR__ . '/mixin/lib');
spl_autoload_register('_contactinfo_dedupe_civix_class_loader', TRUE, TRUE);

function _contactinfo_dedupe_civix_class_loader($class) {
  if ($class === 'CRM_ContactinfoDedupe_DAO_Base') {
    if (version_compare(CRM_Utils_System::version(), '5.74.beta', '>=')) {
      class_alias('CRM_Core_DAO_Base', 'CRM_ContactinfoDedupe_DAO_Base');
    }
    else {
      $realClass = 'CiviMix\\Schema\\ContactinfoDedupe\\DAO';
      class_alias($realClass, $class);
    }
    return;
  }

  if (strpos($class, 'CiviMix\\Schema\\ContactinfoDedupe\\') === 0) {
    pathload()->loadPackage('civimix-schema@5', TRUE);
    CiviMix\Schema\loadClass($class);
  }
}

/**
 * (Delegated) Implements hook_civicrm_config().
 */
function _contactinfo_dedupe_civix_civicrm_config($config = NULL) {
  static $configured = FALSE;
  if ($configured) {
    return;
  }
  $configured = TRUE;

  $extRoot = __DIR__ . DIRECTORY_SEPARATOR;
  $include_path = $extRoot . PATH_SEPARATOR . get_include_path();
  set_include_path($include_path);
}

/**
 * Implements hook_civicrm_install().
 */
function _contactinfo_dedupe_civix_civicrm_install() {
  _contactinfo_dedupe_civix_civicrm_config();
}

/**
 * (Delegated) Implements hook_civicrm_enable().
 */
function _contactinfo_dedupe_civix_civicrm_enable(): void {
  _contactinfo_dedupe_civix_civicrm_config();
}

/**
 * Inserts a navigation menu item at a given place in the hierarchy.
 */
function _contactinfo_dedupe_civix_insert_navigation_menu(&$menu, $path, $item) {
  if (empty($path)) {
    $menu[] = [
      'attributes' => array_merge([
        'label' => $item['name'] ?? NULL,
        'active' => 1,
      ], $item),
    ];
    return TRUE;
  }
  else {
    $found = FALSE;
    $path = explode('/', $path);
    $first = array_shift($path);
    foreach ($menu as $key => &$entry) {
      if ($entry['attributes']['name'] == $first) {
        if (!isset($entry['child'])) {
          $entry['child'] = [];
        }
        $found = _contactinfo_dedupe_civix_insert_navigation_menu($entry['child'], implode('/', $path), $item);
      }
    }
    return $found;
  }
}
