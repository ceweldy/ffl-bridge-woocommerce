<?php
/**
 * 1.3.0 item 6 (plugin side): optional dealer name box in the picker.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class NameFilterTest extends FFL_Bridge_TestCase {
	use FFL_Bridge_Dealer_Fixtures;

	private const OPTION = 'ffl_bridge_name_filter';

	protected function setUp(): void {
		parent::setUp();
		$this->fresh_request();
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
	}

	public function test_name_is_sent_to_the_search_api(): void {
		$this->queue_search( array( $this->raw_dealer() ) );

		FFL_Bridge_API_Client::search_with_meta( '32801', 25, 20, true, false, 'Example' );

		$this->assertStringContainsString( 'name=Example', $GLOBALS['ffl_bridge_test_http'][0]['url'] );
	}

	public function test_searches_without_a_name_send_no_name_parameter(): void {
		$this->queue_search( array( $this->raw_dealer() ) );

		FFL_Bridge_API_Client::search_with_meta( '32801', 25 );

		$this->assertStringNotContainsString( 'name=', $GLOBALS['ffl_bridge_test_http'][0]['url'] );
	}

	public function test_name_searches_are_cached_separately(): void {
		$this->queue_search( array( $this->raw_dealer() ) );
		$this->queue_search( array( $this->raw_dealer() ) );

		FFL_Bridge_API_Client::search_with_meta( '32801', 25 );
		FFL_Bridge_API_Client::search_with_meta( '32801', 25, 20, true, false, 'Example' );

		$this->assertCount( 2, $GLOBALS['ffl_bridge_test_http'] );
	}

	public function test_name_is_trimmed_capped_and_ignored_when_too_short(): void {
		$this->assertSame( 'Example Arms', FFL_Bridge_API_Client::sanitize_name_filter( '  Example   Arms ' ) );
		$this->assertSame( 60, mb_strlen( FFL_Bridge_API_Client::sanitize_name_filter( str_repeat( 'é', 80 ) ) ) );
		$this->assertSame( '', FFL_Bridge_API_Client::sanitize_name_filter( 'a' ) );
		$this->assertSame( '', FFL_Bridge_API_Client::sanitize_name_filter( array( 'Example' ) ) );
	}

	public function test_checkout_search_passes_the_name_and_also_filters_locally(): void {
		// An FFL Bridge API without the name filter returns every dealer; the
		// plugin still shows only matching names.
		$this->queue_search(
			array(
				$this->raw_dealer(),
				$this->raw_dealer( array( 'id' => '223e4567-e89b-12d3-a456-426614174000', 'licenseNumber' => '1-23-456-78-9A-05555', 'tradeName' => 'Other Outfitters', 'businessName' => 'OTHER LLC' ) ),
			)
		);

		$response = $this->ajax( array( FFL_Bridge_Checkout::class, 'ajax_search' ), array( 'zip' => '32801', 'radius' => '25', 'name' => 'example arms' ) );

		$this->assertStringContainsString( 'name=example+arms', $GLOBALS['ffl_bridge_test_http'][0]['url'] );
		$this->assertSame( array( 'Example Arms' ), array_column( $response->data['dealers'], 'name' ) );
	}

	public function test_local_filter_also_matches_the_license_name(): void {
		$dealers = array(
			FFL_Bridge_API_Client::normalize_dealer( $this->raw_dealer( array( 'tradeName' => '', 'businessName' => 'JANE Q LICENSEE' ) ) ),
			FFL_Bridge_API_Client::normalize_dealer( $this->raw_dealer( array( 'tradeName' => 'Example Arms', 'businessName' => 'EXAMPLE ARMS LLC' ) ) ),
		);

		$this->assertSame( array( 'JANE Q LICENSEE' ), array_column( FFL_Bridge_Network::filter_by_name( $dealers, 'jane q' ), 'name' ) );
		$this->assertCount( 2, FFL_Bridge_Network::filter_by_name( $dealers, '' ) );
	}

	public function test_name_is_ignored_when_the_setting_is_off(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'no';
		$this->queue_search( array( $this->raw_dealer() ) );

		$response = $this->ajax( array( FFL_Bridge_Checkout::class, 'ajax_search' ), array( 'zip' => '32801', 'radius' => '25', 'name' => 'nothing matches' ) );

		$this->assertStringNotContainsString( 'name=', $GLOBALS['ffl_bridge_test_http'][0]['url'] );
		$this->assertCount( 1, $response->data['dealers'] );
	}

	public function test_frontend_config_shows_the_name_box_only_when_on(): void {
		$config = FFL_Bridge_Checkout::get_frontend_config();
		$this->assertTrue( $config['nameFilter'] );
		$this->assertSame( 'Dealer name (optional)', $config['messages']['nameLabel'] );

		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'no';
		$this->assertFalse( FFL_Bridge_Checkout::get_frontend_config()['nameFilter'] );
	}

	public function test_existing_stores_are_offered_the_name_box(): void {
		$GLOBALS['ffl_bridge_test_options'] = array( 'ffl_bridge_settings_version' => '1.2.1' );
		FFL_Bridge_Features::maybe_migrate();

		$this->assertSame( 'no', get_option( self::OPTION ) );
		$this->assertContains( 'name_filter', FFL_Bridge_Features::pending_offers() );
	}
}
