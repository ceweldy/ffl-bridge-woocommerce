<?php
/**
 * Server-side FFL Bridge API client.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calls the FFL Bridge API without exposing the merchant credential to clients.
 */
final class FFL_Bridge_API_Client {

	private const API_BASE_URL  = 'https://www.fflbridge.com/api/v1';
	private const MAX_BODY_SIZE = 1048576;
	private const TIMEOUT       = 6;
	private const CACHE_TTL     = 300;

	/**
	 * Return the configured API key.
	 *
	 * A wp-config.php constant is preferred because it keeps the credential out
	 * of the WordPress options table.
	 *
	 * @return string
	 */
	public static function get_api_key(): string {
		if ( defined( 'FFL_BRIDGE_API_KEY' ) && is_string( FFL_BRIDGE_API_KEY ) ) {
			return trim( FFL_BRIDGE_API_KEY );
		}

		$value = get_option( 'ffl_bridge_api_key', '' );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Determine whether a syntactically valid API key is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return self::is_valid_api_key( self::get_api_key() );
	}

	/**
	 * Validate the FFL Bridge API key format.
	 *
	 * @param string $api_key API key.
	 * @return bool
	 */
	public static function is_valid_api_key( string $api_key ): bool {
		return 1 === preg_match( '/\Affl_live_[A-Za-z0-9]{32}\z/', $api_key );
	}

	/**
	 * Return a non-secret suffix for the settings screen.
	 *
	 * @return string
	 */
	public static function get_key_suffix(): string {
		$key = self::get_api_key();
		return '' === $key ? '' : substr( $key, -4 );
	}

