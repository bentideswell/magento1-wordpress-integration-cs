<?php
/**
 * @category		Fishpig
 * @package		Fishpig_Wordpress
 * @license		http://fishpig.co.uk/license.txt
 * @author		Ben Tideswell <help@fishpig.co.uk>
 */

	$this->startSetup();
	
	$oldModuleFile = Mage::getBaseDir() . '/app/etc/modules/Fishpig_WpCustomerSynch.xml';

	if (is_file($oldModuleFile)) {
		@unlink($oldModuleFile);
	}

	$this->endSetup();
