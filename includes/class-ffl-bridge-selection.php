<?php
/**
 * Session- and cart-bound dealer selection handles.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and verifies short-lived opaque selection handles.
 */
final class FFL_Bridge_Selection {

	private const TOKEN_VERSION = 1;
	private const TOKEN_TTL     = 900;
	private const MAX_TOKEN_LEN = 2048;

	/**
	 * Create a signed handle from a server-normalized dealer.
	 *
	 * @param array<string, mixed> $dealer Normalized dealer.
	 * @param int|null             $now Optional timestamp for testing.
	 * @return string|WP_Error
	 */
	public static function create( array $dealer, ?int $now = null ): string|WP_Error {
		$cart_hash       = self::get_cart_hash();
		$session_binding = self::get_session_binding();

		if ( is_wp_error( $cart_hash ) || is_wp_error( $session_binding ) ) {
			return new WP_Error( 'ffl_bridge_session_unavailable', __( 'The checkout session is unavailable. Refresh the page and try again.', 'ffl-bridge-for-woocommerce' ) );
		}

		$dealer_id = isset( $dealer['id'] ) && is_string( $dealer['id'] ) ? $dealer['id'] : '';
		$license   = isset( $dealer['license'] ) && is_string( $dealer['license'] ) ? $dealer['license'] : '';
		if ( '' === $dealer_id || '' === $license ) {
			return new WP_Error( 'ffl_bridge_invalid_dealer', __( 'The dealer selection is incomplete.', 'ffl-bridge-for-woocommerce' ) );
		}

		$issued_at = $now ?? time();
		$payload   = array(
			'v'   => self::TOKEN_VERSION,
			'id'  => $dealer_id,
			'lic' => $license,
			'ch'  => $cart_hash,
			'sh'  => $session_binding,
			'iat' => $issued_at,
			'exp' => $issued_at + self::TOKEN_TTL,
			'n'   => bin2hex( random_bytes( 12 ) ),
		);

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return new WP_Error( 'ffl_bridge_selection_failed', __( 'The dealer selection could not be secured.', 'ffl-bridge-for-woocommerce' ) );
		}

		$encoded   = self::base64url_encode( $json );
		$signature = self::base64url_encode( hash_hmac( 'sha256', $encoded, self::signing_key(), true ) );
		return $encoded . '.' . $signature;
	}

	/**
	 * Verify a signed handle against the current WooCommerce session and cart.
	 *
	 * @param string   $token Selection handle.
	 * @param int|null $now Optional timestamp for testing.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function verify( string $token, ?int $now = null ): array|WP_Error {
		if ( '' === $token || strlen( $token ) > self::MAX_TOKEN_LEN || 1 !== substr_count( $token, '.' ) ) {
			return self::invalid_token();
		}

		list( $encoded, $signature ) = explode( '.', $token, 2 );
		$expected                    = self::base64url_encode( hash_hmac( 'sha256', $encoded, self::signing_key(), true ) );
		if ( ! hash_equals( $expected, $signature ) ) {
			return self::invalid_token();
		}

		$json = self::base64url_decode( $encoded );
		if ( false === $json ) {
			return self::invalid_token();
		}

		$payload = json_decode( $json, true, 16 );
		if ( ! is_array( $payload ) ) {
			return self::invalid_token();
		}

		$required = array( 'v', 'id', 'lic', 'ch', 'sh', 'iat', 'exp', 'n' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				return self::invalid_token();
			}
		}

		$current_time = $now ?? time();
		if (
			self::TOKEN_VERSION !== (int) $payload['v'] ||
			! is_string( $payload['id'] ) ||
			! is_string( $payload['lic'] ) ||
			! is_string( $payload['ch'] ) ||
			! is_string( $payload['sh'] ) ||
			(int) $payload['iat'] > $current_time + 60 ||
			(int) $payload['exp'] < $current_time ||
			(int) $payload['exp'] > $current_time + self::TOKEN_TTL + 60
		) {
			return self::invalid_token();
		}

		$cart_hash       = self::get_cart_hash();
		$session_binding = self::get_session_binding();
		if (
			is_wp_error( $cart_hash ) ||
			is_wp_error( $session_binding ) ||
			! hash_equals( $cart_hash, $payload['ch'] ) ||
			! hash_equals( $session_binding, $payload['sh'] )
		) {
			return new WP_Error( 'ffl_bridge_stale_selection', __( 'The cart changed after the dealer was selected. Search and select the dealer again.', 'ffl-bridge-for-woocommerce' ) );
		}

		return $payload;
	}

	/**
	 * Return a stable binding for the current WooCommerce session.
	 *
	 * @return string|WP_Error
	 */
	public static function get_session_binding(): string|WP_Error {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return new WP_Error( 'ffl_bridge_session_unavailable' );
		}

		$customer_id = WC()->session->get_customer_id();
		if ( ! is_string( $customer_id ) || '' === $customer_id ) {
			return new WP_Error( 'ffl_bridge_session_unavailable' );
		}

		return hash_hmac( 'sha256', $customer_id, wp_salt( 'nonce' ) );
	}

	/**
	 * Return the current cart hash.
	 *
	 * @return string|WP_Error
	 */
	public static function get_cart_hash(): string|WP_Error {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return new WP_Error( 'ffl_bridge_cart_unavailable' );
		}

		$cart_hash = WC()->cart->get_cart_hash();
		return is_string( $cart_hash ) && '' !== $cart_hash ? $cart_hash : new WP_Error( 'ffl_bridge_cart_unavailable' );
	}

	/**
	 * Return a browser request token bound to the WooCommerce session.
	 *
	 * @return string
	 */
	public static function get_request_token(): string {
		$binding = self::get_session_binding();
		return is_wp_error( $binding ) ? '' : hash_hmac( 'sha256', 'ffl-bridge-ajax', $binding );
	}

	/**
	 * Verify a browser request token.
	 *
	 * @param string $token Request token.
	 * @return bool
	 */
	public static function verify_request_token( string $token ): bool {
		$expected = self::get_request_token();
		return '' !== $expected && hash_equals( $expected, $token );
	}

	/**
	 * Derive a signing key from WordPress salts.
	 *
	 * @return string
	 */
	private static function signing_key(): string {
		return hash_hmac( 'sha256', 'ffl-bridge-selection-v1', wp_salt( 'auth' ), true );
	}

	/**
	 * Encode a binary string as URL-safe base64 without padding.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard signed-token transport encoding, not code obfuscation.
	}

	/**
	 * Decode URL-safe base64.
	 *
	 * @param string $value Encoded value.
	 * @return string|false
	 */
	private static function base64url_decode( string $value ): string|false {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_-]+\z/', $value ) ) {
			return false;
		}

		$padding = strlen( $value ) % 4;
		if ( 0 !== $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}

		return base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Standard signed-token transport decoding, not code execution.
	}

	/**
	 * Return a generic invalid-selection error.
	 *
	 * @return WP_Error
	 */
	private static function invalid_token(): WP_Error {
		return new WP_Error( 'ffl_bridge_invalid_selection', __( 'The dealer selection is invalid or expired. Search and select the dealer again.', 'ffl-bridge-for-woocommerce' ) );
	}
}
