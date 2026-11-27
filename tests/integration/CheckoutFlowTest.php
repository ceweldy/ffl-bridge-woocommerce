<?php
/**
 * End-to-end checkout and order display against real WordPress and
 * WooCommerce: the Store API checkout without a dealer, child categories,
 * and the order-received page.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class CheckoutFlowTest extends FFL_Bridge_Integration_TestCase {
	private const KEY = 'ffl_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

	/** @var array<int, int> */
	private array $terms = array();

	/** @var array<int, int> */
	private array $posts = array();

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( 'ffl_bridge_api_key', self::KEY );
		$this->set_option( 'ffl_bridge_required', 'yes' );
		$this->set_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes' ) );
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 10, 3 );
	}

	protected function tearDown(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( 'ffl_bridge_selection_v1' );
		}
		foreach ( $this->posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( array_reverse( $this->terms ) as $term_id ) {
			wp_delete_term( $term_id, 'product_cat' );
		}
		remove_all_filters( 'woocommerce_store_api_disable_nonce_check' );
		$GLOBALS['wp']->query_vars = array();
		wp_reset_query();

		parent::tearDown();
	}

	/**
	 * Tests must never reach FFL Bridge. No request is expected here.
	 *
	 * @param false|array<string, mixed> $response Short-circuit value.
	 * @param array<string, mixed>       $args Request arguments.
	 * @param string                     $url Request URL.
	 * @return array<string, mixed>
	 */
	public function block_http( $response, $args, $url ): array {
		$this->fail( 'Unexpected HTTP request to ' . $url );
	}

	public function test_store_api_checkout_without_a_dealer_returns_400_with_the_message(): void {
		$product_id = $this->rifle_in_child_category();
		$this->set_option( 'ffl_bridge_categories', array( $this->terms[1] ) );
		$this->add_to_cart( $product_id );
		$this->assertTrue( FFL_Bridge_Checkout::selection_is_required() );

		$address  = array(
			'first_name' => 'Pat',
			'last_name'  => 'Shopper',
			'address_1'  => '1 Main Street',
			'city'       => 'Macomb',
			'state'      => 'MI',
			'postcode'   => '48047',
			'country'    => 'US',
			'email'      => 'pat@ffl-bridge.test',
			'phone'      => '5555550100',
		);
		$response = $this->store_api(
			'POST',
			'/wc/store/v1/checkout',
			array(
				'billing_address'  => $address,
				'shipping_address' => $address,
				'payment_method'   => 'cod',
			)
		);

		$data = $response->get_data();
		$this->assertSame( 400, $response->get_status(), (string) wp_json_encode( $data ) );
		$this->assertSame( 'ffl_bridge_selection_required', $data['code'] );
		$this->assertSame( 'Select and verify a transfer dealer before placing the order.', $data['message'] );
	}

	public function test_a_product_in_a_child_of_a_selected_category_needs_a_dealer(): void {
		$product_id = $this->rifle_in_child_category();
		$this->set_option( 'ffl_bridge_categories', array( $this->terms[0] ) );
		$this->add_to_cart( $product_id );

		$this->assertTrue( FFL_Bridge_Checkout::cart_requires_ffl(), 'A product in Firearms > Rifles must use the dealer workflow when Firearms is selected.' );
	}

	public function test_order_received_page_shows_the_dealer_block_once(): void {
		$order = $this->order_with_dealer();
		$this->view_order_received( $order );

		$html = $this->render( static fn () => wc_get_template( 'checkout/thankyou.php', array( 'order' => $order ) ) );

		$this->assertStringContainsString( 'woocommerce-order-details', $html, 'The order details table was not rendered.' );
		$this->assertSame( 1, substr_count( $html, 'Selected transfer dealer' ) );
	}

	public function test_my_account_order_view_shows_the_dealer_block(): void {
		$order = $this->order_with_dealer();

		$html = $this->render( static fn () => woocommerce_order_details_table( $order->get_id() ) );

		$this->assertSame( 1, substr_count( $html, 'Selected transfer dealer' ) );
	}

	private function rifle_in_child_category(): int {
		$parent        = wp_insert_term( 'Firearms ' . wp_generate_password( 6, false ), 'product_cat' );
		$child         = wp_insert_term( 'Rifles ' . wp_generate_password( 6, false ), 'product_cat', array( 'parent' => $parent['term_id'] ) );
		$this->terms[] = (int) $parent['term_id'];
		$this->terms[] = (int) $child['term_id'];

		$product = new WC_Product_Simple();
		$product->set_name( 'Test rifle' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '500' );
		$product->set_category_ids( array( (int) $child['term_id'] ) );
		$product_id    = $product->save();
		$this->posts[] = $product_id;

		return $product_id;
	}

	private function add_to_cart( int $product_id ): void {
		$added = $this->store_api( 'POST', '/wc/store/v1/cart/add-item', array( 'id' => $product_id, 'quantity' => 1 ) );
		$this->assertSame( 201, $added->get_status(), (string) wp_json_encode( $added->get_data() ) );
	}

	private function order_with_dealer(): WC_Order {
		$order = wc_create_order();
		$order->set_billing_email( 'pat@ffl-bridge.test' );
		foreach (
			array(
				'_ffl_bridge_dealer_id'       => '123e4567-e89b-12d3-a456-426614174000',
				'_ffl_bridge_license'         => '1-23-456-78-9A-01234',
				'_ffl_bridge_name'            => 'Example Arms',
				'_ffl_bridge_address'         => '123 Main Street',
				'_ffl_bridge_city'            => 'Orlando',
				'_ffl_bridge_state'           => 'FL',
				'_ffl_bridge_zip'             => '32801',
				'_ffl_bridge_transfer_status' => 'confirmed',
				'_ffl_bridge_selection_basis' => 'verified_network',
				'_ffl_bridge_source'          => 'ffl_bridge_api',
			) as $key => $value
		) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		$this->posts[] = $order->get_id();

		return $order;
	}

	/**
	 * Make is_order_received_page() true, as on /checkout/order-received/{id}/.
	 */
	private function view_order_received( WC_Order $order ): void {
		$checkout_id = wc_get_page_id( 'checkout' );
		if ( $checkout_id <= 0 ) {
			$checkout_id   = (int) wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => 'Checkout',
				)
			);
			$this->posts[] = $checkout_id;
			$this->set_option( 'woocommerce_checkout_page_id', $checkout_id );
		}

		$GLOBALS['wp_query']                         = new WP_Query( array( 'page_id' => $checkout_id ) );
		$GLOBALS['wp_the_query']                     = $GLOBALS['wp_query'];
		$GLOBALS['wp']->query_vars['order-received'] = (string) $order->get_id();
		$this->assertTrue( is_order_received_page() );
	}

	/**
	 * @param array<string, mixed> $body JSON body.
	 */
	private function store_api( string $method, string $route, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}
}
