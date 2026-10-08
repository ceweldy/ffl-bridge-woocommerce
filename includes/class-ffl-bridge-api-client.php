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

	public const ALLOWED_RADII = array( 10, 25, 50, 100 );

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
	 * Results come from the public directory. Each normalized dealer carries
	 * checkout_eligible: true for the verified checkout network, false for a
	 * directory-only listing, and null when the API did not report the flag.
	 *
	 * @param string $zip ZIP code.
	 * @param int    $radius Search radius in miles.
	 * @param int    $limit Maximum result count.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function search( string $zip, int $radius, int $limit = 20 ): array|WP_Error {
		$result = self::search_with_meta( $zip, $radius, $limit );
		return is_wp_error( $result ) ? $result : $result['dealers'];
	}

	/**
	 * Search for dealers and return optional coverage metadata as well.
	 *
	 * By default only dealers listed as accepting transfers are requested. With
	 * $include_unconfirmed, the API also returns its separate unconfirmed tier
	 * (nearby dealers with no transfer acceptance on record), which feeds the
	 * merchant-enabled fallback without a second request. APIs that predate
	 * the tier ignore the parameter, and 'unconfirmed' is then null.
	 *
	 * @param string $zip ZIP code.
	 * @param int    $radius Search radius in miles.
	 * @param int    $limit Maximum result count.
	 * @param bool   $transfers_only Request only transfer-accepting dealers.
	 * @param bool   $include_unconfirmed Also request the unconfirmed tier.
	 * @return array{dealers: array<int, array<string, mixed>>, unconfirmed: array<int, array<string, mixed>>|null, coverage: array<string, int>|null, reason: string, reason_message: string}|WP_Error
	 */
	public static function search_with_meta( string $zip, int $radius, int $limit = 20, bool $transfers_only = true, bool $include_unconfirmed = false ): array|WP_Error {
		if ( 1 !== preg_match( '/\A\d{5}\z/', $zip ) ) {
			return new WP_Error( 'ffl_bridge_invalid_zip', __( 'Enter a valid five-digit ZIP code.', 'ffl-bridge-for-woocommerce' ) );
		}

		if ( ! in_array( $radius, self::ALLOWED_RADII, true ) ) {
			return new WP_Error( 'ffl_bridge_invalid_radius', __( 'Choose a supported search radius.', 'ffl-bridge-for-woocommerce' ) );
		}

		$limit               = max( 1, min( 25, $limit ) );
		$include_unconfirmed = $include_unconfirmed && $transfers_only;
		$cache_key           = self::search_cache_key( $zip, $radius, $limit, $transfers_only, $include_unconfirmed );
		$cached              = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['dealers'] ) && is_array( $cached['dealers'] ) ) {
			return $cached;
		}

		$query = array(
			'zip'    => $zip,
			'radius' => $radius,
			'limit'  => $limit,
		);
		if ( $transfers_only ) {
			$query['acceptsTransfers'] = 'true';
		}
		if ( $include_unconfirmed ) {
			$query['includeUnconfirmed'] = 'true';
		}

		$response = self::request( '/search', $query );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_results = $response['data']['results'] ?? null;
		if ( ! is_array( $raw_results ) ) {
			return new WP_Error( 'ffl_bridge_invalid_response', __( 'FFL Bridge returned an invalid search response.', 'ffl-bridge-for-woocommerce' ) );
		}

		$result = array_merge(
			array(
				'dealers'     => self::normalize_dealer_list( $raw_results, $limit ),
				'unconfirmed' => self::parse_unconfirmed_tier( $response['data'], $limit ),
			),
			self::parse_search_meta( $response['data'] )
		);
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Normalize a list of raw API dealers, dropping invalid entries.
	 *
	 * @param array<int|string, mixed> $raw_dealers Raw API dealers.
	 * @param int                      $limit Maximum count.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_dealer_list( array $raw_dealers, int $limit ): array {
		$dealers = array();
		foreach ( array_slice( array_values( $raw_dealers ), 0, $limit ) as $raw_dealer ) {
			if ( ! is_array( $raw_dealer ) ) {
				continue;
			}

			$dealer = self::normalize_dealer( $raw_dealer );
			if ( ! is_wp_error( $dealer ) ) {
				$dealers[] = $dealer;
			}
		}

		return $dealers;
	}

	/**
	 * Read the API's unconfirmed tier, if the response has one.
	 *
	 * The tier lists current ATF-listed dealers with no transfer acceptance on
	 * record. They are never checkout eligible. Dealers that declined
	 * transfers are dropped here as well, in case the API ever includes one.
	 *
	 * @param mixed $data Response data object.
	 * @param int   $limit Maximum count.
	 * @return array<int, array<string, mixed>>|null Null when the response has no tier.
	 */
	public static function parse_unconfirmed_tier( mixed $data, int $limit = 25 ): ?array {
		$tier = is_array( $data ) ? ( $data['unconfirmedTier'] ?? null ) : null;
		if ( ! is_array( $tier ) || ! is_array( $tier['results'] ?? null ) ) {
			return null;
		}

		$dealers = array();
		foreach ( self::normalize_dealer_list( $tier['results'], $limit ) as $dealer ) {
			if ( 'declined' !== $dealer['transfer_status'] ) {
				$dealer['checkout_eligible'] = false;
				$dealers[]                   = $dealer;
			}
		}

		return $dealers;
	}

	/**
	 * Read coverage metadata from a search response.
	 *
	 * Matches data.coverage from FFL Bridge search: radiusMiles,
	 * directoryDealers, transferConfirmedDealers, verifiedCheckoutDealers,
	 * transferDeclinedDealers, transferUnconfirmedDealers, and
	 * emptyReason { code, message } (null when results are not empty).
	 * Older APIs send no coverage, and malformed values are ignored.
	 *
	 * @param mixed $data Response data object.
	 * @return array{coverage: array<string, int>|null, reason: string, reason_message: string}
	 */
	public static function parse_search_meta( mixed $data ): array {
		$coverage = null;
		$raw      = is_array( $data ) ? ( $data['coverage'] ?? null ) : null;
		if ( is_array( $raw ) ) {
			$map = array(
				'radius'               => 'radiusMiles',
				'dealers_in_radius'    => 'directoryDealers',
				'accepting_transfers'  => 'transferConfirmedDealers',
				'checkout_eligible'    => 'verifiedCheckoutDealers',
				'transfer_declined'    => 'transferDeclinedDealers',
				'transfer_unconfirmed' => 'transferUnconfirmedDealers',
			);
			foreach ( $map as $key => $api_key ) {
				$value = $raw[ $api_key ] ?? null;
				if ( is_int( $value ) && $value >= 0 ) {
					$coverage[ $key ] = min( $value, 1000000 );
				}
			}
		}

		$empty   = is_array( $raw ) && is_array( $raw['emptyReason'] ?? null ) ? $raw['emptyReason'] : array();
		$reason  = is_string( $empty['code'] ?? null ) ? strtoupper( $empty['code'] ) : '';
		$message = is_string( $empty['message'] ?? null ) ? self::bounded_text( $empty['message'], 300 ) : '';
		if ( 1 !== preg_match( '/\A[A-Z][A-Z_]{0,63}\z/', $reason ) ) {
			$reason  = '';
			$message = '';
		}

		return array(
			'coverage'       => $coverage,
			'reason'         => $reason,
			'reason_message' => $message,
		);
	}

	/**
	 * Fetch one canonical active dealer for checkout verification.
	 *
	 * @param string $dealer_id Dealer UUID.
	 * @param bool   $allow_unconfirmed Accept a listed, active dealer that is not
	 *                                  in the verified checkout network. Used only
	 *                                  for the merchant-enabled fallback.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_dealer( string $dealer_id, bool $allow_unconfirmed = false ): array|WP_Error {
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

		return self::apply_eligibility( $dealer, $response['data']['eligibility'] ?? null, $allow_unconfirmed );
	}

	/**
	 * Apply the API eligibility object to a normalized dealer.
	 *
	 * A verified checkout network dealer is always accepted. When the fallback
	 * is allowed, a dealer that is still ATF-listed and active is accepted with
	 * transfer_confirmed set to false so the order records that acceptance and
	 * license review must be confirmed with the dealer.
	 *
	 * @param array<string, mixed> $dealer Normalized dealer.
	 * @param mixed                $eligibility API eligibility object.
	 * @param bool                 $allow_unconfirmed Whether the fallback is allowed.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function apply_eligibility( array $dealer, mixed $eligibility, bool $allow_unconfirmed ): array|WP_Error {
		$eligibility = is_array( $eligibility ) ? $eligibility : array();
		$confirmed   = true === ( $eligibility['selectable'] ?? false ) && true === ( $eligibility['licenseVerified'] ?? false );
		$listed      = true === ( $eligibility['isActive'] ?? false ) && true === ( $eligibility['isAtfListed'] ?? false );

		if ( ! $confirmed && ! ( $allow_unconfirmed && $listed ) ) {
			return new WP_Error( 'ffl_bridge_dealer_unavailable', __( 'That dealer is not currently selectable. Choose another dealer or contact the store.', 'ffl-bridge-for-woocommerce' ) );
		}

		$dealer['license_on_file']    = true === ( $eligibility['licenseOnFile'] ?? false );
		$dealer['license_verified']   = true === ( $eligibility['licenseVerified'] ?? false );
		$dealer['checkout_eligible']  = $confirmed;
		$dealer['transfer_confirmed'] = $confirmed;

		// The detail endpoint has no transferStatus. A dealer with transfers on
		// record has confirmed them with FFL Bridge.
		if ( null === ( $dealer['transfer_status'] ?? null ) && ! empty( $dealer['accepts_transfers'] ) ) {
			$dealer['transfer_status'] = 'confirmed';
		}

		return $dealer;
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
			'checkout_eligible' => array_key_exists( 'checkoutEligible', $raw ) ? true === $raw['checkoutEligible'] : null,
			'transfer_status'   => in_array( $raw['transferStatus'] ?? null, array( 'confirmed', 'declined', 'unconfirmed' ), true ) ? $raw['transferStatus'] : null,
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

		if ( 400 === $status && 'LOCATION_NOT_FOUND' === self::error_code( $response ) ) {
			return new WP_Error( 'ffl_bridge_location_not_found', __( 'That ZIP code could not be located. Check it and try again.', 'ffl-bridge-for-woocommerce' ) );
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
	 * Read the API error code from an error response body.
	 *
	 * @param array<string, mixed> $response HTTP response.
	 * @return string
	 */
	private static function error_code( array $response ): string {
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true, 8 );
		$code = is_array( $data ) && is_array( $data['error'] ?? null ) ? ( $data['error']['code'] ?? '' ) : '';
		return is_string( $code ) ? $code : '';
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
	 * @param bool   $transfers_only Whether only transfer-accepting dealers were requested.
	 * @param bool   $include_unconfirmed Whether the unconfirmed tier was requested.
	 * @return string
	 */
	private static function search_cache_key( string $zip, int $radius, int $limit, bool $transfers_only, bool $include_unconfirmed ): string {
		$key_scope = hash_hmac( 'sha256', self::get_api_key(), wp_salt( 'auth' ) );
		$mode      = ( $transfers_only ? 't' : 'a' ) . ( $include_unconfirmed ? 'u' : '' );
		return 'ffl_bridge_search_v3_' . hash( 'sha256', $key_scope . '|' . $zip . '|' . $radius . '|' . $limit . '|' . $mode );
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
