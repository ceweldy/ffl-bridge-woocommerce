<?php
/**
 * Plugin Name: FFL Bridge for WooCommerce
 * Plugin URI: https://www.fflbridge.com/woocommerce-ffl-plugin
 * Description: Adds server-verified FFL dealer selection metadata to WooCommerce checkout.
 * Version: 1.2.0
 * Author: FFL Bridge
 * Author URI: https://www.fflbridge.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * WC requires at least: 10.8
 * WC tested up to: 10.9
 * Text Domain: ffl-bridge-for-woocommerce
 * Update URI: https://fflbridge.com/api/plugins/woocommerce/update
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'FFL_BRIDGE_VERSION', '1.2.0' );
define( 'FFL_BRIDGE_PLUGIN_FILE', __FILE__ );
define( 'FFL_BRIDGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFL_BRIDGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare WooCommerce feature compatibility.
 *
 * The plugin uses WC_Order CRUD for order data and has dedicated classic and
 * Checkout Block integrations.
 *
 * @return void
 */
function ffl_bridge_declare_compatibility(): void {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'ffl_bridge_declare_compatibility' );

/**
 * Display a dependency notice when WooCommerce is unavailable.
 *
 * @return void
 */
function ffl_bridge_woocommerce_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'FFL Bridge for WooCommerce requires WooCommerce 10.8 or newer.', 'ffl-bridge-for-woocommerce' );
	echo '</p></div>';
}

/**
 * Initialize the plugin after WooCommerce has loaded.
 *
 * @return void
 */
function ffl_bridge_init(): void {
	if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '10.8', '<' ) ) {
		add_action( 'admin_notices', 'ffl_bridge_woocommerce_notice' );
		return;
	}

	require_once FFL_BRIDGE_PLUGIN_DIR . 'includes/class-ffl-bridge-api-client.php';
	require_once FFL_BRIDGE_PLUGIN_DIR . 'includes/class-ffl-bridge-network.php';
	require_once FFL_BRIDGE_PLUGIN_DIR . 'includes/class-ffl-bridge-coverage.php';
	require_once FFL_BRIDGE_PLUGIN_DIR . 'includes/class-ffl-bridge-followup.php';
	require_once FFL_BRIDGE_PLUGIN_DIR . 'includes/class-ffl-bridge-transfer-confirmation.php';
	require_once FFL_BRIDGE_PLUGIN_DIR . 'includes/class-ffl-bridge-selection.php';
	require_once FFL_BRIDGE_PLUGIN_DIR . 'includes/class-ffl-bridge-settings.php';
	require_once FFL_BRIDGE_PLUGIN_DIR . 'includes/class-ffl-bridge-checkout.php';
	require_once FFL_BRIDGE_PLUGIN_DIR . 'includes/class-ffl-bridge-order.php';
	require_once FFL_BRIDGE_PLUGIN_DIR . 'includes/class-ffl-bridge-blocks-integration.php';

	FFL_Bridge_Settings::init();
	FFL_Bridge_Checkout::init();
	FFL_Bridge_Order::init();
	FFL_Bridge_Coverage::init();
	FFL_Bridge_Transfer_Confirmation::init();
	FFL_Bridge_Network::maybe_migrate();
	FFL_Bridge_Blocks_Integration::init();
}
add_action( 'plugins_loaded', 'ffl_bridge_init', 20 );

/**
 * Load the self-hosted updater when it is part of this build.
 *
 * It loads even without WooCommerce so a store can always update the
 * plugin. The WordPress.org directory build leaves the file out.
 *
 * @return void
 */
function ffl_bridge_init_updater(): void {
	$updater = FFL_BRIDGE_PLUGIN_DIR . 'includes/class-ffl-bridge-updater.php';
	if ( is_readable( $updater ) ) {
		require_once $updater;
		FFL_Bridge_Updater::init();
	}
}
add_action( 'plugins_loaded', 'ffl_bridge_init_updater', 5 );

/**
 * Add default options without overwriting an existing installation.
 *
 * @return void
 */
function ffl_bridge_activate(): void {
	// A site that never stored plugin settings is a new install and starts in
	// hybrid mode. Existing installs are migrated by maybe_migrate() instead.
	if ( false === get_option( 'ffl_bridge_settings_version', false ) ) {
		add_option( 'ffl_bridge_dealer_mode', 'hybrid', '', false );
	}

	add_option( 'ffl_bridge_api_key', '', '', false );
	add_option( 'ffl_bridge_theme', 'light', '', false );
	add_option( 'ffl_bridge_required', 'yes', '', false );
	add_option( 'ffl_bridge_categories', array(), '', false );
	add_option( 'ffl_bridge_result_scope', 'all', '', false );
	add_option( 'ffl_bridge_preferred_licenses', array(), '', false );
	add_option( 'ffl_bridge_license_email', '', '', false );
	add_option( 'ffl_bridge_license_fax', '', '', false );
	add_option( 'ffl_bridge_settings_version', FFL_BRIDGE_VERSION, '', false );
}
register_activation_hook( __FILE__, 'ffl_bridge_activate' );

/**
 * Add a settings link to the Plugins screen.
 *
 * @param array<int|string, string> $links Existing action links.
 * @return array<int|string, string>
 */
function ffl_bridge_settings_link( array $links ): array {
	$url  = admin_url( 'admin.php?page=ffl-bridge-settings' );
	$link = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( $url ),
		esc_html__( 'Settings', 'ffl-bridge-for-woocommerce' )
	);

	array_unshift( $links, $link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ffl_bridge_settings_link' );

/**
 * Add suggested privacy-policy disclosure for the external FFL Bridge service.
 *
 * @return void
 */
function ffl_bridge_add_privacy_policy_content(): void {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$content  = '<p>' . esc_html__( 'When a shopper searches for an FFL dealer, this site sends the search ZIP code, radius, site origin, and technical request metadata to FFL Bridge. The site credential is transmitted server-to-server and is never sent to the shopper\'s browser.', 'ffl-bridge-for-woocommerce' ) . '</p>';
	$content .= '<p>' . esc_html__( 'To show store administrators where shoppers could not find a transfer dealer, the plugin stores the first three digits of a searched ZIP code, the search radius, and counts for up to 31 days (30 days per search, kept by day). These records are not linked to a shopper or an order.', 'ffl-bridge-for-woocommerce' ) . '</p>';
	$content .= '<p>' . esc_html__( 'When store staff mark a dealer transfer as confirmed, the plugin sends the dealer identifier, license number, order number, an optional staff note, and an optional copy of the dealer license to FFL Bridge. A license copy uploaded by staff is stored privately with the order.', 'ffl-bridge-for-woocommerce' ) . '</p>';
	$content .= '<p>' . wp_kses_post(
		sprintf(
			/* translators: 1: privacy-policy URL, 2: terms URL. */
			__( 'See the <a href="%1$s">FFL Bridge Privacy Policy</a> and <a href="%2$s">Terms</a> for service retention and processing details.', 'ffl-bridge-for-woocommerce' ),
			esc_url( 'https://www.fflbridge.com/privacy' ),
			esc_url( 'https://www.fflbridge.com/terms' )
		)
	) . '</p>';

	wp_add_privacy_policy_content(
		esc_html__( 'FFL Bridge for WooCommerce', 'ffl-bridge-for-woocommerce' ),
		wp_kses_post( wpautop( $content, false ) )
	);
}
add_action( 'admin_init', 'ffl_bridge_add_privacy_policy_content' );
