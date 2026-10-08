<?php
/**
 * Zero-result handling, fallback selection rules, and optional API metadata.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class SearchOutcomeTest extends FFL_Bridge_TestCase {
	public function test_search_meta_matches_the_api_coverage_shape(): void {
		$meta = FFL_Bridge_API_Client::parse_search_meta( $this->michiganResponse() );

		$this->assertSame(
			array(
				'radius'               => 100,
				'dealers_in_radius'    => 412,
				'accepting_transfers'  => 0,
				'checkout_eligible'    => 0,
				'transfer_declined'    => 0,
				'transfer_unconfirmed' => 412,
			),
			$meta['coverage']
		);
		$this->assertSame( 'NO_TRANSFER_CONFIRMED_DEALERS_IN_RADIUS', $meta['reason'] );
		$this->assertStringStartsWith( '412 ATF-listed dealers are in this radius', $meta['reason_message'] );
	}

	public function test_null_empty_reason_means_no_reason(): void {
		$data                            = $this->michiganResponse();
		$data['coverage']['emptyReason'] = null;

		$meta = FFL_Bridge_API_Client::parse_search_meta( $data );

		$this->assertSame( 412, $meta['coverage']['dealers_in_radius'] );
		$this->assertSame( '', $meta['reason'] );
		$this->assertSame( '', $meta['reason_message'] );
	}

	public function test_older_api_response_without_coverage_degrades(): void {
		$older = array(
			'results' => array(),
			'total'   => 0,
			'query'   => array( 'radius' => 100 ),
		);

		$this->assertSame(
			array(
				'coverage'       => null,
				'reason'         => '',
				'reason_message' => '',
			),
			FFL_Bridge_API_Client::parse_search_meta( $older )
		);
		$this->assertSame( array( 'coverage' => null, 'reason' => '', 'reason_message' => '' ), FFL_Bridge_API_Client::parse_search_meta( null ) );
		$this->assertNull( FFL_Bridge_API_Client::parse_unconfirmed_tier( $older ) );
	}

	public function test_malformed_coverage_values_are_ignored(): void {
		$meta = FFL_Bridge_API_Client::parse_search_meta(
			array(
				'coverage' => array(
					'directoryDealers'         => '412',
					'transferConfirmedDealers' => -1,
					'verifiedCheckoutDealers'  => 3,
					'emptyReason'              => array(
						'code'    => '<script>',
						'message' => 'ignored with a bad code',
					),
				),
			)
		);

		$this->assertSame( array( 'checkout_eligible' => 3 ), $meta['coverage'] );
		$this->assertSame( '', $meta['reason'] );
		$this->assertSame( '', $meta['reason_message'] );

		$string_reason = FFL_Bridge_API_Client::parse_search_meta( array( 'coverage' => array( 'emptyReason' => 'NO_DEALERS_IN_RADIUS' ) ) );
		$this->assertSame( '', $string_reason['reason'] );
	}

	public function test_unconfirmed_tier_is_normalized_and_drops_declined_dealers(): void {
		$data = $this->michiganResponse();
		$tier = FFL_Bridge_API_Client::parse_unconfirmed_tier( $data );

		$this->assertIsArray( $tier );
		$this->assertCount( 1, $tier );
		$this->assertSame( 'unconfirmed', $tier[0]['transfer_status'] );
		$this->assertFalse( $tier[0]['checkout_eligible'] );
		$this->assertSame( 'Macomb Sporting Goods', $tier[0]['name'] );

		$data['unconfirmedTier']['results'] = 'not a list';
		$this->assertNull( FFL_Bridge_API_Client::parse_unconfirmed_tier( $data ) );
	}

	public function test_transfer_status_is_allowlisted(): void {
		$this->assertSame( 'confirmed', FFL_Bridge_API_Client::normalize_dealer( $this->rawDealer( array( 'transferStatus' => 'confirmed' ) ) )['transfer_status'] );
		$this->assertNull( FFL_Bridge_API_Client::normalize_dealer( $this->rawDealer( array( 'transferStatus' => 'maybe' ) ) )['transfer_status'] );
		$this->assertNull( FFL_Bridge_API_Client::normalize_dealer( $this->rawDealer() )['transfer_status'] );
	}

	public function test_fallback_uses_the_api_tier_without_a_second_search(): void {
		$called   = false;
		$primary  = array(
			'dealers'     => array(),
			'unconfirmed' => FFL_Bridge_API_Client::parse_unconfirmed_tier( $this->michiganResponse() ),
		);
		$resolved = FFL_Bridge_Network::resolve(
			$primary,
			static function () use ( &$called ): array {
				$called = true;
				return array( 'dealers' => array() );
			},
			FFL_Bridge_Network::SCOPE_ALL,
			array(),
			true
		);

		$this->assertSame( FFL_Bridge_Network::OUTCOME_FALLBACK, $resolved['outcome'] );
		$this->assertSame( array( 'Macomb Sporting Goods' ), array_column( $resolved['dealers'], 'name' ) );
		$this->assertSame( array( 'unconfirmed' ), array_column( $resolved['dealers'], 'network' ) );
		$this->assertFalse( $called );
	}

	public function test_fallback_lists_transfer_confirmed_dealers_before_the_tier_without_duplicates(): void {
		$unverified = FFL_Bridge_API_Client::normalize_dealer(
			$this->rawDealer(
				array(
					'id'               => '223e4567-e89b-12d3-a456-426614174000',
					'licenseNumber'    => '1-23-456-78-9A-05555',
					'tradeName'        => 'Confirmed Unverified',
					'acceptsTransfers' => true,
					'checkoutEligible' => false,
					'transferStatus'   => 'confirmed',
				)
			)
		);
		$tier       = FFL_Bridge_API_Client::parse_unconfirmed_tier( $this->michiganResponse() );
		$resolved   = FFL_Bridge_Network::resolve(
			array(
				'dealers'     => array( $unverified ),
				'unconfirmed' => array_merge( $tier, array( $unverified ) ),
			),
			null,
			FFL_Bridge_Network::SCOPE_ALL,
			array(),
			true
		);

		$this->assertSame( array( 'Confirmed Unverified', 'Macomb Sporting Goods' ), array_column( $resolved['dealers'], 'name' ) );
		$this->assertSame( array( 'confirmed', 'unconfirmed' ), array_column( $resolved['dealers'], 'transfer_status' ) );
	}

	public function test_older_api_fallback_search_excludes_declined_dealers(): void {
		$resolved = FFL_Bridge_Network::resolve(
			array( 'dealers' => array() ),
			fn (): array => array(
				'dealers' => array(
					FFL_Bridge_API_Client::normalize_dealer( $this->rawDealer( array( 'transferStatus' => 'declined' ) ) ),
					FFL_Bridge_API_Client::normalize_dealer(
						$this->rawDealer(
							array(
								'id'        => '323e4567-e89b-12d3-a456-426614174000',
								'tradeName' => 'No Status Field',
							)
						)
					),
				),
			),
			FFL_Bridge_Network::SCOPE_ALL,
			array(),
			true
		);

		$this->assertSame( array( 'No Status Field' ), array_column( $resolved['dealers'], 'name' ) );
	}

	public function test_eligibility_keeps_transfer_confirmation_separate_from_license(): void {
		$eligibility                     = $this->eligibility( false );
		$eligibility['acceptsTransfers'] = true;

		$allowed = FFL_Bridge_API_Client::apply_eligibility( $this->dealer(), $eligibility, true );

		$this->assertSame( 'confirmed', $allowed['transfer_status'] );
		$this->assertTrue( $allowed['transfer_confirmed'] );
		$this->assertFalse( $allowed['license_verified'] );
		$this->assertFalse( $allowed['checkout_verified'] );

		$none = FFL_Bridge_API_Client::apply_eligibility( $this->dealer(), $this->eligibility( false ), true );
		$this->assertSame( 'unconfirmed', $none['transfer_status'] );
		$this->assertFalse( $none['transfer_confirmed'] );
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

	public function test_no_dealer_notice_uses_api_coverage_and_reason(): void {
		$meta   = FFL_Bridge_API_Client::parse_search_meta( $this->michiganResponse() );
		$counts = FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, $meta['coverage'], $meta['reason'] );
		$this->assertStringContainsString( 'FFL Bridge lists 412 licensed dealers in this area, but none are confirmed to accept transfers yet.', $counts );
		$this->assertStringNotContainsString( 'ATF-listed dealers are in this radius', $counts );

		$empty = FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, array( 'dealers_in_radius' => 0 ), 'NO_DEALERS_IN_RADIUS' );
		$this->assertStringContainsString( 'FFL Bridge lists no licensed dealers in this area.', $empty );

		$unverified = FFL_Bridge_Checkout::search_notice(
			'none',
			'48047',
			100,
			true,
			array(
				'dealers_in_radius'   => 412,
				'accepting_transfers' => 3,
				'checkout_eligible'   => 0,
			),
			''
		);
		$this->assertStringContainsString( 'none are verified for checkout yet', $unverified );
	}

	public function test_no_dealer_notice_uses_reason_codes_without_counts(): void {
		$this->assertStringContainsString( 'FFL Bridge lists no licensed dealers', FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, null, 'NO_DEALERS_IN_RADIUS' ) );
		$this->assertStringContainsString( 'none are confirmed to accept transfers yet', FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, null, 'NO_TRANSFER_CONFIRMED_DEALERS_IN_RADIUS' ) );
		$this->assertStringContainsString( 'none are verified for checkout yet', FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, null, 'NO_VERIFIED_CHECKOUT_DEALERS_IN_RADIUS' ) );

		$this->assertSame(
			FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, null, '' ),
			FFL_Bridge_Checkout::search_notice( 'none', '48047', 100, true, null, 'SOMETHING_NEW' )
		);
	}

	public function test_fallback_notice_tells_shopper_to_contact_dealer(): void {
		$notice = FFL_Bridge_Checkout::search_notice( 'fallback', '48047', 100, true, null, '' );

		$this->assertStringContainsString( 'You can still choose one of the nearby licensed dealers below.', $notice );
		$this->assertStringContainsString( 'contact the dealer to confirm they will accept the transfer', $notice );
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
	 * A search response for ZIP 48047 at 100 miles with acceptsTransfers=true
	 * and includeUnconfirmed=true, in the shape of ceweldy/ffl-bridge PR #71.
	 *
	 * @return array<string, mixed>
	 */
	private function michiganResponse(): array {
		return array(
			'results'         => array(),
			'total'           => 0,
			'query'           => array(
				'center' => array(
					'lat' => 42.673906,
					'lng' => -82.774086,
				),
				'radius' => 100,
			),
			'coverage'        => array(
				'radiusMiles'                => 100,
				'directoryDealers'           => 412,
				'transferConfirmedDealers'   => 0,
				'verifiedCheckoutDealers'    => 0,
				'transferDeclinedDealers'    => 0,
				'transferUnconfirmedDealers' => 412,
				'emptyReason'                => array(
					'code'    => 'NO_TRANSFER_CONFIRMED_DEALERS_IN_RADIUS',
					'message' => '412 ATF-listed dealers are in this radius, but none has confirmed transfer acceptance with FFL Bridge yet. Transfer acceptance is recorded only when a dealer claims their listing or is verified by FFL Bridge.',
				),
			),
			'unconfirmedTier' => array(
				'label'   => 'Transfer acceptance unconfirmed',
				'notice'  => 'These are current ATF-listed dealers with no transfer acceptance on record at FFL Bridge. Call the dealer to confirm before relying on them. They are not checkout eligible and cannot be used to create an order.',
				'total'   => 2,
				'results' => array(
					$this->rawDealer(
						array(
							'tradeName'        => 'Macomb Sporting Goods',
							'city'             => 'New Baltimore',
							'state'            => 'MI',
							'zip'              => '48047',
							'transferStatus'   => 'unconfirmed',
							'network'          => 'directory',
							'checkoutEligible' => false,
						)
					),
					$this->rawDealer(
						array(
							'id'             => '423e4567-e89b-12d3-a456-426614174000',
							'tradeName'      => 'Declined Dealer',
							'transferStatus' => 'declined',
						)
					),
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $changes Overrides.
	 * @return array<string, mixed>
	 */
	private function rawDealer( array $changes = array() ): array {
		return array_replace(
			array(
				'id'               => '123e4567-e89b-12d3-a456-426614174000',
				'licenseNumber'    => '1-23-456-78-9A-01234',
				'licenseType'      => '01',
				'businessName'     => 'Example Firearms LLC',
				'tradeName'        => 'Example Arms',
				'address'          => '123 Main Street',
				'city'             => 'Chesterfield',
				'state'            => 'MI',
				'zip'              => '48047',
				'phone'            => '586-555-0100',
				'distance'         => 3.2,
				'acceptsTransfers' => false,
				'isActive'         => true,
			),
			$changes
		);
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
