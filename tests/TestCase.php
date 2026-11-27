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
		$GLOBALS['ffl_bridge_test_term_parents'] = array();
		$GLOBALS['ffl_bridge_test_logs']         = array();
		$GLOBALS['ffl_bridge_test_notices']      = array();
		$GLOBALS['ffl_bridge_test_transients'] = array();
		$GLOBALS['ffl_bridge_test_settings_errors'] = array();
		$GLOBALS['ffl_bridge_test_filters']         = array();
		$GLOBALS['ffl_bridge_test_caps']            = array();
		$GLOBALS['ffl_bridge_test_orders']          = array();
		$GLOBALS['ffl_bridge_test_post_types']      = array();
		$GLOBALS['ffl_bridge_test_http']            = array();
		$GLOBALS['ffl_bridge_test_http_responses']  = array();
		$GLOBALS['ffl_bridge_test_site_transients'] = array();
		$GLOBALS['ffl_bridge_test_downloads']       = array();
		$GLOBALS['ffl_bridge_test_hooks']           = array();
		$GLOBALS['ffl_bridge_test_post_meta']       = array();
		$GLOBALS['ffl_bridge_test_post_queries']    = array();
		unset( $GLOBALS['ffl_bridge_test_current_post_id'], $GLOBALS['ffl_bridge_test_user_id'], $GLOBALS['ffl_bridge_test_users'] );
		$GLOBALS['ffl_bridge_test_accounts']         = array();
		$GLOBALS['ffl_bridge_test_did_actions']      = array();
		$GLOBALS['ffl_bridge_test_update_callbacks'] = array();
		unset( $GLOBALS['ffl_bridge_test_download_file'] );
		$GLOBALS['ffl_bridge_test_user_meta']       = array();
		unset( $GLOBALS['ffl_bridge_test_screen'] );
		unset( $GLOBALS['ffl_bridge_test_order_received'] );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		$_FILES   = array();

		$woocommerce          = new FFL_Bridge_Test_WooCommerce();
		$woocommerce->cart    = new FFL_Bridge_Test_Cart( array( array( 'product_id' => 100 ) ) );
		$woocommerce->session = new FFL_Bridge_Test_Session();
		$woocommerce->customer = new FFL_Bridge_Test_Customer();
		$GLOBALS['ffl_bridge_test_wc'] = $woocommerce;
	}
}
