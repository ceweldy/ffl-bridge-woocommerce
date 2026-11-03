<?php
/**
 * Customer-facing dealer block on the order-received page and My Account.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class OrderDisplayTest extends FFL_Bridge_TestCase {
	private const ORDER_ID = 501;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ffl_bridge_test_orders'][ self::ORDER_ID ] = new WC_Order(
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
			self::ORDER_ID
		);
	}

	public function test_order_received_page_shows_the_dealer_block_once(): void {
		$GLOBALS['ffl_bridge_test_order_received'] = true;
		$order = wc_get_order( self::ORDER_ID );

		// WooCommerce fires both hooks on the order-received page: the
		// thank-you hook first, then the order details table inside it.
		$html = $this->capture(
			static function () use ( $order ): void {
				FFL_Bridge_Order::display_thankyou_ffl( self::ORDER_ID );
				FFL_Bridge_Order::display_order_details_ffl( $order );
			}
		);

		$this->assertSame( 1, substr_count( $html, 'Selected transfer dealer' ) );
	}

	public function test_my_account_order_view_still_shows_the_dealer_block(): void {
		$html = $this->capture( static fn () => FFL_Bridge_Order::display_order_details_ffl( wc_get_order( self::ORDER_ID ) ) );

		$this->assertSame( 1, substr_count( $html, 'Selected transfer dealer' ) );
		$this->assertStringContainsString( 'Example Arms', $html );
	}

	private function capture( callable $render ): string {
		ob_start();
		try {
			$render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}
}
