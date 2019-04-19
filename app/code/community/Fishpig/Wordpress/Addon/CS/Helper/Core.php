<?php
/*
 *
 * @Obfuscate
 *
 */
class Fishpig_Wordpress_Addon_CS_Helper_Core extends Mage_Core_Helper_Abstract
{
	/**
	 * Status key for register
	 *
	 * @const string
	 */
	const KEY_STATUS            = 'wordpress_core_status';
	const KEY_SIMULATION_ACTIVE = 'wordpress_core_simulation_active';
	const KEY_ENV_DATA          = 'wordpress_core_simvars';
	
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
				throw new Exception($this->__("Can't find file %s.", 'wp-config.php'));
			}

			$transFile = $path . 'wp-includes' . DS . 'l10n.php';
			
			if (!is_file($transFile)) {
				throw new Exception($this->__("Can't find file '%s'.", $transFile));
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
			class_exists('Mage_Core_Exception');
			Mage::helper('log');
			Mage::helper('wordpress');
			Zend_Log::ERR;
			Zend_Log_Formatter_Simple::DEFAULT_FORMAT;
			Zend_Validate_File_Extension::NOT_FOUND;
			class_exists('Zend_Log_Writer_Stream');

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
					if (!isset($GLOBALS[$global])) {
						global $$global;
					}
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

			$html = trim(ob_get_clean());

			Mage::register('wordpress_html', $html);

			$this->endWordPressSimulation();

			if (function_exists('http_response_code')) {
				if (http_response_code(404)) {
					if ($responseCode = (int)Mage::app()->getResponse()->getHttpResponseCode()) {
						http_response_code($responseCode);
					}
				}
			}

			if (function_exists('header_remove') && !headers_sent()) {
				header_remove();
			}

			# Reset cookie notice cookie to original value
			if ($userAllowedSaveCookie !== false) {
				$_COOKIE['user_allowed_save_cookie'] = $userAllowedSaveCookie;
			}

			$this->_setStatus(true);
		}
		catch (Exception $e) {
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
		return ($html = Mage::registry('wordpress_html')) ? $html: false;
	}
	
	/**
	 * Set the status flag
	 *
	 * @param bool $flag
	 * @return $this
	 */
	protected function _setStatus($flag)
	{
		Mage::register(self::KEY_STATUS, $flag, true);
		
		return $this;
	}
		

	/**
	 * Determine whether connection to WP code library has been made
	 *
	 * @return bool
	 */	
	public function isActive()
	{
		return Mage::registry(self::KEY_STATUS) === true;
	}
	
	/**
	 * Start the WordPress simulation and store the environment vars
	 *
	 * @return $this
	 */
	protected function startWordPressSimulation()
	{
		if ($this->_isSimulationActive()) {
			return $this;
		}

		$translate = Mage::getSingleton('wordpress/translate');
						
		$this->_setIsSimulationActive(true);

		// Save the Magento environment
		if ($wpEnvData = $this->takeEnvironmentSnapshot()) {
			$this->applyEnvironmentSnapshot($wpEnvData);
		}

		$translate->isSimulationActive(true);
		
		return $this;
	}
	
	/**
	 * End the WordPress simulation and reset the environment vars
	 *
	 * @return $this
	 */
	protected function endWordPressSimulation()
	{
		if (!$this->_isSimulationActive()) {
			return $this;
		}

		$this->_setIsSimulationActive(false);
		
		// Save the WordPress environment
		$magentoEnvData = $this->takeEnvironmentSnapshot();

		// Apply the Magento env data
		$this->applyEnvironmentSnapshot($magentoEnvData);

		Mage::getSingleton('wordpress/translate')->isSimulationActive(false);

		return $this;
	}

	/*
	 *
	 * @param  string $code
	 * @return string
	 */
	public function doShortcode($code)
	{
		return $this->simulatedCallback(
			function($code) {
				ob_start();
				
				echo do_shortcode($code);
				
				$content = ob_get_clean();

				return str_replace(
					array('&#091;', '&#093;'), 
					array('[', ']'), 
					$content
				);
			}, array($code)
		);
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
	
	/*
	 *
	 *
	 * @return 
	 */
	protected function _isSimulationActive()
	{
		return (int)Mage::registry(self::KEY_SIMULATION_ACTIVE) === 1;
	}
	
	/*
	 *
	 *
	 * @return 
	 */
	protected function _setIsSimulationActive($flag)
	{
		if (!is_null(Mage::registry(self::KEY_SIMULATION_ACTIVE))) {
			Mage::unregister(self::KEY_SIMULATION_ACTIVE);
		}
		
		Mage::register(self::KEY_SIMULATION_ACTIVE, (int)$flag);

		return $this;
	}

	/*
	 *
	 *
	 * @return 
	 */
	protected function applyEnvironmentSnapshot(array $data)
	{
		if (!empty($data['autoloaders'])) {
			foreach ($data['autoloaders'] as $autoloader) {
				
				if (is_object($autoloader) && $autoloader instanceof Closure) {
					$throw = false;
				}
				else {
					$throw = isset($autoloader[0]) && $autoloader[0] instanceof Varien_Autoload;
				}
				
				if (is_array($autoloader) && isset($autoloader[0], $autoloader[1])) {
					if ($autoloader[0] === 'Elementor\Autoloader' && $autoloader[1] === 'autoload') {
						Elementor\Autoloader::run();
						continue;
					}
				}
				
				if (!spl_autoload_register($autoloader, $throw)) {
					Mage::helper('wordpress')->log(sprintf('Could not register autoloader. ' . print_r($autoloader, true)));
				}
			}
		}
		
		if ($data['error_handler']) {
			set_error_handler($data['error_handler']);
		}
			
		if (!empty($data['path'])) {
			chdir($data['path']);
		}
		
		if (isset($data['display_errors'])) {
			ini_set('display_errors', $data['display_errors']);
		}
		
		if (!empty($data['p'])) {
			$_GET['p'] = $data['p'];
		}
				
		return $this;
	}

	/*
	 *
	 *
	 * @return 
	 */
	protected function takeEnvironmentSnapshot()
	{
		if ($existingData = Mage::registry(self::KEY_ENV_DATA)) {
			Mage::unregister(self::KEY_ENV_DATA);
		}

		// Load current data
		$data = array(
			'autoloaders' => spl_autoload_functions(),
			'path' => getcwd(),
			'display_errors' => ini_get('display_errors'),
			'error_handler' => set_error_handler(null),
			'p' => isset($_GET['p']) ? $_GET['p'] : false,
		);

		Mage::register(self::KEY_ENV_DATA, $data);
		
		// Remove existing loaders
		if ($existingLoaders = spl_autoload_functions()) {
			foreach ($existingLoaders as $existingLoader) {
				spl_autoload_unregister($existingLoader);
			}
		}
		
		// Return the old data
		return $existingData;
	}
}
