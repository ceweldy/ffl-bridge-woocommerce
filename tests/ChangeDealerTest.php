<?php
/**
 * 1.3.0 item 7: change the dealer on an order, or add one to a manually
 * created order, from the order screen. Each change adds an order note.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class ChangeDealerTest extends FFL_Bridge_TestCase {
	use FFL_Bridge_Dealer_Fixtures;

	private const ORDER_ID  = 501;
	private const OTHER_ID  = '223e4567-e89b-12d3-a456-426614174000';
	private const OTHER_LIC = '1-23-456-78-9A-05555';

	protected function setUp(): void {
		parent::setUp();
		$this->fresh_request();
		$GLOBALS['ffl_bridge_test_caps'] = array( 'edit_shop_orders' );
	}

	public function test_order_panel_offers_change_dealer_to_staff(): void {
		$html = $this->panel( $this->order_with_dealer() );

		$this->assertStringContainsString( 'data-ffl-bridge-change-dealer', $html );
		$this->assertStringContainsString( 'data-order-id="501"', $html );
		$this->assertStringContainsString( 'data-nonce="nonce-ffl_bridge_change_dealer_501"', $html );
		$this->assertStringContainsString( 'Change dealer', $html );
	}

	public function test_order_without_a_dealer_offers_add_dealer(): void {
		$html = $this->panel( $this->order( array() ) );

		$this->assertStringContainsString( 'No transfer dealer is saved on this order.', $html );
		$this->assertStringContainsString( 'Add a transfer dealer', $html );
		$this->assertStringContainsString( 'data-ffl-bridge-change-dealer', $html );
	}

	public function test_panel_has_no_controls_without_capability(): void {
		$GLOBALS['ffl_bridge_test_caps'] = array();

		$this->assertStringNotContainsString( 'data-ffl-bridge-change-dealer', $this->panel( $this->order_with_dealer() ) );
		$this->assertSame( '', $this->panel( $this->order( array() ) ) );
	}

	public function test_search_requires_capability_and_the_order_nonce(): void {
		$GLOBALS['ffl_bridge_test_caps'] = array();
		$this->assertSame( 403, $this->admin( 'ajax_search', array( 'nonce' => 'nonce-ffl_bridge_change_dealer_501' ) )->status );

		$GLOBALS['ffl_bridge_test_caps'] = array( 'edit_shop_orders' );
		$this->assertSame( 403, $this->admin( 'ajax_search', array( 'nonce' => 'nonce-ffl_bridge_change_dealer_999' ) )->status );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
	}

	public function test_search_lists_dealers_with_their_status_and_hides_blocked_ones(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_blocked_licenses'] = array( self::FIX_LICENSE );
		$this->queue_search( array( $this->raw_dealer(), $this->other_raw() ) );

		$response = $this->admin( 'ajax_search', array( 'zip' => '32801', 'radius' => '25', 'name' => '' ) );

		$this->assertTrue( $response->success );
		$this->assertSame( array( self::OTHER_ID ), array_column( $response->data['dealers'], 'id' ) );
		$this->assertSame( self::OTHER_LIC, $response->data['dealers'][0]['license'] );
		$this->assertSame( 'Confirmed transfer dealer', $response->data['dealers'][0]['status'] );
	}

	public function test_changing_the_dealer_replaces_it_from_the_rechecked_record_and_adds_a_note(): void {
		$order = $this->order_with_dealer(
			array(
				'_ffl_bridge_store_confirmed_at'      => '2026-11-01T00:00:00+00:00',
				'_ffl_bridge_store_confirmed_by'      => '7',
				'_ffl_bridge_store_confirmed_by_name' => 'Store Manager',
				'_ffl_bridge_api_confirmation'        => 'sent',
			)
		);
		$GLOBALS['ffl_bridge_test_orders'][ self::ORDER_ID ] = $order;
		$this->queue_dealer_lookup( $this->other_overrides() );

		$response = $this->admin( 'ajax_set_dealer', array( 'dealer_id' => self::OTHER_ID ) );

		$this->assertTrue( $response->success );
		$this->assertSame( self::OTHER_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
		$this->assertSame( self::OTHER_LIC, $order->get_meta( '_ffl_bridge_license' ) );
		$this->assertSame( 'Other Arms', $order->get_meta( '_ffl_bridge_name' ) );
		$this->assertSame( '500 Other Road', $order->get_meta( '_ffl_bridge_address' ) );
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_store_confirmed_at' ), 'the old dealer\'s confirmation does not carry over' );
		$this->assertSame( '7', $order->get_meta( '_ffl_bridge_changed_by' ) );
		$this->assertStringContainsString( '/ffls/' . self::OTHER_ID, $GLOBALS['ffl_bridge_test_http'][0]['url'] );
		$note = end( $order->notes );
		$this->assertStringContainsString( 'Transfer dealer changed by Store Manager from Example Arms (' . self::FIX_LICENSE . ') to Other Arms (' . self::OTHER_LIC . ').', $note );
		$this->assertStringContainsString( 'Store confirmation for the previous dealer was cleared', $note );
		$this->assertGreaterThan( 0, $order->saves );
	}

	public function test_adding_a_dealer_to_a_manual_order_adds_a_note(): void {
		$order = $this->order( array() );
		$GLOBALS['ffl_bridge_test_orders'][ self::ORDER_ID ] = $order;
		$this->queue_dealer_lookup();

		$response = $this->admin( 'ajax_set_dealer', array( 'dealer_id' => self::FIX_DEALER_ID ) );

		$this->assertTrue( $response->success );
		$this->assertSame( self::FIX_DEALER_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
		$this->assertSame( 'ffl_bridge_api', $order->get_meta( '_ffl_bridge_source' ) );
		$this->assertSame( 'confirmed', $order->get_meta( '_ffl_bridge_transfer_status' ) );
		$this->assertStringContainsString( 'Transfer dealer added by Store Manager: Example Arms (' . self::FIX_LICENSE . ').', end( $order->notes ) );
	}

	public function test_every_change_adds_its_own_note(): void {
		$order = $this->order_with_dealer();
		$GLOBALS['ffl_bridge_test_orders'][ self::ORDER_ID ] = $order;
		$this->queue_dealer_lookup( $this->other_overrides() );
		$this->admin( 'ajax_set_dealer', array( 'dealer_id' => self::OTHER_ID ) );
		$this->fresh_request();
		$this->queue_dealer_lookup();
		$this->admin( 'ajax_set_dealer', array( 'dealer_id' => self::FIX_DEALER_ID ) );

		$this->assertCount( 2, $order->notes );
		$this->assertStringContainsString( 'from Other Arms', $order->notes[1] );
	}

	public function test_a_change_never_sends_a_dealer_email(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_send_dealer_email'] = 'yes';
		$order = $this->order( array() );
		$GLOBALS['ffl_bridge_test_orders'][ self::ORDER_ID ] = $order;
		$this->queue_dealer_lookup();

		$this->admin( 'ajax_set_dealer', array( 'dealer_id' => self::FIX_DEALER_ID ) );

		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		foreach ( $GLOBALS['ffl_bridge_test_http'] as $request ) {
			$this->assertStringNotContainsString( '/orders', $request['url'] );
		}
	}

	public function test_change_is_refused_for_blocked_unknown_or_unverifiable_dealers(): void {
		$order = $this->order_with_dealer();
		$GLOBALS['ffl_bridge_test_orders'][ self::ORDER_ID ] = $order;

		$this->assertSame( 400, $this->admin( 'ajax_set_dealer', array( 'dealer_id' => 'not-a-uuid' ) )->status );

		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_blocked_licenses'] = array( self::OTHER_LIC );
		$this->queue_dealer_lookup( $this->other_overrides() );
		$blocked = $this->admin( 'ajax_set_dealer', array( 'dealer_id' => self::OTHER_ID ) );
		$this->assertSame( 409, $blocked->status );

		$this->fresh_request();
		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->http_json( 404, array( 'success' => false ) );
		$this->assertFalse( $this->admin( 'ajax_set_dealer', array( 'dealer_id' => self::OTHER_ID ) )->success );

		$this->assertSame( self::FIX_DEALER_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
		$this->assertSame( array(), $order->notes );
	}

	public function test_set_requires_capability_and_the_order_nonce(): void {
		$order = $this->order_with_dealer();
		$GLOBALS['ffl_bridge_test_orders'][ self::ORDER_ID ] = $order;

		$GLOBALS['ffl_bridge_test_caps'] = array();
		$this->assertSame( 403, $this->admin( 'ajax_set_dealer', array( 'dealer_id' => self::OTHER_ID ) )->status );
		$GLOBALS['ffl_bridge_test_caps'] = array( 'edit_shop_orders' );
		$this->assertSame( 403, $this->admin( 'ajax_set_dealer', array( 'dealer_id' => self::OTHER_ID, 'nonce' => 'nope' ) )->status );
		$this->assertSame( self::FIX_DEALER_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
	}

	public function test_with_ship_to_dealer_on_the_order_shipping_follows_the_new_dealer(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_ship_to_dealer'] = 'yes';
		$order           = $this->order_with_dealer();
		$order->shipping = array( 'first_name' => 'Pat', 'company' => 'Example Arms', 'address_1' => '123 Main Street', 'city' => 'Orlando', 'state' => 'FL', 'postcode' => '32801', 'country' => 'US' );
		$GLOBALS['ffl_bridge_test_orders'][ self::ORDER_ID ] = $order;
		$this->queue_dealer_lookup( $this->other_overrides() );

		$this->admin( 'ajax_set_dealer', array( 'dealer_id' => self::OTHER_ID ) );

		$this->assertSame( 'Other Arms', $order->shipping['company'] );
		$this->assertSame( '500 Other Road', $order->shipping['address_1'] );
		$this->assertSame( 'Pat', $order->shipping['first_name'] );
	}

	public function test_with_ship_to_dealer_off_the_order_shipping_is_untouched(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_ship_to_dealer'] = 'no';
		$order           = $this->order_with_dealer();
		$order->shipping = array( 'address_1' => '9 Home Road' );
		$GLOBALS['ffl_bridge_test_orders'][ self::ORDER_ID ] = $order;
		$this->queue_dealer_lookup( $this->other_overrides() );

		$this->admin( 'ajax_set_dealer', array( 'dealer_id' => self::OTHER_ID ) );

		$this->assertSame( array( 'address_1' => '9 Home Road' ), $order->shipping );
	}

	/** @param array<string, mixed> $post Posted fields. */
	private function admin( string $handler, array $post ): FFL_Bridge_Test_Json {
		$_POST = array_merge( array( 'order_id' => (string) self::ORDER_ID, 'nonce' => 'nonce-ffl_bridge_change_dealer_' . self::ORDER_ID ), $post );
		try {
			call_user_func( array( FFL_Bridge_Order_Dealer_Change::class, $handler ) );
		} catch ( FFL_Bridge_Test_Json $response ) {
			return $response;
		}
		$this->fail( 'No JSON response.' );
	}

	private function panel( WC_Order $order ): string {
		ob_start();
		FFL_Bridge_Order::display_admin_order_ffl( $order );
		return (string) ob_get_clean();
	}

	/** @param array<string, mixed> $meta Meta. */
	private function order( array $meta ): WC_Order {
		$order          = new WC_Order( $meta, self::ORDER_ID );
		$order->billing = array( 'first_name' => 'Pat', 'last_name' => 'Shopper' );
		$GLOBALS['ffl_bridge_test_orders'][ self::ORDER_ID ] = $order;
		return $order;
	}

	/** @param array<string, mixed> $extra Extra meta. */
	private function order_with_dealer( array $extra = array() ): WC_Order {
		return $this->order(
			array_merge(
				array(
					'_ffl_bridge_dealer_id'       => self::FIX_DEALER_ID,
					'_ffl_bridge_license'         => self::FIX_LICENSE,
					'_ffl_bridge_name'            => 'Example Arms',
					'_ffl_bridge_address'         => '123 Main Street',
					'_ffl_bridge_city'            => 'Orlando',
					'_ffl_bridge_state'           => 'FL',
					'_ffl_bridge_zip'             => '32801',
					'_ffl_bridge_transfer_status' => 'confirmed',
					'_ffl_bridge_selection_basis' => 'verified_network',
					'_ffl_bridge_source'          => 'ffl_bridge_api',
				),
				$extra
			)
		);
	}

	/** @return array<string, mixed> */
	private function other_overrides(): array {
		return array( 'id' => self::OTHER_ID, 'licenseNumber' => self::OTHER_LIC, 'tradeName' => 'Other Arms', 'address' => '500 Other Road' );
	}

	/** @return array<string, mixed> */
	private function other_raw(): array {
		return $this->raw_dealer( $this->other_overrides() );
	}
}
