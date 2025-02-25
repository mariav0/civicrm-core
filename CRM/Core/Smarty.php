<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * Fix for bug CRM-392. Not sure if this is the best fix or it will impact
 * other similar PEAR packages. doubt it
 */
if (!class_exists('Smarty')) {
  require_once 'Smarty/Smarty.class.php';
}

/**
 *
 */
class CRM_Core_Smarty extends Smarty {
  const
    // use print.tpl and bypass the CMS. Civi prints a valid html file
    PRINT_PAGE = 1,
    // this and all the below bypasses the CMS html surrounding it and assumes we will embed this within other pages
    PRINT_SNIPPET = 2,
    // sends the generated html to the chosen pdf engine
    PRINT_PDF = 3,
    // this options also skips the enclosing form html and does not
    // generate any of the hidden fields, most notably qfKey
    // this is typically used in ajax scripts to embed form snippets based on user choices
    PRINT_NOFORM = 4,
    // this prints a complete form and also generates a qfKey, can we replace this with
    // snippet = 2?? Does the constant _NOFFORM do anything?
    PRINT_QFKEY = 5,
    // Note: added in v 4.3 with the value '6'
    // Value changed in 4.5 to 'json' for better readability
    // @see CRM_Core_Page_AJAX::returnJsonResponse
    PRINT_JSON = 'json';

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   */
  static private $_singleton = NULL;

  /**
   * Backup frames.
   *
   * A list of variables ot save temporarily in format (string $name => mixed $value).
   *
   * @var array
   */
  private $backupFrames = [];

  /**
   * Class constructor.
   *
   * @return CRM_Core_Smarty
   */
  public function __construct() {
    parent::__construct();
  }

  private function initialize() {
    $config = CRM_Core_Config::singleton();

    if (isset($config->customTemplateDir) && $config->customTemplateDir) {
      $this->template_dir = array_merge([$config->customTemplateDir],
        $config->templateDir
      );
    }
    else {
      $this->template_dir = $config->templateDir;
    }
    $this->compile_dir = CRM_Utils_File::addTrailingSlash(CRM_Utils_File::addTrailingSlash($config->templateCompileDir) . $this->getLocale());
    CRM_Utils_File::createDir($this->compile_dir);
    CRM_Utils_File::restrictAccess($this->compile_dir);

    // check and ensure it is writable
    // else we sometime suppress errors quietly and this results
    // in blank emails etc
    if (!is_writable($this->compile_dir)) {
      echo "CiviCRM does not have permission to write temp files in {$this->compile_dir}, Exiting";
      exit();
    }

    $this->use_sub_dirs = TRUE;

    $customPluginsDir = NULL;
    if (isset($config->customPHPPathDir)) {
      $customPluginsDir
        = $config->customPHPPathDir . DIRECTORY_SEPARATOR .
        'CRM' . DIRECTORY_SEPARATOR .
        'Core' . DIRECTORY_SEPARATOR .
        'Smarty' . DIRECTORY_SEPARATOR .
        'plugins' . DIRECTORY_SEPARATOR;
      if (!file_exists($customPluginsDir)) {
        $customPluginsDir = NULL;
      }
    }

    $pkgsDir = Civi::paths()->getVariable('civicrm.packages', 'path');
    $smartyDir = $pkgsDir . DIRECTORY_SEPARATOR . 'Smarty' . DIRECTORY_SEPARATOR;
    $pluginsDir = __DIR__ . DIRECTORY_SEPARATOR . 'Smarty' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR;

    if ($customPluginsDir) {
      $this->plugins_dir = [$customPluginsDir, $smartyDir . 'plugins', $pluginsDir];
    }
    else {
      $this->plugins_dir = [$smartyDir . 'plugins', $pluginsDir];
    }

    $this->compile_check = $this->isCheckSmartyIsCompiled();

    // add the session and the config here
    $session = CRM_Core_Session::singleton();

    $this->assign_by_ref('config', $config);
    $this->assign_by_ref('session', $session);

    $tsLocale = CRM_Core_I18n::getLocale();
    $this->assign('tsLocale', $tsLocale);

    // CRM-7163 hack: we don’t display langSwitch on upgrades anyway
    if (!CRM_Core_Config::isUpgradeMode()) {
      $this->assign('langSwitch', CRM_Core_I18n::uiLanguages());
    }

    $this->register_function('crmURL', ['CRM_Utils_System', 'crmURL']);
    $this->load_filter('pre', 'resetExtScope');

    $this->assign('crmPermissions', new CRM_Core_Smarty_Permissions());

    if ($config->debug) {
      $this->error_reporting = E_ALL;
    }
  }

