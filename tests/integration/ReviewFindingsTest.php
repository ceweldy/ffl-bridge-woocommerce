<?php
/**
 * Regression tests for the WordPress.org plugin review findings, run against
 * real WordPress nonces, roles, screens, and option handling.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class ReviewFindingsTest extends FFL_Bridge_Integration_TestCase {
	private const KEY = 'ffl_live_AbCdEfGhIjKlMnOpQrStUvWxYz012345';

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( 'ffl_bridge_api_key', self::KEY );
	}

	public function test_plugin_runs_with_woocommerce_and_registers_its_handlers(): void {
		$this->assertTrue( version_compare( WC_VERSION, '10.8', '>=' ) );
		$this->assertTrue( class_exists( 'FFL_Bridge_Settings' ), 'The plugin did not initialize with WooCommerce active.' );

		foreach ( array( 'ffl_bridge_search', 'ffl_bridge_select', 'ffl_bridge_clear' ) as $action ) {
			$this->assertNotFalse( has_action( "wp_ajax_{$action}" ), $action );
			$this->assertNotFalse( has_action( "wp_ajax_nopriv_{$action}" ), "{$action} must work for guest checkout." );
		}

		// Store actions are for signed-in staff only.
		foreach ( array( 'ffl_bridge_test_connection', 'ffl_bridge_confirm_transfer' ) as $action ) {
			$this->assertNotFalse( has_action( "wp_ajax_{$action}" ), $action );
			$this->assertFalse( has_action( "wp_ajax_nopriv_{$action}" ), "{$action} must not be open to guests." );
		}
		$this->assertFalse( has_action( 'admin_post_nopriv_ffl_bridge_hybrid_offer' ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_ffl_bridge_dismiss_coverage' ) );
		$this->assertSame( array(), rest_get_server()->get_routes( 'ffl-bridge' ), 'The plugin registers no REST routes.' );
	}

	/*
	 * Finding: the API key "remove" flag was read from $_POST without its own
	 * nonce check.
	 */

	public function test_api_key_removal_is_ignored_without_the_settings_nonce(): void {
		$this->act_as( 'shop_manager' );
		$_POST = array( 'ffl_bridge_remove_api_key' => '1' );

		$this->assertSame( self::KEY, sanitize_option( 'ffl_bridge_api_key', '' ) );
	}

	public function test_api_key_removal_is_ignored_with_a_nonce_for_another_action(): void {
		$this->act_as( 'shop_manager' );
		$_POST = array(
			'ffl_bridge_remove_api_key' => '1',
			'_wpnonce'                  => wp_create_nonce( 'ffl_bridge_hybrid_offer' ),
		);

		$this->assertSame( self::KEY, sanitize_option( 'ffl_bridge_api_key', '' ) );
	}

	public function test_api_key_removal_is_ignored_for_a_user_without_the_capability(): void {
		$this->act_as( 'subscriber' );
		$_POST = array(
			'ffl_bridge_remove_api_key' => '1',
			'_wpnonce'                  => wp_create_nonce( 'ffl_bridge_settings-options' ),
		);

		$this->assertSame( self::KEY, sanitize_option( 'ffl_bridge_api_key', '' ) );
		$this->assertSame( self::KEY, sanitize_option( 'ffl_bridge_api_key', 'ffl_live_' . str_repeat( 'Z', 32 ) ) );
	}

	public function test_api_key_removal_works_with_the_settings_nonce_and_capability(): void {
		$this->act_as( 'shop_manager' );
		$_POST = array(
			'ffl_bridge_remove_api_key' => '1',
			'_wpnonce'                  => wp_create_nonce( 'ffl_bridge_settings-options' ),
		);

		$this->assertSame( '', sanitize_option( 'ffl_bridge_api_key', '' ) );
	}

	/*
	 * Finding: options.php checked manage_options, not the capability the
	 * settings page uses.
	 */

	public function test_settings_save_requires_manage_woocommerce(): void {
		$capability = apply_filters( 'option_page_capability_ffl_bridge_settings', 'manage_options' );
		$this->assertSame( 'manage_woocommerce', $capability );

		$registered = get_registered_settings();
		foreach ( array( 'ffl_bridge_api_key', 'ffl_bridge_required', 'ffl_bridge_categories' ) as $option ) {
			$this->assertSame( 'ffl_bridge_settings', $registered[ $option ]['group'] ?? null, $option );
		}

		$this->act_as( 'shop_manager' );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertTrue( current_user_can( $capability ), 'A shop manager could not save the settings.' );

		$this->act_as( 'subscriber' );
		$this->assertFalse( current_user_can( $capability ), 'A subscriber could save the settings.' );
	}

	public function test_settings_page_refuses_users_without_the_capability(): void {
		$this->act_as( 'subscriber' );
		$stop = $this->stop( array( FFL_Bridge_Settings::class, 'settings_page' ) );

		$this->assertSame( 'die', $stop->kind );
	}

	/*
	 * Finding (guideline 11): notices showed site-wide.
	 */

	public function test_hybrid_offer_notice_shows_only_on_the_settings_page(): void {
		$this->assertNotFalse( has_action( 'admin_notices', array( FFL_Bridge_Settings::class, 'render_hybrid_offer' ) ) );
		$this->set_option( FFL_Bridge_Network::MODE_OPTION, FFL_Bridge_Network::MODE_CONFIRMED_ONLY );
		$this->set_option( FFL_Bridge_Network::OFFER_OPTION, 'pending' );
		$this->act_as( 'shop_manager' );

		foreach ( $this->other_screens() as $screen ) {
			$this->set_screen( $screen );
			$this->assertSame( '', $this->render( array( FFL_Bridge_Settings::class, 'render_hybrid_offer' ) ), "Offer shown on {$screen}." );
		}

		foreach ( $this->order_screens() as $screen ) {
			$this->set_screen( $screen );
			$this->assertSame( '', $this->render( array( FFL_Bridge_Settings::class, 'render_hybrid_offer' ) ), "Offer shown on {$screen}." );
		}

		$this->set_screen( 'woocommerce_page_ffl-bridge-settings' );
		$this->assertStringContainsString( 'Switch to hybrid', $this->render( array( FFL_Bridge_Settings::class, 'render_hybrid_offer' ) ) );

		$this->act_as( 'subscriber' );
		$this->assertSame( '', $this->render( array( FFL_Bridge_Settings::class, 'render_hybrid_offer' ) ) );
	}

	public function test_coverage_notice_shows_only_on_plugin_screens_and_is_dismissed_per_user(): void {
		$this->assertNotFalse( has_action( 'admin_notices', array( FFL_Bridge_Coverage::class, 'render_admin_notice' ) ) );
		$this->set_option( FFL_Bridge_Coverage::OPTION, array() );
		FFL_Bridge_Coverage::record_gap( '48047', 25, false );
		$first = $this->act_as( 'shop_manager' );

		foreach ( $this->other_screens() as $screen ) {
			$this->set_screen( $screen );
			$this->assertSame( '', $this->render( array( FFL_Bridge_Coverage::class, 'render_admin_notice' ) ), "Coverage notice shown on {$screen}." );
		}

		foreach ( array_merge( array( 'woocommerce_page_ffl-bridge-settings' ), $this->order_screens() ) as $screen ) {
			$this->set_screen( $screen );
			$this->assertStringContainsString( '480xx', $this->render( array( FFL_Bridge_Coverage::class, 'render_admin_notice' ) ), "Coverage notice missing on {$screen}." );
		}

		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'ffl_bridge_dismiss_coverage' ) );
		$this->assertSame( 'redirect', $this->stop( array( FFL_Bridge_Coverage::class, 'handle_dismiss' ) )->kind );
		$this->assertGreaterThan( 0, (int) get_user_meta( $first, 'ffl_bridge_coverage_dismissed', true ) );
		$this->assertSame( '', $this->render( array( FFL_Bridge_Coverage::class, 'render_admin_notice' ) ), 'Dismissal not honored.' );

		$this->act_as( 'shop_manager' );
		$this->assertStringContainsString( '480xx', $this->render( array( FFL_Bridge_Coverage::class, 'render_admin_notice' ) ), 'One user dismissed the notice for everyone.' );
	}

	public function test_woocommerce_dependency_notice_shows_only_on_the_plugins_screen(): void {
		$this->act_as( 'administrator' );

		foreach ( array_merge( $this->other_screens(), array( 'woocommerce_page_ffl-bridge-settings' ) ) as $screen ) {
			if ( 'plugins' === $screen ) {
				continue;
			}
			$this->set_screen( $screen );
			$this->assertSame( '', $this->render( 'ffl_bridge_woocommerce_notice' ), "Dependency notice shown on {$screen}." );
		}

		$this->set_screen( 'plugins' );
		$this->assertStringContainsString( 'requires WooCommerce', $this->render( 'ffl_bridge_woocommerce_notice' ) );
	}

	/*
	 * Every state-changing handler checks the capability and a real nonce.
	 */

	public function test_hybrid_offer_handler_checks_capability_nonce_and_choice(): void {
		$this->set_option( FFL_Bridge_Network::MODE_OPTION, FFL_Bridge_Network::MODE_CONFIRMED_ONLY );
		$this->set_option( FFL_Bridge_Network::OFFER_OPTION, 'pending' );
		$handler = array( FFL_Bridge_Settings::class, 'handle_hybrid_offer' );

		$this->act_as( 'subscriber' );
		$_GET     = array( 'choice' => 'switch' );
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'ffl_bridge_hybrid_offer' ) );
		$this->assertSame( 403, $this->stop( $handler )->status );

		$this->act_as( 'shop_manager' );
		$_REQUEST = array();
		$this->assertSame( 403, $this->stop( $handler )->status );

		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'ffl_bridge_dismiss_coverage' ) );
		$this->assertSame( 403, $this->stop( $handler )->status );

		$_GET     = array( 'choice' => 'everything' );
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'ffl_bridge_hybrid_offer' ) );
		$this->assertSame( 400, $this->stop( $handler )->status );
		$this->assertSame( FFL_Bridge_Network::MODE_CONFIRMED_ONLY, get_option( FFL_Bridge_Network::MODE_OPTION ) );
		$this->assertSame( 'pending', get_option( FFL_Bridge_Network::OFFER_OPTION ) );

		$_GET = array( 'choice' => 'keep' );
		$this->assertSame( 'redirect', $this->stop( $handler )->kind );
		$this->assertSame( 'answered', get_option( FFL_Bridge_Network::OFFER_OPTION ) );
	}

	public function test_coverage_dismissal_checks_capability_and_nonce(): void {
		$handler = array( FFL_Bridge_Coverage::class, 'handle_dismiss' );

		$subscriber = $this->act_as( 'subscriber' );
		$_REQUEST   = array( '_wpnonce' => wp_create_nonce( 'ffl_bridge_dismiss_coverage' ) );
		$this->assertSame( 403, $this->stop( $handler )->status );
		$this->assertSame( '', get_user_meta( $subscriber, 'ffl_bridge_coverage_dismissed', true ) );

		$manager  = $this->act_as( 'shop_manager' );
		$_REQUEST = array();
		$this->assertSame( 403, $this->stop( $handler )->status );
		$this->assertSame( '', get_user_meta( $manager, 'ffl_bridge_coverage_dismissed', true ) );
	}

	public function test_connection_test_checks_capability_and_nonce(): void {
		$requests = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$requests ) {
				++$requests;
				return new WP_Error( 'blocked', 'No network in tests.' );
			}
		);
		$handler = array( FFL_Bridge_Settings::class, 'ajax_test_connection' );

		$this->act_as( 'subscriber' );
		$_POST = array(
			'nonce' => wp_create_nonce( 'ffl_bridge_admin' ),
			'zip'   => '48047',
		);
		$stop  = $this->stop( $handler, true );
		$this->assertFalse( json_decode( $stop->output, true )['success'] );

		$this->act_as( 'shop_manager' );
		$_POST = array( 'zip' => '48047' );
		$stop  = $this->stop( $handler, true );
		$this->assertFalse( json_decode( $stop->output, true )['success'] );
		$this->assertStringContainsString( 'security token', $stop->output );

		$this->assertSame( 0, $requests, 'A rejected request reached the FFL Bridge API.' );
	}

	public function test_transfer_confirmation_checks_capability_and_order_nonce(): void {
		$order = wc_create_order();
		$id    = $order->get_id();

		try {
			$this->act_as( 'subscriber' );
			$_POST = array(
				'order_id' => (string) $id,
				'nonce'    => wp_create_nonce( 'ffl_bridge_confirm_transfer_' . $id ),
			);
			$stop  = $this->stop( array( FFL_Bridge_Transfer_Confirmation::class, 'ajax_confirm' ), true );
			$this->assertFalse( json_decode( $stop->output, true )['success'] );

			$this->act_as( 'shop_manager' );
			$_POST = array(
				'order_id' => (string) $id,
				'nonce'    => wp_create_nonce( 'ffl_bridge_confirm_transfer_' . ( $id + 1 ) ),
			);
			$stop  = $this->stop( array( FFL_Bridge_Transfer_Confirmation::class, 'ajax_confirm' ), true );
			$this->assertFalse( json_decode( $stop->output, true )['success'] );
			$this->assertSame( '', (string) wc_get_order( $id )->get_meta( '_ffl_bridge_store_confirmed_at' ) );

			$this->act_as( 'subscriber' );
			$_GET     = array( 'order_id' => (string) $id );
			$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'ffl_bridge_license_file_' . $id ) );
			$this->assertSame( 403, $this->stop( array( FFL_Bridge_Transfer_Confirmation::class, 'download_license_file' ) )->status );

			$this->act_as( 'shop_manager' );
			$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'ffl_bridge_license_file_' . ( $id + 1 ) ) );
			$this->assertSame( 403, $this->stop( array( FFL_Bridge_Transfer_Confirmation::class, 'download_license_file' ) )->status );
		} finally {
			$order->delete( true );
		}
	}

	public function test_checkout_ajax_rejects_guests_without_a_nonce(): void {
		foreach ( array( 'ajax_search', 'ajax_select', 'ajax_clear' ) as $method ) {
			$_POST = array(
				'zip'    => '48047',
				'radius' => '25',
			);
			$stop  = $this->stop( array( FFL_Bridge_Checkout::class, $method ), true );
			$this->assertFalse( json_decode( $stop->output, true )['success'], "{$method} accepted a request without a nonce." );
		}
	}

	/**
	 * @return array<int, string>
	 */
	private function other_screens(): array {
		return array( 'dashboard', 'plugins', 'edit-post', 'edit-product', 'woocommerce_page_wc-settings', 'users' );
	}

	/**
	 * @return array<int, string>
	 */
	private function order_screens(): array {
		return array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' );
	}
}
