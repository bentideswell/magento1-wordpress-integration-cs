<?php
/**
 * @category Fishpig
 * @package Fishpig_Wordpress
 * @license http://fishpig.co.uk/license.txt
 * @author Ben Tideswell <ben@fishpig.co.uk>
 */

class Fishpig_Wordpress_Addon_CS_Helper_Core extends Mage_Core_Helper_Abstract
{
	/**
	 * Status key for register
	 *
	 * @const string
	 */
	const STATUS_KEY = 'wordpress_core_status';

	/**
	 * Variables used for WordPress environment simulation
	 *
	 * @var array|false
	 **/
	protected $_simulatorVars = false;
	
	/**
	 * WordPress autloaders
	 *
	 * @var array|false
	 **/
	protected $_wpAutoloaders = false;

	/**
	 * Set the connection to WordPress
	 * 
	 * @return void
	 */
	public function __construct()
	{
		if ($this->isActive()) {
			return;
		}

		if ((int)Mage::app()->getStore()->getId() === 0) {
			if (strpos(Mage::app()->getRequest()->getControllerName(), 'wordpress') !== 0) {
				return;
			}
		}	
		
		try {
			if (($path = Mage::helper('wordpress')->getWordPressPath()) === false) {
				throw new Exception($this->__("Can't read file '%s'.", Mage::helper('wordpress')->getRawWordPressPath() . 'wp-config.php'));
			}

			$transFile = $path . 'wp-includes' . DS . 'l10n.php';
			
			if (!is_file($transFile)) {
				throw new Exception($this->__("Can't read file '%s'.", $transFile));
			}
	
			$content = file_get_contents($transFile);
			
			if (strpos($content, "function_exists('__')") === false) {
				if (!preg_match('/(function[ ]{1,}__\(.*\)[ ]{0,}\{.*\})/Us', $content, $match)) {
					throw new Exception($this->__("Can't read file '%s'.", $transFile));
				}
				
				// If this is set, permissions need to be reverted
				$originalPermissions = false;

				if (!is_writable($transFile)) {
					$originalPermissions = $this->_getFilePermissions($transFile);
					
					// Can't write file so change permissions to 0777
					@chmod($transFile, 0777);

					if (!is_writable($transFile)) {		
						// The permissions cannot be changed so throw exception
						throw new Exception($this->__("Can't write file '%s'.", $transFile));
					}
				}
				
				if ($originalPermissions) {
					@chmod($transFile, $originalPermissions);
				}
	
				$replace = sprintf("if (!function_exists('__')) {\n%s\n}\n\n", "\t" . str_replace("\t", "\t\t", $match[1]));
				$content = str_replace($match[1], $replace, $content);
	
				@file_put_contents($transFile, $content);
			}
			
			if (function_exists('__')) {
				__('X'); // Ensure Magento translation files are included
			}
			
			// This loads Zend_Log before WP loads in case we need it			
			$this->_handlePotentialMissingIncludes();

			if (Mage::helper('wordpress')->isAddonInstalled('Multisite')) {
				$multisiteHelper = Mage::helper('wp_addon_multisite');

				if ($multisiteHelper->canRun()) {
					global $current_site, $current_blog, $blog_id;
					
					list($current_site, $current_blog) = $multisiteHelper->getSiteAndBlogObjects();

					$blog_id = $current_blog->blog_id;
				}
			}
				
			// Apply globals
			if ($globals = (array)Mage::app()->getConfig()->getNode('wordpress/core/globals')) {
				if (isset($globals[0]) && !isset($globals[1]) && !$globals[0]) {
					$globals = array();
				}
				
				$globals = array_merge(array('post', 'plugin_meta'), array_keys($globals));

				foreach(array_unique($globals) as $global) {
					global $$global;
				}
			}

			# Stop cookie notice cookie being malformed by WP
			$userAllowedSaveCookie = isset($_COOKIE['user_allowed_save_cookie']) ? $_COOKIE['user_allowed_save_cookie'] : false;
			
			$this->startWordPressSimulation();
			
			// Check wp-load.php exists
			if (!is_file($path . 'wp-load.php')) {
				throw new Exception(
					$this->__('Unable to find wp-load.php at %s', dirname($file))
				);
			}

			// Fix for Multisite set_prefix error
			global $wpdb;

			ob_start();

			@include_once($path . 'index.php');

			if (headers_sent() && function_exists('header_remove')) {
				header_remove();
			}

			$html = trim(ob_get_clean());

			Mage::register('wordpress_html', $html);

			$this->endWordPressSimulation();

			$this->_setStatus(true);
			
			# Reset cookie notice cookie to original value
			if ($userAllowedSaveCookie !== false) {
				$_COOKIE['user_allowed_save_cookie'] = $userAllowedSaveCookie;
			}
			
			if (defined('WP_DEBUG_OUTPUT') && WP_DEBUG_OUTPUT === true) {
				echo $html;
				exit;
			}
		}
		catch (Exception $e) {
			if (isset($_SERVER['FISHPIG'])) {
				echo sprintf('<h1>%s</h1><pre>%s</pre>', $e->getMessage(), $e->getTraceAsString());
				exit;
			}
			
			$this->_setStatus(false);
			Mage::logException($e);
			Mage::helper('wordpress')->log($e->getMessage());
		}
	}

