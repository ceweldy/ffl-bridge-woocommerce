<?php
/**
 * Self-hosted update checker tests.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class UpdaterTest extends FFL_Bridge_TestCase {
	private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	public function test_newer_version_is_offered_as_an_update(): void {
		$this->serve( $this->manifest( '1.3.0' ) );

		$transient = FFL_Bridge_Updater::inject_update( (object) array( 'response' => array(), 'no_update' => array() ) );
		$item      = $transient->response['ffl-bridge-for-woocommerce/ffl-bridge.php'];

		$this->assertSame( '1.3.0', $item->new_version );
		$this->assertSame( 'https://fflbridge.com/downloads/ffl-bridge-for-woocommerce-1.3.0.zip', $item->package );
		$this->assertSame( 'ffl-bridge-for-woocommerce', $item->slug );
		$this->assertSame( '8.3', $item->requires_php );
		$this->assertArrayNotHasKey( 'ffl-bridge-for-woocommerce/ffl-bridge.php', $transient->no_update );
	}

	public function test_same_or_older_version_is_listed_as_no_update(): void {
		foreach ( array( FFL_BRIDGE_VERSION, '0.9.0' ) as $version ) {
			$GLOBALS['ffl_bridge_test_site_transients'] = array();
			$this->serve( $this->manifest( $version ) );

			$transient = FFL_Bridge_Updater::inject_update( (object) array( 'response' => array() ) );

			$this->assertSame( array(), $transient->response, "Version {$version} should not be offered." );
			$this->assertSame( $version, $transient->no_update['ffl-bridge-for-woocommerce/ffl-bridge.php']->new_version );
		}
	}

	public function test_unreachable_manifest_fails_quietly_and_is_cached_briefly(): void {
		$original = (object) array( 'response' => array() );

		$result = FFL_Bridge_Updater::inject_update( $original );

		$this->assertSame( $original, $result );
		$this->assertSame( array(), $result->response );
		$this->assertSame( FFL_Bridge_Updater::FAILURE_TTL, $GLOBALS['ffl_bridge_test_site_transients'][ FFL_Bridge_Updater::CACHE_KEY ]['ttl'] );

		FFL_Bridge_Updater::inject_update( $original );
		$this->assertCount( 1, $GLOBALS['ffl_bridge_test_http'], 'A cached failure must not trigger another request.' );
	}

	public function test_successful_manifest_is_cached_for_12_hours(): void {
		$this->serve( $this->manifest( '1.3.0' ) );

		FFL_Bridge_Updater::get_manifest();
		FFL_Bridge_Updater::get_manifest();

		$this->assertCount( 1, $GLOBALS['ffl_bridge_test_http'] );
		$this->assertSame( 43200, $GLOBALS['ffl_bridge_test_site_transients'][ FFL_Bridge_Updater::CACHE_KEY ]['ttl'] );
		$this->assertSame( 'https://fflbridge.com/api/plugins/woocommerce/update', $GLOBALS['ffl_bridge_test_http'][0]['url'] );
		$this->assertSame( 0, $GLOBALS['ffl_bridge_test_http'][0]['args']['redirection'] );
	}

	public function test_malformed_manifests_are_rejected(): void {
		$this->assertNull( FFL_Bridge_Updater::parse_manifest( 'not json' ) );
		$this->assertNull( FFL_Bridge_Updater::parse_manifest( array_replace( $this->manifest( '1.3.0' ), array( 'version' => '1.3' ) ) ) );
		$this->assertNull( FFL_Bridge_Updater::parse_manifest( array_replace( $this->manifest( '1.3.0' ), array( 'sha256' => 'abc' ) ) ) );
		$this->assertNull( FFL_Bridge_Updater::parse_manifest( array_replace( $this->manifest( '1.3.0' ), array( 'download_url' => 'http://fflbridge.com/x.zip' ) ) ) );
		$this->assertNull( FFL_Bridge_Updater::parse_manifest( array_replace( $this->manifest( '1.3.0' ), array( 'download_url' => 'https://evil.example/x.zip' ) ) ) );
		$this->assertNull( FFL_Bridge_Updater::parse_manifest( array_replace( $this->manifest( '1.3.0' ), array( 'download_url' => 'https://fflbridge.com.evil.example/x.zip' ) ) ) );

		$GLOBALS['ffl_bridge_test_http_responses'] = array( $this->response( 200, '{"version":' ) );
		$this->assertNull( FFL_Bridge_Updater::get_manifest() );

		$GLOBALS['ffl_bridge_test_site_transients'] = array();
		$GLOBALS['ffl_bridge_test_http_responses']  = array( $this->response( 500, '{}' ) );
		$this->assertNull( FFL_Bridge_Updater::get_manifest() );
	}

	public function test_redirect_is_followed_only_within_fflbridge_https(): void {
		$GLOBALS['ffl_bridge_test_http_responses'] = array(
			$this->response( 308, '', array( 'location' => 'https://www.fflbridge.com/api/plugins/woocommerce/update' ) ),
			$this->response( 200, (string) json_encode( $this->manifest( '1.3.0' ) ) ),
		);
		$this->assertSame( '1.3.0', FFL_Bridge_Updater::fetch_manifest()['version'] );

		$GLOBALS['ffl_bridge_test_http']           = array();
		$GLOBALS['ffl_bridge_test_http_responses'] = array( $this->response( 302, '', array( 'location' => 'http://www.fflbridge.com/api/plugins/woocommerce/update' ) ) );
		$this->assertNull( FFL_Bridge_Updater::fetch_manifest() );
		$this->assertCount( 1, $GLOBALS['ffl_bridge_test_http'] );
	}

	public function test_view_details_modal_data(): void {
		$this->serve( $this->manifest( '1.3.0' ) );

		$info = FFL_Bridge_Updater::plugin_information( false, 'plugin_information', (object) array( 'slug' => 'ffl-bridge-for-woocommerce' ) );

		$this->assertSame( 'FFL Bridge for WooCommerce', $info->name );
		$this->assertSame( '1.3.0', $info->version );
		$this->assertSame( '6.9', $info->requires );
		$this->assertSame( '7.0', $info->tested );
		$this->assertSame( '2026-10-08', $info->last_updated );
		$this->assertSame( array( 'woocommerce' ), $info->requires_plugins );
		$this->assertSame( '<h4>1.3.0</h4><ul><li>Fixes.</li></ul>', $info->sections['changelog'] );
		$this->assertStringContainsString( 'Requires WooCommerce 10.8 or newer.', $info->sections['description'] );

		$this->assertFalse( FFL_Bridge_Updater::plugin_information( false, 'plugin_information', (object) array( 'slug' => 'other-plugin' ) ) );
		$this->assertFalse( FFL_Bridge_Updater::plugin_information( false, 'query_plugins', (object) array( 'slug' => 'ffl-bridge-for-woocommerce' ) ) );
	}

	public function test_changelog_html_is_sanitized(): void {
		$manifest = FFL_Bridge_Updater::parse_manifest( array_replace( $this->manifest( '1.3.0' ), array( 'changelog_html' => '<p>Ok</p><script>alert(1)</script>' ) ) );

		$this->assertStringNotContainsString( '<script>', $manifest['changelog_html'] );
	}

	public function test_checksum_mismatch_rejects_the_package_and_deletes_it(): void {
		$this->serve( $this->manifest( '1.3.0' ) );
		$file = $this->temp( 'zip contents' );
		$GLOBALS['ffl_bridge_test_download_file'] = $file;

		$result = FFL_Bridge_Updater::verify_download( false, 'https://fflbridge.com/downloads/ffl-bridge-for-woocommerce-1.3.0.zip', null, array( 'plugin' => 'ffl-bridge-for-woocommerce/ffl-bridge.php' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ffl_bridge_update_checksum', $result->get_error_code() );
		$this->assertStringContainsString( 'failed its sha256 integrity check', $result->get_error_message() );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_matching_checksum_returns_the_downloaded_file(): void {
		$file     = $this->temp( 'zip contents' );
		$manifest = array_replace( $this->manifest( '1.3.0' ), array( 'sha256' => hash_file( 'sha256', $file ) ) );
		$this->serve( $manifest );
		$GLOBALS['ffl_bridge_test_download_file'] = $file;

		$result = FFL_Bridge_Updater::verify_download( false, $manifest['download_url'], null, array() );

		$this->assertSame( $file, $result );
		unlink( $file );
	}

	public function test_package_that_does_not_match_the_manifest_url_is_refused_for_this_plugin(): void {
		$this->serve( $this->manifest( '1.3.0' ) );

		$result = FFL_Bridge_Updater::verify_download( false, 'https://fflbridge.com/other.zip', null, array( 'plugin' => 'ffl-bridge-for-woocommerce/ffl-bridge.php' ) );

		$this->assertSame( 'ffl_bridge_update_source', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_downloads'] );
	}

	public function test_other_plugins_downloads_are_left_alone(): void {
		$this->serve( $this->manifest( '1.3.0' ) );

		$this->assertFalse( FFL_Bridge_Updater::verify_download( false, 'https://downloads.wordpress.org/plugin/akismet.zip', null, array( 'plugin' => 'akismet/akismet.php' ) ) );
		$this->assertSame( 'prior', FFL_Bridge_Updater::verify_download( 'prior', 'https://fflbridge.com/downloads/ffl-bridge-for-woocommerce-1.3.0.zip' ) );
	}

	public function test_auto_updates_are_supported_but_not_forced_on(): void {
		FFL_Bridge_Updater::init();

		$this->assertArrayHasKey( 'pre_set_site_transient_update_plugins', $GLOBALS['ffl_bridge_test_hooks'] );
		$this->assertArrayHasKey( 'plugins_api', $GLOBALS['ffl_bridge_test_hooks'] );
		$this->assertArrayHasKey( 'upgrader_pre_download', $GLOBALS['ffl_bridge_test_hooks'] );
		$this->assertArrayNotHasKey( 'auto_update_plugin', $GLOBALS['ffl_bridge_test_hooks'] );

		$this->serve( $this->manifest( '1.3.0' ) );
		$item = FFL_Bridge_Updater::update_item( FFL_Bridge_Updater::get_manifest() );
		foreach ( array( 'id', 'slug', 'plugin', 'new_version', 'package' ) as $field ) {
			$this->assertNotEmpty( $item->$field, "WordPress needs {$field} to show the auto-update toggle." );
		}
	}

	public function test_only_https_fflbridge_hosts_are_allowed(): void {
		$this->assertTrue( FFL_Bridge_Updater::is_allowed_url( 'https://fflbridge.com/a.zip' ) );
		$this->assertTrue( FFL_Bridge_Updater::is_allowed_url( 'https://www.fflbridge.com/a.zip' ) );
		$this->assertFalse( FFL_Bridge_Updater::is_allowed_url( 'http://fflbridge.com/a.zip' ) );
		$this->assertFalse( FFL_Bridge_Updater::is_allowed_url( 'https://user@fflbridge.com/a.zip' ) );
		$this->assertFalse( FFL_Bridge_Updater::is_allowed_url( 'https://fflbridge.com:8443/a.zip' ) );
		$this->assertFalse( FFL_Bridge_Updater::is_allowed_url( 'https://notfflbridge.com/a.zip' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function manifest( string $version ): array {
		return array(
			'version'          => $version,
			'download_url'     => 'https://fflbridge.com/downloads/ffl-bridge-for-woocommerce-' . $version . '.zip',
			'sha256'           => self::SHA,
			'requires'         => '6.9',
			'requires_php'     => '8.3',
			'tested'           => '7.0',
			'requires_plugins' => array( 'woocommerce' ),
			'wc_requires'      => '10.8',
			'changelog_html'   => '<h4>1.3.0</h4><ul><li>Fixes.</li></ul>',
			'last_updated'     => '2026-10-08',
		);
	}

	/**
	 * @param array<string, mixed> $manifest Manifest.
	 */
	private function serve( array $manifest ): void {
		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 200, (string) json_encode( $manifest ) );
	}

	/**
	 * @param array<string, string> $headers Headers.
	 * @return array<string, mixed>
	 */
	private function response( int $code, string $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => $headers,
			'body'     => $body,
		);
	}

	private function temp( string $contents ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'fflu' );
		file_put_contents( $path, $contents );
		return $path;
	}
}
