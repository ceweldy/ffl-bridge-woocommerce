<?php
/**
 * API key paste handling with the unit test doubles. The integration suite
 * repeats these checks with real WordPress sanitizing.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

final class ApiKeyHandlingTest extends FFL_Bridge_TestCase {
	private const SAVED = 'ffl_live_SavedSavedSavedSavedSavedSaved00';
	private const KEY   = 'ffl_live_AbCdEfGhIjKlMnOpQrStUvWxYz012345';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_api_key'] = self::SAVED;
		$GLOBALS['ffl_bridge_test_caps']                          = array( 'manage_woocommerce' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function pasted_keys(): array {
		return array(
			'spaces'          => array( '  ' . self::KEY . '  ' ),
			'newline'         => array( self::KEY . "\n" ),
			'Windows newline' => array( self::KEY . "\r\n" ),
			'tab'             => array( "\t" . self::KEY ),
		);
	}

	#[DataProvider( 'pasted_keys' )]
	public function test_a_pasted_key_with_surrounding_whitespace_is_saved_trimmed( string $pasted ): void {
		$this->assertSame( self::KEY, FFL_Bridge_Settings::sanitize_api_key( $pasted ) );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_settings_errors'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function rejected_keys(): array {
		$body = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345';

		return array(
			'newline inside'    => array( 'ffl_live_AbCdEfGhIjKlMnOp' . "\n" . 'QrStUvWxYz012345' ),
			'ff1_pub lookalike' => array( 'ff1_pub_' . $body ),
			'ffl_pub key'       => array( 'ffl_pub_' . $body ),
			'ff1_live'          => array( 'ff1_live_' . $body ),
			'zero-width space'  => array( 'ffl_live_' . "\u{200B}" . $body ),
		);
	}

	#[DataProvider( 'rejected_keys' )]
	public function test_a_malformed_or_lookalike_key_is_rejected( string $pasted ): void {
		$this->assertSame( self::SAVED, FFL_Bridge_Settings::sanitize_api_key( $pasted ) );
		$this->assertSame( 'ffl_bridge_invalid_api_key', $GLOBALS['ffl_bridge_test_settings_errors'][0][1] ?? null );
	}

	public function test_a_stored_key_with_a_trailing_newline_is_used_trimmed(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_api_key'] = self::KEY . "\n";

		$this->assertSame( self::KEY, FFL_Bridge_API_Client::get_api_key() );
		$this->assertTrue( FFL_Bridge_API_Client::is_configured() );
		$this->assertSame( '2345', FFL_Bridge_API_Client::get_key_suffix() );
	}
}
