<?php
/**
 * Integration test bootstrap: loads a real WordPress install with WooCommerce
 * and this plugin active. Run bin/install-wp-tests.sh first.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

$ffl_bridge_wp_dir = getenv( 'WP_CORE_DIR' );
if ( false === $ffl_bridge_wp_dir || '' === $ffl_bridge_wp_dir ) {
	$ffl_bridge_wp_dir = rtrim( (string) ( getenv( 'TMPDIR' ) ?: '/tmp' ), '/' ) . '/ffl-bridge-wp';
}

if ( ! is_readable( $ffl_bridge_wp_dir . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress was not found in {$ffl_bridge_wp_dir}. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']       = 'ffl-bridge.test';
$_SERVER['SERVER_NAME']     = 'ffl-bridge.test';
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

require $ffl_bridge_wp_dir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) || ! is_plugin_active( 'ffl-bridge-for-woocommerce/ffl-bridge.php' ) ) {
	fwrite( STDERR, "WooCommerce and FFL Bridge for WooCommerce must both be active.\n" );
	exit( 1 );
}

if ( ! get_role( 'shop_manager' ) ) {
	WC_Install::create_roles();
}

// Settings are registered on admin_init, which a CLI request never fires.
FFL_Bridge_Settings::register_settings();

fwrite(
	STDOUT,
	sprintf( "WordPress %s, WooCommerce %s, PHP %s\n\n", get_bloginfo( 'version' ), WC_VERSION, PHP_VERSION )
);

require __DIR__ . '/IntegrationTestCase.php';
