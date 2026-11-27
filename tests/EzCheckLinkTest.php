<?php
/**
 * 1.3.0 item 3: ATF eZ Check link on the order dealer panel.
 *
 * Wording follows ceweldy/ffl-bridge#118: eZ Check is a quick license status
 * check, not a substitute for the license copy.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class EzCheckLinkTest extends FFL_Bridge_TestCase {
	private function order( array $meta = array() ): WC_Order {
		return new WC_Order(
			array_merge(
				array(
					'_ffl_bridge_dealer_id'       => '123e4567-e89b-12d3-a456-426614174000',
					'_ffl_bridge_license'         => '1-23-456-78-9A-01234',
					'_ffl_bridge_name'            => 'Example Arms',
					'_ffl_bridge_address'         => '123 Main Street',
					'_ffl_bridge_city'            => 'Orlando',
					'_ffl_bridge_state'           => 'FL',
					'_ffl_bridge_zip'             => '32801',
					'_ffl_bridge_transfer_status' => 'confirmed',
					'_ffl_bridge_selection_basis' => 'verified_network',
					'_ffl_bridge_source'          => 'ffl_bridge_api',
				),
				$meta
			)
		);
	}

	private function panel( WC_Order $order ): string {
		ob_start();
		FFL_Bridge_Order::display_admin_order_ffl( $order );
		return (string) ob_get_clean();
	}

	public function test_dealer_panel_links_to_atf_ez_check_in_a_new_tab(): void {
		$html = $this->panel( $this->order() );

		$this->assertMatchesRegularExpression( '#<a href="https://fflezcheck\.atf\.gov/FFLEzCheck/"[^>]*target="_blank"[^>]*rel="noopener noreferrer"[^>]*>#', $html );
		$this->assertStringContainsString( 'Check this license with ATF eZ Check', $html );
		$this->assertStringContainsString( '(opens in a new tab)', $html );
	}

	public function test_link_shows_the_license_parts_ez_check_asks_for(): void {
		$html = $this->panel( $this->order() );

		$this->assertStringContainsString( 'first 3 digits: <code>123</code>', $html );
		$this->assertStringContainsString( 'last 5 digits: <code>01234</code>', $html );
	}

	public function test_link_says_ez_check_is_not_a_substitute_for_the_license_copy(): void {
		$html = $this->panel( $this->order() );

		$this->assertStringContainsString( 'ATF eZ Check is a quick license status check. It is not a substitute for the license copy.', $html );
		$this->assertStringContainsString( 'This is not legal advice.', $html );
	}

	public function test_legacy_orders_get_the_link_too(): void {
		$order = new WC_Order(
			array(
				'_ffl_license' => '9-87-654-32-1B-98765',
				'_ffl_name'    => 'Legacy Arms',
			)
		);

		$html = $this->panel( $order );

		$this->assertStringContainsString( 'https://fflezcheck.atf.gov/FFLEzCheck/', $html );
		$this->assertStringContainsString( '<code>987</code>', $html );
		$this->assertStringContainsString( '<code>98765</code>', $html );
	}

	public function test_a_malformed_license_gets_the_link_without_parts(): void {
		$html = $this->panel( $this->order( array( '_ffl_bridge_license' => 'not a license' ) ) );

		$this->assertStringContainsString( 'https://fflezcheck.atf.gov/FFLEzCheck/', $html );
		$this->assertStringNotContainsString( 'first 3 digits', $html );
	}

	public function test_ez_check_parts_parser(): void {
		$this->assertSame( array( '123', '01234' ), FFL_Bridge_Order::ez_check_parts( '1-23-456-78-9A-01234' ) );
		$this->assertSame( array( '123', '01234' ), FFL_Bridge_Order::ez_check_parts( '123456789A01234' ) );
		$this->assertNull( FFL_Bridge_Order::ez_check_parts( '1-23' ) );
	}
}
