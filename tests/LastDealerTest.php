<?php
/**
 * 1.3.0 item 5: the logged-in customer's last-used dealer is listed first.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class LastDealerTest extends FFL_Bridge_TestCase {
	use FFL_Bridge_Dealer_Fixtures;

	private const OPTION    = 'ffl_bridge_last_dealer_first';
	private const META      = '_ffl_bridge_last_dealer_id';
	private const CUSTOMER  = 42;
	private const OTHER_ID  = '223e4567-e89b-12d3-a456-426614174000';
	private const OTHER_LIC = '1-23-456-78-9A-05555';

	protected function setUp(): void {
		parent::setUp();
		$this->fresh_request();
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$GLOBALS['ffl_bridge_test_user_id']                = self::CUSTOMER;
	}

	public function test_a_logged_in_customers_order_remembers_the_dealer(): void {
		$this->select_in_session();
		$this->queue_dealer_lookup();
		$order              = $this->shopper_order();
		$order->customer_id = self::CUSTOMER;

		FFL_Bridge_Checkout::save_classic_order( $order );

		$this->assertSame( self::FIX_DEALER_ID, get_user_meta( self::CUSTOMER, self::META, true ) );
	}

	public function test_guest_orders_and_the_setting_off_store_nothing(): void {
		$this->select_in_session();
		$this->queue_dealer_lookup();
		FFL_Bridge_Checkout::save_classic_order( $this->shopper_order() );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_user_meta'] );

		$this->fresh_request();
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'no';
		$this->queue_dealer_lookup();
		$order              = $this->shopper_order();
		$order->customer_id = self::CUSTOMER;
		FFL_Bridge_Checkout::save_classic_order( $order );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_user_meta'] );
	}

	public function test_the_last_dealer_is_pinned_first_when_it_is_in_range(): void {
		update_user_meta( self::CUSTOMER, self::META, self::OTHER_ID );
		$this->queue_search( array( $this->raw_dealer(), $this->other_dealer() ) );

		$dealers = $this->search();

		$this->assertSame( array( 'Other Arms', 'Example Arms' ), array_column( $dealers, 'name' ) );
		$this->assertTrue( $dealers[0]['lastUsed'] );
		$this->assertFalse( $dealers[1]['lastUsed'] );
	}

	public function test_the_last_dealer_outside_the_results_changes_nothing(): void {
		update_user_meta( self::CUSTOMER, self::META, '323e4567-e89b-12d3-a456-426614174000' );
		$this->queue_search( array( $this->raw_dealer(), $this->other_dealer() ) );

		$dealers = $this->search();

		$this->assertSame( array( 'Example Arms', 'Other Arms' ), array_column( $dealers, 'name' ) );
		$this->assertSame( array( false, false ), array_column( $dealers, 'lastUsed' ) );
	}

	public function test_guests_and_the_setting_off_keep_the_normal_order(): void {
		update_user_meta( self::CUSTOMER, self::META, self::OTHER_ID );

		$GLOBALS['ffl_bridge_test_user_id'] = 0;
		$this->queue_search( array( $this->raw_dealer(), $this->other_dealer() ) );
		$this->assertSame( array( 'Example Arms', 'Other Arms' ), array_column( $this->search(), 'name' ) );

		$GLOBALS['ffl_bridge_test_user_id']                = self::CUSTOMER;
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'no';
		$GLOBALS['ffl_bridge_test_transients']             = array();
		WC()->session->set( 'ffl_bridge_rate_search', array() );
		$this->queue_search( array( $this->raw_dealer(), $this->other_dealer() ) );
		$this->assertSame( array( 'Example Arms', 'Other Arms' ), array_column( $this->search(), 'name' ) );
	}

	public function test_a_directory_only_last_dealer_is_not_pinned_above_selectable_dealers(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_dealer_mode'] = 'confirmed_only';
		update_user_meta( self::CUSTOMER, self::META, self::OTHER_ID );
		$this->queue_search( array( $this->raw_dealer(), $this->other_dealer( array( 'checkoutEligible' => false ) ) ) );

		$dealers = $this->search();

		$this->assertSame( 'Example Arms', $dealers[0]['name'] );
	}

	public function test_existing_stores_are_offered_the_setting(): void {
		$GLOBALS['ffl_bridge_test_options'] = array( 'ffl_bridge_settings_version' => '1.2.1' );
		FFL_Bridge_Features::maybe_migrate();

		$this->assertSame( 'no', get_option( self::OPTION ) );
		$this->assertContains( 'last_dealer', FFL_Bridge_Features::pending_offers() );
	}

	public function test_personal_data_export_and_erase_cover_the_saved_dealer(): void {
		$GLOBALS['ffl_bridge_test_users'] = array( self::CUSTOMER => 'pat@example.test' );
		update_user_meta( self::CUSTOMER, self::META, self::OTHER_ID );

		$export = FFL_Bridge_Last_Dealer::export_personal_data( 'pat@example.test' );
		$this->assertTrue( $export['done'] );
		$this->assertSame( self::OTHER_ID, $export['data'][0]['data'][0]['value'] );

		$erase = FFL_Bridge_Last_Dealer::erase_personal_data( 'pat@example.test' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertSame( '', get_user_meta( self::CUSTOMER, self::META, true ) );

		$this->assertSame( array(), FFL_Bridge_Last_Dealer::export_personal_data( 'nobody@example.test' )['data'] );
	}

	/** @return array<int, array<string, mixed>> */
	private function search(): array {
		$response = $this->ajax( array( FFL_Bridge_Checkout::class, 'ajax_search' ), array( 'zip' => '32801', 'radius' => '25' ) );
		$this->assertTrue( $response->success );
		return $response->data['dealers'];
	}

	/** @param array<string, mixed> $overrides Overrides. */
	private function other_dealer( array $overrides = array() ): array {
		return $this->raw_dealer( array_merge( array( 'id' => self::OTHER_ID, 'licenseNumber' => self::OTHER_LIC, 'tradeName' => 'Other Arms' ), $overrides ) );
	}
}
