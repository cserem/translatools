<?php
/*
* 2007-2013 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2013 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_'))
	exit;

require_once dirname(__FILE__).'/classes/TranslationsExtractor.php';
require_once dirname(__FILE__).'/classes/TranslationsLinter.php';
require_once dirname(__FILE__).'/classes/FilesLister.php';
require_once dirname(__FILE__).'/classes/parsing/SmartyFunctionCallParser.php';
require_once dirname(__FILE__).'/controllers/admin/AdminTranslatoolsController.php';


class TranslaTools extends Module
{
	private $_html = '';
	private $_postErrors = array();

	public $details;
	public $owner;
	public $address;
	public $extra_mail_vars;
	public function __construct()
	{
		$this->name = 'translatools';
		$this->version = '0.6';
		$this->author = 'fmdj';
		$this->tab = 'administration';
		
		//TODO: Add warning curl

		$this->bootstrap = true;
		parent::__construct();	

		$this->displayName = 'TranslaTools';
		$this->description = 'Crowdin integration and more!';
	}

	public function install()
	{
		$ok = parent::install() 
		&& $this->registerHook('displayHeader') 
		&& $this->registerHook('actionAdminControllerSetMedia')
		&& $this->registerHook('displayBackOfficeFooter')
		&& $this->registerHook('displayBackOfficeHeader')
		&& $this->installTab();

		Configuration::updateValue('CROWDIN_PROJECT_IDENTIFIER', 'prestashop-official');
		Configuration::updateValue('JIPT_FO', '1');
		Configuration::updateValue('JIPT_BO', '1');

		$this->createVirtualLanguage();
		$ttc = new AdminTranslatoolsController(true);
		$ttc->ajaxDownloadTranslationsAction(array('only_virtual' => true));

		return $ok;
	}

	public function uninstall()
	{
		return parent::uninstall() && $this->uninstallTab();
	}

	public function installTab()
	{
		$tab = new Tab();
		$tab->active = 1;
		$tab->class_name = "AdminTranslatools";
		$tab->name = array();
		foreach (Language::getLanguages(true) as $lang)
			$tab->name[$lang['id_lang']] = "AdminTranslatools";
		$tab->id_parent = -1;
		$tab->module = $this->name;
		return $tab->add();
	}

	public function uninstallTab()
	{
		$id_tab = (int)Tab::getIdFromClassName('AdminTranslatools');
		if ($id_tab)
		{
			$tab = new Tab($id_tab);
			return $tab->delete();
		}
		else
			return false;
	}

	public function hookDisplayHeader($params)
	{
		if (Configuration::get('CROWDIN_PROJECT_IDENTIFIER') && Configuration::get('JIPT_FO') == '1' && $this->context->language->iso_code === 'an')
		{
			$this->context->controller->addJS('https://cdn.crowdin.net/jipt/jipt.js');
			$this->smarty->assign('CROWDIN_PROJECT_IDENTIFIER', Configuration::get('CROWDIN_PROJECT_IDENTIFIER'));
			return $this->display(__FILE__, 'views/templates/hook/header.tpl');
		}
		else return "";
	}

	public function hookDisplayBackOfficeHeader($params)
	{
		if (Configuration::get('CROWDIN_PROJECT_IDENTIFIER') && Configuration::get('JIPT_FO') == '1' && $this->context->language->iso_code === 'an')
		{
			$this->smarty->assign('CROWDIN_PROJECT_IDENTIFIER', Configuration::get('CROWDIN_PROJECT_IDENTIFIER'));
			return $this->display(__FILE__, 'views/templates/hook/jipt.tpl');
		}
		else return "";
	}

	public function hookActionAdminControllerSetMedia($params)
	{
	}

	public function hookDisplayBackOfficeFooter($params)
	{	
		if (!Configuration::get('JIPT_BO'))
			return;
		
		$live_translation_enabled = ($this->context->cookie->JIPT_PREVIOUS_ID_LANG ? 1 : 0) || $this->context->language->iso_code === 'an';
		
		$this->context->smarty->assign('live_translation_enabled', $live_translation_enabled);
		$this->context->smarty->assign('translatools_controller', $this->context->link->getAdminLink('AdminTranslatools'));
		return $this->display(__FILE__, '/views/templates/hook/backOfficeFooter.tpl');
	}

	public function getContent()
	{
		$action = Tools::getValue('action');
		if ($action == '')
			$action = 'default';

		$method = $action.'Action';
		if (is_callable(array($this, $method)))
		{
			$this->tpl = $action;
			$template_parameters = $this->$method();
			if (is_array($template_parameters))
			{
				$this->context->smarty->assign($template_parameters);
			}
			if (file_exists($tpl_path=dirname(__FILE__).'/views/'.$this->tpl.'.tpl')
				||
				file_exists($tpl_path=dirname(__FILE__).'/views/templates/admin/translatools/'.$this->tpl.'.tpl'))
			{
				$this->assignDefaultSmartyParameters();
				return $this->context->smarty->fetch($tpl_path);
			}
			else
				return "Could not find template for: '$action'";
		}
		else
		{
			return "Unknown action: '$action'.";
		}

	}

	public function getNativeModules()
	{
		return array_map('trim', 
			explode("\n", 
				Tools::file_get_contents(dirname(__FILE__).'/data/native_modules')
			)
		);
	}

	public function getPackVersion()
	{
		$m = array();
		if (preg_match('/^(\d+\.\d+)/', _PS_VERSION_, $m))
		{
			return $m[1];
		}
		return _PS_VERSION_;
	}

	public function defaultAction()
	{
		$modules_not_found = array();

		foreach ($this->getNativeModules() as $module)
		{
			if (!is_dir(_PS_MODULE_DIR_.$module))
				$modules_not_found[] = $module;
		}

		if (count($modules_not_found) > 0)
		{
			$install_link = $this->context->link->getAdminLink('AdminModules').'&install='.implode('|', $modules_not_found);
			$modules_not_found_warning = 
			"The following native modules were not found in your installation: "
			.implode(', ', $modules_not_found).'.'
			."&nbsp;<a target='_blank' href='$install_link'>Try to install them</a> automatically.";
		}
		else
			$modules_not_found_warning = false;

		$themes = array();
		foreach (scandir(_PS_ALL_THEMES_DIR_) as $entry)
			if (!preg_match('/^\./', $entry) && is_dir(_PS_ALL_THEMES_DIR_.$entry))
				$themes[] = $entry;


		$languages = array();
		foreach (Language::getLanguages() as $l)
			$languages[$l['iso_code']] = $l['name'];


		if ($_SERVER['REQUEST_METHOD'] === 'POST' && Tools::getValue('update_api_settings'))
		{
			Configuration::updateValue('CROWDIN_PROJECT_IDENTIFIER', Tools::getValue('CROWDIN_PROJECT_IDENTIFIER'));
			Configuration::updateValue('CROWDIN_PROJECT_API_KEY', Tools::getValue('CROWDIN_PROJECT_API_KEY'));
			Tools::redirectAdmin($_SERVER['REQUEST_URI']);
		}

		return array(
			'themes' => $themes,
			'languages' => $languages,
			'jipt_bo' => Configuration::get('JIPT_BO'),
			'jipt_fo' => Configuration::get('JIPT_FO'),
			'modules_not_found_warning' => $modules_not_found_warning,
			'jipt_language' => 'an',
			'CROWDIN_PROJECT_IDENTIFIER' => Configuration::get('CROWDIN_PROJECT_IDENTIFIER'),
			'CROWDIN_PROJECT_API_KEY' => Configuration::get('CROWDIN_PROJECT_API_KEY')
		);
	}

	public function assignDefaultSmartyParameters()
	{
		$hidden = array(
			'token' => Tools::getValue('token'),
			'configure' => $this->name,
			'controller' => 'AdminModules'
		);

		$inputs = array();
		$params = array();
		foreach ($hidden as $name => $value)
		{
			$inputs[] = "<input type='hidden' name='$name' value='$value'>";
			$params[] = urlencode($name).'='.urlencode($value);
		}
		$translatools_stay_here = implode("\n", $inputs);
		$translatools_url = '?'.implode('&', $params);

		$this->context->smarty->assign('translatools_stay_here', $translatools_stay_here); 
		$this->context->smarty->assign('translatools_url', $translatools_url);
		$this->context->smarty->assign('translatools_controller', $this->context->link->getAdminLink('AdminTranslatools'));
	}

	public function exportTranslationsAction()
	{
		if (Tools::getValue('filter_modules') === 'native')
			$module_filter = $this->getNativeModules();
		else
			$module_filter = null;

		$extractor = new TranslationsExtractor();
		$extractor->setSections(Tools::getValue('section'));
		$extractor->setRootDir(_PS_ROOT_DIR_);
		$extractor->setTheme(Tools::getValue('theme'));
		$extractor->setLanguage(Tools::getValue('language'));
		$extractor->setModuleParsingBehaviour(Tools::getValue('overriden_modules'), Tools::getValue('modules_storage'));
		$extractor->setModuleFilter($module_filter);
		$dir = FilesLister::join(dirname(__FILE__), 'packs');
		$extractor->extract($dir);
		$extractor->sendAsGZIP($dir);
	}

	public function viewStatsAction()
	{
		$extractor = new TranslationsExtractor();
		$extractor->setSections(Tools::getValue('section'));
		$extractor->setRootDir(_PS_ROOT_DIR_);
		$extractor->setTheme(Tools::getValue('theme'));
		$extractor->setModuleParsingBehaviour(Tools::getValue('overriden_modules'), Tools::getValue('modules_storage'));
		$extractor->extract();

		$files = $extractor->getFiles();

		$stats = array();

		foreach ($files as $name => $data)
		{
			$stats[$name] = array(
				'total' => count($data)
			); 
		}

		return array(
			'stats' => $stats
		);
	}

	public function purgeTranslationsAction()
	{
		$tokill = array();
		$killed = array();

		foreach (FilesLister::listFiles(_PS_ROOT_DIR_, null, null, true) as $file)
		{
			if (preg_match('#/translations/[a-z]{2}/(?:admin|errors|pdf|fields|tabs)\.php$#', $file))
				$tokill[] = $file;
			elseif (preg_match('#/(?:translations|lang)/[a-z]{2}\.php$#', $file))
				$tokill[] = $file;
		}

		foreach ($tokill as $path)
		{
			unlink($path);
			$killed[] = substr($path, Tools::strlen(_PS_ROOT_DIR_)+1);
		}

		return array('killed' => $killed);
	}

	public function setConfigurationValueAction()
	{
		$key = Tools::getValue('key');
		// Don't let users abuse this to change anything, whitelist the options
		if (in_array($key, array('JIPT_BO', 'JIPT_FO', 'CROWDIN_PROJECT_IDENTIFIER', 'CROWDIN_PROJECT_API_KEY')))
			Configuration::updateValue($key, Tools::getValue('value'));
		die();
	}

	public function createVirtualLanguage()
	{
		if (!Language::getIdByIso('an'))
		{
			$language = new Language();
			$language->iso_code = 'an';
			$language->language_code = 'an';
			$language->name = 'Aragonese';
			$language->save();
			if ($language->id)
				copy(dirname(__FILE__).'/img/an.jpg', _PS_IMG_DIR_.'/l/'.$language->id.'.jpg');
		}
	}

	public function createVirtualLanguageAction()
	{
		$this->tpl = 'default';

		$this->createVirtualLanguage();

		Tools::redirectAdmin('?controller=AdminModules&configure='.$this->name.'&token='.Tools::getValue('token'));
	}

	// Crowdin => PrestaShop
	public static $languageMapping = array(
		'zh-CN' => 'zh',
		'sr-CS' => 'sr'
	);

	public function getPrestaShopLanguageCode($foreignCode)
	{
		if (isset(self::$languageMapping[$foreignCode])) 
			return self::$$languageMapping[$foreignCode];
		else
			return $foreignCode;
	}

	public function getCrowdinLanguageCode($prestashopCode)
	{
		static $reverseLanguageMapping;
		if (!is_array($reverseLanguageMapping))
		{
			$reverseLanguageMapping = array();
			foreach (static::$languageMapping as $crowdin => $prestashop)
			{
				$reverseLanguageMapping[$prestashop] = $crowdin;
			}
		}
		if (isset($reverseLanguageMapping[$prestashopCode])) 
			return $reverseLanguageMapping[$prestashopCode];
		else
			return $prestashopCode;
	}

	public function importTranslationFile($path, $contents, $languages = array())
	{
		// Guess language code
		$m = array();
		$lc = null;
		if (preg_match('#(?:^|/)translations/([^/]+)/(?:admin|errors|pdf|tabs)\.php$#', $path, $m))
			$lc = $m[1];
		elseif(preg_match('#(?:^|/)modules/(?:[^/]+)/translations/(.*?)\.php$#', $path, $m))
			$lc = $m[1];
		elseif(preg_match('#^themes/(?:[^/]+)/lang/(.*?)\.php$#', $path, $m))
			$lc = $m[1];
		elseif(preg_match('#mails/([^/]+)/lang.php$#', $path, $m))
			$lc = $m[1];
		elseif(basename($path) === 'lang_content.php')
			return true;

		if ($lc === null)
			return "Could not infer language code from file named '$path'.";

		// Remove empty lines, just in case
		$contents = preg_replace('/^\\s*\\$?\\w+\\s*\\[\\s*\'((?:\\\\\'|[^\'])+)\'\\s*\\]\\s*=\\s*\'\'\\s*;$/m', '', $contents);
		

		$languageCode = $this->getPrestaShopLanguageCode($lc);

		if (count($languages) > 0 && !in_array($languageCode, $languages))
			return true;

		if ($languageCode === null)
			return "Could not map language code '$lc' to a PrestaShop code.";

		$path = str_replace(
			array("/$lc/", "/$lc.php"),
			array("/$languageCode/", "/$languageCode.php"),
			$path
		);

		$full_path = _PS_ROOT_DIR_.'/'.$path;
		$dir = dirname($full_path);

		if (!is_dir($dir))
			if (!@mkdir($dir, 0777, true))
				return "Could not create directory for file '$path'.";

		file_put_contents($full_path, $contents);

		$this->postProcessTranslationFile($languageCode, $full_path);

		return true;
	}

	public function postProcessTranslationFile($language_code, $full_path)
	{
		if (basename($full_path) === 'tabs.php' && $language_code === 'an')
		{
			$te = new TranslationsExtractor();
			foreach ($te->parseDictionary($full_path) as $class => $name)
			{
				// Unescape the quotes
				$name = preg_replace('/\\\*\'/', '\'', $name);

				$id_lang = Language::getIdByIso($language_code);

				if ($id_lang)
				{
					$sql = 'SELECT id_tab FROM '._DB_PREFIX_.'tab WHERE class_name=\''.pSQL($class).'\'';
					$id_tab = Db::getInstance()->getValue($sql);

					if ($id_tab)
					{
						// DELETE old tab name in case it exists
						Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.'tab_lang WHERE id_tab='.(int)$id_tab.' AND id_lang='.(int)$id_lang);
						// INSERT new tab name
						Db::getInstance()->execute('INSERT INTO '._DB_PREFIX_.'tab_lang (id_tab, id_lang, name) VALUES ('.(int)$id_tab.','.(int)$id_lang.',\''.pSQL($name).'\')');
					}
				}
			}
		}
	}

	public function checkLUseAction()
	{
		$linter = new TranslationsLinter();
		return $linter->checkLUse();
	}

	public function checkCoherenceAction()
	{
		$linter = new TranslationsLinter();
		return $linter->checkCoherence();
	}	
}
