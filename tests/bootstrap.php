<?php
/**
 * Lightweight WordPress and WooCommerce test doubles.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'FFL_BRIDGE_VERSION', '1.1.0-test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'FFL_BRIDGE_PLUGIN_FILE', '/srv/www/wp-content/plugins/ffl-bridge-for-woocommerce/ffl-bridge.php' );
define( 'FFL_BRIDGE_PLUGIN_URL', 'https://store.example.test/wp-content/plugins/ffl-bridge-for-woocommerce/' );

$GLOBALS['ffl_bridge_test_options']    = array();
$GLOBALS['ffl_bridge_test_term_map']   = array();
$GLOBALS['ffl_bridge_test_wc']         = null;
$GLOBALS['ffl_bridge_test_home_url']   = 'https://store.example.test/';
$GLOBALS['ffl_bridge_test_transients'] = array();
$GLOBALS['ffl_bridge_test_settings_errors'] = array();
$GLOBALS['ffl_bridge_test_filters']         = array();
$GLOBALS['ffl_bridge_test_caps']            = array();
$GLOBALS['ffl_bridge_test_orders']          = array();
$GLOBALS['ffl_bridge_test_http']            = array();
$GLOBALS['ffl_bridge_test_http_responses']  = array();
$GLOBALS['ffl_bridge_test_upload_dir']      = sys_get_temp_dir();
$GLOBALS['ffl_bridge_test_site_transients'] = array();
$GLOBALS['ffl_bridge_test_downloads']       = array();
$GLOBALS['ffl_bridge_test_hooks']           = array();

/** Minimal WordPress error object used by the runtime. */
class WP_Error {
	/** @var array<string, string> */
	private array $errors = array();

	public function __construct( string $code = '', string $message = '' ) {
		if ( '' !== $code ) {
			$this->errors[ $code ] = $message;
		}
	}

	public function get_error_code(): string {
		return (string) array_key_first( $this->errors );
	}

	public function get_error_message(): string {
		return (string) reset( $this->errors );
	}
}

/** In-memory WooCommerce order test double. */
class WC_Order {
	/** @var array<int, string> */
	public array $notes = array();

	public int $saves = 0;

