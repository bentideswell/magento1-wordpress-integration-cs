<?php
/*
 *
 */
class Fishpig_Wordpress_Addon_CS_Model_Customer extends Mage_Customer_Model_Customer
{
	public function authenticate($login, $password)
	{
		Mage::getSingleton('wp_addon_cs/observer')->beforeAuthenticate($this, $login, $password);
		
		return parent::authenticate($login, $password);
	}
}