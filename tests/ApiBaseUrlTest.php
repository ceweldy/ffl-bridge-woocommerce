<?php
/**
 * Configurable FFL Bridge API base URL and optional extra request headers.
 *
 * Every request is mocked through the wp_safe_remote_* doubles in
 * tests/bootstrap.php. No test calls a live host.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ApiBaseUrlTest extends FFL_Bridge_TestCase {
	private const KEY       = 'ffl_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
	private const DEALER_ID = '123e4567-e89b-12d3-a456-426614174000';
	private const DEFAULT   = 'https://www.fflbridge.com/api/v1';
	private const PREVIEW   = 'https://ffl-bridge-example.vercel.app/api/v1';
	private const OPTION    = 'ffl_bridge_api_base_url';
	private const BYPASS    = 'bypass-secret-value-do-not-log-0123456789';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_api_key'] = self::KEY;
		$GLOBALS['ffl_bridge_test_caps']                          = array( 'manage_woocommerce' );
	}

	public function test_default_base_url_is_production(): void {
		$this->assertSame( self::DEFAULT, FFL_Bridge_API_Client::DEFAULT_API_BASE_URL );
		$this->assertSame( self::DEFAULT, FFL_Bridge_API_Client::get_api_base_url() );
		$this->assertFalse( FFL_Bridge_API_Client::api_base_url_from_constant() );
	}

	public function test_default_search_and_order_requests_go_to_production(): void {
		FFL_Bridge_API_Client::search_with_meta( '48047', 25 );
		$this->create_order();

		$urls = array_column( $GLOBALS['ffl_bridge_test_http'], 'url' );
		$this->assertNotEmpty( $urls );
		$this->assertStringStartsWith( self::DEFAULT . '/search?', $urls[0] );
		$this->assertSame( self::DEFAULT . '/orders', end( $urls ) );
	}

	public function test_valid_option_is_used_for_every_request(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = self::PREVIEW;

		FFL_Bridge_API_Client::search_with_meta( '48047', 25 );
		FFL_Bridge_API_Client::get_dealer( self::DEALER_ID );
		$this->create_order();

		$urls = array_column( $GLOBALS['ffl_bridge_test_http'], 'url' );
		$this->assertNotEmpty( $urls );
		foreach ( $urls as $url ) {
			$this->assertStringStartsWith( self::PREVIEW . '/', $url );
			$this->assertStringNotContainsString( 'fflbridge.com', $url );
		}
		$this->assertSame( self::PREVIEW . '/orders', end( $urls ) );
	}

	public function test_trailing_slashes_do_not_double_the_path_separator(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = self::PREVIEW . '///';

		$this->assertSame( self::PREVIEW, FFL_Bridge_API_Client::get_api_base_url() );
		$this->create_order();
		$this->assertSame( self::PREVIEW . '/orders', $GLOBALS['ffl_bridge_test_http'][0]['url'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalid_urls(): array {
		return array(
			'http'              => array( 'http://ffl-bridge-example.vercel.app/api/v1' ),
			'protocol relative' => array( '//ffl-bridge-example.vercel.app/api/v1' ),
			'javascript'        => array( 'javascript:alert(1)' ),
			'empty'             => array( '' ),
			'spaces'            => array( '   ' ),
			'missing host'      => array( 'https:///api/v1' ),
			'scheme only'       => array( 'https://' ),
			'credentials'       => array( 'https://user:pass@ffl-bridge-example.vercel.app/api/v1' ),
			'user only'         => array( 'https://user@ffl-bridge-example.vercel.app/api/v1' ),
			'garbage'           => array( 'not a url at all' ),
			'ftp'               => array( 'ftp://ffl-bridge-example.vercel.app/api/v1' ),
			'query string'      => array( 'https://ffl-bridge-example.vercel.app/api/v1?x=1' ),
			'fragment'          => array( 'https://ffl-bridge-example.vercel.app/api/v1#x' ),
			'newline'           => array( "https://ffl-bridge-example.vercel.app/api/v1\nX: y" ),
			'backslash host'    => array( 'https://evil.example\\@ffl-bridge-example.vercel.app/api/v1' ),
			'null'              => array( null ),
			'array'             => array( array( self::PREVIEW ) ),
			'integer'           => array( 443 ),
		);
	}

	#[DataProvider( 'invalid_urls' )]
	public function test_validator_rejects_unsafe_urls( mixed $input ): void {
		$this->assertSame( '', FFL_Bridge_API_Client::normalize_api_base_url( $input ) );
	}

	#[DataProvider( 'invalid_urls' )]
	public function test_invalid_saved_option_falls_back_to_default( mixed $input ): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = $input;

		$this->assertSame( self::DEFAULT, FFL_Bridge_API_Client::get_api_base_url() );
		$this->create_order();
		$this->assertSame( self::DEFAULT . '/orders', $GLOBALS['ffl_bridge_test_http'][0]['url'] );
	}

	#[DataProvider( 'invalid_urls' )]
	public function test_settings_sanitizer_never_saves_an_unsafe_url( mixed $input ): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = self::PREVIEW;

		$this->assertSame( '', FFL_Bridge_Settings::sanitize_api_base_url( $input ) );
	}

	public function test_settings_sanitizer_reports_rejected_input(): void {
		FFL_Bridge_Settings::sanitize_api_base_url( 'http://ffl-bridge-example.vercel.app/api/v1' );

		$codes = array_column( $GLOBALS['ffl_bridge_test_settings_errors'], 1 );
		$this->assertContains( 'ffl_bridge_invalid_api_base_url', $codes );
	}

	public function test_settings_sanitizer_keeps_a_valid_https_url_without_trailing_slash(): void {
		$this->assertSame( self::PREVIEW, FFL_Bridge_Settings::sanitize_api_base_url( '  ' . self::PREVIEW . '/ ' ) );
		$this->assertSame( 'https://staging.example.test:8443/api/v1', FFL_Bridge_Settings::sanitize_api_base_url( 'https://staging.example.test:8443/api/v1' ) );
	}

	public function test_settings_sanitizer_stores_the_default_as_empty(): void {
		$this->assertSame( '', FFL_Bridge_Settings::sanitize_api_base_url( self::DEFAULT ) );
		$this->assertSame( '', FFL_Bridge_Settings::sanitize_api_base_url( self::DEFAULT . '/' ) );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_settings_errors'] );
	}

	public function test_settings_sanitizer_requires_the_settings_capability(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = self::PREVIEW;
		$GLOBALS['ffl_bridge_test_caps']                    = array();

		$this->assertSame( self::PREVIEW, FFL_Bridge_Settings::sanitize_api_base_url( 'https://other.example.test/api/v1' ) );
	}

	public function test_filter_overrides_a_valid_option(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ]               = 'https://option.example.test/api/v1';
		$GLOBALS['ffl_bridge_test_filters']['ffl_bridge_api_base_url'] = static fn (): string => self::PREVIEW . '/';

		$this->assertSame( self::PREVIEW, FFL_Bridge_API_Client::get_api_base_url() );
		$this->create_order();
		$this->assertSame( self::PREVIEW . '/orders', $GLOBALS['ffl_bridge_test_http'][0]['url'] );
	}

	public function test_filter_receives_the_option_or_default(): void {
		$seen = array();
		$GLOBALS['ffl_bridge_test_filters']['ffl_bridge_api_base_url'] = static function ( mixed $url ) use ( &$seen ): mixed {
			$seen[] = $url;
			return $url;
		};

		FFL_Bridge_API_Client::get_api_base_url();
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = self::PREVIEW;
		FFL_Bridge_API_Client::get_api_base_url();

		$this->assertSame( array( self::DEFAULT, self::PREVIEW ), $seen );
	}

	#[DataProvider( 'invalid_urls' )]
	public function test_invalid_filtered_value_is_ignored( mixed $input ): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ]               = self::PREVIEW;
		$GLOBALS['ffl_bridge_test_filters']['ffl_bridge_api_base_url'] = static fn (): mixed => $input;

		$this->assertSame( self::PREVIEW, FFL_Bridge_API_Client::get_api_base_url() );

		unset( $GLOBALS['ffl_bridge_test_options'][ self::OPTION ] );
		$this->assertSame( self::DEFAULT, FFL_Bridge_API_Client::get_api_base_url() );
	}

	/**
	 * Constants cannot be undefined inside one PHP process, so each constant
	 * case runs in its own child process with a fresh bootstrap.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_valid_constant_wins_over_filter_and_option(): void {
		define( 'FFL_BRIDGE_API_BASE_URL', 'https://constant.example.test/api/v1/' );
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ]               = 'https://option.example.test/api/v1';
		$GLOBALS['ffl_bridge_test_filters']['ffl_bridge_api_base_url'] = static fn (): string => self::PREVIEW;

		$this->assertTrue( FFL_Bridge_API_Client::api_base_url_from_constant() );
		$this->assertSame( 'https://constant.example.test/api/v1', FFL_Bridge_API_Client::get_api_base_url() );
		$this->create_order();
		$this->assertSame( 'https://constant.example.test/api/v1/orders', $GLOBALS['ffl_bridge_test_http'][0]['url'] );

		// The saved option is left alone while the constant is active.
		$this->assertSame( 'https://option.example.test/api/v1', FFL_Bridge_Settings::sanitize_api_base_url( self::PREVIEW ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_invalid_constant_falls_through_to_filter(): void {
		define( 'FFL_BRIDGE_API_BASE_URL', 'http://constant.example.test/api/v1' );
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ]               = 'https://option.example.test/api/v1';
		$GLOBALS['ffl_bridge_test_filters']['ffl_bridge_api_base_url'] = static fn (): string => self::PREVIEW;

		$this->assertFalse( FFL_Bridge_API_Client::api_base_url_from_constant() );
		$this->assertSame( self::PREVIEW, FFL_Bridge_API_Client::get_api_base_url() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_invalid_constant_falls_through_to_option(): void {
		define( 'FFL_BRIDGE_API_BASE_URL', 'https://user:pass@constant.example.test/api/v1' );
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = self::PREVIEW;

		$this->assertSame( self::PREVIEW, FFL_Bridge_API_Client::get_api_base_url() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_invalid_constant_falls_through_to_default(): void {
		define( 'FFL_BRIDGE_API_BASE_URL', array( 'https://constant.example.test/api/v1' ) );

		$this->assertSame( self::DEFAULT, FFL_Bridge_API_Client::get_api_base_url() );
		$this->create_order();
		$this->assertSame( self::DEFAULT . '/orders', $GLOBALS['ffl_bridge_test_http'][0]['url'] );
	}

	public function test_extra_header_filter_adds_the_vercel_bypass_header(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = self::PREVIEW;
		$this->add_bypass_filter();

		FFL_Bridge_API_Client::search_with_meta( '48047', 25 );
		$this->create_order();

		$this->assertNotEmpty( $GLOBALS['ffl_bridge_test_http'] );
		foreach ( $GLOBALS['ffl_bridge_test_http'] as $request ) {
			$this->assertSame( self::BYPASS, $request['args']['headers']['x-vercel-protection-bypass'] ?? null );
		}
	}

	public function test_no_extra_headers_by_default(): void {
		$this->create_order();

		$headers = array_change_key_case( $GLOBALS['ffl_bridge_test_http'][0]['args']['headers'] );
		$this->assertSame(
			array( 'accept', 'authorization', 'origin', 'referer', 'user-agent', 'content-type' ),
			array_keys( $headers )
		);
	}

	public function test_extra_headers_cannot_replace_required_client_headers(): void {
		$GLOBALS['ffl_bridge_test_filters']['ffl_bridge_api_request_headers'] = static fn (): array => array(
			'Authorization'  => 'Bearer stolen',
			'authorization'  => 'Bearer stolen',
			'Content-Type'   => 'text/plain',
			'CONTENT-TYPE'   => 'text/plain',
			'Accept'         => 'text/html',
			'Origin'         => 'https://evil.example',
			'Referer'        => 'https://evil.example/',
			'User-Agent'     => 'evil',
			'Host'           => 'evil.example',
			'Content-Length' => '1',
			'X-Allowed'      => 'ok',
		);

		$this->create_order();
		$headers = $GLOBALS['ffl_bridge_test_http'][0]['args']['headers'];

		$this->assertSame( 'Bearer ' . self::KEY, $headers['Authorization'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( 'application/json', $headers['Accept'] );
		$this->assertSame( 'https://store.example.test', $headers['Origin'] );
		$this->assertSame( 'ok', $headers['X-Allowed'] );
		$lower = array_change_key_case( $headers );
		$this->assertSame( count( $headers ), count( $lower ), 'No header may appear twice with different case.' );
		$this->assertArrayNotHasKey( 'host', $lower );
		$this->assertArrayNotHasKey( 'content-length', $lower );
		$this->assertStringNotContainsString( 'stolen', (string) wp_json_encode( $headers ) );
	}

	public function test_extra_headers_with_bad_names_or_values_are_ignored(): void {
		$GLOBALS['ffl_bridge_test_filters']['ffl_bridge_api_request_headers'] = static fn (): array => array(
			0              => 'numeric name',
			'X-Int'        => 5,
			'X-Array'      => array( 'a' ),
			'X-Null'       => null,
			'X-Bool'       => true,
			'X-Object'     => new stdClass(),
			'X-Newline'    => "value\r\nX-Injected: yes",
			'Bad Name'     => 'space in name',
			"X-Inject\r\n" => 'crlf in name',
			''             => 'empty name',
			'X-Good'       => 'good',
		);

		$this->create_order();
		$headers = $GLOBALS['ffl_bridge_test_http'][0]['args']['headers'];

		$this->assertSame( 'good', $headers['X-Good'] );
		foreach ( array( 0, 'X-Int', 'X-Array', 'X-Null', 'X-Bool', 'X-Object', 'X-Newline', 'Bad Name', "X-Inject\r\n", '' ) as $name ) {
			$this->assertArrayNotHasKey( $name, $headers );
		}
		$this->assertStringNotContainsString( 'Injected', (string) wp_json_encode( $headers ) );
	}

	public function test_non_array_header_filter_result_is_ignored(): void {
		$GLOBALS['ffl_bridge_test_filters']['ffl_bridge_api_request_headers'] = static fn (): string => 'x-vercel-protection-bypass: ' . self::BYPASS;

		$this->create_order();

		$this->assertStringNotContainsString( self::BYPASS, (string) wp_json_encode( $GLOBALS['ffl_bridge_test_http'][0]['args']['headers'] ) );
	}

	public function test_bypass_value_never_reaches_logs_or_errors_on_transport_failure(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = self::PREVIEW;
		$this->add_bypass_filter();

		// No queued responses: every request fails at the transport layer.
		$search = FFL_Bridge_API_Client::search_with_meta( '48047', 25 );
		$order  = $this->create_order();
		$dealer = FFL_Bridge_API_Client::get_dealer( self::DEALER_ID );

		$this->assertNotEmpty( $GLOBALS['ffl_bridge_test_logs'], 'A transport failure should be logged.' );
		$this->assert_no_secret_leak( array( $search, $order, $dealer ) );
	}

	public function test_bypass_value_never_reaches_logs_or_errors_on_http_errors(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = self::PREVIEW;
		$this->add_bypass_filter();
		$GLOBALS['ffl_bridge_test_http_responses'] = array(
			self::response( 401, '{"error":{"code":"INVALID_API_KEY"}}' ),
			self::response( 500, '{"error":{"code":"INTERNAL"}}' ),
			self::response( 418, 'teapot' ),
		);

		$search = FFL_Bridge_API_Client::search_with_meta( '48047', 25 );
		$order  = $this->create_order();
		$dealer = FFL_Bridge_API_Client::get_dealer( self::DEALER_ID );

		$this->assertNotEmpty( $GLOBALS['ffl_bridge_test_logs'] );
		$this->assert_no_secret_leak( array( $search, $order, $dealer ) );
	}

	/**
	 * @param array<int, mixed> $results Client results.
	 */
	private function assert_no_secret_leak( array $results ): void {
		$logs = (string) wp_json_encode( $GLOBALS['ffl_bridge_test_logs'] );
		$this->assertStringNotContainsString( self::BYPASS, $logs );
		$this->assertStringNotContainsStringIgnoringCase( 'x-vercel-protection-bypass', $logs );
		$this->assertStringNotContainsString( self::KEY, $logs );

		foreach ( $results as $result ) {
			if ( $result instanceof WP_Error ) {
				$this->assertStringNotContainsString( self::BYPASS, $result->get_error_message() );
				$this->assertStringNotContainsString( self::BYPASS, $result->get_error_code() );
			}
		}
	}

	private function add_bypass_filter(): void {
		$GLOBALS['ffl_bridge_test_filters']['ffl_bridge_api_request_headers'] = static fn ( array $headers ): array => $headers + array( 'x-vercel-protection-bypass' => self::BYPASS );
	}

	private function create_order(): array|WP_Error {
		return FFL_Bridge_API_Client::create_order(
			array(
				'fflId'           => self::DEALER_ID,
				'customerName'    => 'Pat Shopper',
				'customerEmail'   => 'pat@example.test',
				'externalOrderId' => '501',
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function response( int $code, string $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
			'headers'  => array(),
		);
	}
}
