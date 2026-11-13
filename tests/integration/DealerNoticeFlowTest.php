<?php
/**
 * Optional dealer notice against real WordPress and WooCommerce: a Store API
 * checkout with a verified dealer sends POST /api/v1/orders only when the
 * store turned the setting on, and a failed notice never fails checkout.
 * No request leaves the test process.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class DealerNoticeFlowTest extends FFL_Bridge_Integration_TestCase {
	private const KEY       = 'ffl_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
	private const DEALER_ID = '123e4567-e89b-12d3-a456-426614174000';
	private const LICENSE   = '1-23-456-78-9A-01234';

	/** @var array<int, array{url: string, args: array<string, mixed>}> */
	private array $requests = array();

	/** HTTP status the mocked POST /orders answers with; 0 is a transport error. */
	private int $orders_status = 201;

	/** @var array<int, int> */
	private array $posts = array();

	/**
	 * Optional dealerNotice field in the mocked 201 body.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $dealer_notice = null;

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( 'ffl_bridge_api_key', self::KEY );
		$this->set_option( 'ffl_bridge_required', 'yes' );
		$this->set_option( 'ffl_bridge_categories', array() );
		$this->set_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes' ) );
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	protected function tearDown(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( 'ffl_bridge_selection_v1' );
		}
		foreach ( $this->posts as $post_id ) {
			$order = wc_get_order( $post_id );
			if ( $order ) {
				$order->delete( true );
			} else {
				wp_delete_post( $post_id, true );
			}
		}
		remove_all_filters( 'woocommerce_store_api_disable_nonce_check' );

		parent::tearDown();
	}

	/**
	 * Answer FFL Bridge requests in process and record them.
	 *
	 * @param false|array<string, mixed> $response Short-circuit value.
	 * @param array<string, mixed>       $args Request arguments.
	 * @param string                     $url Request URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public function mock_http( $response, $args, $url ): array|WP_Error {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( 'https://www.fflbridge.com/api/v1/ffls/' . self::DEALER_ID === $url ) {
			return $this->json( 200, $this->dealer_body() );
		}

		if ( 'https://www.fflbridge.com/api/v1/orders' === $url ) {
			return 0 === $this->orders_status
				? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' )
				: $this->json( $this->orders_status, $this->orders_body() );
		}

		$this->fail( 'Unexpected HTTP request to ' . $url );
	}

	public function test_setting_is_registered_and_off_by_default(): void {
		delete_option( 'ffl_bridge_send_dealer_email' );

		$this->assertSame( 'no', get_option( 'ffl_bridge_send_dealer_email' ) );
		$this->assertFalse( FFL_Bridge_Checkout::dealer_notice_enabled() );
		update_option( 'ffl_bridge_send_dealer_email', 'on' );
		$this->assertSame( 'no', get_option( 'ffl_bridge_send_dealer_email' ), 'Garbage is sanitized to off.' );
		delete_option( 'ffl_bridge_send_dealer_email' );
	}

	public function test_off_checkout_saves_the_dealer_and_sends_no_order_request(): void {
		$this->set_option( 'ffl_bridge_send_dealer_email', 'no' );

		$order = $this->checkout();

		$this->assertSame( self::DEALER_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( array(), $this->order_requests() );
		$this->assertStringNotContainsString( 'pat@ffl-bridge.test', (string) wp_json_encode( $this->requests ) );
	}

	public function test_on_checkout_posts_one_order_for_the_dealer(): void {
		$this->set_option( 'ffl_bridge_send_dealer_email', 'yes' );
		$this->dealer_notice = array( 'status' => 'sent', 'reason' => null, 'logId' => '0b6f1f8e-6d0a-4c43-9a43-3f2c1b7d9e10', 'retryable' => false );

		$order = $this->checkout();

		$requests = $this->order_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( 'POST', $requests[0]['args']['method'] );
		$this->assertSame( 6, (int) $requests[0]['args']['timeout'] );
		$this->assertSame(
			array(
				'fflId'             => self::DEALER_ID,
				'customerName'      => 'Pat Shopper',
				'customerEmail'     => 'pat@ffl-bridge.test',
				'externalOrderId'   => (string) $order->get_order_number(),
				'sendFflEmail'      => true,
				'sendCustomerEmail' => false,
			),
			json_decode( (string) $requests[0]['args']['body'], true )
		);
		$this->assertSame( 'sent', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( 'ORD-QW7VZYHJQW', $order->get_meta( '_ffl_bridge_dealer_notice_order' ) );
		$this->assertSame( '0b6f1f8e-6d0a-4c43-9a43-3f2c1b7d9e10', $order->get_meta( '_ffl_bridge_dealer_notice_log_id' ) );

		// Re-firing either processed hook never creates a second FFL Bridge order.
		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order->get_id() ) );
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), wc_get_order( $order->get_id() ) );
		$this->assertCount( 1, $this->order_requests() );
	}

	public function test_on_checkout_with_a_failed_dealer_email_is_recorded_as_failed(): void {
		$this->set_option( 'ffl_bridge_send_dealer_email', 'yes' );
		$this->dealer_notice = null;

		$order = $this->checkout();

		$this->assertCount( 1, $this->order_requests() );
		$this->assertSame( 'failed', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertContains( 'FFL Bridge could not email the selected dealer. The order is not affected. Contact the dealer directly if needed.', $this->order_notes( $order ) );
	}

	public function test_on_checkout_with_a_queued_dealer_email_is_not_recorded_as_sent(): void {
		$this->set_option( 'ffl_bridge_send_dealer_email', 'yes' );
		$this->dealer_notice = array( 'status' => 'queued', 'reason' => 'MAIL_GATEWAY_REVIEW_REQUIRED', 'logId' => null, 'retryable' => false );

		$order = $this->checkout();

		$this->assertSame( 'queued', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( 'MAIL_GATEWAY_REVIEW_REQUIRED', $order->get_meta( '_ffl_bridge_dealer_notice_reason' ) );

		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order->get_id() ) );
		$this->assertCount( 1, $this->order_requests() );
	}

	public function test_on_checkout_with_a_failed_notice_still_places_the_order(): void {
		$this->set_option( 'ffl_bridge_send_dealer_email', 'yes' );
		$this->orders_status = 0;

		$order = $this->checkout();

		$this->assertCount( 1, $this->order_requests() );
		$this->assertSame( self::DEALER_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
		$this->assertSame( 'failed', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	/**
	 * Add a product, select the dealer, and place a Store API checkout.
	 */
	private function checkout(): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name( 'Test rifle' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '500' );
		$product_id    = $product->save();
		$this->posts[] = $product_id;

		$added = $this->store_api( 'POST', '/wc/store/v1/cart/add-item', array( 'id' => $product_id, 'quantity' => 1 ) );
		$this->assertSame( 201, $added->get_status(), (string) wp_json_encode( $added->get_data() ) );

		$address = array(
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
		$updated = $this->store_api(
			'POST',
			'/wc/store/v1/cart/update-customer',
			array(
				'billing_address'  => $address,
				'shipping_address' => $address,
			)
		);
		$this->assertSame( 200, $updated->get_status(), (string) wp_json_encode( $updated->get_data() ) );

		// Same session shape the select AJAX handler stores after verification.
		WC()->cart->calculate_totals();
		WC()->session->set(
			'ffl_bridge_selection_v1',
			array(
				'dealer'     => array(
					'id'                => self::DEALER_ID,
					'license'           => self::LICENSE,
					'checkout_verified' => true,
				),
				'cart_hash'  => WC()->cart->get_cart_hash(),
				'expires_at' => time() + 3600,
			)
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
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $data ) );

		$order = wc_get_order( (int) $data['order_id'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->posts[] = $order->get_id();

		return $order;
	}

	/** @return array<int, array{url: string, args: array<string, mixed>}> */
	private function order_requests(): array {
		return array_values(
			array_filter(
				$this->requests,
				static fn ( array $request ): bool => str_ends_with( $request['url'], '/api/v1/orders' )
			)
		);
	}

	/**
	 * POST /orders body in the real API shape: no data wrapper, and a failed
	 * fflEmail unless a dealerNotice is set.
	 *
	 * @return array<string, mixed>
	 */
	private function orders_body(): array {
		if ( $this->orders_status >= 300 ) {
			return array( 'success' => false );
		}

		$body = array(
			'success' => true,
			'order'   => array(
				'orderNumber'     => 'ORD-QW7VZYHJQW',
				'externalOrderId' => '1',
			),
			'ffl'     => array( 'id' => self::DEALER_ID ),
			'emails'  => array(
				'customerEmail' => null,
				'fflEmail'      => array( 'success' => null === $this->dealer_notice ? false : true ),
			),
		);
		if ( null !== $this->dealer_notice ) {
			$body['dealerNotice'] = $this->dealer_notice;
		}

		return $body;
	}

	/**
	 * @return array<int, string>
	 */
	private function order_notes( WC_Order $order ): array {
		return array_map( static fn ( $note ): string => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/** @return array<string, mixed> */
	private function dealer_body(): array {
		return array(
			'success' => true,
			'data'    => array(
				'dealer'      => array(
					'id'               => self::DEALER_ID,
					'licenseNumber'    => self::LICENSE,
					'tradeName'        => 'Example Arms',
					'address'          => '123 Main Street',
					'city'             => 'Orlando',
					'state'            => 'FL',
					'zip'              => '32801',
					'acceptsTransfers' => true,
				),
				'eligibility' => array(
					'selectable'       => true,
					'licenseVerified'  => true,
					'licenseOnFile'    => true,
					'acceptsTransfers' => true,
					'isActive'         => true,
					'isAtfListed'      => true,
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $body JSON body.
	 * @return array<string, mixed>
	 */
	private function json( int $status, array $body ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
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