	/**
	 * Search for active dealers by ZIP code.
	 *
	 * @param string $zip ZIP code.
	 * @param int    $radius Search radius in miles.
	 * @param int    $limit Maximum result count.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function search( string $zip, int $radius, int $limit = 20 ): array|WP_Error {
		if ( 1 !== preg_match( '/\A\d{5}\z/', $zip ) ) {
			return new WP_Error( 'ffl_bridge_invalid_zip', __( 'Enter a valid five-digit ZIP code.', 'ffl-bridge-for-woocommerce' ) );
		}

		$allowed_radii = array( 10, 25, 50, 100 );
		if ( ! in_array( $radius, $allowed_radii, true ) ) {
			return new WP_Error( 'ffl_bridge_invalid_radius', __( 'Choose a supported search radius.', 'ffl-bridge-for-woocommerce' ) );
		}

		$limit     = max( 1, min( 25, $limit ) );
		$cache_key = self::search_cache_key( $zip, $radius, $limit );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = self::request(
			'/search',
			array(
				'zip'              => $zip,
				'radius'           => $radius,
				'limit'            => $limit,
				'acceptsTransfers' => 'true',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_results = $response['data']['results'] ?? null;
		if ( ! is_array( $raw_results ) ) {
			return new WP_Error( 'ffl_bridge_invalid_response', __( 'FFL Bridge returned an invalid search response.', 'ffl-bridge-for-woocommerce' ) );
		}

		$results = array();
		foreach ( array_slice( $raw_results, 0, $limit ) as $raw_dealer ) {
			if ( ! is_array( $raw_dealer ) ) {
				continue;
			}

			$dealer = self::normalize_dealer( $raw_dealer );
			if ( ! is_wp_error( $dealer ) ) {
				$results[] = $dealer;
			}
		}

		set_transient( $cache_key, $results, self::CACHE_TTL );
		return $results;
	}

	/**
	 * Fetch one canonical active dealer for checkout verification.
	 *
	 * @param string $dealer_id Dealer UUID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_dealer( string $dealer_id ): array|WP_Error {
		if ( 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $dealer_id ) ) {
			return new WP_Error( 'ffl_bridge_invalid_dealer', __( 'The selected dealer identifier is invalid.', 'ffl-bridge-for-woocommerce' ) );
		}

		$response = self::request( '/ffls/' . rawurlencode( strtolower( $dealer_id ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_dealer = $response['data']['dealer'] ?? null;
		if ( ! is_array( $raw_dealer ) ) {
			return new WP_Error( 'ffl_bridge_invalid_response', __( 'FFL Bridge returned an invalid dealer response.', 'ffl-bridge-for-woocommerce' ) );
		}

		$dealer = self::normalize_dealer( $raw_dealer );
		if ( is_wp_error( $dealer ) ) {
			return $dealer;
		}

		$eligibility = $response['data']['eligibility'] ?? array();
		if (
			! is_array( $eligibility ) ||
			true !== ( $eligibility['selectable'] ?? false ) ||
			true !== ( $eligibility['licenseVerified'] ?? false )
		) {
			return new WP_Error( 'ffl_bridge_dealer_unavailable', __( 'That dealer is not currently selectable. Choose another dealer or contact the store.', 'ffl-bridge-for-woocommerce' ) );
		}

		$dealer['license_on_file']  = true === ( $eligibility['licenseOnFile'] ?? false );
		$dealer['license_verified'] = true;
		return $dealer;
	}

	/**
	 * Verify that the configured credential can reach the API.
	 *
	 * @return true|WP_Error
	 */
	public static function test_connection(): bool|WP_Error {
		$result = self::search( '32174', 10, 1 );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Normalize an API dealer object into a strict allowlist.
	 *
	 * @param array<string, mixed> $raw Raw API object.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function normalize_dealer( array $raw ): array|WP_Error {
		$id      = self::plain_text( $raw['id'] ?? '' );
		$license = strtoupper( self::plain_text( $raw['licenseNumber'] ?? '' ) );
		$name    = self::bounded_text( $raw['tradeName'] ?? '', 160 );
		$address = self::bounded_text( $raw['address'] ?? '', 180 );
		$city    = self::bounded_text( $raw['city'] ?? '', 100 );

		if ( '' === $name ) {
			$name = self::bounded_text( $raw['businessName'] ?? '', 160 );
		}

		if (
			1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $id ) ||
			1 !== preg_match( '/\A[0-9]-[0-9]{2}-[0-9]{3}-[0-9]{2}-[0-9A-Z]{2}-[0-9]{5}\z/', $license ) ||
			'' === $name ||
			'' === $address ||
			'' === $city
		) {
			return new WP_Error( 'ffl_bridge_invalid_dealer', __( 'FFL Bridge returned incomplete dealer data.', 'ffl-bridge-for-woocommerce' ) );
		}

		$distance = isset( $raw['distance'] ) && is_numeric( $raw['distance'] ) ? round( max( 0, min( 500, (float) $raw['distance'] ) ), 1 ) : null;
		$state    = strtoupper( self::plain_text( $raw['state'] ?? '' ) );
		$zip      = self::plain_text( $raw['zip'] ?? '' );

		if ( 1 !== preg_match( '/\A[A-Z]{2}\z/', $state ) || 1 !== preg_match( '/\A\d{5}(?:-\d{4})?\z/', $zip ) ) {
			return new WP_Error( 'ffl_bridge_invalid_dealer', __( 'FFL Bridge returned an invalid dealer address.', 'ffl-bridge-for-woocommerce' ) );
		}

		return array(
			'id'                => strtolower( $id ),
			'license'           => $license,
			'license_type'      => self::bounded_text( $raw['licenseType'] ?? '', 120 ),
			'name'              => $name,
			'business_name'     => self::bounded_text( $raw['businessName'] ?? '', 160 ),
			'address'           => $address,
			'city'              => $city,
			'state'             => $state,
			'zip'               => $zip,
			'phone'             => self::bounded_text( $raw['phone'] ?? '', 32 ),
			'distance'          => $distance,
			'accepts_transfers' => true === ( $raw['acceptsTransfers'] ?? false ),
			'is_active'         => ! array_key_exists( 'isActive', $raw ) || true === $raw['isActive'],
		);
	}

