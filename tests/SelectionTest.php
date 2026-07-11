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

		WC()->cart->set_cart_hash( 'different-cart-hash' );
		$result = FFL_Bridge_Selection::verify( $token, self::NOW + 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffl_bridge_stale_selection', $result->get_error_code() );
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
