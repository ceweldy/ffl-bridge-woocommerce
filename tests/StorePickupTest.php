<?php
/**
 * 1.3.0 item 8: in-store pickup. When the store saved its own FFL license
 * and the shopper picks local pickup, the store is the transfer dealer.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

final class StorePickupTest extends FFL_Bridge_TestCase {
	use FFL_Bridge_Dealer_Fixtures;

	private const STORE_ID  = '323e4567-e89b-12d3-a456-426614174000';
	private const STORE_LIC = '1-59-111-01-2B-00777';

	protected function setUp(): void {
		parent::setUp();
		$this->fresh_request();
		$GLOBALS['ffl_bridge_test_caps']                           = array( 'manage_woocommerce' );
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_required'] = 'yes';
	}

	public function test_saving_the_store_license_finds_the_store_record(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_store_zip'] = '32801';
		$this->queue_search( array( $this->raw_dealer(), $this->store_raw() ) );

		$saved = FFL_Bridge_Store_Pickup::sanitize_store_license( '159111012B00777' );

		$this->assertSame( self::STORE_LIC, $saved );
		$this->assertSame( self::STORE_ID, get_option( 'ffl_bridge_store_dealer_id' ) );
		$this->assertSame( 'Our Store Guns', get_option( 'ffl_bridge_store_dealer' )['name'] );
		$this->assertStringContainsString( 'zip=32801', $GLOBALS['ffl_bridge_test_http'][0]['url'] );
		$this->assertStringNotContainsString( 'acceptsTransfers', $GLOBALS['ffl_bridge_test_http'][0]['url'] );
	}

	public function test_a_store_license_not_found_near_the_zip_is_kept_with_a_warning(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_store_zip']      = '32801';
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_store_dealer_id'] = self::STORE_ID;
		$this->queue_search( array( $this->raw_dealer() ) );

		$this->assertSame( self::STORE_LIC, FFL_Bridge_Store_Pickup::sanitize_store_license( self::STORE_LIC ) );
		$this->assertSame( '', get_option( 'ffl_bridge_store_dealer_id' ) );
		$this->assertSame( 'ffl_bridge_store_license_not_found', $GLOBALS['ffl_bridge_test_settings_errors'][0][1] );
	}

	public function test_an_invalid_or_empty_store_license_turns_pickup_off(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_store_dealer_id'] = self::STORE_ID;

		$this->assertSame( '', FFL_Bridge_Store_Pickup::sanitize_store_license( '' ) );
		$this->assertSame( '', get_option( 'ffl_bridge_store_dealer_id' ) );

		$this->assertSame( '', FFL_Bridge_Store_Pickup::sanitize_store_license( 'nope' ) );
		$this->assertSame( 'ffl_bridge_invalid_store_license', $GLOBALS['ffl_bridge_test_settings_errors'][0][1] );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
	}

	public function test_store_zip_defaults_to_the_woocommerce_store_postcode(): void {
		$GLOBALS['ffl_bridge_test_options']['woocommerce_store_postcode'] = '32801-1234';
		$this->assertSame( '32801', FFL_Bridge_Store_Pickup::store_zip() );

		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_store_zip'] = '33601';
		$this->assertSame( '33601', FFL_Bridge_Store_Pickup::store_zip() );
	}

	public function test_local_pickup_methods_are_recognized(): void {
		$this->assertFalse( FFL_Bridge_Store_Pickup::pickup_chosen() );

		foreach ( array( 'local_pickup:3', 'pickup_location:0', 'local_pickup_plus' ) as $method ) {
			WC()->session->set( 'chosen_shipping_methods', array( $method ) );
			$this->assertTrue( FFL_Bridge_Store_Pickup::pickup_chosen(), $method );
		}

		WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:1' ) );
		$this->assertFalse( FFL_Bridge_Store_Pickup::pickup_chosen() );
	}

	public function test_pickup_order_uses_the_store_without_a_shopper_selection(): void {
		$this->configure_store();
		WC()->session->set( 'chosen_shipping_methods', array( 'local_pickup:3' ) );
		$this->queue_dealer_lookup( $this->store_overrides() );
		$order = $this->shopper_order();

		FFL_Bridge_Checkout::save_classic_order( $order );

		$this->assertSame( self::STORE_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
		$this->assertSame( self::STORE_LIC, $order->get_meta( '_ffl_bridge_license' ) );
		$this->assertSame( 'yes', $order->get_meta( '_ffl_bridge_store_pickup' ) );
		$this->assertStringContainsString( '/ffls/' . self::STORE_ID, $GLOBALS['ffl_bridge_test_http'][0]['url'] );
		$this->assertStringContainsString( 'In-store pickup', implode( "\n", $order->notes ) );
	}

	public function test_pickup_replaces_a_dealer_the_shopper_chose_before_switching_to_pickup(): void {
		$this->configure_store();
		$this->select_in_session();
		WC()->session->set( 'chosen_shipping_methods', array( 'pickup_location:0' ) );
		$this->queue_dealer_lookup( $this->store_overrides() );
		$order = $this->shopper_order();

		FFL_Bridge_Checkout::save_store_api_order( $order );

		$this->assertSame( self::STORE_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
	}

	public function test_pickup_does_not_change_the_shipping_address(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_ship_to_dealer'] = 'yes';
		$this->configure_store();
		WC()->session->set( 'chosen_shipping_methods', array( 'local_pickup:3' ) );
		$this->queue_dealer_lookup( $this->store_overrides() );
		$order  = $this->shopper_order();
		$before = $order->shipping;

		FFL_Bridge_Checkout::save_classic_order( $order );
		FFL_Bridge_Ship_To_Dealer::store_api_update_order( $order, null );

		$this->assertSame( $before, $order->shipping );
	}

	public function test_shipping_methods_other_than_pickup_still_need_a_selection(): void {
		$this->configure_store();
		WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:1' ) );

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Select and verify a transfer dealer before placing the order.' );
		FFL_Bridge_Checkout::save_classic_order( $this->shopper_order() );
	}

	public function test_without_a_store_license_pickup_still_needs_a_selection(): void {
		WC()->session->set( 'chosen_shipping_methods', array( 'local_pickup:3' ) );

		try {
			FFL_Bridge_Checkout::save_store_api_order( $this->shopper_order() );
			$this->fail( 'The order was accepted without a dealer.' );
		} catch ( RouteException $exception ) {
			$this->assertSame( 'ffl_bridge_selection_required', $exception->getErrorCode() );
		}
	}

	public function test_a_cart_without_ffl_items_is_left_alone_at_pickup(): void {
		$this->configure_store();
		$this->cart_with_product_in_ffl_category( false );
		WC()->session->set( 'chosen_shipping_methods', array( 'local_pickup:3' ) );
		$order = $this->shopper_order();

		FFL_Bridge_Checkout::save_classic_order( $order );

		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_id' ) );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
	}

	public function test_a_store_record_that_fails_the_recheck_blocks_the_pickup_order(): void {
		$this->configure_store();
		WC()->session->set( 'chosen_shipping_methods', array( 'local_pickup:3' ) );
		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->http_json( 404, array( 'success' => false ) );

		$this->expectException( Exception::class );
		FFL_Bridge_Checkout::save_classic_order( $this->shopper_order() );
	}

	public function test_frontend_config_and_fragment_report_the_pickup_state(): void {
		$this->configure_store();

		$config = FFL_Bridge_Checkout::get_frontend_config();
		$this->assertFalse( $config['pickupActive'] );
		$this->assertSame( 'Our Store Guns', $config['storeDealer']['name'] );
		$this->assertNotEmpty( $config['messages']['pickupNote'] );

		WC()->session->set( 'chosen_shipping_methods', array( 'local_pickup:3' ) );
		$this->assertTrue( FFL_Bridge_Checkout::get_frontend_config()['pickupActive'] );

		$fragments = FFL_Bridge_Store_Pickup::add_fragment( array( 'existing' => 'x' ) );
		$this->assertSame( 'x', $fragments['existing'] );
		$this->assertStringContainsString( 'data-active="1"', $fragments['.ffl-bridge-pickup-state'] );
	}

	private function configure_store(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_store_license']   = self::STORE_LIC;
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_store_dealer_id'] = self::STORE_ID;
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_store_dealer']    = array(
			'name'    => 'Our Store Guns',
			'address' => '1 Store Way',
			'city'    => 'Orlando',
			'state'   => 'FL',
			'zip'     => '32801',
		);
	}

	/** @return array<string, mixed> */
	private function store_overrides(): array {
		return array( 'id' => self::STORE_ID, 'licenseNumber' => self::STORE_LIC, 'tradeName' => 'Our Store Guns', 'address' => '1 Store Way', 'checkoutEligible' => false );
	}

	/** @return array<string, mixed> */
	private function store_raw(): array {
		return $this->raw_dealer( $this->store_overrides() );
	}
}
