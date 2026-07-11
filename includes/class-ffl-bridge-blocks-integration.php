<?php
/**
 * WooCommerce Checkout Blocks integration.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Registers a local Checkout Block SlotFill and its non-secret settings.
 */
final class FFL_Bridge_Blocks_Integration implements IntegrationInterface {

	/**
	 * Register this integration with WooCommerce Blocks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'woocommerce_blocks_checkout_block_registration', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register an instance in the WooCommerce integration registry.
	 *
	 * @param object $integration_registry WooCommerce integration registry.
	 * @return void
	 */
	public static function register( object $integration_registry ): void {
		if ( method_exists( $integration_registry, 'register' ) ) {
			$integration_registry->register( new self() );
		}
	}

	/**
	 * Integration name used for script data.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'ffl-bridge';
	}

	/**
	 * Register local scripts and styles.
	 *
	 * @return void
	 */
	public function initialize(): void {
		if ( ! wp_script_is( 'ffl-bridge-checkout', 'registered' ) ) {
			wp_register_script(
				'ffl-bridge-checkout',
				FFL_BRIDGE_PLUGIN_URL . 'assets/js/checkout.js',
				array( 'jquery' ),
				FFL_BRIDGE_VERSION,
				true
			);
		}

		wp_register_script(
			'ffl-bridge-checkout-blocks',
			FFL_BRIDGE_PLUGIN_URL . 'assets/js/blocks.js',
			array( 'ffl-bridge-checkout', 'wc-blocks-checkout', 'wc-settings', 'wp-element', 'wp-plugins' ),
			FFL_BRIDGE_VERSION,
			true
		);
		wp_register_style(
			'ffl-bridge-checkout',
			FFL_BRIDGE_PLUGIN_URL . 'assets/css/checkout.css',
			array(),
			FFL_BRIDGE_VERSION
		);
		wp_enqueue_style( 'ffl-bridge-checkout' );
	}

	/**
	 * Frontend script handle.
	 *
	 * @return array<int, string>
	 */
	public function get_script_handles(): array {
		return array( 'ffl-bridge-checkout-blocks' );
	}

	/**
	 * The integration does not render in the editor.
	 *
	 * @return array<int, string>
	 */
	public function get_editor_script_handles(): array {
		return array();
	}

	/**
	 * Return non-secret frontend settings exposed through wcSettings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_script_data(): array {
		return FFL_Bridge_Checkout::get_frontend_config();
	}
}
