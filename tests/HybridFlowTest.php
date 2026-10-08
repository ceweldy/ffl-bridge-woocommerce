<?php
/**
 * Hybrid dealer selection, migration, status labels, and shopper copy.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class HybridFlowTest extends FFL_Bridge_TestCase {
	public function test_hybrid_lists_verified_then_license_gap_then_unconfirmed_and_skips_declined(): void {
		$primary = array(
			'dealers'     => array(
				$this->dealer( 'license-gap', '00001', false, 'confirmed' ),
				$this->dealer( 'verified', '00002', true, 'confirmed' ),
			),
			'unconfirmed' => array(
				$this->dealer( 'unconfirmed', '00003', false, 'unconfirmed' ),
				$this->dealer( 'declined', '00004', false, 'declined' ),
			),
		);

		$resolved = FFL_Bridge_Network::resolve( $primary, null, FFL_Bridge_Network::SCOPE_ALL, array(), true );

		$this->assertSame( FFL_Bridge_Network::OUTCOME_CONFIRMED, $resolved['outcome'] );
		$this->assertSame( array( 'verified', 'license-gap', 'unconfirmed' ), array_column( $resolved['dealers'], 'name' ) );
		$this->assertSame( array( true, true, true ), array_column( $resolved['dealers'], 'selectable' ) );
		$this->assertSame( array( 'verified', 'unconfirmed', 'unconfirmed' ), array_column( $resolved['dealers'], 'network' ) );
	}

	public function test_hybrid_with_zero_network_coverage_still_offers_dealers(): void {
		$resolved = FFL_Bridge_Network::resolve(
			array(
				'dealers'     => array(),
				'unconfirmed' => array( $this->dealer( 'nearby', '00001', false, 'unconfirmed' ) ),
			),
			null,
			FFL_Bridge_Network::SCOPE_ALL,
			array(),
			true
		);

		$this->assertSame( FFL_Bridge_Network::OUTCOME_FALLBACK, $resolved['outcome'] );
		$this->assertCount( 1, $resolved['dealers'] );
		$this->assertTrue( $resolved['dealers'][0]['selectable'] );
	}

	public function test_confirmed_only_mode_keeps_unverified_dealers_unselectable(): void {
		$resolved = FFL_Bridge_Network::resolve(
			array(
				'dealers'     => array( $this->dealer( 'license-gap', '00001', false, 'confirmed' ) ),
				'unconfirmed' => array( $this->dealer( 'unconfirmed', '00002', false, 'unconfirmed' ) ),
			),
			null,
			FFL_Bridge_Network::SCOPE_ALL,
			array(),
			false
		);

		$this->assertSame( FFL_Bridge_Network::OUTCOME_NONE, $resolved['outcome'] );
		$this->assertSame( array( 'license-gap' ), array_column( $resolved['dealers'], 'name' ) );
		$this->assertFalse( $resolved['dealers'][0]['selectable'] );
	}

	public function test_follow_up_state_separates_license_gap_from_transfer_gap(): void {
		$this->assertSame( 'verified', FFL_Bridge_Network::follow_up_state( array( 'checkout_verified' => true ) ) );
		$this->assertSame( 'license_unverified', FFL_Bridge_Network::follow_up_state( array( 'checkout_verified' => false, 'transfer_confirmed' => true ) ) );
		$this->assertSame( 'license_unverified', FFL_Bridge_Network::follow_up_state( array( 'network' => 'unconfirmed', 'transfer_status' => 'confirmed' ) ) );
		$this->assertSame( 'transfer_unconfirmed', FFL_Bridge_Network::follow_up_state( array( 'network' => 'unconfirmed', 'transfer_status' => 'unconfirmed' ) ) );
	}

	public function test_merchant_confirmed_dealers_rank_with_transfer_confirmed_dealers(): void {
		$resolved = FFL_Bridge_Network::resolve(
			array(
				'dealers'     => array(),
				'unconfirmed' => array(
					$this->dealer( 'unconfirmed', '00001', false, 'unconfirmed' ),
					$this->dealer( 'store-confirmed', '00002', false, 'merchant_confirmed' ),
				),
			),
			null,
			FFL_Bridge_Network::SCOPE_ALL,
			array(),
			true
		);

		$this->assertSame( array( 'store-confirmed', 'unconfirmed' ), array_column( $resolved['dealers'], 'name' ) );
		$this->assertSame( 'license_unverified', FFL_Bridge_Network::follow_up_state( $resolved['dealers'][0] ) );

		$raw = array(
			'id'             => '123e4567-e89b-12d3-a456-426614174000',
			'licenseNumber'  => '1-23-456-78-9A-01234',
			'tradeName'      => 'Store Confirmed',
			'address'        => '1 Main',
			'city'           => 'Chesterfield',
			'state'          => 'MI',
			'zip'            => '48047',
			'transferStatus' => 'merchant_confirmed',
		);
		$this->assertSame( 'merchant_confirmed', FFL_Bridge_API_Client::normalize_dealer( $raw )['transfer_status'] );
	}

	public function test_new_install_defaults_to_hybrid(): void {
		FFL_Bridge_Network::maybe_migrate();

		$this->assertSame( 'hybrid', $GLOBALS['ffl_bridge_test_options']['ffl_bridge_dealer_mode'] );
		$this->assertFalse( FFL_Bridge_Network::hybrid_offer_pending() );
	}

	public function test_existing_install_keeps_confirmed_only_and_gets_an_offer(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_settings_version'] = '1.1.0';

		FFL_Bridge_Network::maybe_migrate();

		$this->assertSame( 'confirmed_only', $GLOBALS['ffl_bridge_test_options']['ffl_bridge_dealer_mode'] );
		$this->assertTrue( FFL_Bridge_Network::hybrid_offer_pending() );

		FFL_Bridge_Settings::apply_hybrid_offer( 'keep' );
		$this->assertFalse( FFL_Bridge_Network::hybrid_offer_pending() );
		$this->assertSame( 'confirmed_only', FFL_Bridge_Network::get_dealer_mode() );
	}

	public function test_offer_switch_turns_on_hybrid(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_settings_version'] = '1.1.0';
		FFL_Bridge_Network::maybe_migrate();

		FFL_Bridge_Settings::apply_hybrid_offer( 'switch' );

		$this->assertSame( 'hybrid', FFL_Bridge_Network::get_dealer_mode() );
		$this->assertFalse( FFL_Bridge_Network::hybrid_offer_pending() );

		FFL_Bridge_Settings::apply_hybrid_offer( 'bogus' );
		$this->assertSame( 'answered', $GLOBALS['ffl_bridge_test_options']['ffl_bridge_hybrid_offer'] );
	}

	public function test_existing_install_with_fallback_on_migrates_to_hybrid_without_offer(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_settings_version'] = '1.1.0';
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_fallback']         = 'yes';

		FFL_Bridge_Network::maybe_migrate();

		$this->assertSame( 'hybrid', FFL_Bridge_Network::get_dealer_mode() );
		$this->assertArrayNotHasKey( 'ffl_bridge_hybrid_offer', $GLOBALS['ffl_bridge_test_options'] );
	}

	public function test_saved_mode_is_never_overwritten(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_settings_version'] = '1.1.0';
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_dealer_mode']      = 'hybrid';

		FFL_Bridge_Network::maybe_migrate();

		$this->assertSame( 'hybrid', FFL_Bridge_Network::get_dealer_mode() );
	}

	public function test_status_meta_keeps_transfer_license_and_basis_separate(): void {
		$license_gap = FFL_Bridge_Checkout::status_meta(
			array(
				'checkout_verified'  => false,
				'transfer_confirmed' => true,
				'license_verified'   => false,
			)
		);
		$this->assertSame(
			array(
				'_ffl_bridge_transfer_status'  => 'confirmed',
				'_ffl_bridge_license_verified' => 'no',
				'_ffl_bridge_selection_basis'  => 'hybrid',
			),
			$license_gap
		);

		$verified = FFL_Bridge_Checkout::status_meta(
			array(
				'checkout_verified'  => true,
				'transfer_confirmed' => true,
				'license_verified'   => true,
			)
		);
		$this->assertSame( 'verified_network', $verified['_ffl_bridge_selection_basis'] );
		$this->assertSame( 'yes', $verified['_ffl_bridge_license_verified'] );

		$session_from_1_1 = FFL_Bridge_Checkout::status_meta( array( 'license_verified' => true ) );
		$this->assertSame( 'verified_network', $session_from_1_1['_ffl_bridge_selection_basis'] );
	}

	public function test_selection_note_does_not_call_a_license_gap_unconfirmed_transfer(): void {
		$dealer = array(
			'name'               => 'Macomb Arms',
			'license'            => '1-23-456-78-9A-01234',
			'checkout_verified'  => false,
			'transfer_confirmed' => true,
			'license_verified'   => false,
		);

		$note = FFL_Bridge_Checkout::selection_note( $dealer );
		$this->assertStringContainsString( 'Transfer acceptance: confirmed by the dealer with FFL Bridge. License copy: not verified.', $note );

		$dealer['transfer_confirmed'] = false;
		$this->assertStringContainsString( 'Transfer acceptance: not confirmed.', FFL_Bridge_Checkout::selection_note( $dealer ) );
	}

	public function test_order_labels_read_each_fact_independently(): void {
		$license_gap = FFL_Bridge_Order::get_ffl_data(
			$this->order(
				array(
					'_ffl_bridge_transfer_status'  => 'confirmed',
					'_ffl_bridge_license_verified' => 'no',
					'_ffl_bridge_selection_basis'  => 'hybrid',
				)
			)
		);
		$labels      = FFL_Bridge_Order::status_labels( $license_gap );

		$this->assertSame( 'Confirmed by the dealer with FFL Bridge', $labels['transfer'] );
		$this->assertSame( 'Not verified', $labels['license'] );
		$this->assertTrue( FFL_Bridge_Order::is_transfer_confirmed( $license_gap ) );
		$this->assertTrue( FFL_Bridge_Order::is_unconfirmed( $license_gap ) );

		$store = FFL_Bridge_Order::get_ffl_data(
			$this->order(
				array(
					'_ffl_bridge_transfer_status'          => 'confirmed',
					'_ffl_bridge_selection_basis'          => 'hybrid',
					'_ffl_bridge_store_confirmed_at'       => '2026-10-08T18:00:00+00:00',
					'_ffl_bridge_store_confirmed_by_name'  => 'Store Manager',
				)
			)
		);
		$this->assertSame( 'Confirmed by the store', FFL_Bridge_Order::status_labels( $store )['transfer'] );
		$this->assertFalse( FFL_Bridge_Order::is_unconfirmed( $store ) );
	}

	public function test_older_order_formats_are_read_correctly(): void {
		$v110 = FFL_Bridge_Order::get_ffl_data( $this->order( array( '_ffl_bridge_license_verified' => 'yes' ) ) );
		$this->assertSame( 'verified_network', $v110['basis'] );
		$this->assertSame( 'confirmed', $v110['transfer_status'] );
		$this->assertFalse( FFL_Bridge_Order::is_unconfirmed( $v110 ) );

		$pr6 = FFL_Bridge_Order::get_ffl_data( $this->order( array( '_ffl_bridge_transfer_confirmed' => 'no' ) ) );
		$this->assertSame( 'hybrid', $pr6['basis'] );
		$this->assertSame( 'unconfirmed', $pr6['transfer_status'] );
		$this->assertTrue( FFL_Bridge_Order::is_unconfirmed( $pr6 ) );
	}

	public function test_follow_up_copy_uses_settings_and_admin_email_default(): void {
		$GLOBALS['ffl_bridge_test_options']['admin_email'] = 'owner@store.example.test';

		$default = FFL_Bridge_Followup::instructions( 'Macomb Arms', 'thankyou' );
		$this->assertSame( 'Next step: contact Macomb Arms and confirm they will accept this transfer. Then ask them to email a copy of their current FFL to owner@store.example.test. Your order is not on hold while you do this.', $default );

		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_license_email'] = 'licenses@store.example.test';
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_license_fax']   = '(586) 555-0199';
		$with_fax = FFL_Bridge_Followup::instructions( '', 'email' );
		$this->assertStringContainsString( 'contact your selected FFL dealer', $with_fax );
		$this->assertStringContainsString( 'email a copy of their current FFL to licenses@store.example.test or fax it to (586) 555-0199', $with_fax );
	}

	public function test_follow_up_copy_is_filterable_and_has_no_em_dash(): void {
		$GLOBALS['ffl_bridge_test_options']['admin_email']             = 'owner@store.example.test';
		$GLOBALS['ffl_bridge_test_filters']['ffl_bridge_followup_instructions'] = static fn ( string $text, array $context ): string => 'Call us. <b>' . $context['context'] . '</b>';

		$this->assertSame( 'Call us. checkout', FFL_Bridge_Followup::instructions( 'X', 'checkout' ) );

		$GLOBALS['ffl_bridge_test_filters'] = array();
		$this->assertStringNotContainsString( "\u{2014}", FFL_Bridge_Followup::instructions( 'X', 'checkout' ) );
	}

	public function test_contact_settings_are_sanitized(): void {
		$this->assertSame( '', FFL_Bridge_Followup::sanitize_email_setting( 'not an email' ) );
		$this->assertSame( 'a@b.example', FFL_Bridge_Followup::sanitize_email_setting( ' a@b.example ' ) );
		$this->assertSame( '+1 (586) 555-0199', FFL_Bridge_Followup::sanitize_fax( '+1 (586) 555-0199<script>' ) );
		$this->assertSame( '', FFL_Bridge_Followup::sanitize_fax( '123' ) );
	}

	public function test_thank_you_page_shows_follow_up_until_store_confirms(): void {
		$GLOBALS['ffl_bridge_test_options']['admin_email'] = 'owner@store.example.test';
		$GLOBALS['ffl_bridge_test_orders'][501]            = $this->order( array( '_ffl_bridge_selection_basis' => 'hybrid' ) );

		ob_start();
		FFL_Bridge_Order::display_thankyou_ffl( 501 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'ask them to email a copy of their current FFL to owner@store.example.test', $html );

		$GLOBALS['ffl_bridge_test_orders'][501] = $this->order(
			array(
				'_ffl_bridge_selection_basis'    => 'hybrid',
				'_ffl_bridge_store_confirmed_at' => '2026-10-08T18:00:00+00:00',
			)
		);
		ob_start();
		FFL_Bridge_Order::display_thankyou_ffl( 501 );
		$this->assertStringNotContainsString( 'Next step', (string) ob_get_clean() );

		$GLOBALS['ffl_bridge_test_orders'][501] = $this->order( array( '_ffl_bridge_selection_basis' => 'verified_network' ) );
		ob_start();
		FFL_Bridge_Order::display_thankyou_ffl( 501 );
		$this->assertStringNotContainsString( 'Next step', (string) ob_get_clean() );
	}

	public function test_customer_emails_include_follow_up_and_admin_emails_show_status(): void {
		$GLOBALS['ffl_bridge_test_options']['admin_email'] = 'owner@store.example.test';
		$ffl = FFL_Bridge_Order::get_ffl_data( $this->order( array( '_ffl_bridge_selection_basis' => 'hybrid' ) ) );

		$customer = FFL_Bridge_Order::plain_text_details( $ffl, false );
		$this->assertStringContainsString( 'Next step: contact Example Arms', $customer );

		$admin = FFL_Bridge_Order::plain_text_details( $ffl, true );
		$this->assertStringContainsString( 'Transfer: Not confirmed', $admin );
		$this->assertStringNotContainsString( 'Next step', $admin );

		ob_start();
		FFL_Bridge_Order::display_email_ffl( $this->order( array( '_ffl_bridge_selection_basis' => 'hybrid' ) ), false, false );
		$this->assertStringContainsString( 'Next step: contact Example Arms', (string) ob_get_clean() );
	}

	public function test_hybrid_selection_survives_only_while_hybrid_is_on(): void {
		$this->assertFalse( FFL_Bridge_Checkout::is_verified_selection( array( 'checkout_verified' => false ) ) );
		$this->assertTrue( FFL_Bridge_Checkout::is_verified_selection( array() ) );
		$this->assertFalse( FFL_Bridge_Checkout::is_verified_selection( array( 'transfer_confirmed' => false ) ) );

		$this->assertTrue( FFL_Bridge_Checkout::dealer_still_acceptable( array( 'is_active' => true, 'checkout_verified' => false, 'accepts_transfers' => false ) ) );
		$this->assertFalse( FFL_Bridge_Checkout::dealer_still_acceptable( array( 'is_active' => true, 'checkout_verified' => true, 'accepts_transfers' => false ) ) );
	}

	/**
	 * @param array<string, string> $meta Extra metadata.
	 */
	private function order( array $meta ): WC_Order {
		return new WC_Order(
			array_merge(
				array(
					'_ffl_bridge_dealer_id' => '123e4567-e89b-12d3-a456-426614174000',
					'_ffl_bridge_license'   => '1-23-456-78-9A-01234',
					'_ffl_bridge_name'      => 'Example Arms',
					'_ffl_bridge_address'   => '123 Main Street',
					'_ffl_bridge_city'      => 'Chesterfield',
					'_ffl_bridge_state'     => 'MI',
					'_ffl_bridge_zip'       => '48047',
					'_ffl_bridge_phone'     => '586-555-0100',
					'_ffl_bridge_source'    => 'ffl_bridge_api',
				),
				$meta
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function dealer( string $name, string $suffix, bool $eligible, string $status ): array {
		return array(
			'id'                => '123e4567-e89b-12d3-a456-4266141' . $suffix,
			'name'              => $name,
			'license'           => '1-11-111-11-1A-' . $suffix,
			'checkout_eligible' => $eligible,
			'transfer_status'   => $status,
		);
	}
}
