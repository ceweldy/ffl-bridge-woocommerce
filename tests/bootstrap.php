<?php
/**
 * Lightweight WordPress and WooCommerce test doubles.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'FFL_BRIDGE_VERSION', '1.1.0-test' );

$GLOBALS['ffl_bridge_test_options']    = array();
$GLOBALS['ffl_bridge_test_term_map']   = array();
$GLOBALS['ffl_bridge_test_wc']         = null;
$GLOBALS['ffl_bridge_test_home_url']   = 'https://store.example.test/';
$GLOBALS['ffl_bridge_test_transients'] = array();

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
	/** @param array<string, mixed> $meta Initial metadata. */
	public function __construct( private array $meta = array() ) {}

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

	public function add_order_note( string $note ): void {}

	public function save(): void {}
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

function sanitize_text_field( mixed $value ): string {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$value = strip_tags( (string) $value );
	$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );
	return trim( (string) $value );
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
require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-selection.php';
require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-checkout.php';
require_once dirname( __DIR__ ) . '/includes/class-ffl-bridge-order.php';
require_once __DIR__ . '/TestCase.php';
