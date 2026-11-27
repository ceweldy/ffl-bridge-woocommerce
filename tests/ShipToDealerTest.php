<?php
/**
 * 1.3.0 item 1: "Use the chosen dealer as the shipping address".
 *
 * The dealer's premises address and name are written into the shipping
 * fields when the shopper selects a dealer, and the order's shipping address
 * is set again from the re-checked dealer record when the order is created,
 * so nothing the browser sends can change it.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class ShipToDealerTest extends FFL_Bridge_TestCase {
	use FFL_Bridge_Dealer_Fixtures;

	private const OPTION = 'ffl_bridge_ship_to_dealer';

	private const DEALER_SHIPPING = array(
		'company'   => 'Example Arms',
		'address_1' => '123 Main Street',
		'address_2' => '',
		'city'      => 'Orlando',
		'state'     => 'FL',
		'postcode'  => '32801',
		'country'   => 'US',
	);

	protected function setUp(): void {
		parent::setUp();
		$this->fresh_request();
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_required'] = 'yes';
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ]        = 'yes';
	}

	public function test_the_setting_is_off_unless_saved_on(): void {
		unset( $GLOBALS['ffl_bridge_test_options'][ self::OPTION ] );
		$this->assertFalse( FFL_Bridge_Ship_To_Dealer::enabled() );

		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'no';
		$this->assertFalse( FFL_Bridge_Ship_To_Dealer::enabled() );

		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$this->assertTrue( FFL_Bridge_Ship_To_Dealer::enabled() );
	}

	public function test_dealer_address_fields_use_the_premises_address_and_name(): void {
		$dealer = FFL_Bridge_API_Client::normalize_dealer( $this->raw_dealer() );
		$this->assertSame( self::DEALER_SHIPPING, FFL_Bridge_Ship_To_Dealer::address_fields( $dealer ) );
	}

	public function test_classic_selection_writes_and_returns_the_dealer_shipping_address(): void {
		WC()->customer->shipping = array( 'first_name' => 'Pat', 'address_1' => '9 Home Road', 'city' => 'Tampa', 'postcode' => '33601', 'state' => 'FL', 'country' => 'US' );
		$this->queue_dealer_lookup();

		$response = $this->ajax( array( FFL_Bridge_Checkout::class, 'ajax_select' ), array( 'selection' => $this->selection_token() ) );

		$this->assertTrue( $response->success );
		$this->assertSame( self::DEALER_SHIPPING, $response->data['shipTo'] );
		foreach ( self::DEALER_SHIPPING as $field => $value ) {
			$this->assertSame( $value, WC()->customer->shipping[ $field ], $field );
		}
		$this->assertSame( 'Pat', WC()->customer->shipping['first_name'], 'the shopper name stays on the label' );
		$this->assertGreaterThan( 0, WC()->customer->saves );
	}

	public function test_clearing_the_dealer_restores_the_shoppers_shipping_address(): void {
		$home                    = array( 'first_name' => 'Pat', 'company' => '', 'address_1' => '9 Home Road', 'address_2' => 'Apt 4', 'city' => 'Tampa', 'state' => 'FL', 'postcode' => '33601', 'country' => 'US' );
		WC()->customer->shipping = $home;
		$this->queue_dealer_lookup();
		$this->ajax( array( FFL_Bridge_Checkout::class, 'ajax_select' ), array( 'selection' => $this->selection_token() ) );

		$response = $this->ajax( array( FFL_Bridge_Checkout::class, 'ajax_clear' ) );

		$this->assertTrue( $response->success );
		$this->assertSame( $home, WC()->customer->shipping );
		$this->assertArrayHasKey( 'shipTo', $response->data );
		$this->assertNull( $response->data['shipTo'] );
	}

	public function test_classic_order_ships_to_the_rechecked_dealer_not_the_posted_address(): void {
		$this->select_in_session();
		$this->queue_dealer_lookup();
		$order = $this->shopper_order();

		FFL_Bridge_Checkout::save_classic_order( $order );

		foreach ( self::DEALER_SHIPPING as $field => $value ) {
			$this->assertSame( $value, $order->shipping[ $field ], $field );
		}
		$this->assertSame( 'Pat', $order->shipping['first_name'] );
		$this->assertSame( 'Shopper', $order->shipping['last_name'] );
		$this->assertSame( '9 Home Road', $order->billing['address_1'], 'billing is never changed' );
		$this->assertSame( 'yes', $order->get_meta( '_ffl_bridge_ship_to_dealer' ) );
	}

	public function test_dealer_metadata_is_kept_when_shipping_to_the_dealer(): void {
		$this->select_in_session();
		$this->queue_dealer_lookup();
		$order = $this->shopper_order();

		FFL_Bridge_Checkout::save_classic_order( $order );

		$this->assertSame( self::FIX_DEALER_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
		$this->assertSame( self::FIX_LICENSE, $order->get_meta( '_ffl_bridge_license' ) );
		$this->assertSame( 'Example Arms', $order->get_meta( '_ffl_bridge_name' ) );
		$this->assertSame( '123 Main Street', $order->get_meta( '_ffl_bridge_address' ) );
		$this->assertStringContainsString( 'Shipping address set to the selected dealer', implode( "\n", $order->notes ) );
	}

	public function test_classic_order_uses_the_rechecked_record_when_the_dealer_moved(): void {
		// The session still holds the address from selection time; FFL Bridge
		// now has a new premises address, and that is where the order ships.
		$this->select_in_session();
		$this->queue_dealer_lookup( array( 'address' => '500 New Premises Road', 'zip' => '32803' ) );
		$order = $this->shopper_order();

		FFL_Bridge_Checkout::save_classic_order( $order );

		$this->assertSame( '500 New Premises Road', $order->shipping['address_1'] );
		$this->assertSame( '32803', $order->shipping['postcode'] );
	}

	public function test_block_checkout_ships_to_the_dealer_even_when_the_request_sends_another_address(): void {
		$this->select_in_session();
		$this->queue_dealer_lookup();
		$order = $this->shopper_order();

		// Store API order: meta first, then the address from the request is
		// written onto the order, then the plugin re-applies the dealer.
		FFL_Bridge_Checkout::save_store_api_order( $order );
		$order->shipping = array_merge( $order->shipping, array( 'company' => 'Tampered', 'address_1' => '1 Elsewhere Lane', 'postcode' => '10001', 'state' => 'NY', 'city' => 'New York' ) );
		FFL_Bridge_Ship_To_Dealer::store_api_update_order( $order, null );

		foreach ( self::DEALER_SHIPPING as $field => $value ) {
			$this->assertSame( $value, $order->shipping[ $field ], $field );
		}
		$this->assertSame( 1, count( $GLOBALS['ffl_bridge_test_http'] ), 'the dealer is looked up once per request' );
	}

	public function test_block_customer_address_updates_are_put_back_to_the_dealer(): void {
		$this->select_in_session();
		$customer           = new FFL_Bridge_Test_Customer();
		$customer->shipping = array( 'first_name' => 'Pat', 'address_1' => '1 Elsewhere Lane', 'city' => 'New York', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US' );

		FFL_Bridge_Ship_To_Dealer::store_api_update_customer( $customer, null );

		foreach ( self::DEALER_SHIPPING as $field => $value ) {
			$this->assertSame( $value, $customer->shipping[ $field ], $field );
		}
		$this->assertSame( 'Pat', $customer->shipping['first_name'] );
	}

	public function test_classic_posted_address_tampering_is_overwritten(): void {
		$this->select_in_session();
		$this->queue_dealer_lookup();
		$_POST = array(
			'ship_to_different_address' => '1',
			'shipping_company'          => 'Tampered',
			'shipping_address_1'        => '1 Elsewhere Lane',
			'shipping_postcode'         => '10001',
			'ffl_bridge_dealer_address' => '1 Elsewhere Lane',
		);
		$order           = $this->shopper_order();
		$order->shipping = array_merge( $order->shipping, array( 'company' => 'Tampered', 'address_1' => '1 Elsewhere Lane', 'postcode' => '10001' ) );

		FFL_Bridge_Checkout::save_classic_order( $order );

		$this->assertSame( 'Example Arms', $order->shipping['company'] );
		$this->assertSame( '123 Main Street', $order->shipping['address_1'] );
		$this->assertSame( '32801', $order->shipping['postcode'] );
		$this->assertSame( '123 Main Street', $order->get_meta( '_ffl_bridge_address' ) );
	}

	public function test_shipping_rates_use_the_dealer_address(): void {
		$this->select_in_session();
		$packages = array( array( 'destination' => array( 'country' => 'US', 'state' => 'NY', 'postcode' => '10001', 'city' => 'New York', 'address' => '1 Elsewhere Lane', 'address_1' => '1 Elsewhere Lane', 'address_2' => '' ) ) );

		$filtered = FFL_Bridge_Ship_To_Dealer::filter_packages( $packages );

		$this->assertSame( 'FL', $filtered[0]['destination']['state'] );
		$this->assertSame( '32801', $filtered[0]['destination']['postcode'] );
		$this->assertSame( 'Orlando', $filtered[0]['destination']['city'] );
		$this->assertSame( '123 Main Street', $filtered[0]['destination']['address_1'] );
		$this->assertSame( '123 Main Street', $filtered[0]['destination']['address'] );
	}

	public function test_cart_without_an_ffl_item_keeps_the_shoppers_shipping(): void {
		$this->cart_with_product_in_ffl_category( false );
		$this->select_in_session();
		$order    = $this->shopper_order();
		$before   = $order->shipping;
		$packages = array( array( 'destination' => array( 'state' => 'NY', 'postcode' => '10001' ) ) );

		FFL_Bridge_Checkout::save_classic_order( $order );
		FFL_Bridge_Ship_To_Dealer::store_api_update_order( $order, null );
		$customer           = new FFL_Bridge_Test_Customer();
		$customer->shipping = array( 'address_1' => '9 Home Road' );
		FFL_Bridge_Ship_To_Dealer::store_api_update_customer( $customer, null );

		$this->assertSame( $before, $order->shipping );
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_ship_to_dealer' ) );
		$this->assertSame( array( 'address_1' => '9 Home Road' ), $customer->shipping );
		$this->assertSame( $packages, FFL_Bridge_Ship_To_Dealer::filter_packages( $packages ) );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'], 'no dealer lookup for a cart without FFL items' );
	}

	public function test_setting_off_keeps_the_current_behavior(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'no';
		$this->select_in_session();
		$this->queue_dealer_lookup();
		$order  = $this->shopper_order();
		$before = $order->shipping;

		FFL_Bridge_Checkout::save_classic_order( $order );
		FFL_Bridge_Ship_To_Dealer::store_api_update_order( $order, null );

		$this->assertSame( $before, $order->shipping );
		$this->assertSame( self::FIX_DEALER_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
		$packages = array( array( 'destination' => array( 'state' => 'NY' ) ) );
		$this->assertSame( $packages, FFL_Bridge_Ship_To_Dealer::filter_packages( $packages ) );
	}

	public function test_frontend_config_tells_both_checkouts_to_lock_the_dealer_address(): void {
		$this->select_in_session();

		$config = FFL_Bridge_Checkout::get_frontend_config();

		$this->assertTrue( $config['shipToDealer'] );
		$this->assertSame( self::DEALER_SHIPPING, $config['shipTo'] );
		$this->assertNotEmpty( $config['messages']['shipToDealerNote'] );

		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'no';
		$config = FFL_Bridge_Checkout::get_frontend_config();
		$this->assertFalse( $config['shipToDealer'] );
		$this->assertNull( $config['shipTo'] );
	}

	public function test_existing_stores_are_offered_the_setting_and_new_stores_get_it_on(): void {
		$GLOBALS['ffl_bridge_test_options'] = array( 'ffl_bridge_settings_version' => '1.2.1' );
		FFL_Bridge_Features::maybe_migrate();
		$this->assertSame( 'no', get_option( self::OPTION ) );
		$this->assertContains( 'ship_to_dealer', FFL_Bridge_Features::pending_offers() );

		FFL_Bridge_Features::apply_offer( 'ship_to_dealer', 'switch' );
		$this->assertSame( 'yes', get_option( self::OPTION ) );
		$this->assertNotContains( 'ship_to_dealer', FFL_Bridge_Features::pending_offers() );

		$GLOBALS['ffl_bridge_test_options'] = array();
		FFL_Bridge_Features::maybe_migrate();
		$this->assertSame( 'yes', get_option( self::OPTION ) );
		$this->assertSame( array(), FFL_Bridge_Features::pending_offers() );
	}

	public function test_keeping_the_current_behavior_answers_the_offer(): void {
		$GLOBALS['ffl_bridge_test_options'] = array( 'ffl_bridge_settings_version' => '1.2.1' );
		FFL_Bridge_Features::maybe_migrate();

		FFL_Bridge_Features::apply_offer( 'ship_to_dealer', 'keep' );

		$this->assertSame( 'no', get_option( self::OPTION ) );
		$this->assertNotContains( 'ship_to_dealer', FFL_Bridge_Features::pending_offers() );
	}

	public function test_offer_notice_shows_only_to_store_managers_on_the_settings_page(): void {
		$GLOBALS['ffl_bridge_test_options'] = array( 'ffl_bridge_settings_version' => '1.2.1' );
		FFL_Bridge_Features::maybe_migrate();

		$this->assertSame( '', $this->render_offers() );

		$GLOBALS['ffl_bridge_test_caps']   = array( 'manage_woocommerce' );
		$GLOBALS['ffl_bridge_test_screen'] = 'woocommerce_page_ffl-bridge-settings';
		$html                              = $this->render_offers();
		$this->assertStringContainsString( 'Use the chosen dealer as the shipping address', $html );
		$this->assertStringContainsString( 'action=ffl_bridge_feature_offer', $html );
		$this->assertStringContainsString( 'offer=ship_to_dealer', $html );
		$this->assertStringContainsString( 'This is not legal advice.', $html );
	}

	public function test_offer_handler_requires_capability_nonce_and_a_known_choice(): void {
		$GLOBALS['ffl_bridge_test_options'] = array( 'ffl_bridge_settings_version' => '1.2.1' );
		FFL_Bridge_Features::maybe_migrate();
		$_GET = $_REQUEST = array( 'offer' => 'ship_to_dealer', 'choice' => 'switch', '_wpnonce' => 'nonce-ffl_bridge_feature_offer' );

		$this->assertSame( 403, $this->die_status( array( FFL_Bridge_Features::class, 'handle_offer' ) ) );

		$GLOBALS['ffl_bridge_test_caps'] = array( 'manage_woocommerce' );
		$_REQUEST['_wpnonce']            = 'bad';
		$this->assertSame( 403, $this->die_status( array( FFL_Bridge_Features::class, 'handle_offer' ) ) );

		$_REQUEST['_wpnonce'] = 'nonce-ffl_bridge_feature_offer';
		$_GET['offer']        = 'unknown';
		$this->assertSame( 400, $this->die_status( array( FFL_Bridge_Features::class, 'handle_offer' ) ) );
		$this->assertSame( 'no', get_option( self::OPTION ) );

		$_GET['offer'] = 'ship_to_dealer';
		$this->assertSame( 302, $this->die_status( array( FFL_Bridge_Features::class, 'handle_offer' ) ) );
		$this->assertSame( 'yes', get_option( self::OPTION ) );
	}

	public function test_settings_sanitize_the_option_to_yes_or_no(): void {
		$this->assertSame( 'yes', FFL_Bridge_Settings::sanitize_yes_no( 'yes' ) );
		$this->assertSame( 'no', FFL_Bridge_Settings::sanitize_yes_no( 'on' ) );
		$this->assertSame( 'no', FFL_Bridge_Settings::sanitize_yes_no( array( 'yes' ) ) );
	}

	// Found on a real WooCommerce 11.3 store: with "ship to a different
	// address" forced on, WooCommerce validates shipping first name, last name
	// and phone, which the shopper never typed.
	public function test_classic_posted_data_ships_to_the_dealer_with_the_shoppers_name(): void {
		$this->select_in_session();
		$posted = array(
			'billing_first_name'        => 'Pat',
			'billing_last_name'         => 'Shopper',
			'billing_phone'             => '813-555-0100',
			'ship_to_different_address' => false,
			'shipping_first_name'       => '',
			'shipping_last_name'        => '',
			'shipping_phone'            => '',
			'shipping_address_1'        => '1 Tampered Lane',
			'shipping_postcode'         => '10001',
		);

		$filtered = FFL_Bridge_Ship_To_Dealer::filter_posted_data( $posted );

		$this->assertTrue( $filtered['ship_to_different_address'] );
		$this->assertSame( 'Pat', $filtered['shipping_first_name'] );
		$this->assertSame( 'Shopper', $filtered['shipping_last_name'] );
		$this->assertSame( '813-555-0100', $filtered['shipping_phone'] );
		$this->assertSame( 'Example Arms', $filtered['shipping_company'] );
		$this->assertSame( '123 Main Street', $filtered['shipping_address_1'] );
		$this->assertSame( '32801', $filtered['shipping_postcode'] );
		$this->assertSame( 'US', $filtered['shipping_country'] );
	}

	public function test_classic_posted_data_keeps_a_shipping_name_the_shopper_typed(): void {
		$this->select_in_session();
		$filtered = FFL_Bridge_Ship_To_Dealer::filter_posted_data( array( 'billing_first_name' => 'Pat', 'billing_last_name' => 'Shopper', 'shipping_first_name' => 'Sam', 'shipping_last_name' => 'Gift' ) );

		$this->assertSame( 'Sam', $filtered['shipping_first_name'] );
		$this->assertSame( 'Gift', $filtered['shipping_last_name'] );
	}

	public function test_classic_posted_data_is_untouched_without_a_dealer_or_with_the_setting_off(): void {
		$posted = array( 'shipping_address_1' => '9 Home Road', 'ship_to_different_address' => false );
		$this->assertSame( $posted, FFL_Bridge_Ship_To_Dealer::filter_posted_data( $posted ), 'no selection' );

		$this->select_in_session();
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'no';
		$this->assertSame( $posted, FFL_Bridge_Ship_To_Dealer::filter_posted_data( $posted ), 'setting off' );
	}

	// Found on a real WooCommerce 11.3 store: a customer with no saved shipping
	// name could not place a Checkout Block order after the dealer address
	// replaced theirs. Like classic checkout, the label uses the billing name.
	public function test_block_customer_without_a_shipping_name_gets_the_billing_name(): void {
		$this->select_in_session();
		$customer           = new FFL_Bridge_Test_Customer();
		$customer->billing  = array( 'first_name' => 'Pat', 'last_name' => 'Shopper' );
		$customer->shipping = array( 'first_name' => '', 'last_name' => '' );

		FFL_Bridge_Ship_To_Dealer::store_api_update_customer( $customer, null );

		$this->assertSame( 'Pat', $customer->shipping['first_name'] );
		$this->assertSame( 'Shopper', $customer->shipping['last_name'] );

		$customer->shipping['first_name'] = 'Sam';
		FFL_Bridge_Ship_To_Dealer::store_api_update_customer( $customer, null );
		$this->assertSame( 'Sam', $customer->shipping['first_name'], 'a typed shipping name is kept' );
	}

	private function selection_token(): string {
		$token = FFL_Bridge_Selection::create( FFL_Bridge_API_Client::normalize_dealer( $this->raw_dealer() ) + array( 'network' => 'verified' ) );
		$this->assertIsString( $token );
		return $token;
	}

	private function render_offers(): string {
		ob_start();
		FFL_Bridge_Features::render_offers();
		return (string) ob_get_clean();
	}

	private function die_status( callable $handler ): int {
		try {
			$handler();
		} catch ( FFL_Bridge_Test_Die $die ) {
			return $die->status;
		}
		$this->fail( 'The handler did not stop.' );
	}
}
