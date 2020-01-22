<?php
/**
 * @category    Fishpig
 * @package     Fishpig_WpCustomerSynch
 * @license     http://fishpig.co.uk/license.txt
 * @author      Ben Tideswell <help@fishpig.co.uk>
 */

class Fishpig_Wordpress_Addon_CS_Model_Observer
{
	/**
	 * If true, any exception will be printed to the screen
	 *
	 * @var bool
	 */
	protected $_debug = false;
	
	/*
	 *
	 *
	 */
	public function beforeAuthenticate($customer, $login, $password)
	{
		if (!$this->isCustomerSynchronisationEnabled()) {
			return false;
		}
		
		try {
			if ($this->_emailExistsInMagento($login)) {
				return false;	
			}
			
			$user = Mage::getModel('wordpress/user')->loadByEmail($login);
		
			if (!$user->getId()) {
				return false;
			}

			if (!$this->isValidWordPressPassword($password, $user->getUserPass())) {
				return false;
			}

			$this->synchroniseUser($user->setMagentoPassword($password));
		}
		catch (Exception $e) {
			$this->_handleException($e);
		}
		
		return true;
	}
	
	protected function _emailExistsInMagento($email)
	{
		$resource = Mage::getSingleton('core/resource');
		$db       = $resource->getConnection('core_read');
		
		return (int)$db->fetchOne(
			$db->select()
				->from($resource->getTableName('customer_entity'), 'entity_id')	
				->where('email=?', $email)
				->limit(1)
		) !== 0;
	}
	
	/**
	 * This observer runs each time a customer logs in to Magento
	 * First, Magento checks whether the customer exists
	 * if so, it pushes it to WP
	 * If not, it checks in WP for a user
	 * If exists, pulls to Magento
	 *
	 * @param Varien_Event_Observer $observer
	 * @return bool
	 */
	public function customerCustomerAuthenticatedObserver(Varien_Event_Observer $observer)
	{
		if (!$this->isCustomerSynchronisationEnabled()) {
			return false;
		}

		try {
			$customer = $observer->getEvent()->getModel();
			$password = $observer->getEvent()->getPassword();
			
			$customer->setPassword($password);
			
			$this->synchroniseCustomer($customer);			
			$this->loginToWordPress($customer);
		}
		catch (Exception $e) {
			$this->_handleException($e);
		}
		
		return true;
	}
	
	/**
	 * This observer runs each time a customer model is loaded
	 * It takes a copy of any data to be synchronised and stores it in orig_data
	 *
	 * @param Varien_Event_Observer $observer
	 */
	public function customerLoadAfterObserver(Varien_Event_Observer $observer)
	{
		if (!$this->isCustomerSynchronisationEnabled()) {
			return false;
		}
		
		$customer = $observer->getEvent()->getCustomer();

		$customer->setCsData(new Varien_Object(array(
			'firstname' => $customer->getFirstname(),
			'lastname' => $customer->getLastname(),
			'email' => $customer->getEmail(),
			'password_hash' => $customer->getPasswordHash(),
		)));
		
		$user = Mage::getModel('wordpress/user')->loadByEmail($customer->getEmail());
		
		if ($user->getId()) {
			$customer->setWordpressUser($user);
		}
	}
	
	/**
	 * This observer runs each time a customer model is saved
	 *
	 * @param Varien_Event_Observer $observer
	 * @return bool
	 */
	public function customerSaveAfterObserver(Varien_Event_Observer $observer)
	{
		if (!$this->isCustomerSynchronisationEnabled()) {
			return false;
		}

		if (Mage::getSingleton('customer/session')->isLoggedIn()) {
			$customer = Mage::getSingleton('customer/session')->getCustomer();
		}
		else {
			$customer = $observer->getEvent()->getCustomer();
		}
		
		$this->synchroniseCustomer($customer);
		
		if ($customer->getWordpressUser() && $customer->getPassword()) {
			$this->loginToWordPress($customer, $customer->getWordpressUser());
		}

		return true;
	}
	
	/**
	 * This observer is triggered after an order is placed
	 *
	 * @param Varien_Event_Observer $observer
	 * @return bool
	 */
	public function onepageSaveOrderAfterObserver(Varien_Event_Observer $observer)
	{
		if (!$this->isCustomerSynchronisationEnabled()) {
			return false;
		}

		try {
			$quote = $observer->getEvent()->getQuote();
			$customer = $quote->getCustomer();
			$customer->setPassword($customer->decryptPassword($quote->getPasswordHash()));

			return $this->synchroniseCustomer($customer);
		}
		catch (Exception $e) {
			$this->_handleException($e);
		}
		
		return false;
	}
	
