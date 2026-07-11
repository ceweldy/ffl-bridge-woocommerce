<?php
/**
 * API client validation and normalization tests.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

final class ApiClientTest extends FFL_Bridge_TestCase {
	public function test_api_key_validation_is_strict(): void {
		$this->assertTrue( FFL_Bridge_API_Client::is_valid_api_key( 'ffl_live_' . str_repeat( 'A1', 16 ) ) );
		$this->assertFalse( FFL_Bridge_API_Client::is_valid_api_key( 'ffl_test_' . str_repeat( 'A', 32 ) ) );
		$this->assertFalse( FFL_Bridge_API_Client::is_valid_api_key( 'ffl_live_' . str_repeat( 'A', 31 ) ) );
		$this->assertFalse( FFL_Bridge_API_Client::is_valid_api_key( 'ffl_live_' . str_repeat( 'A', 33 ) ) );
		$this->assertFalse( FFL_Bridge_API_Client::is_valid_api_key( 'ffl_live_' . str_repeat( 'A', 32 ) . '-' ) );
		$this->assertFalse( FFL_Bridge_API_Client::is_valid_api_key( ' ffl_live_' . str_repeat( 'A', 32 ) ) );
	}

	public function test_normalize_dealer_returns_only_bounded_allowlisted_fields(): void {
		$raw = $this->validDealer(
			array(
				'tradeName'     => '<b>Example Arms</b>',
				'state'         => 'fl',
				'distance'      => 9999,
				'email'         => 'private@example.test',
				'latitude'      => 28.0,
				'longitude'     => -81.0,
				'isActive'      => true,
			)
		);

		$dealer = FFL_Bridge_API_Client::normalize_dealer( $raw );

		$this->assertIsArray( $dealer );
		$this->assertSame( 'example arms', strtolower( $dealer['name'] ) );
		$this->assertSame( 'FL', $dealer['state'] );
		$this->assertSame( 500.0, $dealer['distance'] );
		$this->assertTrue( $dealer['accepts_transfers'] );
		$this->assertTrue( $dealer['is_active'] );
		$this->assertArrayNotHasKey( 'email', $dealer );
		$this->assertArrayNotHasKey( 'latitude', $dealer );
		$this->assertArrayNotHasKey( 'longitude', $dealer );
	}

	public function test_business_name_is_used_when_trade_name_is_empty(): void {
		$dealer = FFL_Bridge_API_Client::normalize_dealer( $this->validDealer( array( 'tradeName' => '' ) ) );

		$this->assertIsArray( $dealer );
		$this->assertSame( 'Example Firearms LLC', $dealer['name'] );
	}

	public function test_string_boolean_values_are_not_treated_as_true(): void {
		$dealer = FFL_Bridge_API_Client::normalize_dealer(
			$this->validDealer(
				array(
					'acceptsTransfers' => 'false',
					'isActive'         => 'false',
				)
			)
		);

		$this->assertIsArray( $dealer );
		$this->assertFalse( $dealer['accepts_transfers'] );
		$this->assertFalse( $dealer['is_active'] );
	}

	#[DataProvider( 'invalid_dealer_provider' )]
	public function test_invalid_or_incomplete_dealer_is_rejected( array $changes ): void {
		$result = FFL_Bridge_API_Client::normalize_dealer( $this->validDealer( $changes ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffl_bridge_invalid_dealer', $result->get_error_code() );
	}

	/** @return iterable<string, array{0: array<string, mixed>}> */
	public static function invalid_dealer_provider(): iterable {
		yield 'bad UUID' => array( array( 'id' => 'not-a-uuid' ) );
		yield 'UUID with suffix' => array( array( 'id' => '123e4567-e89b-12d3-a456-426614174000-extra' ) );
		yield 'bad license' => array( array( 'licenseNumber' => '123' ) );
		yield 'missing names' => array( array( 'tradeName' => '', 'businessName' => '' ) );
		yield 'missing street' => array( array( 'address' => '' ) );
		yield 'missing city' => array( array( 'city' => '' ) );
		yield 'bad state' => array( array( 'state' => 'Florida' ) );
		yield 'bad ZIP' => array( array( 'zip' => 'ABCDE' ) );
		yield 'ZIP with suffix' => array( array( 'zip' => '32801abc' ) );
	}

	/**
	 * @param array<string, mixed> $changes Overrides.
	 * @return array<string, mixed>
	 */
	private function validDealer( array $changes = array() ): array {
		return array_replace(
			array(
				'id'               => '123e4567-e89b-12d3-a456-426614174000',
				'licenseNumber'    => '1-23-456-78-9A-01234',
				'licenseType'      => '01 - Dealer in Firearms',
				'tradeName'        => 'Example Arms',
				'businessName'     => 'Example Firearms LLC',
				'address'          => '123 Main Street',
				'city'             => 'Orlando',
				'state'            => 'FL',
				'zip'              => '32801',
				'phone'            => '407-555-0100',
				'distance'         => 4.24,
				'acceptsTransfers' => true,
				'isActive'         => true,
			),
			$changes
		);
	}
}