	/** @param array<string, mixed> $meta Initial metadata. */
	public function __construct( private array $meta = array(), private int $id = 501 ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_order_number(): string {
		return (string) $this->id;
	}

	public function get_meta( string $key ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	/** @return array<string, mixed> */
	public function all_meta(): array {
		return $this->meta;
	}

	public function add_order_note( string $note ): void {
		$this->notes[] = $note;
	}

	public function save(): void {
		++$this->saves;
	}
}

/** In-memory WooCommerce session test double. */
class FFL_Bridge_Test_Session {
	/** @var array<string, mixed> */
	private array $data = array();

	public function __construct( private string $customer_id = 'guest-session-1' ) {}

	public function get_customer_id(): string {
		return $this->customer_id;
	}

	public function set_customer_id( string $customer_id ): void {
		$this->customer_id = $customer_id;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}

	public function set( string $key, mixed $value ): void {
		$this->data[ $key ] = $value;
	}

	public function __unset( string $key ): void {
		unset( $this->data[ $key ] );
	}
}

/** In-memory WooCommerce cart test double. */
class FFL_Bridge_Test_Cart {
	/**
	 * @param array<int, array<string, mixed>> $items Cart items.
	 */
	public function __construct(
		private array $items = array(),
		private string $hash = 'cart-hash-1'
	) {}

	public function is_empty(): bool {
		return array() === $this->items;
	}

	/** @return array<int, array<string, mixed>> */
	public function get_cart(): array {
		return $this->items;
	}

	public function get_cart_hash(): string {
		return $this->hash;
	}

	public function set_cart_hash( string $hash ): void {
		$this->hash = $hash;
	}
}

/** Container returned by WC(). */
class FFL_Bridge_Test_WooCommerce {
	public object|null $cart     = null;
	public object|null $session  = null;
	public object|null $customer = null;
}

/** Thrown by the wp_send_json_* doubles so tests can read the response. */
class FFL_Bridge_Test_Json extends Exception {
	/** @param array<string, mixed> $data Response data. */
	public function __construct( public bool $success, public array $data, public int $status ) {
		parent::__construct( 'json response' );
	}
}

function wp_send_json_success( mixed $data = null, int $status = 200 ): never {
	throw new FFL_Bridge_Test_Json( true, is_array( $data ) ? $data : array(), $status );
}

function wp_send_json_error( mixed $data = null, int $status = 400 ): never {
	throw new FFL_Bridge_Test_Json( false, is_array( $data ) ? $data : array(), $status );
}

function current_user_can( string $capability, mixed ...$args ): bool {
	return in_array( $capability, $GLOBALS['ffl_bridge_test_caps'], true );
}

function check_ajax_referer( string $action, string $field = '_wpnonce', bool $stop = true ): bool|int {
	return ( $_POST[ $field ] ?? '' ) === 'nonce-' . $action ? 1 : false; // phpcs:ignore
}

function wp_create_nonce( string $action ): string {
	return 'nonce-' . $action;
}

function wc_get_order( mixed $id ): WC_Order|false {
	return $GLOBALS['ffl_bridge_test_orders'][ (int) $id ] ?? false;
}

function wp_delete_file( string $file ): void {
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}

function get_post_type( int $post_id ): string|false {
	return $GLOBALS['ffl_bridge_test_post_types'][ $post_id ] ?? false;
}

function get_current_user_id(): int {
	return 7;
}

function wp_get_current_user(): object {
	return (object) array( 'ID' => 7, 'display_name' => 'Store Manager' );
}

function wp_unslash( mixed $value ): mixed {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function sanitize_email( string $email ): string {
	return (string) filter_var( trim( $email ), FILTER_SANITIZE_EMAIL );
}

function is_email( string $email ): bool {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function sanitize_file_name( string $name ): string {
	return (string) preg_replace( '/[^A-Za-z0-9._-]/', '-', $name );
}

function sanitize_key( string $key ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

function wp_check_filetype_and_ext( string $file, string $filename, ?array $mimes = null ): array {
	$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$real = is_file( $file ) ? (string) ( new finfo( FILEINFO_MIME_TYPE ) )->file( $file ) : '';
	if ( null === $mimes || ! isset( $mimes[ $ext ] ) || $mimes[ $ext ] !== $real ) {
		return array( 'ext' => false, 'type' => false, 'proper_filename' => false );
	}
	return array( 'ext' => $ext, 'type' => $real, 'proper_filename' => false );
}

function wp_upload_dir( mixed $time = null, bool $create = true ): array {
	return array( 'basedir' => $GLOBALS['ffl_bridge_test_upload_dir'], 'error' => false );
}

function wp_mkdir_p( string $dir ): bool {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component );
}

function add_query_arg( array $args, string $url ): string {
	return $url . '?' . http_build_query( $args );
}

function wp_safe_remote_post( string $url, array $args = array() ): array|WP_Error {
	$GLOBALS['ffl_bridge_test_http'][] = array( 'url' => $url, 'args' => $args );
	$next = array_shift( $GLOBALS['ffl_bridge_test_http_responses'] );
	return $next ?? new WP_Error( 'http_request_failed', 'No response queued.' );
}

function wp_safe_remote_get( string $url, array $args = array() ): array|WP_Error {
	return wp_safe_remote_post( $url, $args );
}

function wp_remote_retrieve_response_code( array|WP_Error $response ): int|string {
	return is_array( $response ) ? $response['response']['code'] : '';
}

function wp_remote_retrieve_body( array|WP_Error $response ): string {
	return is_array( $response ) ? $response['body'] : '';
}

function wp_remote_retrieve_header( array|WP_Error $response, string $header ): string {
	return is_array( $response ) ? (string) ( $response['headers'][ strtolower( $header ) ] ?? '' ) : '';
}

function plugin_basename( string $file ): string {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function get_site_transient( string $key ): mixed {
	return $GLOBALS['ffl_bridge_test_site_transients'][ $key ]['value'] ?? false;
}

function set_site_transient( string $key, mixed $value, int $expiration = 0 ): bool {
	$GLOBALS['ffl_bridge_test_site_transients'][ $key ] = array( 'value' => $value, 'ttl' => $expiration );
	return true;
}

function delete_site_transient( string $key ): bool {
	unset( $GLOBALS['ffl_bridge_test_site_transients'][ $key ] );
	return true;
}

function wp_kses_post( string $html ): string {
	return strip_tags( $html, '<p><ul><li><strong><em><a><h4>' );
}

function download_url( string $url, int $timeout = 300 ): string|WP_Error {
	$GLOBALS['ffl_bridge_test_downloads'][] = $url;
	return $GLOBALS['ffl_bridge_test_download_file'] ?? new WP_Error( 'http_request_failed', 'No file.' );
}

function wp_delete_file( string $file ): void {
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}

function add_filter( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): bool {
	$GLOBALS['ffl_bridge_test_hooks'][ $hook ][] = $callback;
	return true;
}

function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): bool {
	return add_filter( $hook, $callback, $priority, $args );
}

function __( string $text, string $domain = '' ): string {
	return $text;
}

function esc_html__( string $text, string $domain = '' ): string {
	return $text;
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function is_wp_error( mixed $thing ): bool {
	return $thing instanceof WP_Error;
}

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['ffl_bridge_test_options'][ $key ] ?? $default;
}

function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
	$GLOBALS['ffl_bridge_test_options'][ $key ] = $value;
	return true;
}

function _n( string $single, string $plural, int $number, string $domain = '' ): string {
	return 1 === $number ? $single : $plural;
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$callback = $GLOBALS['ffl_bridge_test_filters'][ $hook ] ?? null;
	return is_callable( $callback ) ? $callback( $value, ...$args ) : $value;
}

function sanitize_text_field( mixed $value ): string {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$value = strip_tags( (string) $value );
	$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );
	return trim( (string) $value );
}

function sanitize_textarea_field( mixed $value ): string {
	return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
}

function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
	$GLOBALS['ffl_bridge_test_settings_errors'][] = array( $setting, $code, $type );
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . $scheme;
}

function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
	return json_encode( $value, $flags );
}

function wp_get_post_terms( int $post_id, string $taxonomy, array $args = array() ): array|WP_Error {
	return $GLOBALS['ffl_bridge_test_term_map'][ $post_id ] ?? array();
}

function WC(): FFL_Bridge_Test_WooCommerce {
	return $GLOBALS['ffl_bridge_test_wc'];
}

function home_url( string $path = '' ): string {
	return rtrim( $GLOBALS['ffl_bridge_test_home_url'], '/' ) . '/' . ltrim( $path, '/' );
}

function get_transient( string $key ): mixed {
	return $GLOBALS['ffl_bridge_test_transients'][ $key ] ?? false;
}

function set_transient( string $key, mixed $value, int $expiration ): bool {
	$GLOBALS['ffl_bridge_test_transients'][ $key ] = $value;
	return true;
}

require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-network.php';
require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-coverage.php';
require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-followup.php';
require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-transfer-confirmation.php';
if ( is_readable( dirname( __DIR__ ) . '/includes/class-ffl-bridge-updater.php' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-updater.php';
}
require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-selection.php';
require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-checkout.php';
require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-order.php';
require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-settings.php';
require_once __DIR__ . '/TestCase.php';
