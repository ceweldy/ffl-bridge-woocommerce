<?php
/**
 * 1.3.0 item 4: store blocklist of dealer license numbers.
 *
 * Blocked dealers are hidden from search results and rejected again when a
 * shopper selects one and when the order is created, so an old result or a
 * replayed selection cannot get a blocked dealer onto an order.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

final class BlocklistTest extends FFL_Bridge_TestCase {
	use FFL_Bridge_Dealer_Fixtures;

	private const OPTION      = 'ffl_bridge_blocked_licenses';
	private const OTHER_ID    = '223e4567-e89b-12d3-a456-426614174000';
	private const OTHER_LIC   = '1-23-456-78-9A-05555';
	private const BLOCKED_MSG = 'That dealer is not available for orders from this store. Choose another dealer.';

	protected function setUp(): void {
		parent::setUp();
		$this->fresh_request();
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_required'] = 'yes';
	}

	public function test_blocklist_is_empty_by_default_and_normalizes_entries(): void {
		$this->assertSame( array(), FFL_Bridge_Network::get_blocked_licenses() );

		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = array( self::FIX_LICENSE );
		$this->assertTrue( FFL_Bridge_Network::is_blocked( '123456789A01234' ) );
		$this->assertTrue( FFL_Bridge_Network::is_blocked( self::FIX_LICENSE ) );
		$this->assertFalse( FFL_Bridge_Network::is_blocked( self::OTHER_LIC ) );
	}

	public function test_settings_save_one_license_per_line_and_warn_about_invalid_entries(): void {
		$saved = FFL_Bridge_Settings::sanitize_blocked_licenses( "123456789A01234\nnot-a-license\n" . self::OTHER_LIC . "\n" . self::FIX_LICENSE );

		$this->assertSame( array( self::FIX_LICENSE, self::OTHER_LIC ), $saved );
		$this->assertSame( 'ffl_bridge_invalid_blocked_licenses', $GLOBALS['ffl_bridge_test_settings_errors'][0][1] );
	}

	public function test_blocklist_holds_more_than_the_preferred_dealer_limit(): void {
		$lines = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$lines[] = sprintf( '1-23-456-78-9A-%05d', $i );
		}
		$this->assertCount( 300, FFL_Bridge_Settings::sanitize_blocked_licenses( implode( "\n", $lines ) ) );
	}

	public function test_search_hides_blocked_dealers(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = array( self::FIX_LICENSE );
		$this->queue_search( array( $this->raw_dealer(), $this->raw_dealer( array( 'id' => self::OTHER_ID, 'licenseNumber' => self::OTHER_LIC, 'tradeName' => 'Other Arms' ) ) ) );

		$response = $this->ajax( array( FFL_Bridge_Checkout::class, 'ajax_search' ), array( 'zip' => '32801', 'radius' => '25' ) );

		$this->assertTrue( $response->success );
		$this->assertSame( array( 'Other Arms' ), array_column( $response->data['dealers'], 'name' ) );
		$this->assertSame( 'confirmed', $response->data['outcome'] );
	}

	public function test_search_with_only_blocked_dealers_reports_no_confirmed_dealer(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = array( self::FIX_LICENSE );
		$this->queue_search( array( $this->raw_dealer() ) );
		// Hybrid mode asks once more for nearby dealers; the blocked one is hidden there too.
		$this->queue_search( array( $this->raw_dealer( array( 'checkoutEligible' => false ) ) ) );

		$response = $this->ajax( array( FFL_Bridge_Checkout::class, 'ajax_search' ), array( 'zip' => '32801', 'radius' => '25' ) );

		$this->assertSame( array(), $response->data['dealers'] );
		$this->assertSame( 'none', $response->data['outcome'] );
		$this->assertNotSame( '', $response->data['notice'] );
	}

	public function test_selecting_a_blocked_dealer_from_an_old_result_is_rejected(): void {
		// The result was shown before the store blocked the dealer.
		$token = FFL_Bridge_Selection::create( FFL_Bridge_API_Client::normalize_dealer( $this->raw_dealer() ) + array( 'network' => 'verified' ) );
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = array( self::FIX_LICENSE );
		$this->queue_dealer_lookup();

		$response = $this->ajax( array( FFL_Bridge_Checkout::class, 'ajax_select' ), array( 'selection' => $token ) );

		$this->assertFalse( $response->success );
		$this->assertSame( 409, $response->status );
		$this->assertSame( self::BLOCKED_MSG, $response->data['message'] );
		$this->assertNull( WC()->session->get( 'ffl_bridge_selection_v1' ) );
	}

	public function test_classic_order_with_a_dealer_blocked_after_selection_fails(): void {
		$this->select_in_session();
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = array( self::FIX_LICENSE );
		$this->queue_dealer_lookup();
		$order = $this->shopper_order();

		try {
			FFL_Bridge_Checkout::save_classic_order( $order );
			$this->fail( 'The order was created with a blocked dealer.' );
		} catch ( Exception $exception ) {
			$this->assertSame( self::BLOCKED_MSG, $exception->getMessage() );
		}
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_id' ) );
	}

	public function test_store_api_order_with_a_blocked_dealer_fails_with_a_400(): void {
		$this->select_in_session();
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = array( self::FIX_LICENSE );
		$this->queue_dealer_lookup();

		try {
			FFL_Bridge_Checkout::save_store_api_order( $this->shopper_order() );
			$this->fail( 'The order was created with a blocked dealer.' );
		} catch ( RouteException $exception ) {
			$this->assertSame( 'ffl_bridge_dealer_blocked', $exception->getErrorCode() );
			$this->assertSame( 400, $exception->getCode() );
		}
	}

	public function test_a_dealer_whose_license_changed_to_a_blocked_one_is_rejected_at_order_time(): void {
		// The session holds the old license; FFL Bridge now reports a blocked one.
		$this->select_in_session();
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = array( self::OTHER_LIC );
		$this->queue_dealer_lookup( array( 'licenseNumber' => self::OTHER_LIC ) );

		$this->expectException( Exception::class );
		FFL_Bridge_Checkout::save_classic_order( $this->shopper_order() );
	}

	public function test_unblocked_dealers_still_go_through(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = array( self::OTHER_LIC );
		$this->select_in_session();
		$this->queue_dealer_lookup();
		$order = $this->shopper_order();

		FFL_Bridge_Checkout::save_classic_order( $order );

		$this->assertSame( self::FIX_DEALER_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
	}
}