	/**
	 * Synchronise a Magento customer with WordPress
	 *
	 * @param Mage_Customer_Model_Customer $customer
	 * @return bool
	 */
	public function synchroniseCustomer(Mage_Customer_Model_Customer $customer)
	{
		$email = $customer->getEmail();
		
		if ($customer->getCsData() && $customer->getCsData()->getEmail()) {
			$email = $customer->getCsData()->getEmail();
		}

		if (!$customer->getPassword()) {
			if (!Mage::app()->getRequest()->getPost('password'))	 {
				return false;
			}
			
			$customer->setPassword(Mage::app()->getRequest()->getPost('password'));
		}
		
		$user = Mage::getModel('wordpress/user')->loadByEmail($email);
		
		if (!$user->getId() && !$customer->getPassword()) {
			return false;
		}
		
		if ($user->getRole() === 'administrator') {
			return false;
		}

		$user->setUserEmail($customer->getEmail())
			->setUserRegistered($customer->getCreatedAt())
			->setUserNicename($customer->getName())
			->setDisplayName($customer->getFirstname())
			->setFirstName($customer->getFirstname())
			->setLastName($customer->getLastname())
			->setNickname($customer->getFirstname());
		
		if (!$user->getUserLogin() || strpos($user->getUserLogin(), '@') !== false) {
			$user->setUserLogin($customer->getEmail());
		}

		if ($this->getCustomUserLoginAttribute()) {
			$user->setUserLogin($customer->getData($this->getCustomUserLoginAttribute()));
		}

		try {
			if ($customer->hasPassword()) {
				if ($customer->validatePassword($customer->getPassword())) {
					$wpHash = $this->hashPasswordForWordPress($customer->getPassword());

					if ($this->isValidWordPressPassword($customer->getPassword(), $wpHash)) {
						$user->setUserPass($wpHash);
					}
				}
			}

			$user->save();
			
			$customer->setWordpressUser($user);
		}
		catch (Exception $e) {
			$this->_handleException($e);
		}
	}
	
	/**
	 * Push a WordPress user model to Magento
	 *
	 * @param Fishpig_Wordpress_Model_User $user
	 * @return bool
	 */
	public function synchroniseUser(Fishpig_Wordpress_Model_User $user)
	{
		$customer = Mage::getModel('customer/customer')
			->setWebsiteId(Mage::app()->getStore()->getWebsite()->getId())
			->loadByEmail($user->getUserEmail());
			
		if (!$customer->getId() && !$user->getMagentoPassword()) {
			return false;
		}
		
		$customer->setEmail($user->getUserEmail())
			->setFirstname($user->getFirstName())
			->setLastname($user->getLastName())
			->setStoreId(Mage::app()->getStore()->getId());
			
		if ($user->getMagentoPassword()) {
			$customer->setPassword($user->getMagentoPassword());
		}
		
		try {
			$customer->save();
			$customer->setWordpressUser($user);
		}
		catch (Exception $e) {
			$this->_handleException($e);
		}
		
		return false;
	}
	
	/**
	 * Test whether can open the PhPassword file
	 *
	 * @return bool
	 */
	public function canOpenPhPasswordFile()
	{
		try {
			if ($this->_requirePhPassClass()) {
				return true;
			}
		}
		catch (Exception $e) {
			$this->_handleException($e);
			if (Mage::getDesign()->getArea() == 'adminhtml') {
				Mage::getSingleton('adminhtml/session')->addError('Customer Synch Error: ' . $e->getMessage());
			}
			
			Mage::helper('wordpress')->log("There was an error including your PhPassword file (see error in entry below)");
			Mage::helper('wordpress')->log($e);
		}
		
		return false;
	}
	
	/**
	 * Force inclusion of WordPress Password class file
	 */
	public function _requirePhPassClass()
	{
		if (is_null(Mage::registry('_wordpress_require_phpass'))) {
			if ($this->_getCoreHelper() && @class_exists('PasswordHash')) {
				Mage::register('_wordpress_require_phpass', true, true);
			}
			else {
				$classFile = Mage::helper('wordpress')->getWordPressPath() . 'wp-includes/class-phpass.php';
	
				if (file_exists($classFile) && is_readable($classFile)) {
					require_once($classFile);
					Mage::register('_wordpress_require_phpass', true, true);
				}
				else {
					Mage::register('_wordpress_require_phpass', false, true);
					Mage::helper('wordpress')->log(Mage::helper('wordpress')->__('Error including password file (%s)', $classFile));
				}
			}
		}
		
		return Mage::registry('_wordpress_require_phpass');
	}

