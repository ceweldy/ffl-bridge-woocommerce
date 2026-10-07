<?php
/**
 * Zero-result handling, fallback selection rules, and optional API metadata.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class SearchOutcomeTest extends FFL_Bridge_TestCase {
	public function test_search_meta_is_read_when_present(): void {
		$meta = FFL_Bridge_API_Client::parse_search_meta(
			array(
				'results'          => array(),
				'coverage'         => array(
					'dealersInRadius'    => 41,
					'acceptingTransfers' => 0,
					'checkoutEligible'   => 0,
				),
				'zeroResultReason' => 'no_transfer_dealers_in_radius',
			)
		);

		$this->assertSame(
			array(
				'dealers_in_radius'   => 41,
				'accepting_transfers' => 0,
				'checkout_eligible'   => 0,
			),
			$meta['coverage']
		);
		$this->assertSame( 'NO_TRANSFER_DEALERS_IN_RADIUS', $meta['reason'] );
	}

	public function test_search_meta_degrades_when_absent_or_malformed(): void {
		$this->assertSame( array( 'coverage' => null, 'reason' => '' ), FFL_Bridge_API_Client::parse_search_meta( array( 'results' => array() ) ) );
		$this->assertSame( array( 'coverage' => null, 'reason' => '' ), FFL_Bridge_API_Client::parse_search_meta( null ) );

		$meta = FFL_Bridge_API_Client::parse_search_meta(
			array(
				'coverage'         => array(
					'dealersInRadius'    => '41',
					'acceptingTransfers' => -1,
					'checkoutEligible'   => 3,
				),
				'zeroResultReason' => '<script>',
			)
		);
		$this->assertSame( array( 'checkout_eligible' => 3 ), $meta['coverage'] );
		$this->assertSame( '', $meta['reason'] );
	}

	public function test_eligibility_accepts_verified_network_dealer(): void {
		$dealer = FFL_Bridge_API_Client::apply_eligibility( $this->dealer(), $this->eligibility( true ), false );

		$this->assertIsArray( $dealer );
		$this->assertTrue( $dealer['transfer_confirmed'] );
		$this->assertTrue( $dealer['license_verified'] );
	}

	public function test_eligibility_rejects_unconfirmed_dealer_unless_fallback_is_allowed(): void {
		$denied = FFL_Bridge_API_Client::apply_eligibility( $this->dealer(), $this->eligibility( false ), false );
		$this->assertInstanceOf( WP_Error::class, $denied );

		$allowed = FFL_Bridge_API_Client::apply_eligibility( $this->dealer(), $this->eligibility( false ), true );
		$this->assertIsArray( $allowed );
		$this->assertFalse( $allowed['transfer_confirmed'] );
		$this->assertFalse( $allowed['license_verified'] );
		$this->assertFalse( $allowed['checkout_eligible'] );
	}

	public function test_fallback_still_rejects_inactive_or_unlisted_dealers(): void {
		foreach ( array( 'isActive', 'isAtfListed' ) as $flag ) {
			$eligibility          = $this->eligibility( false );
			$eligibility[ $flag ] = false;

			$this->assertInstanceOf( WP_Error::class, FFL_Bridge_API_Client::apply_eligibility( $this->dealer(), $eligibility, true ) );
		}

		$this->assertInstanceOf( WP_Error::class, FFL_Bridge_API_Client::apply_eligibility( $this->dealer(), null, true ) );
	}

	public function test_selection_acceptability_depends_on_confirmation(): void {
		$this->assertTrue( FFL_Bridge_Checkout::dealer_still_acceptable( array( 'is_active' => true, 'accepts_transfers' => true, 'transfer_confirmed' => true ) ) );
		$this->assertFalse( FFL_Bridge_Checkout::dealer_still_acceptable( array( 'is_active' => true, 'accepts_transfers' => false, 'transfer_confirmed' => true ) ) );
		$this->assertTrue( FFL_Bridge_Checkout::dealer_still_acceptable( array( 'is_active' => true, 'accepts_transfers' => false, 'transfer_confirmed' => false ) ) );
		$this->assertFalse( FFL_Bridge_Checkout::dealer_still_acceptable( array( 'is_active' => false, 'transfer_confirmed' => false ) ) );
		$this->assertTrue( FFL_Bridge_Checkout::dealer_still_acceptable( array( 'is_active' => true, 'accepts_transfers' => true ) ) );
	}

	public function test_no_dealer_notice_without_api_metadata(): void {
		$required = FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, null, '' );
		$this->assertSame( 'No dealers within 100 miles of 48047 are confirmed to accept transfers. This order needs a transfer dealer before it can be placed. Contact the store for help arranging one.', $required );

		$optional = FFL_Bridge_Checkout::search_notice( 'none', '48047', 25, false, null, '' );
		$this->assertStringContainsString( 'Try a larger radius.', $optional );
		$this->assertStringContainsString( 'You can still place the order.', $optional );

		$this->assertSame( '', FFL_Bridge_Checkout::search_notice( 'confirmed', '48047', 25, true, null, '' ) );
	}

	public function test_no_dealer_notice_uses_coverage_and_reason_when_present(): void {
		$counts = FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, array( 'dealers_in_radius' => 41, 'accepting_transfers' => 0 ), '' );
		$this->assertStringContainsString( 'FFL Bridge lists 41 licensed dealers in this area, but none are confirmed to accept transfers yet.', $counts );

		$empty = FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, array( 'dealers_in_radius' => 0 ), '' );
		$this->assertStringContainsString( 'FFL Bridge lists no licensed dealers in this area.', $empty );

		$reason = FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, null, 'NO_VERIFIED_DEALERS_IN_RADIUS' );
		$this->assertStringContainsString( 'none are verified for checkout yet', $reason );

		$unknown = FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, null, 'SOMETHING_NEW' );
		$this->assertStringNotContainsString( 'FFL Bridge lists', $unknown );
	}

	public function test_fallback_notice_tells_shopper_to_contact_dealer(): void {
		$notice = FFL_Bridge_Checkout::search_notice( 'fallback', '48047', 100, true, null, '' );

		$this->assertStringContainsString( 'transfer acceptance is not confirmed', $notice );
		$this->assertStringContainsString( 'Contact the dealer to confirm', $notice );
		$this->assertStringNotContainsString( 'Try a larger radius', $notice );
	}

	public function test_notice_filter_output_is_plain_text(): void {
		$GLOBALS['ffl_bridge_test_filters']['ffl_bridge_search_notice'] = static fn ( string $message ): string => 'Call us at <b>555-0100</b>.';

		$this->assertSame( 'Call us at 555-0100.', FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, null, '' ) );
	}

	public function test_selection_token_carries_signed_fallback_flag(): void {
		$dealer            = $this->dealer();
		$dealer['network'] = FFL_Bridge_Network::NETWORK_UNCONFIRMED;

		$fallback  = FFL_Bridge_Selection::verify( (string) FFL_Bridge_Selection::create( $dealer ) );
		$confirmed = FFL_Bridge_Selection::verify( (string) FFL_Bridge_Selection::create( $this->dealer() ) );

		$this->assertSame( 1, $fallback['fb'] );
		$this->assertArrayNotHasKey( 'fb', $confirmed );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function dealer(): array {
		return array(
			'id'                => '123e4567-e89b-12d3-a456-426614174000',
			'license'           => '1-23-456-78-9A-01234',
			'name'              => 'Example Arms',
			'accepts_transfers' => false,
			'is_active'         => true,
		);
	}

	/**
	 * @return array<string, bool>
	 */
	private function eligibility( bool $confirmed ): array {
		return array(
			'selectable'       => $confirmed,
			'licenseOnFile'    => $confirmed,
			'licenseVerified'  => $confirmed,
			'isActive'         => true,
			'isAtfListed'      => true,
			'acceptsTransfers' => $confirmed,
		);
	}
}
