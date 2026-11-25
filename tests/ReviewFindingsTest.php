<?php
/**
 * Regressions found in review of the 1.3.0 changes, checked against
 * WooCommerce 11.3 source (load order, add_order_note, account sync).
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class ReviewFindingsTest extends FFL_Bridge_TestCase {
	use FFL_Bridge_Dealer_Fixtures;

	private const CUSTOMER = 42;
	private const HOME     = array(
		'company'   => '',
		'address_1' => '9 Home Road',
		'address_2' => 'Apt 4',
		'city'      => 'Tampa',
		'state'     => 'FL',
		'postcode'  => '33601',
		'country'   => 'US',
	);

	protected function setUp(): void {
		parent::setUp();
		$this->fresh_request();
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_required']       = 'yes';
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_ship_to_dealer'] = 'yes';
	}

	// WooCommerce fires woocommerce_blocks_loaded during plugins_loaded at
	// priority 10, before this plugin initializes at 20.
	public function test_store_api_callback_is_registered_when_blocks_already_loaded(): void {
		$GLOBALS['ffl_bridge_test_did_actions']['woocommerce_blocks_loaded'] = 1;

		FFL_Bridge_Ship_To_Dealer::init();

		$this->assertSame( 'ffl-bridge', $GLOBALS['ffl_bridge_test_update_callbacks'][0]['namespace'] ?? null );
	}

	public function test_store_api_callback_waits_for_blocks_when_not_loaded_yet(): void {
		FFL_Bridge_Ship_To_Dealer::init();

		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_update_callbacks'] );
		$this->assertContains( array( FFL_Bridge_Ship_To_Dealer::class, 'register_store_api_callback' ), $GLOBALS['ffl_bridge_test_hooks']['woocommerce_blocks_loaded'] ?? array() );
	}

	// The product form also contains the loaded variation rows.
	public function test_variation_checkboxes_do_not_overwrite_the_product_checkbox(): void {
		$GLOBALS['ffl_bridge_test_caps'] = array( 'edit_products' );
		ob_start();
		FFL_Bridge_Product_Flags::render_variation_field( 3, array(), (object) array( 'ID' => 301 ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="_ffl_bridge_requires_ffl_variation[3]"', $html );

		$_POST   = array( '_ffl_bridge_requires_ffl' => 'yes', '_ffl_bridge_requires_ffl_variation' => array( 3 => 'yes' ) );
		$product = new WC_Product( 300 );
		FFL_Bridge_Product_Flags::save_product( $product );
		FFL_Bridge_Product_Flags::save_variation( 301, 3 );

		$this->assertSame( 'yes', $product->get_meta( '_ffl_bridge_requires_ffl' ) );
		$this->assertSame( 'yes', get_post_meta( 301, '_ffl_bridge_requires_ffl', true ) );
	}

	// add_order_note() records nothing before the order has an ID, and classic
	// checkout saves dealer details before the first save.
	public function test_classic_order_notes_are_added_once_the_order_is_saved(): void {
		$this->select_in_session();
		$this->queue_dealer_lookup();
		$order          = new WC_Order( array(), 0 );
		$order->billing = array( 'first_name' => 'Pat', 'last_name' => 'Shopper' );

		FFL_Bridge_Checkout::save_classic_order( $order );
		$this->assertSame( array(), $order->notes );

		$order->ffl_test_set_id( 777 );
		FFL_Bridge_Checkout::checkout_order_created( $order );

		$this->assertCount( 2, $order->notes );
		$this->assertStringContainsString( 'server-verified dealer selection', $order->notes[0] );
		$this->assertStringContainsString( 'Shipping address set to the selected dealer', $order->notes[1] );

		FFL_Bridge_Checkout::checkout_order_created( $order );
		$this->assertCount( 2, $order->notes, 'notes are added once' );
	}

	// Classic process_customer and the Store API's sync_customer_data_with_order
	// copy the dealer address onto the customer's account.
	public function test_classic_checkout_puts_the_account_shipping_address_back(): void {
		$GLOBALS['ffl_bridge_test_accounts'][ self::CUSTOMER ]['shipping'] = self::HOME;
		WC()->customer->shipping                                         = self::HOME;
		$this->queue_dealer_lookup();
		$this->ajax( array( FFL_Bridge_Checkout::class, 'ajax_select' ), array( 'selection' => $this->token() ) );
		$this->queue_dealer_lookup();
		$order              = $this->shopper_order();
		$order->customer_id = self::CUSTOMER;
		FFL_Bridge_Checkout::save_classic_order( $order );
		// WooCommerce saves the posted (dealer) address to the account.
		$GLOBALS['ffl_bridge_test_accounts'][ self::CUSTOMER ]['shipping'] = $order->shipping;

		FFL_Bridge_Checkout::checkout_order_created( $order );

		$this->assertSame( self::HOME, array_intersect_key( $GLOBALS['ffl_bridge_test_accounts'][ self::CUSTOMER ]['shipping'], self::HOME ) );
		$this->assertSame( '123 Main Street', $order->shipping['address_1'], 'the order still ships to the dealer' );
	}

	public function test_block_checkout_puts_the_account_shipping_address_back(): void {
		WC()->customer->shipping = self::HOME;
		$this->queue_dealer_lookup();
		$this->ajax( array( FFL_Bridge_Checkout::class, 'ajax_select' ), array( 'selection' => $this->token() ) );
		$this->queue_dealer_lookup();
		$order              = $this->shopper_order();
		$order->customer_id = self::CUSTOMER;
		FFL_Bridge_Checkout::save_store_api_order( $order );
		FFL_Bridge_Ship_To_Dealer::store_api_update_order( $order, null );
		$GLOBALS['ffl_bridge_test_accounts'][ self::CUSTOMER ]['shipping'] = $order->shipping;

		FFL_Bridge_Ship_To_Dealer::store_api_order_processed( $order );

		$this->assertSame( self::HOME, array_intersect_key( $GLOBALS['ffl_bridge_test_accounts'][ self::CUSTOMER ]['shipping'], self::HOME ) );
	}

	public function test_guest_orders_touch_no_account(): void {
		WC()->customer->shipping = self::HOME;
		$this->queue_dealer_lookup();
		$this->ajax( array( FFL_Bridge_Checkout::class, 'ajax_select' ), array( 'selection' => $this->token() ) );
		$this->queue_dealer_lookup();
		$order = $this->shopper_order();
		FFL_Bridge_Checkout::save_classic_order( $order );

		FFL_Bridge_Checkout::checkout_order_created( $order );

		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_accounts'] );
	}

	// The index must follow flags that change outside the product screen.
	public function test_flag_index_follows_meta_changes_and_trash(): void {
		$GLOBALS['ffl_bridge_test_post_meta'][100]['_ffl_bridge_requires_ffl'] = 'yes';
		FFL_Bridge_Product_Flags::on_meta_change( 1, 100, '_ffl_bridge_requires_ffl' );
		$this->assertTrue( FFL_Bridge_Product_Flags::any_flagged() );

		unset( $GLOBALS['ffl_bridge_test_post_meta'][100]['_ffl_bridge_requires_ffl'] );
		FFL_Bridge_Product_Flags::on_meta_change( 1, 100, '_ffl_bridge_requires_ffl' );
		$this->assertFalse( FFL_Bridge_Product_Flags::any_flagged(), 'deleting the last flag restores the safe default' );

		$queries = count( $GLOBALS['ffl_bridge_test_post_queries'] );
		FFL_Bridge_Product_Flags::on_meta_change( 1, 100, '_price' );
		$this->assertCount( $queries, $GLOBALS['ffl_bridge_test_post_queries'], 'other meta keys are ignored' );

		FFL_Bridge_Product_Flags::on_status_change( 'trash', 'publish', (object) array( 'post_type' => 'product' ) );
		$last = end( $GLOBALS['ffl_bridge_test_post_queries'] );
		$this->assertSame( array( 'publish', 'private' ), $last['post_status'], 'drafts and trashed products do not count' );
	}

	// Switching to local pickup on classic checkout must give back the fields.
	public function test_change_dealer_on_a_pickup_order_leaves_its_shipping_alone(): void {
		$GLOBALS['ffl_bridge_test_caps'] = array( 'edit_shop_orders' );
		$order                           = new WC_Order( array( '_ffl_bridge_store_pickup' => 'yes' ), 501 );
		$order->shipping_methods         = array( (object) array( 'method_id' => 'local_pickup' ) );
		$order->shipping                 = array( 'address_1' => '' );
		$this->queue_dealer_lookup();

		FFL_Bridge_Order_Dealer_Change::change( $order, FFL_Bridge_API_Client::get_dealer( self::FIX_DEALER_ID, true ), 7, 'Store Manager' );

		$this->assertSame( array( 'address_1' => '' ), $order->shipping );
		$this->assertSame( 'no', $order->get_meta( '_ffl_bridge_store_pickup' ) );
	}

	public function test_saving_other_settings_keeps_the_store_pickup_listing(): void {
		$GLOBALS['ffl_bridge_test_options'] = array(
			'ffl_bridge_api_key'          => self::FIX_KEY,
			'ffl_bridge_store_license'    => '1-59-111-01-2B-00777',
			'ffl_bridge_store_zip'        => '32801',
			'ffl_bridge_store_dealer_id'  => '323e4567-e89b-12d3-a456-426614174000',
			'ffl_bridge_store_dealer_zip' => '32801',
			'ffl_bridge_store_dealer'     => array( 'name' => 'Our Store Guns' ),
		);

		$this->assertSame( '1-59-111-01-2B-00777', FFL_Bridge_Store_Pickup::sanitize_store_license( '1-59-111-01-2B-00777' ) );

		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'], 'no lookup when nothing changed' );
		$this->assertSame( '323e4567-e89b-12d3-a456-426614174000', get_option( 'ffl_bridge_store_dealer_id' ) );
	}

	public function test_an_outage_while_saving_keeps_the_store_pickup_listing(): void {
		$GLOBALS['ffl_bridge_test_options'] = array(
			'ffl_bridge_api_key'          => self::FIX_KEY,
			'ffl_bridge_store_license'    => '1-59-111-01-2B-00777',
			'ffl_bridge_store_zip'        => '32803',
			'ffl_bridge_store_dealer_id'  => '323e4567-e89b-12d3-a456-426614174000',
			'ffl_bridge_store_dealer_zip' => '32801',
			'ffl_bridge_store_dealer'     => array( 'name' => 'Our Store Guns' ),
		);
		$GLOBALS['ffl_bridge_test_http_responses'][] = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );

		FFL_Bridge_Store_Pickup::sanitize_store_license( '1-59-111-01-2B-00777' );

		$this->assertSame( '323e4567-e89b-12d3-a456-426614174000', get_option( 'ffl_bridge_store_dealer_id' ) );
		$this->assertSame( 'ffl_bridge_store_license_unchecked', $GLOBALS['ffl_bridge_test_settings_errors'][0][1] );
	}

	public function test_name_searches_ask_for_the_most_results(): void {
		$this->queue_search( array() );
		FFL_Bridge_API_Client::search_with_meta( '32801', 25, 20, true, false, 'Example' );

		$this->assertStringContainsString( 'limit=25', $GLOBALS['ffl_bridge_test_http'][0]['url'] );
	}

	private function token(): string {
		return (string) FFL_Bridge_Selection::create( FFL_Bridge_API_Client::normalize_dealer( $this->raw_dealer() ) + array( 'network' => 'verified' ) );
	}
}
