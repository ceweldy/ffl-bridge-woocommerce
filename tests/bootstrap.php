<?php
/**
 * Lightweight WordPress and WooCommerce test doubles.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

define( 'FFL_BRIDGE_TEST_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'ABSPATH', FFL_BRIDGE_TEST_PLUGIN_DIR );
define( 'FFL_BRIDGE_VERSION', '1.1.0-test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'FFL_BRIDGE_PLUGIN_FILE', '/srv/www/wp-content/plugins/ffl-bridge-for-woocommerce/ffl-bridge.php' );
define( 'FFL_BRIDGE_PLUGIN_URL', 'https://store.example.test/wp-content/plugins/ffl-bridge-for-woocommerce/' );

$GLOBALS['ffl_bridge_test_options']    = array();
$GLOBALS['ffl_bridge_test_term_map']   = array();
$GLOBALS['ffl_bridge_test_term_parents'] = array();
$GLOBALS['ffl_bridge_test_logs']         = array();
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

	public function __construct( string $code = '', string $message = '', private mixed $data = '' ) {
		if ( '' !== $code ) {
			$this->errors[ $code ] = $message;
		}
	}

	public function get_error_data(): mixed {
		return $this->data;
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

	/** GMT creation time, or null for an order without one. */
	public ?string $date_created = '2026-11-13T12:00:00+00:00';

	/** @var array<string, string> Billing fields: first_name, last_name, email, phone. */
	public array $billing = array();

	/** @var array<string, string> Shipping fields: first_name, company, address_1, city, state, postcode, country. */
	public array $shipping = array();

	/** Customer user ID, 0 for a guest. */
	public int $customer_id = 0;

	public function get_customer_id(): int {
		return $this->customer_id;
	}

	/** @param array<string, mixed> $meta Initial metadata. */
	public function __construct( private array $meta = array(), private int $id = 501 ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_order_number(): string {
		return (string) $this->id;
	}

	public function get_date_created(): ?DateTimeImmutable {
		return null === $this->date_created ? null : new DateTimeImmutable( $this->date_created );
	}

	public function get_billing_first_name(): string {
		return $this->billing['first_name'] ?? '';
	}

	public function get_billing_last_name(): string {
		return $this->billing['last_name'] ?? '';
	}

	public function get_billing_email(): string {
		return $this->billing['email'] ?? '';
	}

	public function get_billing_phone(): string {
		return $this->billing['phone'] ?? '';
	}

	public function get_meta( string $key ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}

	/** @return array<string, mixed> */
	public function all_meta(): array {
		return $this->meta;
	}

	/** Like WooCommerce, an order without an ID (not saved yet) records no note. */
	public function add_order_note( string $note ): int {
		if ( 0 === $this->id ) {
			return 0;
		}
		$this->notes[] = $note;
		return count( $this->notes );
	}

	/** Simulate the first save giving the order an ID. */
	public function ffl_test_set_id( int $id ): void {
		$this->id = $id;
	}

	/** @var array<int, object> Shipping line items. */
	public array $shipping_methods = array();

	/** @return array<int, object> */
	public function get_shipping_methods(): array {
		return $this->shipping_methods;
	}

	public function save(): void {
		++$this->saves;
	}

	/**
	 * get_shipping_* and set_shipping_* accessors, plus set_billing_*.
	 *
	 * @param array<int, mixed> $args Arguments.
	 */
	public function __call( string $name, array $args ): mixed {
		if ( preg_match( '/\A(get|set)_(shipping|billing)_([a-z0-9_]+)\z/', $name, $parts ) ) {
			$group = $parts[2];
			if ( 'set' === $parts[1] ) {
				$this->{$group}[ $parts[3] ] = (string) $args[0];
				return null;
			}
			return $this->{$group}[ $parts[3] ] ?? '';
		}
		throw new BadMethodCallException( $name );
	}
}

/** In-memory WC_Customer test double with shipping and billing fields. */
class FFL_Bridge_Test_Customer {
	/** @var array<string, string> */
	public array $shipping = array();

	/** @var array<string, string> */
	public array $billing = array();

	public int $saves = 0;

	public function save(): void {
		++$this->saves;
	}

