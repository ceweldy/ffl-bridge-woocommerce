<?php
/**
 * Nonce and capability checks on every request handler, and where admin
 * notices may appear.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class SecurityTest extends FFL_Bridge_TestCase {
	private const ORDER_ID = 501;

	public function test_checkout_ajax_handlers_reject_requests_without_a_nonce(): void {
		foreach ( array( 'ajax_search', 'ajax_select', 'ajax_clear' ) as $handler ) {
			$_POST    = array(
				'zip'    => '48047',
				'radius' => '25',
			);
			$response = $this->json( static fn () => FFL_Bridge_Checkout::$handler() );

			$this->assertFalse( $response->success, "{$handler} accepted a request without a nonce." );
			$this->assertStringContainsString( 'security token', $response->data['message'] );
		}

		$_POST    = array( 'nonce' => 'nonce-some_other_action' );
		$response = $this->json( static fn () => FFL_Bridge_Checkout::ajax_clear() );
		$this->assertFalse( $response->success, 'A nonce for another action was accepted.' );
	}

	public function test_connection_test_requires_capability_and_nonce(): void {
		$_POST    = array(
			'nonce' => 'nonce-ffl_bridge_admin',
			'zip'   => '48047',
		);
		$response = $this->json( static fn () => FFL_Bridge_Settings::ajax_test_connection() );
		$this->assertSame( 403, $response->status );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );

		$GLOBALS['ffl_bridge_test_caps'] = array( 'manage_woocommerce' );
		$_POST                           = array( 'zip' => '48047' );
		$response                        = $this->json( static fn () => FFL_Bridge_Settings::ajax_test_connection() );
		$this->assertSame( 403, $response->status );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
	}

	public function test_hybrid_offer_handler_requires_capability_nonce_and_a_known_choice(): void {
		$GLOBALS['ffl_bridge_test_options'][ FFL_Bridge_Network::OFFER_OPTION ] = 'pending';

		$_GET     = array( 'choice' => 'switch' );
		$_REQUEST = array( '_wpnonce' => 'nonce-ffl_bridge_hybrid_offer' );
		$this->assertSame( 403, $this->die( static fn () => FFL_Bridge_Settings::handle_hybrid_offer() ) );

		$GLOBALS['ffl_bridge_test_caps'] = array( 'manage_woocommerce' );
		$_REQUEST                        = array();
		$this->assertSame( 403, $this->die( static fn () => FFL_Bridge_Settings::handle_hybrid_offer() ) );

		$_GET     = array( 'choice' => 'something-else' );
		$_REQUEST = array( '_wpnonce' => 'nonce-ffl_bridge_hybrid_offer' );
		$this->assertSame( 400, $this->die( static fn () => FFL_Bridge_Settings::handle_hybrid_offer() ) );

		$this->assertSame( 'pending', $GLOBALS['ffl_bridge_test_options'][ FFL_Bridge_Network::OFFER_OPTION ] );
		$this->assertArrayNotHasKey( FFL_Bridge_Network::MODE_OPTION, $GLOBALS['ffl_bridge_test_options'] );

		$_GET = array( 'choice' => 'keep' );
		$this->assertSame( 302, $this->die( static fn () => FFL_Bridge_Settings::handle_hybrid_offer() ) );
		$this->assertSame( 'answered', $GLOBALS['ffl_bridge_test_options'][ FFL_Bridge_Network::OFFER_OPTION ] );
	}

	public function test_coverage_dismissal_requires_capability_and_nonce_and_is_stored_per_user(): void {
		$_REQUEST = array( '_wpnonce' => 'nonce-ffl_bridge_dismiss_coverage' );
		$this->assertSame( 403, $this->die( static fn () => FFL_Bridge_Coverage::handle_dismiss() ) );

		$GLOBALS['ffl_bridge_test_caps'] = array( 'manage_woocommerce' );
		$_REQUEST                        = array();
		$this->assertSame( 403, $this->die( static fn () => FFL_Bridge_Coverage::handle_dismiss() ) );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_user_meta'] );

		$_REQUEST = array( '_wpnonce' => 'nonce-ffl_bridge_dismiss_coverage' );
		$this->assertSame( 302, $this->die( static fn () => FFL_Bridge_Coverage::handle_dismiss() ) );
		$this->assertGreaterThan( 0, $GLOBALS['ffl_bridge_test_user_meta'][ get_current_user_id() ]['ffl_bridge_coverage_dismissed'] );
	}

	public function test_license_download_requires_capability_and_order_specific_nonce(): void {
		$_GET     = array( 'order_id' => (string) self::ORDER_ID );
		$_REQUEST = array( '_wpnonce' => 'nonce-ffl_bridge_license_file_' . self::ORDER_ID );
		$this->assertSame( 403, $this->die( static fn () => FFL_Bridge_Transfer_Confirmation::download_license_file() ) );

		$GLOBALS['ffl_bridge_test_caps'] = array( 'edit_shop_orders' );
		$_REQUEST                        = array( '_wpnonce' => 'nonce-ffl_bridge_license_file_999' );
		$this->assertSame( 403, $this->die( static fn () => FFL_Bridge_Transfer_Confirmation::download_license_file() ) );
	}

	public function test_confirm_transfer_rejects_missing_capability_before_reading_the_request(): void {
		$GLOBALS['ffl_bridge_test_orders'][ self::ORDER_ID ] = new WC_Order(
			array(
				'_ffl_bridge_dealer_id' => 'd',
				'_ffl_bridge_license'   => '1-23-456-78-9A-01234',
			)
		);
		$_POST = array( 'order_id' => (string) self::ORDER_ID );

		$response = $this->json( static fn () => FFL_Bridge_Transfer_Confirmation::ajax_confirm() );
		$this->assertSame( 403, $response->status );
		$this->assertStringContainsString( 'not allowed', $response->data['message'] );

		$GLOBALS['ffl_bridge_test_caps'] = array( 'edit_shop_orders' );
		$response                        = $this->json( static fn () => FFL_Bridge_Transfer_Confirmation::ajax_confirm() );
		$this->assertSame( 403, $response->status );
		$this->assertStringContainsString( 'security token', $response->data['message'] );
		$this->assertSame( '', (string) $GLOBALS['ffl_bridge_test_orders'][ self::ORDER_ID ]->get_meta( '_ffl_bridge_store_confirmed_at' ) );
	}

	public function test_api_key_removal_needs_settings_nonce_and_capability(): void {
		$saved = 'ffl_live_' . str_repeat( 'A1', 16 );
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_api_key'] = $saved;
		$_POST = array( 'ffl_bridge_remove_api_key' => '1' );

		$GLOBALS['ffl_bridge_test_caps'] = array( 'manage_woocommerce' );
		$this->assertSame( $saved, FFL_Bridge_Settings::sanitize_api_key( '' ), 'Removed without a settings nonce.' );

		$_POST['_wpnonce']               = 'nonce-ffl_bridge_settings-options';
		$GLOBALS['ffl_bridge_test_caps'] = array();
		$this->assertSame( $saved, FFL_Bridge_Settings::sanitize_api_key( 'ffl_live_' . str_repeat( 'B2', 16 ) ), 'Changed without the capability.' );

		$GLOBALS['ffl_bridge_test_caps'] = array( 'manage_woocommerce' );
		$this->assertSame( '', FFL_Bridge_Settings::sanitize_api_key( '' ) );
	}

	public function test_settings_save_uses_the_woocommerce_capability(): void {
		FFL_Bridge_Settings::init();

		$this->assertArrayHasKey( 'option_page_capability_ffl_bridge_settings', $GLOBALS['ffl_bridge_test_hooks'] );
		$this->assertSame( 'manage_woocommerce', FFL_Bridge_Settings::settings_capability() );
	}

	public function test_hybrid_offer_notice_shows_only_on_the_settings_page(): void {
		$GLOBALS['ffl_bridge_test_caps']                                        = array( 'manage_woocommerce' );
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_settings_version']      = '1.1.0';
		$GLOBALS['ffl_bridge_test_options'][ FFL_Bridge_Network::MODE_OPTION ]  = FFL_Bridge_Network::MODE_CONFIRMED_ONLY;
		$GLOBALS['ffl_bridge_test_options'][ FFL_Bridge_Network::OFFER_OPTION ] = 'pending';

		foreach ( array( 'dashboard', 'plugins', 'edit-post', 'woocommerce_page_wc-settings', 'woocommerce_page_wc-orders' ) as $screen ) {
			$GLOBALS['ffl_bridge_test_screen'] = $screen;
			$this->assertSame( '', $this->render( static fn () => FFL_Bridge_Settings::render_hybrid_offer() ), "Offer shown on {$screen}." );
		}

		$GLOBALS['ffl_bridge_test_screen'] = 'woocommerce_page_ffl-bridge-settings';
		$this->assertStringContainsString( 'Switch to hybrid', $this->render( static fn () => FFL_Bridge_Settings::render_hybrid_offer() ) );

		$GLOBALS['ffl_bridge_test_caps'] = array();
		$this->assertSame( '', $this->render( static fn () => FFL_Bridge_Settings::render_hybrid_offer() ) );
	}

	public function test_coverage_notice_shows_only_on_plugin_and_order_screens(): void {
		$GLOBALS['ffl_bridge_test_caps'] = array( 'manage_woocommerce' );
		FFL_Bridge_Coverage::record_gap( '48047', 25, false );

		foreach ( array( 'dashboard', 'plugins', 'woocommerce_page_wc-settings', 'edit-product' ) as $screen ) {
			$GLOBALS['ffl_bridge_test_screen'] = $screen;
			$this->assertSame( '', $this->render( static fn () => FFL_Bridge_Coverage::render_admin_notice() ), "Coverage notice shown on {$screen}." );
		}

		foreach ( array( 'woocommerce_page_ffl-bridge-settings', 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' ) as $screen ) {
			$GLOBALS['ffl_bridge_test_screen'] = $screen;
			$this->assertStringContainsString( 'Dismiss', $this->render( static fn () => FFL_Bridge_Coverage::render_admin_notice() ), "Coverage notice missing on {$screen}." );
		}

		update_user_meta( get_current_user_id(), 'ffl_bridge_coverage_dismissed', time() );
		$this->assertSame( '', $this->render( static fn () => FFL_Bridge_Coverage::render_admin_notice() ), 'Dismissal not honored.' );
	}

	private function json( callable $handler ): FFL_Bridge_Test_Json {
		try {
			$handler();
		} catch ( FFL_Bridge_Test_Json $response ) {
			return $response;
		}

		$this->fail( 'The handler did not send a JSON response.' );
	}

	private function die( callable $handler ): int {
		try {
			$handler();
		} catch ( FFL_Bridge_Test_Die $stop ) {
			return $stop->status;
		}

		$this->fail( 'The handler neither stopped nor redirected.' );
	}

	private function render( callable $notice ): string {
		ob_start();
		$notice();
		return trim( (string) ob_get_clean() );
	}
}
