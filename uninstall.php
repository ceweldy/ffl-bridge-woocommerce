<?php
/**
 * Remove plugin-owned settings on uninstall.
 *
 * Order metadata is intentionally retained as part of the merchant's order
 * record. Short-lived search transients expire naturally within five minutes.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ffl_bridge_api_key' );
delete_option( 'ffl_bridge_theme' );
delete_option( 'ffl_bridge_required' );
delete_option( 'ffl_bridge_categories' );
delete_option( 'ffl_bridge_result_scope' );
delete_option( 'ffl_bridge_preferred_licenses' );
delete_option( 'ffl_bridge_fallback' );
delete_option( 'ffl_bridge_coverage_log' );
delete_metadata( 'user', 0, 'ffl_bridge_coverage_dismissed', '', true );
delete_option( 'ffl_bridge_settings_version' );
