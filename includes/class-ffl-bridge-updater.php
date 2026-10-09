<?php
/**
 * Self-hosted updates from FFL Bridge.
 *
 * This file is left out of the WordPress.org directory build, because the
 * directory serves its own updates.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the FFL Bridge update manifest and plugs it into the standard
 * WordPress plugin update flow: "Update available", the "View details"
 * modal, and optional WordPress auto-updates (never forced on).
 *
 * Every downloaded package is checked against the manifest's sha256 before
 * WordPress installs it.
 */
final class FFL_Bridge_Updater {

	public const MANIFEST_URL  = 'https://fflbridge.com/api/plugins/woocommerce/update';
	public const SLUG          = 'ffl-bridge-for-woocommerce';
	public const CACHE_KEY     = 'ffl_bridge_update_manifest';
	public const CACHE_TTL     = 43200;
	public const FAILURE_TTL   = 3600;
	private const MAX_BODY     = 262144;
	private const ALLOWED_HOST = 'fflbridge.com';

	/**
	 * Register update hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 0 );
	}

	/**
	 * Return this plugin's basename, for example ffl-bridge-for-woocommerce/ffl-bridge.php.
	 *
	 * @return string
	 */
	public static function basename(): string {
		return plugin_basename( FFL_BRIDGE_PLUGIN_FILE );
	}

	/**
	 * Return the validated manifest, from cache when fresh.
	 *
	 * A failed fetch is cached for an hour so an unreachable server does not
	 * slow down every admin page. Failures are silent.
	 *
	 * @param bool $force Skip the cache.
	 * @return array<string, mixed>|null
	 */
	public static function get_manifest( bool $force = false ): ?array {
		$cached = $force ? false : get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && array_key_exists( 'manifest', $cached ) ) {
			return is_array( $cached['manifest'] ) ? $cached['manifest'] : null;
		}