	/** @param array<int, mixed> $args Arguments. */
	public function __call( string $name, array $args ): mixed {
		if ( preg_match( '/\A(get|set)_(shipping|billing)_([a-z0-9_]+)\z/', $name, $parts ) ) {
			$group = $parts[2];
			if ( 'set' === $parts[1] ) {
				$this->{$group}[ $parts[3] ] = (string) $args[0];
				return null;
			}
			return $this->{$group}[ $parts[3] ] ?? '';
		}
		throw new BadMethodCallException( $name );
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

/** Thrown by the wp_die and wp_safe_redirect doubles. */
class FFL_Bridge_Test_Die extends Exception {
	public function __construct( public int $status, string $message = '' ) {
		parent::__construct( $message );
	}
}

function wp_die( mixed $message = '', mixed $title = '', mixed $args = array() ): never {
	$status = is_int( $title ) ? $title : ( is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 500 );
	throw new FFL_Bridge_Test_Die( $status, is_string( $message ) ? $message : '' );
}

function check_admin_referer( string $action = '', string $query_arg = '_wpnonce' ): int {
	$nonce = $_REQUEST[ $query_arg ] ?? ''; // phpcs:ignore
	if ( 'nonce-' . $action !== $nonce ) {
		wp_die( 'The link you followed has expired.', 403 );
	}
	return 1;
}

function wp_verify_nonce( mixed $nonce, string $action = '' ): int|false {
	return 'nonce-' . $action === $nonce ? 1 : false;
}

function wp_safe_redirect( string $location, int $status = 302 ): never {
	throw new FFL_Bridge_Test_Die( $status, $location );
}

function wp_get_referer(): string|false {
	return false;
}

function get_current_screen(): ?object {
	$id = $GLOBALS['ffl_bridge_test_screen'] ?? null;
	return null === $id ? null : (object) array( 'id' => $id );
}

function admin_url( string $path = '' ): string {
	return 'https://store.example.test/wp-admin/' . ltrim( $path, '/' );
}

function wp_nonce_url( string $url, string $action = '' ): string {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . '_wpnonce=nonce-' . $action;
}

function esc_url( string $url ): string {
	return htmlspecialchars( $url, ENT_QUOTES );
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}

function get_user_meta( int $user_id, string $key = '', bool $single = false ): mixed {
	return $GLOBALS['ffl_bridge_test_user_meta'][ $user_id ][ $key ] ?? '';
}

function update_user_meta( int $user_id, string $key, mixed $value ): bool {
	$GLOBALS['ffl_bridge_test_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

function current_user_can( string $capability, mixed ...$args ): bool {
	// An object capability such as edit_post for post 100 is granted as "edit_post:100".
	$capability = array() === $args ? $capability : $capability . ':' . (string) $args[0];
	return in_array( $capability, $GLOBALS['ffl_bridge_test_caps'], true );
}

function get_the_ID(): int|false {
	return $GLOBALS['ffl_bridge_test_current_post_id'] ?? false;
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
	return $GLOBALS['ffl_bridge_test_user_id'] ?? 7;
}

function delete_user_meta( int $user_id, string $key ): bool {
	$existed = isset( $GLOBALS['ffl_bridge_test_user_meta'][ $user_id ][ $key ] );
	unset( $GLOBALS['ffl_bridge_test_user_meta'][ $user_id ][ $key ] );
	return $existed;
}

function get_user_by( string $field, mixed $value ): object|false {
	foreach ( $GLOBALS['ffl_bridge_test_users'] ?? array() as $id => $email ) {
		if ( 'email' === $field && $email === $value ) {
			return (object) array( 'ID' => $id, 'user_email' => $email );
		}
	}
	return false;
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

/**
 * Ancestors of a term, nearest first, from the ffl_bridge_test_term_parents
 * map of term ID to parent term ID.
 *
 * @return array<int, int>
 */
function get_ancestors( int $object_id = 0, string $object_type = '', string $resource_type = '' ): array {
	$ancestors = array();
	$parents   = $GLOBALS['ffl_bridge_test_term_parents'];
	while ( isset( $parents[ $object_id ] ) && ! in_array( $parents[ $object_id ], $ancestors, true ) ) {
		$object_id   = $parents[ $object_id ];
		$ancestors[] = $object_id;
	}

	return $ancestors;
}

/** Records log entries written through wc_get_logger(). */
final class FFL_Bridge_Test_Logger {
	/** @param array<string, mixed> $context Context. */
	public function log( string $level, string $message, array $context = array() ): void {
		$GLOBALS['ffl_bridge_test_logs'][] = array( 'level' => $level, 'message' => $message, 'context' => $context );
	}
}

function wc_get_logger(): FFL_Bridge_Test_Logger {
	return new FFL_Bridge_Test_Logger();
}

function wc_add_notice( string $message, string $notice_type = 'success', array $data = array() ): void {
	$GLOBALS['ffl_bridge_test_notices'][] = array( $message, $notice_type );
}

function is_order_received_page(): bool {
	return ! empty( $GLOBALS['ffl_bridge_test_order_received'] );
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

$GLOBALS['ffl_bridge_test_post_meta'] = array();

function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
	$value = $GLOBALS['ffl_bridge_test_post_meta'][ $post_id ][ $key ] ?? null;
	return null === $value ? ( $single ? '' : array() ) : ( $single ? $value : array( $value ) );
}

function update_post_meta( int $post_id, string $key, mixed $value ): bool {
	$GLOBALS['ffl_bridge_test_post_meta'][ $post_id ][ $key ] = $value;
	return true;
}

function delete_post_meta( int $post_id, string $key ): bool {
	unset( $GLOBALS['ffl_bridge_test_post_meta'][ $post_id ][ $key ] );
	return true;
}

/**
 * Supports the one query shape the plugin uses: posts with meta_key equal to
 * meta_value, optionally excluding IDs, returning IDs.
 *
 * @param array<string, mixed> $args Query.
 * @return array<int, int>
 */
function get_posts( array $args = array() ): array {
	$GLOBALS['ffl_bridge_test_post_queries'][] = $args;
	$ids = array();
	foreach ( $GLOBALS['ffl_bridge_test_post_meta'] as $post_id => $meta ) {
		if ( in_array( $post_id, (array) ( $args['exclude'] ?? array() ), true ) ) {
			continue;
		}
		if ( ( $meta[ $args['meta_key'] ?? '' ] ?? null ) === ( $args['meta_value'] ?? null ) ) {
			$ids[] = (int) $post_id;
		}
	}
	return array_slice( $ids, 0, (int) ( $args['posts_per_page'] ?? 5 ) );
}

function woocommerce_wp_checkbox( array $field ): void {
	printf(
		'<p class="form-field %1$s_field"><label for="%1$s">%2$s</label><input type="checkbox" name="%3$s" id="%1$s" value="yes" %4$s> <span class="description">%5$s</span></p>',
		esc_attr( $field['id'] ),
		esc_html( $field['label'] ?? '' ),
		esc_attr( $field['name'] ?? $field['id'] ),
		'yes' === ( $field['value'] ?? '' ) ? 'checked' : '',
		esc_html( $field['description'] ?? '' )
	);
}

function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
	$result = (string) $checked === (string) $current ? " checked='checked'" : '';
	if ( $display ) {
		echo $result; // phpcs:ignore
	}
	return $result;
}

/** WC_Product test double with meta. */
class WC_Product {
	/** @param array<string, mixed> $meta Meta. */
	public function __construct( private int $id = 100, public array $meta = array() ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_meta( string $key ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}
}

require_once __DIR__ . '/stubs/RouteException.php';
require_once FFL_BRIDGE_TEST_PLUGIN_DIR . 'includes/class-ffl-bridge-api-client.php';
require_once FFL_BRIDGE_TEST_PLUGIN_DIR . 'includes/class-ffl-bridge-network.php';
require_once FFL_BRIDGE_TEST_PLUGIN_DIR . 'includes/class-ffl-bridge-coverage.php';
require_once FFL_BRIDGE_TEST_PLUGIN_DIR . 'includes/class-ffl-bridge-followup.php';
require_once FFL_BRIDGE_TEST_PLUGIN_DIR . 'includes/class-ffl-bridge-transfer-confirmation.php';
if ( is_readable( FFL_BRIDGE_TEST_PLUGIN_DIR . 'includes/class-ffl-bridge-updater.php' ) ) {
	require_once FFL_BRIDGE_TEST_PLUGIN_DIR . 'includes/class-ffl-bridge-updater.php';
}
require_once FFL_BRIDGE_TEST_PLUGIN_DIR . 'includes/class-ffl-bridge-selection.php';
require_once FFL_BRIDGE_TEST_PLUGIN_DIR . 'includes/class-ffl-bridge-checkout.php';
require_once FFL_BRIDGE_TEST_PLUGIN_DIR . 'includes/class-ffl-bridge-order.php';
require_once FFL_BRIDGE_TEST_PLUGIN_DIR . 'includes/class-ffl-bridge-settings.php';
// Any other runtime class, except the Checkout Block integration, which needs
// WooCommerce Blocks interfaces.
foreach ( glob( FFL_BRIDGE_TEST_PLUGIN_DIR . 'includes/class-ffl-bridge-*.php' ) ?: array() as $ffl_bridge_class_file ) {
	if ( ! str_ends_with( $ffl_bridge_class_file, 'class-ffl-bridge-blocks-integration.php' ) ) {
		require_once $ffl_bridge_class_file;
	}
}
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/DealerFixtures.php';

function esc_attr__( string $text, string $domain = '' ): string {
	return esc_attr( $text );
}

/** @param array<string, array<string, bool>> $allowed Allowed tags. */
function wp_kses( string $html, array $allowed ): string {
	return strip_tags( $html, array_keys( $allowed ) );
}

/** WC_Customer loaded by ID: the customer's saved account data. */
class WC_Customer extends FFL_Bridge_Test_Customer {
	public function __construct( public int $id = 0 ) {
		$this->shipping = $GLOBALS['ffl_bridge_test_accounts'][ $id ]['shipping'] ?? array();
	}

	public function save(): void {
		parent::save();
		$GLOBALS['ffl_bridge_test_accounts'][ $this->id ]['shipping'] = $this->shipping;
	}
}

function did_action( string $hook ): int {
	return (int) ( $GLOBALS['ffl_bridge_test_did_actions'][ $hook ] ?? 0 );
}

function woocommerce_store_api_register_update_callback( array $args ): bool {
	$GLOBALS['ffl_bridge_test_update_callbacks'][] = $args;
	return true;
}