  /**
   * Static instance provider.
   *
   * Method providing static instance of SmartTemplate, as
   * in Singleton pattern.
   *
   * @return \CRM_Core_Smarty
   */
  public static function &singleton() {
    if (!isset(self::$_singleton)) {
      self::$_singleton = new CRM_Core_Smarty();
      self::$_singleton->initialize();

      self::registerStringResource();
    }
    return self::$_singleton;
  }

  /**
   * Executes & returns or displays the template results
   *
   * @param string $resource_name
   * @param string $cache_id
   * @param string $compile_id
   * @param bool $display
   *
   * @return bool|mixed|string
   */
  public function fetch($resource_name, $cache_id = NULL, $compile_id = NULL, $display = FALSE) {
    if (preg_match('/^(\s+)?string:/', $resource_name)) {
      $old_security = $this->security;
      $this->security = TRUE;
    }
    $output = parent::fetch($resource_name, $cache_id, $compile_id, $display);
    if (isset($old_security)) {
      $this->security = $old_security;
    }
    return $output;
  }

  /**
   * Fetch a template (while using certain variables)
   *
   * @param string $resource_name
   * @param array $vars
   *   (string $name => mixed $value) variables to export to Smarty.
   * @throws Exception
   * @return bool|mixed|string
   */
  public function fetchWith($resource_name, $vars) {
    $this->pushScope($vars);
    try {
      $result = $this->fetch($resource_name);
    }
    catch (Exception $e) {
      // simulate try { ... } finally { ... }
      $this->popScope();
      throw $e;
    }
    $this->popScope();
    return $result;
  }

  /**
   * @param string $name
   * @param $value
   */
  public function appendValue($name, $value) {
    $currentValue = $this->get_template_vars($name);
    if (!$currentValue) {
      $this->assign($name, $value);
    }
    else {
      if (strpos($currentValue, $value) === FALSE) {
        $this->assign($name, $currentValue . $value);
      }
    }
  }

  public function clearTemplateVars() {
    foreach (array_keys($this->_tpl_vars) as $key) {
      if ($key == 'config' || $key == 'session') {
        continue;
      }
      unset($this->_tpl_vars[$key]);
    }
  }

  public static function registerStringResource() {
    require_once 'CRM/Core/Smarty/resources/String.php';
    civicrm_smarty_register_string_resource();
  }

  /**
   * @param $path
   */
  public function addTemplateDir($path) {
    if (is_array($this->template_dir)) {
      array_unshift($this->template_dir, $path);
    }
    else {
      $this->template_dir = [$path, $this->template_dir];
    }

  }

  /**
   * Temporarily assign a list of variables.
   *
   * ```
   * $smarty->pushScope(array(
   *   'first_name' => 'Alice',
   *   'last_name' => 'roberts',
   * ));
   * $html = $smarty->fetch('view-contact.tpl');
   * $smarty->popScope();
   * ```
   *
   * @param array $vars
   *   (string $name => mixed $value).
   * @return CRM_Core_Smarty
   * @see popScope
   */
  public function pushScope($vars) {
    $oldVars = $this->get_template_vars();
    $backupFrame = [];
    foreach ($vars as $key => $value) {
      $backupFrame[$key] = $oldVars[$key] ?? NULL;
    }
    $this->backupFrames[] = $backupFrame;

    $this->assignAll($vars);

    return $this;
  }

  /**
   * Remove any values that were previously pushed.
   *
   * @return CRM_Core_Smarty
   * @see pushScope
   */
  public function popScope() {
    $this->assignAll(array_pop($this->backupFrames));
    return $this;
  }

  /**
   * @param array $vars
   *   (string $name => mixed $value).
   * @return CRM_Core_Smarty
   */
  public function assignAll($vars) {
    foreach ($vars as $key => $value) {
      $this->assign($key, $value);
    }
    return $this;
  }

  /**
   * Get the locale for translation.
   *
   * @return string
   */
  private function getLocale() {
    $tsLocale = CRM_Core_I18n::getLocale();
    if (!empty($tsLocale)) {
      return $tsLocale;
    }

    $config = CRM_Core_Config::singleton();
    if (!empty($config->lcMessages)) {
      return $config->lcMessages;
    }

    return 'en_US';
  }

  /**
   * Get the compile_check value.
   *
   * @return bool
   */
  private function isCheckSmartyIsCompiled() {
    // check for define in civicrm.settings.php as FALSE, otherwise returns TRUE
    return CRM_Utils_Constant::value('CIVICRM_TEMPLATE_COMPILE_CHECK', TRUE);
  }

}