		$manifest = self::fetch_manifest();
		set_site_transient( self::CACHE_KEY, array( 'manifest' => $manifest ), null === $manifest ? self::FAILURE_TTL : self::CACHE_TTL );
		return $manifest;
	}

	/**
	 * Fetch and validate the manifest over HTTPS.
	 *
	 * Redirects are not followed automatically. One redirect to another
	 * fflbridge.com HTTPS address (such as the www host) is followed by hand.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function fetch_manifest(): ?array {
		$url = self::MANIFEST_URL;
		for ( $hop = 0; $hop < 2; $hop++ ) {
			if ( ! self::is_allowed_url( $url ) ) {
				return null;
			}

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => self::MAX_BODY,
					'headers'             => array(
						'Accept'     => 'application/json',
						'User-Agent' => 'FFL-Bridge-WooCommerce/' . FFL_BRIDGE_VERSION,
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				return null;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $status, array( 301, 302, 307, 308 ), true ) ) {
				$url = (string) wp_remote_retrieve_header( $response, 'location' );
				continue;
			}

			if ( 200 !== $status ) {
				return null;
			}

			return self::parse_manifest( json_decode( (string) wp_remote_retrieve_body( $response ), true, 8 ) );
		}

		return null;
	}

	/**
	 * Validate a decoded manifest and keep only known, well-formed fields.
	 *
	 * Required: version, download_url (HTTPS on fflbridge.com), and sha256.
	 * Optional: requires, requires_php, tested, requires_plugins,
	 * wc_requires, changelog_html, last_updated.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array<string, mixed>|null
	 */
	public static function parse_manifest( mixed $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$version = is_string( $data['version'] ?? null ) ? trim( $data['version'] ) : '';
		$url     = is_string( $data['download_url'] ?? null ) ? trim( $data['download_url'] ) : '';
		$sha256  = is_string( $data['sha256'] ?? null ) ? strtolower( trim( $data['sha256'] ) ) : '';

		if (
			1 !== preg_match( '/\A\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?\z/', $version ) ||
			! self::is_allowed_url( $url ) ||
			1 !== preg_match( '/\A[0-9a-f]{64}\z/', $sha256 )
		) {
			return null;
		}

		$requires_plugins = $data['requires_plugins'] ?? 'woocommerce';
		if ( is_array( $requires_plugins ) ) {
			$requires_plugins = implode( ',', array_filter( $requires_plugins, 'is_string' ) );
		}

		return array(
			'version'          => $version,
			'download_url'     => $url,
			'sha256'           => $sha256,
			'requires'         => self::version_field( $data['requires'] ?? '' ),
			'requires_php'     => self::version_field( $data['requires_php'] ?? '' ),
			'tested'           => self::version_field( $data['tested'] ?? '' ),
			'wc_requires'      => self::version_field( $data['wc_requires'] ?? ( $data['wc_requires_at_least'] ?? '' ) ),
			'requires_plugins' => is_string( $requires_plugins ) ? sanitize_text_field( $requires_plugins ) : 'woocommerce',
			'changelog_html'   => is_string( $data['changelog_html'] ?? null ) ? wp_kses_post( $data['changelog_html'] ) : '',
			'last_updated'     => is_string( $data['last_updated'] ?? null ) ? sanitize_text_field( $data['last_updated'] ) : '',
		);
	}

	/**
	 * Add this plugin to the update transient.
	 *
	 * A newer version goes in "response", which shows "Update available".
	 * Otherwise the plugin goes in "no_update", which still lets WordPress
	 * show the auto-update toggle. Auto-updates stay off until the store
	 * turns them on.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public static function inject_update( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$manifest = self::get_manifest();
		if ( null === $manifest ) {
			return $transient;
		}

		$item     = self::update_item( $manifest );
		$basename = self::basename();
		// Right after an update, this request still has the old constant, while
		// WordPress has already read the new version from disk into checked.
		$installed = is_array( $transient->checked ?? null ) && is_string( $transient->checked[ $basename ] ?? null )
			? $transient->checked[ $basename ]
			: FFL_BRIDGE_VERSION;
		if ( version_compare( $manifest['version'], $installed, '>' ) ) {
			$transient->response              = is_array( $transient->response ?? null ) ? $transient->response : array();
			$transient->response[ $basename ] = $item;
			unset( $transient->no_update[ $basename ] );
		} else {
			$transient->no_update              = is_array( $transient->no_update ?? null ) ? $transient->no_update : array();
			$transient->no_update[ $basename ] = $item;
			unset( $transient->response[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Build the update object WordPress expects.
	 *
	 * @param array<string, mixed> $manifest Validated manifest.
	 * @return object
	 */
	public static function update_item( array $manifest ): object {
		return (object) array(
			'id'               => self::MANIFEST_URL,
			'slug'             => self::SLUG,
			'plugin'           => self::basename(),
			'new_version'      => $manifest['version'],
			'url'              => 'https://www.fflbridge.com/woocommerce-ffl-plugin',
			'package'          => $manifest['download_url'],
			'requires'         => $manifest['requires'],
			'requires_php'     => $manifest['requires_php'],
			'tested'           => $manifest['tested'],
			'requires_plugins' => array_filter( explode( ',', (string) $manifest['requires_plugins'] ) ),
			'icons'            => array(),
			'banners'          => array(),
		);
	}

	/**
	 * Supply the "View details" modal.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action API action.
	 * @param mixed  $args Request arguments.
	 * @return mixed
	 */
	public static function plugin_information( mixed $result, string $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$manifest = self::get_manifest();
		if ( null === $manifest ) {
			return $result;
		}

		$description = '<p>' . esc_html__( 'Server-verified FFL dealer selection for WooCommerce checkout, with hybrid dealer selection and license follow-up.', 'ffl-bridge-for-woocommerce' ) . '</p>';
		if ( '' !== $manifest['wc_requires'] ) {
			$description .= '<p>' . esc_html(
				sprintf(
					/* translators: %s: WooCommerce version. */
					__( 'Requires WooCommerce %s or newer.', 'ffl-bridge-for-woocommerce' ),
					$manifest['wc_requires']
				)
			) . '</p>';
		}

		return (object) array(
			'name'             => 'FFL Bridge for WooCommerce',
			'slug'             => self::SLUG,
			'version'          => $manifest['version'],
			'author'           => '<a href="https://www.fflbridge.com">FFL Bridge</a>',
			'homepage'         => 'https://www.fflbridge.com/woocommerce-ffl-plugin',
			'requires'         => $manifest['requires'],
			'requires_php'     => $manifest['requires_php'],
			'tested'           => $manifest['tested'],
			'requires_plugins' => array_filter( explode( ',', (string) $manifest['requires_plugins'] ) ),
			'last_updated'     => $manifest['last_updated'],
			'download_link'    => $manifest['download_url'],
			'sections'         => array(
				'description' => $description,
				'changelog'   => '' !== $manifest['changelog_html'] ? $manifest['changelog_html'] : '<p>' . esc_html__( 'See the FFL Bridge website for release notes.', 'ffl-bridge-for-woocommerce' ) . '</p>',
			),
			'banners'          => array(),
		);
	}

	/**
	 * Download this plugin's package and check its sha256 before install.
	 *
	 * Other packages are left to WordPress. On a mismatch the file is deleted
	 * and the update stops with a clear error.
	 *
	 * @param mixed  $reply Default reply, false to let WordPress download.
	 * @param string $package Package URL.
	 * @param mixed  $upgrader Upgrader instance.
	 * @param mixed  $hook_extra Extra hook data.
	 * @return mixed File path, WP_Error, or the original reply.
	 */
	public static function verify_download( mixed $reply, string $package, mixed $upgrader = null, mixed $hook_extra = array() ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WordPress hook signature.
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$ours = is_array( $hook_extra ) && self::basename() === ( $hook_extra['plugin'] ?? '' );
		// Another plugin's (or WordPress core's) download: do not touch the
		// network for the manifest at all.
		if ( ! $ours && ( ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) ) || ! self::is_allowed_url( $package ) ) ) {
			return $reply;
		}

		$manifest = self::get_manifest();
		if ( null === $manifest ) {
			// Without the manifest there is no checksum, so never let WordPress
			// install this plugin's package unchecked.
			return $ours
				? new WP_Error( 'ffl_bridge_update_manifest', __( 'The FFL Bridge update was not installed because the update information could not be loaded to verify the download. Nothing was changed. Try again later.', 'ffl-bridge-for-woocommerce' ) )
				: $reply;
		}

		if ( $package !== $manifest['download_url'] && ! $ours ) {
			return $reply;
		}

		// Another filter already supplied a local file: check that file too.
		if ( is_string( $reply ) ) {
			return self::check_file( $reply, $manifest['sha256'] );
		}

		if ( false !== $reply ) {
			return $reply;
		}

		if ( $package !== $manifest['download_url'] || ! self::is_allowed_url( $package ) ) {
			return new WP_Error( 'ffl_bridge_update_source', __( 'The FFL Bridge update was not installed because its download address does not match the FFL Bridge update manifest.', 'ffl-bridge-for-woocommerce' ) );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		return self::check_file( (string) $file, $manifest['sha256'] );
	}

	/**
	 * Compare a downloaded file with the expected sha256.
	 *
	 * @param string $file Downloaded file path.
	 * @param string $expected Expected lowercase hex sha256.
	 * @return string|WP_Error The file path when it matches.
	 */
	public static function check_file( string $file, string $expected ): string|WP_Error {
		$actual = is_file( $file ) ? (string) hash_file( 'sha256', $file ) : '';
		if ( '' === $actual || ! hash_equals( $expected, $actual ) ) {
			wp_delete_file( $file );
			return new WP_Error(
				'ffl_bridge_update_checksum',
				__( 'The FFL Bridge update was not installed because the downloaded file failed its sha256 integrity check. Nothing was changed. Try again later or contact FFL Bridge support.', 'ffl-bridge-for-woocommerce' )
			);
		}

		return $file;
	}

	/**
	 * Clear the cached manifest after an update run.
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Allow only HTTPS addresses on fflbridge.com or its subdomains.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function is_allowed_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || isset( $parts['user'] ) || isset( $parts['port'] ) ) {
			return false;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		return self::ALLOWED_HOST === $host || str_ends_with( $host, '.' . self::ALLOWED_HOST );
	}

	/**
	 * Keep a short version string such as 6.9 or 8.3.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function version_field( mixed $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return 1 === preg_match( '/\A\d+(?:\.\d+){0,2}\z/', $value ) ? $value : '';
	}
}