	/**
	 * Perform a fixed-origin authenticated request.
	 *
	 * @param string               $path API path beneath /api/v1.
	 * @param array<string, mixed> $query Optional query string.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function request( string $path, array $query = array() ): array|WP_Error {
		$api_key = self::get_api_key();
		if ( ! self::is_valid_api_key( $api_key ) ) {
			return new WP_Error( 'ffl_bridge_not_configured', __( 'FFL Bridge is not configured. Ask the store administrator for help.', 'ffl-bridge-for-woocommerce' ) );
		}

		$origin = self::get_site_origin();
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}

		$url = self::API_BASE_URL . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => self::MAX_BODY_SIZE,
			'headers'             => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
				'Origin'        => $origin,
				'Referer'       => trailingslashit( $origin ),
				'User-Agent'    => 'FFL-Bridge-WooCommerce/' . FFL_BRIDGE_VERSION . '; ' . $origin,
			),
		);

		$response = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			$response = wp_safe_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			self::log( 'warning', 'API transport failure.' );
			return new WP_Error( 'ffl_bridge_api_unavailable', __( 'FFL Bridge is temporarily unavailable. Try again shortly.', 'ffl-bridge-for-woocommerce' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $status, array( 502, 503, 504 ), true ) ) {
			$response = wp_safe_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'ffl_bridge_api_unavailable', __( 'FFL Bridge is temporarily unavailable. Try again shortly.', 'ffl-bridge-for-woocommerce' ) );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
		}

		if ( 401 === $status ) {
			self::log( 'error', 'API credential rejected.' );
			return new WP_Error( 'ffl_bridge_api_unauthorized', __( 'The store\'s FFL Bridge connection needs attention.', 'ffl-bridge-for-woocommerce' ) );
		}

		if ( 403 === $status ) {
			self::log( 'error', 'API origin rejected.' );
			return new WP_Error( 'ffl_bridge_api_forbidden', __( 'This store domain is not authorized in FFL Bridge.', 'ffl-bridge-for-woocommerce' ) );
		}

		if ( 429 === $status ) {
			return new WP_Error( 'ffl_bridge_api_limited', __( 'The store has reached its FFL search limit. Try again later or contact the store.', 'ffl-bridge-for-woocommerce' ) );
		}

		if ( 404 === $status ) {
			return new WP_Error( 'ffl_bridge_dealer_unavailable', __( 'That dealer is no longer available. Search and select another dealer.', 'ffl-bridge-for-woocommerce' ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			self::log( 'warning', 'API returned an unexpected status.', array( 'status' => $status ) );
			return new WP_Error( 'ffl_bridge_api_error', __( 'FFL Bridge could not complete the request.', 'ffl-bridge-for-woocommerce' ) );
		}

		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( ! str_contains( $content_type, 'application/json' ) ) {
			return new WP_Error( 'ffl_bridge_invalid_response', __( 'FFL Bridge returned an unexpected response.', 'ffl-bridge-for-woocommerce' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true, 64 );
		if ( ! is_array( $data ) || true !== ( $data['success'] ?? false ) ) {
			return new WP_Error( 'ffl_bridge_invalid_response', __( 'FFL Bridge returned an invalid response.', 'ffl-bridge-for-woocommerce' ) );
		}

		return $data;
	}

	/**
	 * Build the exact site origin used by FFL Bridge domain restrictions.
	 *
	 * @return string|WP_Error
	 */
	private static function get_site_origin(): string|WP_Error {
		$parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'ffl_bridge_invalid_site_url', __( 'The WordPress site URL is invalid.', 'ffl-bridge-for-woocommerce' ) );
		}

		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . absint( $parts['port'] );
		}

		return $origin;
	}

	/**
	 * Generate a cache key without storing the API credential.
	 *
	 * @param string $zip ZIP code.
	 * @param int    $radius Radius.
	 * @param int    $limit Result limit.
	 * @return string
	 */
	private static function search_cache_key( string $zip, int $radius, int $limit ): string {
		$key_scope = hash_hmac( 'sha256', self::get_api_key(), wp_salt( 'auth' ) );
		return 'ffl_bridge_search_' . hash( 'sha256', $key_scope . '|' . $zip . '|' . $radius . '|' . $limit );
	}

	/**
	 * Sanitize and bound one text value from the API.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $length Maximum UTF-8 character count.
	 * @return string
	 */
	private static function bounded_text( mixed $value, int $length ): string {
		$text = self::plain_text( $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}

	/**
	 * Sanitize a scalar without truncating structural identifiers before their
	 * anchored format checks run.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function plain_text( mixed $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Write a redacted operational log entry.
	 *
	 * @param string               $level Log level.
	 * @param string               $message Safe message.
	 * @param array<string, mixed> $context Safe context.
	 * @return void
	 */
	private static function log( string $level, string $message, array $context = array() ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$context['source'] = 'ffl-bridge-for-woocommerce';
		wc_get_logger()->log( $level, $message, $context );
	}
}
