<?php
/**
 * How the FFL Bridge API key is accepted, stored, and used, with real
 * WordPress sanitizing.
 *
 * The plugin has a single server-side key (ffl_live_...). It has no
 * publishable or widget key and never sends the key to the browser.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

final class ApiKeyHandlingTest extends FFL_Bridge_Integration_TestCase {
	private const SAVED = 'ffl_live_SavedSavedSavedSavedSavedSaved00';
	private const KEY   = 'ffl_live_AbCdEfGhIjKlMnOpQrStUvWxYz012345';

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( 'ffl_bridge_api_key', self::SAVED );
		$this->act_as( 'shop_manager' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function pasted_keys(): array {
		return array(
			'leading and trailing spaces' => array( '  ' . self::KEY . '  ' ),
			'trailing newline'            => array( self::KEY . "\n" ),
			'Windows line ending'         => array( self::KEY . "\r\n" ),
			'tab and newlines'            => array( "\t" . self::KEY . "\n\n" ),
		);
	}

	#[DataProvider( 'pasted_keys' )]
	public function test_a_pasted_key_with_surrounding_whitespace_is_saved_trimmed( string $pasted ): void {
		$this->assertSame( self::KEY, sanitize_option( 'ffl_bridge_api_key', $pasted ) );
		$this->assertSame( array(), get_settings_errors( 'ffl_bridge_api_key' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function rejected_keys(): array {
		$body = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345';

		return array(
			'newline inside the key'        => array( 'ffl_live_AbCdEfGhIjKlMnOp' . "\n" . 'QrStUvWxYz012345' ),
			'space inside the key'          => array( 'ffl_live_AbCdEfGhIjKlMnOp QrStUvWxYz012345' ),
			'ff1_pub lookalike'             => array( 'ff1_pub_' . $body ),
			'ffl_pub publishable key'       => array( 'ffl_pub_' . $body ),
			'ff1_live lookalike'            => array( 'ff1_live_' . $body ),
			'uppercase prefix'              => array( 'FFL_LIVE_' . $body ),
			'zero-width space in the key'   => array( 'ffl_live_' . "\u{200B}" . $body ),
			'Cyrillic letter in the prefix' => array( "ff\u{04CF}_live_" . $body ),
			'one character short'           => array( 'ffl_live_' . substr( $body, 1 ) ),
			'one character long'            => array( 'ffl_live_' . $body . 'A' ),
			'quoted key'                    => array( '"ffl_live_' . $body . '"' ),
			'Bearer prefix pasted'          => array( 'Bearer ffl_live_' . $body ),
		);
	}

	#[DataProvider( 'rejected_keys' )]
	public function test_a_malformed_or_lookalike_key_is_rejected_and_the_saved_key_kept( string $pasted ): void {
		$this->assertSame( self::SAVED, sanitize_option( 'ffl_bridge_api_key', $pasted ) );

		$codes = wp_list_pluck( get_settings_errors( 'ffl_bridge_api_key' ), 'code' );
		$this->assertContains( 'ffl_bridge_invalid_api_key', $codes );
	}

	public function test_a_blank_submission_keeps_the_saved_key(): void {
		$this->assertSame( self::SAVED, sanitize_option( 'ffl_bridge_api_key', '' ) );
		$this->assertSame( self::SAVED, sanitize_option( 'ffl_bridge_api_key', " \n\t " ) );
	}

	public function test_a_stored_key_with_a_trailing_newline_is_sent_trimmed(): void {
		$this->set_option( 'ffl_bridge_api_key', self::KEY . "\n" );
		$this->assertTrue( FFL_Bridge_API_Client::is_configured() );

		$sent = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( &$sent ) {
				$sent[] = array(
					'url'     => $url,
					'headers' => $args['headers'],
				);
				return new WP_Error( 'blocked', 'No network in tests.' );
			},
			10,
			3
		);

		FFL_Bridge_API_Client::search( '48047', 25 );

		$this->assertNotEmpty( $sent );
		foreach ( $sent as $request ) {
			$this->assertSame( 'Bearer ' . self::KEY, $request['headers']['Authorization'] );
			$this->assertStringNotContainsString( 'ffl_live_', $request['url'], 'The key leaked into the request URL.' );
		}
	}

	public function test_the_key_is_never_sent_to_the_browser(): void {
		$this->set_option( 'ffl_bridge_api_key', self::KEY );

		$checkout = (string) wp_json_encode( FFL_Bridge_Checkout::get_frontend_config() );
		$this->assertStringNotContainsString( self::KEY, $checkout );
		$this->assertStringNotContainsString( 'ffl_live_', $checkout );

		$page = $this->render( array( FFL_Bridge_Settings::class, 'settings_page' ) );
		$this->assertStringNotContainsString( self::KEY, $page, 'The settings page printed the full key.' );
		$this->assertStringContainsString( substr( self::KEY, -4 ), $page, 'The settings page lost the key suffix.' );
	}
}
