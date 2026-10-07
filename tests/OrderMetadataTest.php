<?php
/**
 * Current and legacy order metadata read tests.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class OrderMetadataTest extends FFL_Bridge_TestCase {
	public function test_current_namespaced_metadata_is_read(): void {
		$order = new WC_Order(
			array(
				'_ffl_bridge_dealer_id'        => '123e4567-e89b-12d3-a456-426614174000',
				'_ffl_bridge_license'          => '1-23-456-78-9A-01234',
				'_ffl_bridge_license_type'     => '01',
				'_ffl_bridge_name'             => 'Example Arms',
				'_ffl_bridge_business_name'    => 'Example Firearms LLC',
				'_ffl_bridge_address'          => '123 Main Street',
				'_ffl_bridge_city'             => 'Orlando',
				'_ffl_bridge_state'            => 'FL',
				'_ffl_bridge_zip'              => '32801',
				'_ffl_bridge_phone'            => '407-555-0100',
				'_ffl_bridge_license_on_file'  => 'yes',
				'_ffl_bridge_license_verified' => 'no',
				'_ffl_bridge_verified_at'      => '2026-07-11T12:00:00+00:00',
				'_ffl_bridge_source'           => 'ffl_bridge_api',
			)
		);

		$data = FFL_Bridge_Order::get_ffl_data( $order );

		$this->assertIsArray( $data );
		$this->assertSame( '123e4567-e89b-12d3-a456-426614174000', $data['dealer_id'] );
		$this->assertSame( '1-23-456-78-9A-01234', $data['license'] );
		$this->assertSame( 'Example Arms', $data['name'] );
		$this->assertSame( 'yes', $data['license_on_file'] );
		$this->assertSame( 'no', $data['verified'] );
		$this->assertSame( 'ffl_bridge_api', $data['source'] );
	}

	public function test_legacy_metadata_remains_readable_and_is_labeled_legacy(): void {
		$order = new WC_Order(
			array(
				'_ffl_license' => '1-23-456-78-9A-01234',
				'_ffl_name'    => 'Legacy Dealer',
				'_ffl_address' => '456 Old Road',
				'_ffl_city'    => 'Tampa',
				'_ffl_state'   => 'FL',
				'_ffl_zip'     => '33602',
				'_ffl_phone'   => '813-555-0100',
			)
		);

		$data = FFL_Bridge_Order::get_ffl_data( $order );

		$this->assertIsArray( $data );
		$this->assertSame( 'Legacy Dealer', $data['name'] );
		$this->assertSame( 'legacy', $data['source'] );
		$this->assertSame( '', $data['dealer_id'] );
		$this->assertSame( '', $data['verified_at'] );
	}

	public function test_current_metadata_takes_precedence_over_legacy_metadata(): void {
		$order = new WC_Order(
			array(
				'_ffl_bridge_license' => '1-23-456-78-9A-01234',
				'_ffl_bridge_name'    => 'Current Dealer',
				'_ffl_license'        => '9-99-999-99-9Z-99999',
				'_ffl_name'           => 'Legacy Dealer',
			)
		);

		$data = FFL_Bridge_Order::get_ffl_data( $order );

		$this->assertIsArray( $data );
		$this->assertSame( 'Current Dealer', $data['name'] );
		$this->assertSame( '1-23-456-78-9A-01234', $data['license'] );
		$this->assertNotSame( 'legacy', $data['source'] );
	}

	public function test_transfer_confirmation_flag_is_read_and_defaults_to_confirmed(): void {
		$unconfirmed = FFL_Bridge_Order::get_ffl_data(
			new WC_Order(
				array(
					'_ffl_bridge_license'            => '1-23-456-78-9A-01234',
					'_ffl_bridge_transfer_confirmed' => 'no',
				)
			)
		);
		$older       = FFL_Bridge_Order::get_ffl_data( new WC_Order( array( '_ffl_bridge_license' => '1-23-456-78-9A-01234' ) ) );
		$legacy      = FFL_Bridge_Order::get_ffl_data( new WC_Order( array( '_ffl_license' => '1-23-456-78-9A-01234' ) ) );

		$this->assertTrue( FFL_Bridge_Order::is_unconfirmed( $unconfirmed ) );
		$this->assertFalse( FFL_Bridge_Order::is_unconfirmed( $older ) );
		$this->assertFalse( FFL_Bridge_Order::is_unconfirmed( $legacy ) );
	}

	public function test_order_without_any_license_has_no_ffl_data(): void {
		$this->assertNull( FFL_Bridge_Order::get_ffl_data( new WC_Order() ) );
		$this->assertNull( FFL_Bridge_Order::get_ffl_data( false ) );
	}

	public function test_address_formatting_handles_complete_and_partial_locations(): void {
		$this->assertSame(
			'123 Main Street, Orlando, FL 32801',
			FFL_Bridge_Order::format_address( array( 'address' => '123 Main Street', 'city' => 'Orlando', 'state' => 'FL', 'zip' => '32801' ) )
		);
		$this->assertSame( 'Orlando', FFL_Bridge_Order::format_address( array( 'city' => 'Orlando' ) ) );
	}
}
