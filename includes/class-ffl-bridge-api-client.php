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

	public const DEFAULT_API_BASE_URL = 'https://www.fflbridge.com/api/v1';
	public const API_BASE_URL_OPTION  = 'ffl_bridge_api_base_url';

	private const MAX_BODY_SIZE = 1048576;
	private const TIMEOUT       = 6;

	/**
	 * Seconds to wait for POST /orders. FFL Bridge sends the dealer email
	 * inside that request, which alone can take several seconds.
	 */
	private const ORDER_TIMEOUT = 20;
	private const CACHE_TTL     = 300;

	public const ALLOWED_RADII = array( 10, 25, 50, 100 );

	/**
	 * WP_Error codes for known 403 reasons in the API error body. Any other
	 * 403 becomes the generic ffl_bridge_api_forbidden.
	 */
	private const FORBIDDEN_CODES = array(
		'EMAIL_NOT_VERIFIED'    => 'ffl_bridge_email_not_verified',
		'DOMAIN_NOT_ALLOWED'    => 'ffl_bridge_domain_not_allowed',
		'KEY_SCOPE_NOT_ALLOWED' => 'ffl_bridge_key_scope_not_allowed',
	);

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
	 * Return the FFL Bridge API base URL, without a trailing slash.
	 *
	 * Precedence: a valid FFL_BRIDGE_API_BASE_URL constant, then a valid value
	 * from the ffl_bridge_api_base_url filter, then a valid saved option, then
	 * the production default. Every source must be an HTTPS URL; an invalid
	 * value is skipped in favor of the next source.
	 *
	 * @return string
	 */
	public static function get_api_base_url(): string {
		if ( self::api_base_url_from_constant() ) {
			return self::normalize_api_base_url( constant( 'FFL_BRIDGE_API_BASE_URL' ) );
		}

		$base = self::normalize_api_base_url( get_option( self::API_BASE_URL_OPTION, '' ) );
		if ( '' === $base ) {
			$base = self::DEFAULT_API_BASE_URL;
		}

		/**
		 * Filter the FFL Bridge API base URL, for example to point a staging
		 * site at a preview deployment. Must return an HTTPS URL; any other
		 * value is ignored. A valid FFL_BRIDGE_API_BASE_URL constant wins.
		 *
		 * @param string $base Saved setting or the production default.
		 */
		$filtered = self::normalize_api_base_url( apply_filters( 'ffl_bridge_api_base_url', $base ) );

		return '' !== $filtered ? $filtered : $base;
	}

	/**
	 * Determine whether a valid FFL_BRIDGE_API_BASE_URL constant is active.
	 *
	 * @return bool
	 */
	public static function api_base_url_from_constant(): bool {
		return defined( 'FFL_BRIDGE_API_BASE_URL' ) && '' !== self::normalize_api_base_url( constant( 'FFL_BRIDGE_API_BASE_URL' ) );
	}

	/**
	 * Validate and normalize an API base URL.
	 *
	 * Accepts only absolute HTTPS URLs with a host, an optional port and an
	 * optional path. Credentials, query strings, fragments, whitespace and
	 * control characters are rejected. Trailing slashes are removed.
	 *
	 * @param mixed $url Candidate URL.
	 * @return string Normalized URL, or an empty string when invalid.
	 */
	public static function normalize_api_base_url( mixed $url ): string {
		if ( ! is_string( $url ) ) {
			return '';
		}

		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 2048 ) {
			return '';
		}

		// Scheme, host, optional port, optional path of unreserved characters.
		if ( 1 !== preg_match( '#\Ahttps://([A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?)(?::([0-9]{1,5}))?((?:/[A-Za-z0-9._~%-]*)*)\z#i', $url, $matches ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || ( $parts['host'] ?? '' ) !== $matches[1]
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		if ( isset( $matches[2] ) && '' !== $matches[2] && ( (int) $matches[2] < 1 || (int) $matches[2] > 65535 ) ) {
			return '';
		}

		return 'https://' . strtolower( $matches[1] ) . ( isset( $matches[2] ) && '' !== $matches[2] ? ':' . (int) $matches[2] : '' ) . rtrim( $matches[3] ?? '', '/' );
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
	 * hybrid dealer list without a second request. APIs that predate
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
	 *                                  for hybrid dealer selection.
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
	 * A verified checkout network dealer is always accepted. In hybrid mode a
	 * dealer that is still ATF-listed and active is accepted as well. Three
	 * facts are recorded separately:
	 * - checkout_verified: in the verified checkout network (selectable).
	 * - transfer_confirmed: the dealer confirmed transfers with FFL Bridge.
	 * - license_verified: FFL Bridge verified a current license copy.
	 *
	 * @param array<string, mixed> $dealer Normalized dealer.
	 * @param mixed                $eligibility API eligibility object.
	 * @param bool                 $allow_unconfirmed Whether hybrid selection is allowed.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function apply_eligibility( array $dealer, mixed $eligibility, bool $allow_unconfirmed ): array|WP_Error {
		$eligibility = is_array( $eligibility ) ? $eligibility : array();
		$verified    = true === ( $eligibility['selectable'] ?? false ) && true === ( $eligibility['licenseVerified'] ?? false );
		$listed      = true === ( $eligibility['isActive'] ?? false ) && true === ( $eligibility['isAtfListed'] ?? false );

		$declined = 'declined' === ( $dealer['transfer_status'] ?? null );

		if ( ! $verified && ! ( $allow_unconfirmed && $listed && ! $declined ) ) {
			return new WP_Error( 'ffl_bridge_dealer_unavailable', __( 'That dealer is not currently selectable. Choose another dealer or contact the store.', 'ffl-bridge-for-woocommerce' ) );
		}

		$accepts = array_key_exists( 'acceptsTransfers', $eligibility )
			? true === $eligibility['acceptsTransfers']
			: ! empty( $dealer['accepts_transfers'] );

		$dealer['license_on_file']    = true === ( $eligibility['licenseOnFile'] ?? false );
		$dealer['license_verified']   = true === ( $eligibility['licenseVerified'] ?? false );
		$dealer['checkout_eligible']  = $verified;
		$dealer['checkout_verified']  = $verified;
		$dealer['transfer_confirmed'] = $verified || $accepts;

		// The detail endpoint has no transferStatus, so derive it.
		if ( null === ( $dealer['transfer_status'] ?? null ) ) {
			$dealer['transfer_status'] = $dealer['transfer_confirmed'] ? 'confirmed' : 'unconfirmed';
		}

		return $dealer;
	}

	/**
	 * Report a store's transfer confirmation to FFL Bridge.
	 *
	 * Calls POST /api/v1/dealers/{dealerId}/transfer-confirmations. Without a
	 * file the body is JSON. With a file it is multipart/form-data with the
	 * same fields as text parts and a licenseFile part, which is what the API
	 * expects. The request is sent once, without retries. A 404 means the API
	 * does not offer the endpoint yet.
	 *
	 * @param string                     $dealer_id Dealer UUID.
	 * @param string                     $license License number.
	 * @param string                     $order_reference Store order number.
	 * @param string                     $note Optional note from store staff.
	 * @param array<string, string>|null $file Optional license file: filename, contentType, path.
	 * @return array<string, mixed>|WP_Error Response data on success.
	 */
	public static function confirm_transfer( string $dealer_id, string $license, string $order_reference, string $note = '', ?array $file = null ): array|WP_Error {
		if ( 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $dealer_id ) ) {
			return new WP_Error( 'ffl_bridge_invalid_dealer', __( 'The order has no FFL Bridge dealer identifier.', 'ffl-bridge-for-woocommerce' ) );
		}

		$body = array(
			'licenseNumber'    => $license,
			'acceptsTransfers' => true,
			'orderReference'   => $order_reference,
		);
		if ( '' !== $note ) {
			$body['note'] = $note;
		}

		$path      = '/dealers/' . rawurlencode( strtolower( $dealer_id ) ) . '/transfer-confirmations';
		$multipart = null;
		if ( null !== $file && is_readable( $file['path'] ?? '' ) ) {
			$contents = file_get_contents( $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local private file, not a remote request.
			if ( false !== $contents ) {
				$multipart = self::multipart_body( $body, $contents, $file['filename'] ?? 'license', $file['contentType'] ?? 'application/octet-stream' );
			}
		}

		$response = self::send( 'POST', $path, array(), $body, $multipart );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			return new WP_Error( 'ffl_bridge_endpoint_missing', __( 'FFL Bridge does not accept transfer confirmations yet.', 'ffl-bridge-for-woocommerce' ) );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true, 16 );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || true !== ( $data['success'] ?? false ) ) {
			self::log( 'warning', 'Transfer confirmation was not accepted.', array( 'status' => $status ) );
			return new WP_Error( 'ffl_bridge_confirmation_failed', __( 'FFL Bridge did not accept the transfer confirmation.', 'ffl-bridge-for-woocommerce' ) );
		}

		return is_array( $data['data'] ?? null ) ? $data['data'] : array();
	}

	/**
	 * Ask FFL Bridge to email the selected dealer about a new order.
	 *
	 * Calls POST /api/v1/orders with sendFflEmail true and sendCustomerEmail
	 * false, because WooCommerce already emails the shopper. Only the allowlisted
	 * fields below are sent, whatever the caller passes. The request is sent
	 * once, without retries, with a 20 second timeout because FFL Bridge sends
	 * the dealer email before it answers. The plugin itself never emails dealers.
	 *
	 * A timeout or dropped connection after the request was sent, or a 504,
	 * leaves the outcome unknown: the email may have gone out. That is
	 * reported as requested with reason no_response, never failed or sent.
	 *
	 * A 2xx with success true only means FFL Bridge created the order. Whether
	 * the dealer email went out is read from the top-level dealerNotice field,
	 * or, from APIs that do not send it yet, from emails.fflEmail. The notice is
	 * reported as sent only when dealerNotice.status is sent.
	 *
	 * @param array<string, mixed> $fields fflId, customerName, customerEmail, externalOrderId.
	 * @return array{order_number: string, notice_status: string, reason: string, log_id: string}|WP_Error
	 */
	public static function create_order( array $fields ): array|WP_Error {
		$dealer_id = self::plain_text( $fields['fflId'] ?? '' );
		$name      = self::bounded_text( $fields['customerName'] ?? '', 200 );
		$email     = self::plain_text( $fields['customerEmail'] ?? '' );
		$reference = self::bounded_text( $fields['externalOrderId'] ?? '', 100 );

		if ( 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $dealer_id ) ) {
			return new WP_Error( 'ffl_bridge_invalid_dealer', __( 'The order has no FFL Bridge dealer identifier.', 'ffl-bridge-for-woocommerce' ) );
		}

		if ( '' === $name || '' === $reference || ! is_email( $email ) ) {
			return new WP_Error( 'ffl_bridge_invalid_order', __( 'The order is missing the details FFL Bridge needs to notify the dealer.', 'ffl-bridge-for-woocommerce' ) );
		}

		$body = array(
			'fflId'             => strtolower( $dealer_id ),
			'customerName'      => $name,
			'customerEmail'     => $email,
			'externalOrderId'   => $reference,
			'sendFflEmail'      => true,
			'sendCustomerEmail' => false,
		);

		$response = self::send( 'POST', '/orders', array(), $body, null, self::ORDER_TIMEOUT );
		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			if ( is_array( $error_data ) && 'unknown' === ( $error_data['outcome'] ?? '' ) ) {
				return self::unknown_notice_result();
			}
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 504 === $status ) {
			return self::unknown_notice_result();
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true, 16 );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || true !== ( $data['success'] ?? false ) ) {
			$api_code = 400 <= $status ? self::error_code( $response ) : '';
			self::log(
				'warning',
				'Dealer notice order was not accepted.',
				array(
					'status'   => $status,
					'api_code' => 1 === preg_match( '/\A[A-Z0-9_]{1,64}\z/', $api_code ) ? $api_code : '',
				)
			);
			return new WP_Error( 'ffl_bridge_order_failed', __( 'FFL Bridge did not accept the dealer notice.', 'ffl-bridge-for-woocommerce' ) );
		}

		$result = self::dealer_notice_result( $data );
		if ( in_array( $result['notice_status'], array( 'failed', 'queued' ), true ) ) {
			self::log(
				'warning',
				'Dealer notice was not confirmed as sent.',
				array(
					'notice_status' => $result['notice_status'],
					'reason'        => $result['reason'],
				)
			);
		}

		return $result;
	}

	/**
	 * The result for a POST /orders that may have reached FFL Bridge but got
	 * no answer, so the dealer email may or may not have been sent.
	 *
	 * @return array{order_number: string, notice_status: string, reason: string, log_id: string}
	 */
	private static function unknown_notice_result(): array {
		self::log( 'warning', 'Dealer notice outcome is unknown: FFL Bridge did not answer in time.', array( 'notice_status' => 'requested' ) );

		return array(
			'order_number'  => '',
			'notice_status' => 'requested',
			'reason'        => 'no_response',
			'log_id'        => '',
		);
	}

	/**
	 * Decide whether a failed POST may still have reached FFL Bridge.
	 *
	 * Only errors that happen before the request is sent (bad URL, DNS,
	 * refused or timed-out connection, TLS) mean it never arrived. A read
	 * timeout or a dropped connection afterward leaves the outcome unknown.
	 *
	 * @param WP_Error $error Transport error from the HTTP API.
	 * @return bool
	 */
	private static function request_may_have_arrived( WP_Error $error ): bool {
		if ( 'http_request_failed' !== $error->get_error_code() ) {
			return false;
		}

		$message = $error->get_error_message();
		if ( 1 === preg_match( '/cURL error (?:3|5|6|7|35|51|53|54|58|59|60|64|66|77|80|82|83|90|91)\b/', $message ) ) {
			return false;
		}

		foreach ( array( 'Connection timed out', 'Resolving timed out', 'Could not resolve', 'Failed to connect', 'Connection refused', 'A valid URL was not provided', 'User has blocked requests' ) as $before_send ) {
			if ( false !== stripos( $message, $before_send ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read the dealer email outcome from an accepted POST /orders body.
	 *
	 * Only validated codes and identifiers are kept; shopper data and free
	 * API text are never returned.
	 *
	 * @param array<string, mixed> $data Decoded response body.
	 * @return array{order_number: string, notice_status: string, reason: string, log_id: string}
	 */
	private static function dealer_notice_result( array $data ): array {
		$order        = is_array( $data['order'] ?? null ) ? $data['order'] : array();
		$order_number = is_string( $order['orderNumber'] ?? null ) && 1 === preg_match( '/\AORD-[A-Z0-9_-]{1,32}\z/', $order['orderNumber'] ) ? $order['orderNumber'] : '';

		$notice = is_array( $data['dealerNotice'] ?? null ) ? $data['dealerNotice'] : null;
		if ( null !== $notice && in_array( $notice['status'] ?? null, array( 'sent', 'queued', 'failed', 'skipped' ), true ) ) {
			$status = $notice['status'];
			$reason = self::notice_code( $notice['reason'] ?? null );
			$log_id = is_string( $notice['logId'] ?? null ) && 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $notice['logId'] ) ? strtolower( $notice['logId'] ) : '';
		} else {
			$emails = is_array( $data['emails'] ?? null ) ? $data['emails'] : array();
			$email  = is_array( $emails['fflEmail'] ?? null ) ? $emails['fflEmail'] : array();
			$reason = self::notice_code( $email['reason'] ?? null );
			$log_id = '';
			if ( true === ( $email['skipped'] ?? false ) ) {
				$status = 'skipped';
			} elseif ( false === ( $email['success'] ?? null ) ) {
				$status = 'failed';
			} else {
				// Older APIs and today's success shape cannot confirm delivery.
				$status = 'requested';
				$reason = '';
			}
		}

		// A skip because the dealer was already emailed is not a confirmed send.
		if ( 'skipped' === $status && 'already_sent' === $reason ) {
			$status = 'requested';
		}

		return array(
			'order_number'  => $order_number,
			'notice_status' => $status,
			'reason'        => $reason,
			'log_id'        => $log_id,
		);
	}

	/**
	 * Keep a dealer notice reason only when it is a plain code.
	 *
	 * @param mixed $value Raw reason.
	 * @return string
	 */
	private static function notice_code( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9_]{1,64}\z/', $value ) ? $value : '';
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
			'transfer_status'   => in_array( $raw['transferStatus'] ?? null, array( 'confirmed', 'merchant_confirmed', 'declined', 'unconfirmed' ), true ) ? $raw['transferStatus'] : null,
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
		$response = self::send( 'GET', $path, $query );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		// Credential and account problems get their own codes so the admin
		// connection test can explain them. The message stays short and
		// non-technical because shoppers can see it at checkout.
		if ( 401 === $status || 403 === $status ) {
			$api_code = self::error_code( $response );
			$code     = 401 === $status ? 'ffl_bridge_api_unauthorized' : ( self::FORBIDDEN_CODES[ $api_code ] ?? 'ffl_bridge_api_forbidden' );
			self::log(
				'error',
				'API request rejected.',
				array(
					'status'   => $status,
					'api_code' => 1 === preg_match( '/\A[A-Z0-9_]{1,64}\z/', $api_code ) ? $api_code : '',
					'reason'   => $code,
				)
			);
			return new WP_Error( $code, __( 'Dealer search is not available for this store right now. Contact the store.', 'ffl-bridge-for-woocommerce' ) );
		}

		if ( 429 === $status ) {
			self::log( 'warning', 'API rate limit reached.', array( 'status' => $status ) );
			return new WP_Error( 'ffl_bridge_api_limited', __( 'The store has reached its FFL search limit. Try again later or contact the store.', 'ffl-bridge-for-woocommerce' ) );
		}

		if ( $status >= 500 ) {
			self::log( 'warning', 'API server error.', array( 'status' => $status ) );
			return new WP_Error( 'ffl_bridge_api_unavailable', __( 'FFL Bridge is temporarily unavailable. Try again shortly.', 'ffl-bridge-for-woocommerce' ) );
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
	 * Send an authenticated request to a fixed FFL Bridge path.
	 *
	 * GET requests are retried once on a transport error or a 502, 503, or
	 * 504 response. Other methods are sent once.
	 *
	 * @param string                     $method GET or POST.
	 * @param string                     $path API path beneath /api/v1.
	 * @param array<string, mixed>       $query Optional query string.
	 * @param array<string, mixed>       $body Optional JSON body for POST.
	 * @param array<string, string>|null $raw Optional prebuilt body: content_type and body.
	 * @param int                        $timeout Optional timeout in seconds. Zero uses the default for the method.
	 * @return array<string, mixed>|WP_Error Raw HTTP response.
	 */
	private static function send( string $method, string $path, array $query = array(), array $body = array(), ?array $raw = null, int $timeout = 0 ): array|WP_Error {
		$api_key = self::get_api_key();
		if ( ! self::is_valid_api_key( $api_key ) ) {
			return new WP_Error( 'ffl_bridge_not_configured', __( 'FFL Bridge is not configured. Ask the store administrator for help.', 'ffl-bridge-for-woocommerce' ) );
		}

		$origin = self::get_site_origin();
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}

		$url = self::get_api_base_url() . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'timeout'             => $timeout > 0 ? $timeout : ( 'GET' === $method ? self::TIMEOUT : 20 ),
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

		if ( 'GET' !== $method ) {
			$args['headers']['Content-Type'] = null === $raw ? 'application/json' : $raw['content_type'];
		}

		$args['headers'] = self::merge_extra_headers( $args['headers'], $method, $path );

		if ( 'GET' !== $method ) {
			$args['body'] = null === $raw ? (string) wp_json_encode( $body ) : $raw['body'];
			$response     = wp_safe_remote_post( $url, $args );
			if ( is_wp_error( $response ) ) {
				$outcome = self::request_may_have_arrived( $response ) ? 'unknown' : 'not_sent';
				self::log( 'warning', 'API transport failure.', array( 'outcome' => $outcome ) );
				return new WP_Error( 'ffl_bridge_api_unavailable', __( 'FFL Bridge is temporarily unavailable. Try again shortly.', 'ffl-bridge-for-woocommerce' ), array( 'outcome' => $outcome ) );
			}
			return $response;
		}

		$response = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) || in_array( (int) wp_remote_retrieve_response_code( $response ), array( 502, 503, 504 ), true ) ) {
			$response = wp_safe_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			self::log( 'warning', 'API transport failure.' );
			return new WP_Error( 'ffl_bridge_api_unavailable', __( 'FFL Bridge is temporarily unavailable. Try again shortly.', 'ffl-bridge-for-woocommerce' ) );
		}

		return $response;
	}

	/**
	 * Add optional headers from the ffl_bridge_api_request_headers filter.
	 *
	 * Lets a test site send, for example, x-vercel-protection-bypass to a
	 * protected preview. The filter cannot replace or duplicate a header the
	 * client sets, nor set transport headers. Names must be HTTP tokens and
	 * values plain strings without control characters. Values are never
	 * logged.
	 *
	 * @param array<string, string> $headers Client headers.
	 * @param string                $method HTTP method.
	 * @param string                $path API path.
	 * @return array<string, string>
	 */
	private static function merge_extra_headers( array $headers, string $method, string $path ): array {
		/**
		 * Filter extra headers sent with every FFL Bridge API request.
		 *
		 * @param array<string, string> $extra  Header name => value. Empty by default.
		 * @param string                $method HTTP method.
		 * @param string                $path   API path, such as /search.
		 */
		$extra = apply_filters( 'ffl_bridge_api_request_headers', array(), $method, $path );
		if ( ! is_array( $extra ) ) {
			return $headers;
		}

		$reserved = array( 'host', 'content-length', 'transfer-encoding', 'connection', 'cookie', 'proxy-authorization', 'te', 'upgrade', 'expect' );
		$taken    = array_merge( $reserved, array_map( 'strtolower', array_keys( $headers ) ) );

		foreach ( $extra as $name => $value ) {
			if ( ! is_string( $name ) || ! is_string( $value ) ) {
				continue;
			}

			if ( 1 !== preg_match( '/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,128}\z/', $name ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) || strlen( $value ) > 4096 ) {
				continue;
			}

			$lower = strtolower( $name );
			if ( in_array( $lower, $taken, true ) ) {
				continue;
			}

			$headers[ $name ] = $value;
			$taken[]          = $lower;
		}

		return $headers;
	}

	/**
	 * Build a multipart/form-data body with text fields and one file part.
	 *
	 * @param array<string, mixed> $fields Text fields. Booleans become "true" or "false".
	 * @param string               $contents File contents.
	 * @param string               $filename File name.
	 * @param string               $content_type File MIME type.
	 * @return array{content_type: string, body: string}
	 */
	public static function multipart_body( array $fields, string $contents, string $filename, string $content_type ): array {
		$boundary = 'ffl-bridge-' . bin2hex( random_bytes( 12 ) );
		$filename = (string) preg_replace( '/[^A-Za-z0-9._-]/', '_', $filename );
		$body     = '';
		foreach ( $fields as $name => $value ) {
			$text  = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
			$body .= '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n" . $text . "\r\n";
		}

		$body .= '--' . $boundary . "\r\n"
			. 'Content-Disposition: form-data; name="licenseFile"; filename="' . $filename . '"' . "\r\n"
			. 'Content-Type: ' . $content_type . "\r\n\r\n"
			. $contents . "\r\n"
			. '--' . $boundary . "--\r\n";

		return array(
			'content_type' => 'multipart/form-data; boundary=' . $boundary,
			'body'         => $body,
		);
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
