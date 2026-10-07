<?php
/**
 * Directory versus verified checkout network tests.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class NetworkTest extends FFL_Bridge_TestCase {
	public function test_license_numbers_are_normalized_with_or_without_dashes(): void {
		$this->assertSame( '1-23-456-78-9A-01234', FFL_Bridge_Network::normalize_license( '1-23-456-78-9a-01234' ) );
		$this->assertSame( '1-23-456-78-9A-01234', FFL_Bridge_Network::normalize_license( ' 123456789A01234 ' ) );
		$this->assertSame( '', FFL_Bridge_Network::normalize_license( '1-23-456-78-9A-0123' ) );
		$this->assertSame( '', FFL_Bridge_Network::normalize_license( 'A-23-456-78-9A-01234' ) );
	}

	public function test_license_list_drops_invalid_and_duplicate_entries(): void {
		$list = FFL_Bridge_Network::parse_license_list( "1-23-456-78-9A-01234\n123456789a01234, not-a-license;5-55-555-55-5B-55555" );

		$this->assertSame( array( '1-23-456-78-9A-01234', '5-55-555-55-5B-55555' ), $list );
		$this->assertSame( array(), FFL_Bridge_Network::parse_license_list( 42 ) );
	}

	public function test_license_list_is_bounded(): void {
		$input = array();
		for ( $i = 0; $i < FFL_Bridge_Network::MAX_PREFERRED + 5; $i++ ) {
			$input[] = sprintf( '1-23-456-78-9A-%05d', $i );
		}

		$this->assertCount( FFL_Bridge_Network::MAX_PREFERRED, FFL_Bridge_Network::parse_license_list( $input ) );
	}

	public function test_scope_defaults_to_all(): void {
		$this->assertSame( FFL_Bridge_Network::SCOPE_ALL, FFL_Bridge_Network::get_result_scope() );
		$this->assertSame( FFL_Bridge_Network::SCOPE_ALL, FFL_Bridge_Network::sanitize_scope( 'anything' ) );
		$this->assertSame( FFL_Bridge_Network::SCOPE_VERIFIED, FFL_Bridge_Network::sanitize_scope( 'verified' ) );
	}

	public function test_classification_follows_checkout_flag(): void {
		$this->assertSame( FFL_Bridge_Network::NETWORK_VERIFIED, FFL_Bridge_Network::classify( array( 'checkout_eligible' => true ) ) );
		$this->assertSame( FFL_Bridge_Network::NETWORK_DIRECTORY, FFL_Bridge_Network::classify( array( 'checkout_eligible' => false ) ) );
		$this->assertSame( FFL_Bridge_Network::NETWORK_UNKNOWN, FFL_Bridge_Network::classify( array( 'checkout_eligible' => null ) ) );
		$this->assertSame( FFL_Bridge_Network::NETWORK_UNKNOWN, FFL_Bridge_Network::classify( array() ) );

		$this->assertTrue( FFL_Bridge_Network::is_selectable( FFL_Bridge_Network::NETWORK_VERIFIED ) );
		$this->assertTrue( FFL_Bridge_Network::is_selectable( FFL_Bridge_Network::NETWORK_UNKNOWN ) );
		$this->assertFalse( FFL_Bridge_Network::is_selectable( FFL_Bridge_Network::NETWORK_DIRECTORY ) );
	}

	public function test_all_scope_orders_selectable_then_preferred_then_distance(): void {
		$dealers = array(
			$this->dealer( 'near-directory', '1-11-111-11-1A-00001', false ),
			$this->dealer( 'near-verified', '1-11-111-11-1A-00002', true ),
			$this->dealer( 'far-verified-preferred', '1-11-111-11-1A-00003', true ),
			$this->dealer( 'far-directory-preferred', '1-11-111-11-1A-00004', false ),
		);

		$prepared = FFL_Bridge_Network::prepare_results(
			$dealers,
			FFL_Bridge_Network::SCOPE_ALL,
			array( '1-11-111-11-1A-00003', '1-11-111-11-1A-00004' )
		);

		$this->assertSame(
			array( 'far-verified-preferred', 'near-verified', 'far-directory-preferred', 'near-directory' ),
			array_column( $prepared, 'name' )
		);
		$this->assertSame( array( true, true, false, false ), array_column( $prepared, 'selectable' ) );
		$this->assertSame( array( true, false, true, false ), array_column( $prepared, 'store_preferred' ) );
		$this->assertSame( 'directory', $prepared[2]['network'] );
		$this->assertArrayNotHasKey( 'original_position', $prepared[0] );
	}

	public function test_verified_scope_hides_directory_listings_but_keeps_unknown(): void {
		$dealers = array(
			$this->dealer( 'directory', '1-11-111-11-1A-00001', false ),
			$this->dealer( 'verified', '1-11-111-11-1A-00002', true ),
			$this->dealer( 'legacy-api', '1-11-111-11-1A-00003', null ),
		);

		$prepared = FFL_Bridge_Network::prepare_results( $dealers, FFL_Bridge_Network::SCOPE_VERIFIED, array() );

		$this->assertSame( array( 'verified', 'legacy-api' ), array_column( $prepared, 'name' ) );
		$this->assertSame( array( 'verified', 'unknown' ), array_column( $prepared, 'network' ) );
	}

	public function test_network_counts(): void {
		$counts = FFL_Bridge_Network::count_by_network(
			array(
				$this->dealer( 'a', '1-11-111-11-1A-00001', false ),
				$this->dealer( 'b', '1-11-111-11-1A-00002', false ),
				$this->dealer( 'c', '1-11-111-11-1A-00003', true ),
			)
		);

		$this->assertSame( array( 'verified' => 1, 'directory' => 2, 'unknown' => 0 ), $counts );
	}

	public function test_preferred_license_setting_reports_dropped_entries(): void {
		$saved = FFL_Bridge_Settings::sanitize_preferred_licenses( "1-23-456-78-9A-01234\nbogus" );

		$this->assertSame( array( '1-23-456-78-9A-01234' ), $saved );
		$this->assertCount( 1, $GLOBALS['ffl_bridge_test_settings_errors'] );

		$GLOBALS['ffl_bridge_test_settings_errors'] = array();
		FFL_Bridge_Settings::sanitize_preferred_licenses( '' );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_settings_errors'] );
	}

	public function test_connection_summary_reports_network_coverage(): void {
		$transfer = array(
			'dealers'  => array(
				$this->dealer( 'a', '1-11-111-11-1A-00001', true ),
				$this->dealer( 'b', '1-11-111-11-1A-00002', false ),
			),
			'coverage' => null,
			'reason'   => '',
		);

		$summary = FFL_Bridge_Settings::connection_summary( '32174', 25, $transfer, null );
		$this->assertStringContainsString( 'Within 25 miles of 32174: 2 dealers listed as accepting transfers, 1 in the verified checkout network.', $summary );
		$this->assertStringNotContainsString( 'fallback', $summary );

		$legacy = FFL_Bridge_Settings::connection_summary( '32174', 25, array( 'dealers' => array( $this->dealer( 'a', '1-11-111-11-1A-00001', null ) ) ), null );
		$this->assertStringContainsString( 'did not report checkout-network status', $legacy );
	}

	public function test_connection_summary_reports_zero_coverage_and_fallback_potential(): void {
		$transfer = array(
			'dealers'  => array(),
			'coverage' => array( 'dealers_in_radius' => 41 ),
			'reason'   => 'NO_TRANSFER_DEALERS_IN_RADIUS',
		);

		$summary = FFL_Bridge_Settings::connection_summary( '48047', 100, $transfer, 25 );

		$this->assertStringContainsString( 'Within 100 miles of 48047: 0 dealers listed as accepting transfers, 0 in the verified checkout network.', $summary );
		$this->assertStringContainsString( 'FFL Bridge reports 41 licensed dealers in this area.', $summary );
		$this->assertStringContainsString( '25 nearby listed dealers could be offered as unconfirmed', $summary );
	}

	public function test_fallback_is_off_by_default(): void {
		$this->assertFalse( FFL_Bridge_Network::fallback_enabled() );
		$this->assertSame( 'no', FFL_Bridge_Network::sanitize_fallback( '1' ) );

		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_fallback'] = 'yes';
		$this->assertTrue( FFL_Bridge_Network::fallback_enabled() );
	}

	public function test_resolve_returns_confirmed_without_calling_the_nearby_search(): void {
		$called   = false;
		$resolved = FFL_Bridge_Network::resolve(
			array( 'dealers' => array( $this->dealer( 'verified', '1-11-111-11-1A-00001', true ) ) ),
			static function () use ( &$called ): array {
				$called = true;
				return array( 'dealers' => array() );
			},
			FFL_Bridge_Network::SCOPE_ALL,
			array(),
			true
		);

		$this->assertSame( FFL_Bridge_Network::OUTCOME_CONFIRMED, $resolved['outcome'] );
		$this->assertFalse( $called );
	}

	public function test_zero_transfer_results_without_fallback_is_none_and_skips_nearby_search(): void {
		$called   = false;
		$resolved = FFL_Bridge_Network::resolve(
			array( 'dealers' => array() ),
			function () use ( &$called ): array {
				$called = true;
				return array( 'dealers' => array( $this->dealer( 'nearby', '1-11-111-11-1A-00001', false ) ) );
			},
			FFL_Bridge_Network::SCOPE_ALL,
			array(),
			false
		);

		$this->assertSame( FFL_Bridge_Network::OUTCOME_NONE, $resolved['outcome'] );
		$this->assertSame( array(), $resolved['dealers'] );
		$this->assertFalse( $called );
	}

	public function test_zero_transfer_results_with_fallback_offers_unconfirmed_nearby_dealers(): void {
		$resolved = FFL_Bridge_Network::resolve(
			array( 'dealers' => array() ),
			fn (): array => array(
				'dealers' => array(
					$this->dealer( 'near', '1-11-111-11-1A-00001', false ),
					$this->dealer( 'far-preferred', '1-11-111-11-1A-00002', null ),
				),
			),
			FFL_Bridge_Network::SCOPE_VERIFIED,
			array( '1-11-111-11-1A-00002' ),
			true
		);

		$this->assertSame( FFL_Bridge_Network::OUTCOME_FALLBACK, $resolved['outcome'] );
		$this->assertSame( array( 'far-preferred', 'near' ), array_column( $resolved['dealers'], 'name' ) );
		$this->assertSame( array( 'unconfirmed', 'unconfirmed' ), array_column( $resolved['dealers'], 'network' ) );
		$this->assertSame( array( true, true ), array_column( $resolved['dealers'], 'selectable' ) );
	}

	public function test_fallback_reuses_directory_only_transfer_results_without_a_second_search(): void {
		$called   = false;
		$resolved = FFL_Bridge_Network::resolve(
			array( 'dealers' => array( $this->dealer( 'unverified', '1-11-111-11-1A-00001', false ) ) ),
			static function () use ( &$called ): array {
				$called = true;
				return array( 'dealers' => array() );
			},
			FFL_Bridge_Network::SCOPE_ALL,
			array(),
			true
		);

		$this->assertSame( FFL_Bridge_Network::OUTCOME_FALLBACK, $resolved['outcome'] );
		$this->assertSame( array( 'unverified' ), array_column( $resolved['dealers'], 'name' ) );
		$this->assertFalse( $called );
	}

	public function test_fallback_with_failed_or_empty_nearby_search_is_none(): void {
		foreach ( array( new WP_Error( 'ffl_bridge_api_error', 'x' ), array( 'dealers' => array() ) ) as $nearby ) {
			$resolved = FFL_Bridge_Network::resolve(
				array( 'dealers' => array() ),
				static fn () => $nearby,
				FFL_Bridge_Network::SCOPE_ALL,
				array(),
				true
			);

			$this->assertSame( FFL_Bridge_Network::OUTCOME_NONE, $resolved['outcome'] );
			$this->assertSame( array(), $resolved['dealers'] );
		}
	}

	public function test_fallback_keeps_verified_dealers_classified_as_verified(): void {
		$prepared = FFL_Bridge_Network::prepare_fallback( array( $this->dealer( 'v', '1-11-111-11-1A-00001', true ) ), array() );

		$this->assertSame( 'verified', $prepared[0]['network'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function dealer( string $name, string $license, ?bool $eligible ): array {
		return array(
			'name'              => $name,
			'license'           => $license,
			'checkout_eligible' => $eligible,
		);
	}
}
