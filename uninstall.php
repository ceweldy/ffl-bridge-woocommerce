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
delete_option( 'ffl_bridge_dealer_mode' );
delete_option( 'ffl_bridge_hybrid_offer' );
delete_option( 'ffl_bridge_license_email' );
delete_option( 'ffl_bridge_license_fax' );
delete_option( 'ffl_bridge_send_dealer_email' );
delete_option( 'ffl_bridge_coverage_log' );
delete_option( 'ffl_bridge_api_base_url' );
delete_option( 'ffl_bridge_ship_to_dealer' );
delete_option( 'ffl_bridge_last_dealer_first' );
delete_option( 'ffl_bridge_name_filter' );
delete_option( 'ffl_bridge_feature_offers' );
delete_option( 'ffl_bridge_blocked_licenses' );
delete_option( 'ffl_bridge_product_flags' );
delete_option( 'ffl_bridge_store_license' );
delete_option( 'ffl_bridge_store_zip' );
delete_option( 'ffl_bridge_store_dealer_id' );
delete_option( 'ffl_bridge_store_dealer' );
delete_option( 'ffl_bridge_store_dealer_zip' );
delete_metadata( 'user', 0, 'ffl_bridge_coverage_dismissed', '', true );
delete_metadata( 'user', 0, '_ffl_bridge_last_dealer_id', '', true );
delete_post_meta_by_key( '_ffl_bridge_requires_ffl' );
delete_option( 'ffl_bridge_settings_version' );
