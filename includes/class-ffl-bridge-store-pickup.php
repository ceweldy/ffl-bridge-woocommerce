<?php
/**
 * Optional setting: the store's own FFL for in-store pickup.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * When the store saved its own FFL license number and a shopper picks local
 * pickup for a cart that needs a dealer, the store is the transfer dealer.
 *
 * The license is matched to the store's FFL Bridge record once, when the
 * setting is saved, with a search at the store ZIP code. At checkout that
 * record is fetched again from FFL Bridge, as for any dealer.
 */
final class FFL_Bridge_Store_Pickup {

	public const LICENSE_OPTION = 'ffl_bridge_store_license';
	public const ZIP_OPTION     = 'ffl_bridge_store_zip';
	public const ID_OPTION      = 'ffl_bridge_store_dealer_id';
	public const DEALER_OPTION  = 'ffl_bridge_store_dealer';
	public const FOUND_ZIP      = 'ffl_bridge_store_dealer_zip';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'add_fragment' ) );
	}

	/**
	 * The store's FFL Bridge dealer ID, when configured.
	 *
	 * @return string
	 */
	public static function store_dealer_id(): string {
		$id = get_option( self::ID_OPTION, '' );
		return is_string( $id ) && '' !== FFL_Bridge_Network::normalize_license( (string) get_option( self::LICENSE_OPTION, '' ) ) ? $id : '';
	}

	/**
	 * Public details of the store's dealer record, for the picker.
	 *
	 * @return array<string, string>|null
	 */
	public static function store_dealer(): ?array {
		$dealer = get_option( self::DEALER_OPTION, null );
		if ( '' === self::store_dealer_id() || ! is_array( $dealer ) ) {
			return null;
		}

		$public = array();
		foreach ( array( 'name', 'address', 'city', 'state', 'zip' ) as $key ) {
			$public[ $key ] = is_string( $dealer[ $key ] ?? null ) ? $dealer[ $key ] : '';
		}
		return $public;
	}

	/**
	 * The ZIP code used to find the store: the saved one, else the WooCommerce
	 * store postcode.
	 *
	 * @return string
	 */
	public static function store_zip(): string {
		foreach ( array( get_option( self::ZIP_OPTION, '' ), get_option( 'woocommerce_store_postcode', '' ) ) as $value ) {
			$zip = substr( (string) preg_replace( '/[^0-9]/', '', is_string( $value ) ? $value : '' ), 0, 5 );
			if ( 5 === strlen( $zip ) ) {
				return $zip;
			}
		}

		return '';
	}

	/**
	 * Whether the shopper chose a local pickup shipping method.
	 *
	 * @return bool
	 */
	public static function pickup_chosen(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		$methods = WC()->session->get( 'chosen_shipping_methods', array() );
		foreach ( is_array( $methods ) ? $methods : array() as $method ) {
			if ( is_string( $method ) && 1 === preg_match( '/\A(?:local_pickup|pickup_location)/', $method ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the store is the dealer for the current cart.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return '' !== self::store_dealer_id() && self::pickup_chosen() && FFL_Bridge_Checkout::cart_requires_ffl();
	}

	/**
	 * Sanitize the store license and find the store's FFL Bridge record.
	 *
	 * @param mixed $input Raw input.
	 * @return string Normalized license, or empty.
	 */
	public static function sanitize_store_license( mixed $input ): string {
		$raw      = is_string( $input ) ? trim( sanitize_text_field( wp_unslash( $input ) ) ) : '';
		$license  = FFL_Bridge_Network::normalize_license( $raw );
		$previous = FFL_Bridge_Network::normalize_license( (string) get_option( self::LICENSE_OPTION, '' ) );
		$saved_id = (string) get_option( self::ID_OPTION, '' );
		$zip      = self::store_zip();

		if ( '' === $raw || '' === $license ) {
			self::forget();
			if ( '' !== $raw ) {
				add_settings_error( self::LICENSE_OPTION, 'ffl_bridge_invalid_store_license', esc_html__( 'The store FFL license number is not a valid license number, so in-store pickup was turned off.', 'ffl-bridge-for-woocommerce' ), 'error' );
			}
			return '';
		}

		// Saving other settings (or the second sanitize pass of a first save)
		// keeps the listing found before: nothing to look up again.
		if ( $license === $previous && '' !== $saved_id && $zip === (string) get_option( self::FOUND_ZIP, '' ) ) {
			return $license;
		}

		$result = '' === $zip ? null : FFL_Bridge_API_Client::search_with_meta( $zip, 10, 25, false );
		if ( is_wp_error( $result ) ) {
			if ( $license !== $previous ) {
				self::forget();
			}
			add_settings_error( self::LICENSE_OPTION, 'ffl_bridge_store_license_unchecked', esc_html__( 'FFL Bridge could not be reached to look up the store license. In-store pickup settings were not changed; save again later.', 'ffl-bridge-for-woocommerce' ), 'warning' );
			return $license;
		}

		self::forget();
		$found = null;
		foreach ( is_array( $result ) ? $result['dealers'] : array() as $dealer ) {
			if ( ( $dealer['license'] ?? '' ) === $license ) {
				$found = $dealer;
				break;
			}
		}

		if ( null === $found ) {
			add_settings_error(
				self::LICENSE_OPTION,
				'ffl_bridge_store_license_not_found',
				esc_html__( 'FFL Bridge has no current listing for the store license within 10 miles of the store ZIP code, so in-store pickup is not active. Check the license number and the store ZIP code, then save again.', 'ffl-bridge-for-woocommerce' ),
				'warning'
			);
			return $license;
		}

		update_option( self::ID_OPTION, $found['id'], false );
		update_option( self::FOUND_ZIP, $zip, false );
		update_option(
			self::DEALER_OPTION,
			array(
				'name'    => (string) $found['name'],
				'address' => (string) $found['address'],
				'city'    => (string) $found['city'],
				'state'   => (string) $found['state'],
				'zip'     => (string) $found['zip'],
			),
			false
		);
		return $license;
	}

	/**
	 * Clear the saved store listing.
	 *
	 * @return void
	 */
	private static function forget(): void {
		update_option( self::ID_OPTION, '', false );
		update_option( self::DEALER_OPTION, array(), false );
		update_option( self::FOUND_ZIP, '', false );
	}

	/**
	 * Whether an order was placed with a local pickup shipping method.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function order_is_pickup( WC_Order $order ): bool {
		if ( 'yes' === $order->get_meta( '_ffl_bridge_store_pickup' ) ) {
			return true;
		}

		foreach ( $order->get_shipping_methods() as $item ) {
			$method = is_object( $item ) && is_callable( array( $item, 'get_method_id' ) ) ? $item->get_method_id() : ( $item->method_id ?? '' );
			if ( is_string( $method ) && 1 === preg_match( '/\A(?:local_pickup|pickup_location)/', $method ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitize the store ZIP code.
	 *
	 * @param mixed $input Raw input.
	 * @return string
	 */
	public static function sanitize_store_zip( mixed $input ): string {
		$zip = is_string( $input ) ? substr( (string) preg_replace( '/[^0-9]/', '', $input ), 0, 5 ) : '';
		return 5 === strlen( $zip ) ? $zip : '';
	}

	/**
	 * Classic checkout: report the pickup state after every order review
	 * refresh, so the picker can follow shipping method changes.
	 *
	 * @param mixed $fragments Fragments.
	 * @return mixed
	 */
	public static function add_fragment( mixed $fragments ): mixed {
		if ( is_array( $fragments ) ) {
			$fragments['.ffl-bridge-pickup-state'] = self::state_html();
		}
		return $fragments;
	}

	/**
	 * The hidden element that carries the pickup state.
	 *
	 * @return string
	 */
	public static function state_html(): string {
		return '<div class="ffl-bridge-pickup-state" hidden data-active="' . ( self::active() ? '1' : '0' ) . '"></div>';
	}
}