	/**
	 * @return
	**/
	protected function _getCoreHelper()
	{
		return Mage::helper('wp_addon_cs/core');
	}

	/**
	 * Returns true if the password can be hashed to equal $hash
	 *
	 * @oaram string $password
	 * @param string(hash) $hash
	 * @return bool
	 */
	public function isValidWordPressPassword($password, $hash)
	{
		$this->_requirePhPassClass();
						
		$wpHasher = new PasswordHash(8, TRUE);

		return $wpHasher->CheckPassword($password, $hash) ? true : $hash == md5($password);
	}
	
	/**
	 * Convert a string to a valid WordPress password hash
	 *
	 * @param string $password
	 * @return string
	 */
	public function hashPasswordForWordPress($password)
	{
		$this->_requirePhPassClass();
		
		if (class_exists('PasswordHash')) {
			$wpHasher = new PasswordHash(8, TRUE);
		
			return $wpHasher->HashPassword($password);
		}
		
		throw new Exception('Cannot find class PasswordHash');
	}
	
	/**
	 * Determine whether synchronisation can be ran
	 *
	 * @return bool
	 */
	public function isCustomerSynchronisationEnabled()
	{
		if ($this->isEnabled()) {
			if ($this->canOpenPhPasswordFile()) {
				return true;
			}
		}

		return false;
	}
	
	/**
	 * Determine whether customer synch is enabled in the admin
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		return Mage::helper('wordpress')->isEnabled();
	}
	
	/**
	 * Login to WordPress
	 *
	 * @param Mage_Customer_Model_Customer $customer
	 * @param Fishpig_Wordpress_Model_User $user = null
	 * @return bool
	 */
	public function loginToWordPress(Mage_Customer_Model_Customer $customer, Fishpig_Wordpress_Model_User $user = null)
	{
		if (is_null($user)) {
			$user = $customer->getWordpressUser();
			
			if (!$user) {
				return false;
			}
		}
		
		if (!$customer->getPassword()) {
			return false;
		}
		
		try {
			return Mage::helper('wordpress/system')->loginToWordPress(
				$user->getUserLogin(),
				$customer->getPassword(),
				null,
				false
			);
		}
		catch (Exception $e) {
			$this->_handleException($e);
		}
		
		return false;
	}
	
	/**
	 * Retrieve an attribute code for a custom attribute
	 * This attribute code is used to generate the WP username
	 * If not set, the email_address will be used
	 *
	 * @return false|string
	 */
	public function getCustomUserLoginAttribute()
	{
		return false;
	}
	
	/**
	 * Retrieve the Disqus SSO data
	 *
	 * @param
	 * @return $this
	 */
	public function getDisqusSsoDataObserver(Varien_Event_Observer $observer)
	{
		$session = Mage::getSingleton('customer/session');
		
		if (!$session->isLoggedIn()) {
			return $this;
		}

		$customer = $session->getCustomer();
		
		if (($user = $customer->getWordpressUser()) !== null) {
			$observer->getEvent()->getUserData()->setDetails(array(
				'username' => $user->getDisplayName(),
				'id' => $user->getId(),
				'avatar' => $user->getGravatarUrl(96),
				'email' => $user->getUserEmail(),
				'url' => $user->getUserUrl(),
			));
		}
		
		return $this;
	}
	
	protected function _handleException(Exception $e)
	{
		if ($this->_debug === true) {
			echo sprintf('<h1>%s</h1><pre>%s</pre>', $e->getMessage(), $e->getTraceAsString());
			exit;
		}
		
		Mage::helper('wordpress')->log($e);
		
		return $this;
	}
	
	/**
   *
   *
   */
	public function customerLogoutObserver()
	{
  	$dataHelper   = Mage::helper('wordpress');
  	$systemHelper = Mage::helper('wordpress/system');
  	
  	try {
    	$this->_getCoreHelper()->simulatedCallback(function() {
        wp_logout();
    	});
    }
    catch (Exception $e) {
      $dataHelper->log($e);
    }
    
    return $this;
	}
}
