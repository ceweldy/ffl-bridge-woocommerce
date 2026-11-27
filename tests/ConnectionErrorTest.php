<?php
/**
 * Specific API errors: each rejection reason gets its own error code, the
 * admin connection test explains what to fix, and shoppers only see a short,
 * non-technical message.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

final class ConnectionErrorTest extends FFL_Bridge_TestCase {
	private const KEY = 'ffl_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

	private const SHOPPER_MESSAGE = 'Dealer search is not available for this store right now. Contact the store.';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_api_key'] = self::KEY;
	}

	/**
	 * HTTP responses and the WP_Error code each one must produce.
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>|WP_Error>, 1: string}>
	 */
	public static function api_failures(): array {
		return array(
			'401 invalid key'          => array( array( self::error_response( 401, 'INVALID_API_KEY' ) ), 'ffl_bridge_api_unauthorized' ),
			'401 missing key'          => array( array( self::error_response( 401, 'MISSING_API_KEY' ) ), 'ffl_bridge_api_unauthorized' ),
			'401 without a body'       => array( array( self::raw_response( 401, '' ) ), 'ffl_bridge_api_unauthorized' ),
			'403 email not verified'   => array( array( self::error_response( 403, 'EMAIL_NOT_VERIFIED' ) ), 'ffl_bridge_email_not_verified' ),
			'403 domain not allowed'   => array( array( self::error_response( 403, 'DOMAIN_NOT_ALLOWED' ) ), 'ffl_bridge_domain_not_allowed' ),
			'403 key scope'            => array( array( self::error_response( 403, 'KEY_SCOPE_NOT_ALLOWED' ) ), 'ffl_bridge_key_scope_not_allowed' ),
			'403 unknown code'         => array( array( self::error_response( 403, 'SOMETHING_NEW' ) ), 'ffl_bridge_api_forbidden' ),
			'403 without a body'       => array( array( self::raw_response( 403, 'Forbidden' ) ), 'ffl_bridge_api_forbidden' ),
			'429 rate limit'           => array( array( self::error_response( 429, 'RATE_LIMITED' ) ), 'ffl_bridge_api_limited' ),
			'500 server error'         => array( array( self::error_response( 500, 'INTERNAL' ) ), 'ffl_bridge_api_unavailable' ),
			'503 twice'                => array( array( self::raw_response( 503, '' ), self::raw_response( 503, '' ) ), 'ffl_bridge_api_unavailable' ),
			'network failure twice'    => array( array(), 'ffl_bridge_api_unavailable' ),
		);
	}

	/**
	 * @param array<int, array<string, mixed>|WP_Error> $responses Queued responses.
	 */
	#[DataProvider( 'api_failures' )]
	public function test_each_api_failure_has_its_own_error_code( array $responses, string $expected ): void {
		$GLOBALS['ffl_bridge_test_http_responses'] = $responses;

		$result = FFL_Bridge_API_Client::search_with_meta( '48047', 25 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $expected, $result->get_error_code() );
	}

	/**
	 * @param array<int, array<string, mixed>|WP_Error> $responses Queued responses.
	 */
	#[DataProvider( 'api_failures' )]
	public function test_api_failures_never_put_account_details_in_the_error_message( array $responses ): void {
		$GLOBALS['ffl_bridge_test_http_responses'] = $responses;

		$message = FFL_Bridge_API_Client::search_with_meta( '48047', 25 )->get_error_message();

		foreach ( array( 'ffl_live_', 'API key', 'email', 'domain', 'verify' ) as $detail ) {
			$this->assertStringNotContainsStringIgnoringCase( $detail, $message );
		}
	}

	public function test_credential_and_account_failures_show_shoppers_one_short_message(): void {
		foreach ( array( 'INVALID_API_KEY' => 401, 'EMAIL_NOT_VERIFIED' => 403, 'DOMAIN_NOT_ALLOWED' => 403, 'KEY_SCOPE_NOT_ALLOWED' => 403, 'OTHER' => 403 ) as $code => $status ) {
			$GLOBALS['ffl_bridge_test_http_responses'] = array( self::error_response( $status, $code ) );

			$this->assertSame( self::SHOPPER_MESSAGE, FFL_Bridge_API_Client::search_with_meta( '48047', 25 )->get_error_message(), $code );
		}
	}

	public function test_the_specific_reason_is_logged_without_the_key(): void {
		$GLOBALS['ffl_bridge_test_http_responses'] = array( self::error_response( 403, 'EMAIL_NOT_VERIFIED' ) );

		FFL_Bridge_API_Client::search_with_meta( '48047', 25 );

		$this->assertNotEmpty( $GLOBALS['ffl_bridge_test_logs'] );
		$entry = $GLOBALS['ffl_bridge_test_logs'][0];
		$this->assertSame( 'error', $entry['level'] );
		$this->assertSame( 403, $entry['context']['status'] );
		$this->assertSame( 'EMAIL_NOT_VERIFIED', $entry['context']['api_code'] );
		$this->assertStringNotContainsString( self::KEY, (string) json_encode( $GLOBALS['ffl_bridge_test_logs'] ) );
	}

	/**
	 * API responses and the exact text the admin connection test must show.
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>|WP_Error>, 1: string}>
	 */
	public static function admin_messages(): array {
		return array(
			'invalid key'        => array(
				array( self::error_response( 401, 'INVALID_API_KEY' ) ),
				'FFL Bridge rejected this API key. Check that you pasted the full server key (starts with ffl_live_) and that it has not been revoked. If you just signed up, verify your account email first.',
			),
			'email not verified' => array(
				array( self::error_response( 403, 'EMAIL_NOT_VERIFIED' ) ),
				'Verify the email address on your FFL Bridge account, then test again.',
			),
			'domain not allowed' => array(
				array( self::error_response( 403, 'DOMAIN_NOT_ALLOWED' ) ),
				'This server key has an allowed-domain list that does not include this site. Server keys do not need a domain. Remove the list or add this site\'s address, then test again.',
			),
			'key scope'          => array(
				array( self::error_response( 403, 'KEY_SCOPE_NOT_ALLOWED' ) ),
				'This API key is not allowed to search dealers. Use a server key (starts with ffl_live_), not a browser key, then test again.',
			),
			'unknown 403'        => array(
				array( self::error_response( 403, 'SOMETHING_NEW' ) ),
				'FFL Bridge refused this request. Check the API key and your FFL Bridge account, then test again.',
			),
			'rate limit'         => array(
				array( self::error_response( 429, 'RATE_LIMITED' ) ),
				'FFL Bridge rate limit reached for this API key. Wait a few minutes, then test again.',
			),
			'network'            => array(
				array(),
				'Your server could not reach www.fflbridge.com, or FFL Bridge is temporarily unavailable. Check outbound HTTPS from this server, then test again.',
			),
		);
	}

	/**
	 * @param array<int, array<string, mixed>|WP_Error> $responses Queued responses.
	 */
	#[DataProvider( 'admin_messages' )]
	public function test_connection_test_explains_what_to_fix( array $responses, string $expected ): void {
		$GLOBALS['ffl_bridge_test_caps']           = array( 'manage_woocommerce' );
		$GLOBALS['ffl_bridge_test_http_responses'] = $responses;
		$_POST                                     = array(
			'nonce' => 'nonce-ffl_bridge_admin',
			'zip'   => '48047',
		);

		$response = $this->json( static fn () => FFL_Bridge_Settings::ajax_test_connection() );

		$this->assertFalse( $response->success );
		$this->assertSame( 400, $response->status );
		$this->assertSame( $expected, $response->data['message'] );
		$this->assertMatchesRegularExpression( '/\A[\x20-\x7E]+\z/', $response->data['message'], 'Admin text must be plain ASCII.' );
	}

	public function test_connection_test_without_a_key_asks_for_a_server_key(): void {
		$GLOBALS['ffl_bridge_test_caps']                          = array( 'manage_woocommerce' );
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_api_key'] = '';
		$_POST = array(
			'nonce' => 'nonce-ffl_bridge_admin',
			'zip'   => '48047',
		);

		$response = $this->json( static fn () => FFL_Bridge_Settings::ajax_test_connection() );

		$this->assertSame( 'Enter and save your FFL Bridge server API key (starts with ffl_live_), then test again.', $response->data['message'] );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
	}

	public function test_shopper_dealer_search_shows_the_short_message(): void {
		$GLOBALS['ffl_bridge_test_http_responses'] = array( self::error_response( 403, 'EMAIL_NOT_VERIFIED' ) );
		$_POST = array(
			'nonce'        => 'nonce-ffl_bridge_checkout',
			'requestToken' => FFL_Bridge_Selection::get_request_token(),
			'zip'          => '48047',
			'radius'       => '25',
		);

		$response = $this->json( static fn () => FFL_Bridge_Checkout::ajax_search() );

		$this->assertFalse( $response->success );
		$this->assertSame( self::SHOPPER_MESSAGE, $response->data['message'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function error_response( int $status, string $code ): array {
		return self::raw_response(
			$status,
			(string) json_encode(
				array(
					'success' => false,
					'error'   => array(
						'code'    => $code,
						'message' => 'Server-side detail.',
					),
				)
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function raw_response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => $body,
		);
	}

	private function json( callable $handler ): FFL_Bridge_Test_Json {
		try {
			$handler();
		} catch ( FFL_Bridge_Test_Json $response ) {
			return $response;
		}

		$this->fail( 'The handler did not send a JSON response.' );
	}
}
