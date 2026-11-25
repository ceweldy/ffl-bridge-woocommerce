<?php
/**
 * Signed selection tests.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class SelectionTest extends FFL_Bridge_TestCase {
	private const NOW = 1700000000;

	public function test_valid_token_round_trips_dealer_identity(): void {
		$token = FFL_Bridge_Selection::create( $this->dealer(), self::NOW );

		$this->assertIsString( $token );
		$payload = FFL_Bridge_Selection::verify( $token, self::NOW + 30 );
		$this->assertIsArray( $payload );
		$this->assertSame( $this->dealer()['id'], $payload['id'] );
		$this->assertSame( $this->dealer()['license'], $payload['lic'] );
	}

	public function test_payload_tampering_is_rejected(): void {
		$token = FFL_Bridge_Selection::create( $this->dealer(), self::NOW );
		$this->assertIsString( $token );

		list( $payload, $signature ) = explode( '.', $token, 2 );
		$replacement = 'A' === $payload[0] ? 'B' : 'A';
		$tampered    = $replacement . substr( $payload, 1 ) . '.' . $signature;
		$result      = FFL_Bridge_Selection::verify( $tampered, self::NOW + 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffl_bridge_invalid_selection', $result->get_error_code() );
	}

	public function test_signature_tampering_is_rejected(): void {
		$token = FFL_Bridge_Selection::create( $this->dealer(), self::NOW );
		$this->assertIsString( $token );

		list( $payload, $signature ) = explode( '.', $token, 2 );
		$replacement = 'A' === $signature[0] ? 'B' : 'A';
		$tampered    = $payload . '.' . $replacement . substr( $signature, 1 );
		$result      = FFL_Bridge_Selection::verify( $tampered, self::NOW + 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffl_bridge_invalid_selection', $result->get_error_code() );
	}

	public function test_expired_token_is_rejected(): void {
		$token = FFL_Bridge_Selection::create( $this->dealer(), self::NOW );
		$this->assertIsString( $token );

		$result = FFL_Bridge_Selection::verify( $token, self::NOW + 901 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffl_bridge_invalid_selection', $result->get_error_code() );
	}

	public function test_cart_change_invalidates_token(): void {
		$token = FFL_Bridge_Selection::create( $this->dealer(), self::NOW );
		$this->assertIsString( $token );

		WC()->cart = new FFL_Bridge_Test_Cart( array( array( 'product_id' => 100, 'quantity' => 2 ) ) );
		$result = FFL_Bridge_Selection::verify( $token, self::NOW + 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffl_bridge_stale_selection', $result->get_error_code() );
	}

	// WooCommerce's cart hash includes the cart total, which changes whenever
	// shipping is recalculated, for example after the shipping address becomes
	// the dealer's or the shopper picks another shipping method. Found on a
	// real WooCommerce 11.3 store, where the selection was lost before payment.
	public function test_a_total_change_keeps_the_selection_but_a_contents_change_does_not(): void {
		$token = FFL_Bridge_Selection::create( $this->dealer(), self::NOW );
		WC()->session->set( 'ffl_bridge_selection_v1', array( 'dealer' => $this->dealer(), 'cart_hash' => FFL_Bridge_Selection::get_cart_hash(), 'expires_at' => time() + 3600 ) );

		WC()->cart->set_cart_hash( 'hash-after-shipping-recalculated' );
		$this->assertIsArray( FFL_Bridge_Selection::verify( (string) $token, self::NOW + 1 ) );
		$this->assertIsArray( FFL_Bridge_Checkout::get_current_selection() );

		WC()->cart = new FFL_Bridge_Test_Cart( array( array( 'product_id' => 100 ), array( 'product_id' => 200 ) ) );
		$this->assertNull( FFL_Bridge_Checkout::get_current_selection() );
	}

	public function test_session_change_invalidates_token_and_request_token(): void {
		$token         = FFL_Bridge_Selection::create( $this->dealer(), self::NOW );
		$request_token = FFL_Bridge_Selection::get_request_token();
		$this->assertIsString( $token );
		$this->assertTrue( FFL_Bridge_Selection::verify_request_token( $request_token ) );

		WC()->session->set_customer_id( 'different-session' );

		$result = FFL_Bridge_Selection::verify( $token, self::NOW + 1 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffl_bridge_stale_selection', $result->get_error_code() );
		$this->assertFalse( FFL_Bridge_Selection::verify_request_token( $request_token ) );
	}

	/** @return array{id: string, license: string} */
	private function dealer(): array {
		return array(
			'id'      => '123e4567-e89b-12d3-a456-426614174000',
			'license' => '1-23-456-78-9A-01234',
		);
	}
}
