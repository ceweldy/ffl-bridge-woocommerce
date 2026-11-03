<?php
/**
 * Checkout Block (Store API) and classic checkout without a dealer.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

final class StoreApiCheckoutTest extends FFL_Bridge_TestCase {
	private const MESSAGE = 'Select and verify a transfer dealer before placing the order.';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_required'] = 'yes';
	}

	public function test_store_api_order_without_a_dealer_fails_with_a_400_route_exception(): void {
		try {
			FFL_Bridge_Checkout::save_store_api_order( new WC_Order() );
		} catch ( RouteException $exception ) {
			$this->assertSame( 'ffl_bridge_selection_required', $exception->getErrorCode() );
			$this->assertSame( self::MESSAGE, $exception->getMessage() );
			$this->assertSame( 400, $exception->getCode() );
			return;
		}

		$this->fail( 'The Store API order was not rejected with a RouteException.' );
	}

	public function test_store_api_order_with_an_optional_selection_is_accepted(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_required'] = 'no';
		$order = new WC_Order();

		FFL_Bridge_Checkout::save_store_api_order( $order );

		$this->assertSame( array(), $order->all_meta() );
	}

	public function test_classic_checkout_still_adds_the_notice_and_throws_the_message(): void {
		$GLOBALS['ffl_bridge_test_notices'] = array();
		FFL_Bridge_Checkout::validate_classic_checkout();
		$this->assertSame( array( array( self::MESSAGE, 'error' ) ), $GLOBALS['ffl_bridge_test_notices'] );

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( self::MESSAGE );
		FFL_Bridge_Checkout::save_classic_order( new WC_Order() );
	}
}
