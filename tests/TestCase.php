<?php
/**
 * Shared test state reset.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class FFL_Bridge_TestCase extends PHPUnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['ffl_bridge_test_options']    = array();
		$GLOBALS['ffl_bridge_test_term_map']   = array();
		$GLOBALS['ffl_bridge_test_transients'] = array();
		$GLOBALS['ffl_bridge_test_settings_errors'] = array();
		$GLOBALS['ffl_bridge_test_filters']         = array();
		$GLOBALS['ffl_bridge_test_caps']            = array();
		$GLOBALS['ffl_bridge_test_orders']          = array();
		$GLOBALS['ffl_bridge_test_http']            = array();
		$GLOBALS['ffl_bridge_test_http_responses']  = array();
		$_POST  = array();
		$_FILES = array();

		$woocommerce          = new FFL_Bridge_Test_WooCommerce();
		$woocommerce->cart    = new FFL_Bridge_Test_Cart( array( array( 'product_id' => 100 ) ) );
		$woocommerce->session = new FFL_Bridge_Test_Session();
		$GLOBALS['ffl_bridge_test_wc'] = $woocommerce;
	}
}