	/*
	 * Get the HTML
	 *
	 * @return string|false
	 */
	public function getHtml()
	{
		return ($html = Mage::registry('wordpress_html'))
			? $html: false;
	}

	/*
	 * Update an array
	 *
	 */
	protected function _updateArray(&$a, $values)
	{
		$originals = array();
		
		foreach($values as $key => $value) {
			if (isset($a[$key]))	 {
				$originals[$key] = $a[$key];
			}
			
			$a[$key] = $value;
		}
		
		return $originals;
	}

	/**
	 * Determine whether connection to WP code library has been made
	 *
	 * @return bool
	 */	
	public function isActive()
	{
		return Mage::registry('wordpress_core_status') === true;
	}
	
	/**
	 * Unregister the existing autoloaders
	 *
	 * @return array
	 */
	protected function _unregisterAutoloaders()
	{
		$existingLoaders = spl_autoload_functions();

		if (is_array($existingLoaders)) {
			foreach ($existingLoaders as $existingLoader) {
				spl_autoload_unregister($existingLoader);
			}
		}
		
		return $existingLoaders;
	}
	
	/**
	 * Register autoloaders
	 *
	 * @param array $autoloaders
	 * @return $this
	 */
	protected function _registerAutoloaders(array $autoloaders)
	{
		foreach ($autoloaders as $autoloader) {
			if (is_object($autoloader) && $autoloader instanceof Closure) {
				spl_autoload_register($autoloader, false);
			}
			else {
				spl_autoload_register($autoloader, (isset($autoloader[0]) && $autoloader[0] instanceof Varien_Autoload));
			}
		}
		
		return $this;
	}	
	
	/**
	 * Set the status flag
	 *
	 * @param bool $flag
	 * @return $this
	 */
	protected function _setStatus($flag)
	{
		Mage::register(self::STATUS_KEY, $flag, true);
		
		return $this;
	}
		
	/**
	 * Start the WordPress simulation and store the environment vars
	 *
	 * @return $this
	 */
	public function startWordPressSimulation()
	{
		$path = Mage::helper('wordpress')->getWordPressPath();
		$translate = Mage::getSingleton('wordpress/translate');
		
		if ($this->_simulatorVars === false && $path !== false) {
			$this->_simulatorVars = array(
				'autoloaders' => $this->_unregisterAutoloaders(),
				'path' => getcwd(),
			);
			
			if (isset($_GET['p'])) {
				$this->_simulatorVars['p'] = $_GET['p'];
				unset($_GET['p']);
			}
		
			// Set the current directory to the WordPress path
			chdir($path);
			
			// If WP sets autloaders, save them for re-use later
			if ($this->_wpAutoloaders) {
				$this->_registerAutoloaders($this->_wpAutoloaders);
			}
			
			$translate->isSimulationActive(true);
		}
		
		return $this;
	}
	
	/**
	 * End the WordPress simulation and reset the environment vars
	 *
	 * @return $this
	 */
	public function endWordPressSimulation()
	{
		if ($this->_simulatorVars !== false) {
			// Grab any WP autoloaders
			if (spl_autoload_functions() && !$this->_wpAutoloaders) {
				$this->_wpAutoloaders = spl_autoload_functions();
			}
			
			// Reinstate the Magento autloaders
			$this->_registerAutoloaders($this->_simulatorVars['autoloaders']);
			
			// Change the path back to the Magento path
			chdir($this->_simulatorVars['path']);
			
			if (isset($this->_simulatorVars['p']) && $this->_simulatorVars['p']) {
				$_GET['p'] = $this->_simulatorVars['p'];
			}

			$this->_simulatorVars = false;
			Mage::getSingleton('wordpress/translate')->isSimulationActive(false);
		}
		
		return $this;
	}

	/**
	 * Get the permissions for $file
	 *
	 * @param string $file
	 * @return mixed
	 */
	protected function _getFilePermissions($file)
	{
		return substr(sprintf('%o', fileperms($file)), -4);
	}
	
	/**
	 * Ensure required classes are included
	 *
	 * @return $this
	**/
	protected function _handlePotentialMissingIncludes()
	{
		Mage::helper('log');

		Zend_Log::ERR;
		Zend_Log_Formatter_Simple::DEFAULT_FORMAT;

		$classes = array(
			'Zend_Log_Writer_Stream',
		);

		foreach($classes as $class) {
			include_once(Mage::getBaseDir() . DS . 'lib' . DS . str_replace('_', DS, $class) . '.php');
		}
		
		return $this;
	}	

	/*
	 * Perform a callback during WordPress simulation mode
	 *
	 * @param $callback
	 * @return mixed
	 */
	public function simulatedCallback($callback, array $params = array())
	{
		$result = null;
		
		if ($this->isActive()) {
			try {
				$this->startWordPressSimulation();
				
				$result = call_user_func_array($callback, $params);
				
				$this->endWordPressSimulation();
			}
			catch (Exception $e) {
				$this->endWordPressSimulation();
				
				Mage::helper('wordpress')->log($e);
			}
		}
		
		return $result;
	}
}
