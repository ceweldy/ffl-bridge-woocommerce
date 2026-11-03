<?php
/**
 * The admin connection test names the reason FFL Bridge rejected the key,
 * using real WordPress HTTP responses and AJAX handling.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

final class ConnectionErrorsTest extends FFL_Bridge_Integration_TestCase {
	private const KEY = 'ffl_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( 'ffl_bridge_api_key', self::KEY );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: string}>
	 */
	public static function rejections(): array {
		return array(
			'old API, unverified account or bad key' => array( 401, 'INVALID_API_KEY', 'FFL Bridge rejected this API key. Check that you pasted the full server key (starts with ffl_live_) and that it has not been revoked. If you just signed up, verify your account email first.' ),
			'email not verified'                     => array( 403, 'EMAIL_NOT_VERIFIED', 'Verify the email address on your FFL Bridge account, then test again.' ),
			'domain not allowed'                     => array( 403, 'DOMAIN_NOT_ALLOWED', 'This server key has an allowed-domain list that does not include this site. Server keys do not need a domain. Remove the list or add this site\'s address, then test again.' ),
		);
	}

	#[DataProvider( 'rejections' )]
	public function test_connection_test_names_the_reason( int $status, string $code, string $expected ): void {
		add_filter(
			'pre_http_request',
			static fn () => array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => (string) wp_json_encode(
					array(
						'success' => false,
						'error'   => array(
							'code'    => $code,
							'message' => 'Server-side detail.',
						),
					)
				),
				'response' => array(
					'code'    => $status,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			)
		);

		$this->act_as( 'shop_manager' );
		$_POST = array(
			'nonce' => wp_create_nonce( 'ffl_bridge_admin' ),
			'zip'   => '48047',
		);
		$_REQUEST = $_POST;
		$stop     = $this->stop( array( FFL_Bridge_Settings::class, 'ajax_test_connection' ), true );
		$json     = json_decode( $stop->output, true );

		$this->assertFalse( $json['success'] );
		$this->assertSame( $expected, $json['data']['message'] );
	}
}
