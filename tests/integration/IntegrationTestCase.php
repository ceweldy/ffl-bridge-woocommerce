<?php
/**
 * Base class for tests that run against real WordPress and WooCommerce.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Thrown in place of wp_die(), a redirect, or a JSON response so a handler
 * stops without ending the test process.
 */
final class FFL_Bridge_Integration_Stop extends RuntimeException {
	public function __construct( public readonly string $kind, public readonly int $status, string $message, public readonly string $output = '' ) {
		parent::__construct( $message );
	}
}

abstract class FFL_Bridge_Integration_TestCase extends TestCase {
	/** @var array<int, int> */
	private array $users = array();

	/** @var array<string, mixed> */
	private array $saved_options = array();

	protected function setUp(): void {
		parent::setUp();

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$GLOBALS['wp_settings_errors'] = array();
		$GLOBALS['current_screen']     = null;
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		foreach ( $this->users as $user_id ) {
			wp_delete_user( $user_id );
		}

		foreach ( $this->saved_options as $name => $value ) {
			$this->without_sanitizing(
				$name,
				static fn () => false === $value ? delete_option( $name ) : update_option( $name, $value )
			);
		}

		remove_all_filters( 'wp_die_handler' );
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'pre_http_request' );
		$GLOBALS['current_screen'] = null;
		wp_set_current_user( 0 );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		parent::tearDown();
	}

	/**
	 * Create a user with a role and make it the current user.
	 */
	protected function act_as( string $role ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'ffl_' . $role . '_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => wp_generate_password( 8, false ) . '@ffl-bridge.test',
				'role'       => $role,
			)
		);
		$this->assertIsInt( $user_id );
		$this->users[] = $user_id;
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Store an option exactly as given for this test, bypassing its sanitize
	 * callback, and restore the previous value afterwards.
	 */
	protected function set_option( string $name, mixed $value ): void {
		if ( ! array_key_exists( $name, $this->saved_options ) ) {
			$this->saved_options[ $name ] = get_option( $name, false );
		}

		$this->without_sanitizing( $name, static fn () => update_option( $name, $value ) );
	}

	private function without_sanitizing( string $name, callable $write ): void {
		global $wp_filter;

		$hook  = "sanitize_option_{$name}";
		$saved = $wp_filter[ $hook ] ?? null;
		unset( $wp_filter[ $hook ] );
		try {
			$write();
		} finally {
			if ( null !== $saved ) {
				$wp_filter[ $hook ] = $saved;
			}
		}
	}

	protected function set_screen( string $id ): void {
		set_current_screen( $id );
		$this->assertSame( $id, get_current_screen()->id );
	}

	/**
	 * Run a handler that ends with wp_die(), a redirect, or a JSON response.
	 */
	protected function stop( callable $handler, bool $ajax = false ): FFL_Bridge_Integration_Stop {
		$die = static function () {
			return static function ( $message, $title = '', $args = array() ): void {
				$status = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 0;
				throw new FFL_Bridge_Integration_Stop( 'die', $status, is_scalar( $message ) ? (string) $message : '', (string) ob_get_contents() );
			};
		};
		add_filter( 'wp_die_handler', $die );
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter(
			'wp_redirect',
			static function ( $location, $status ): void {
				throw new FFL_Bridge_Integration_Stop( 'redirect', (int) $status, (string) $location );
			},
			10,
			2
		);
		if ( $ajax ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		}

		$level = ob_get_level();
		ob_start();
		try {
			$handler();
		} catch ( FFL_Bridge_Integration_Stop $stop ) {
			return $stop;
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}

		$this->fail( 'The handler returned without stopping.' );
	}

	protected function render( callable $notice ): string {
		ob_start();
		try {
			$notice();
		} finally {
			$output = (string) ob_get_clean();
		}

		return trim( $output );
	}
}
