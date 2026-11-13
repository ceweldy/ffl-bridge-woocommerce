<?php
/**
 * Optional dealer notice: when the store turns it on, the plugin asks
 * FFL Bridge (POST /api/v1/orders) to email the selected dealer. When it is
 * off, nothing about the shopper leaves the site.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

final class DealerNoticeTest extends FFL_Bridge_TestCase {
	private const KEY       = 'ffl_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
	private const DEALER_ID = '123e4567-e89b-12d3-a456-426614174000';
	private const LICENSE   = '1-23-456-78-9A-01234';
	private const OPTION    = 'ffl_bridge_send_dealer_email';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_api_key']  = self::KEY;
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_required'] = 'yes';

		// Dealer lookups are cached for the request; each test is a new request.
		foreach ( array( 'verification_cache', 'notices_handled' ) as $property ) {
			( new ReflectionProperty( FFL_Bridge_Checkout::class, $property ) )->setValue( null, array() );
		}
	}

	public function test_dealer_notice_is_off_by_default(): void {
		$this->assertFalse( FFL_Bridge_Checkout::dealer_notice_enabled() );

		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'no';
		$this->assertFalse( FFL_Bridge_Checkout::dealer_notice_enabled() );

		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$this->assertTrue( FFL_Bridge_Checkout::dealer_notice_enabled() );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function garbage(): array {
		return array(
			'empty'      => array( '' ),
			'null'       => array( null ),
			'true'       => array( true ),
			'one'        => array( 1 ),
			'string one' => array( '1' ),
			'on'         => array( 'on' ),
			'YES'        => array( 'YES' ),
			'yes space'  => array( ' yes' ),
			'array'      => array( array( 'yes' ) ),
		);
	}

	#[DataProvider( 'garbage' )]
	public function test_settings_sanitizer_never_turns_garbage_on( mixed $input ): void {
		$this->assertSame( 'no', FFL_Bridge_Settings::sanitize_dealer_notice( $input ) );

		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = $input;
		$this->assertFalse( FFL_Bridge_Checkout::dealer_notice_enabled() );
	}

	public function test_settings_sanitizer_keeps_yes_and_no(): void {
		$this->assertSame( 'yes', FFL_Bridge_Settings::sanitize_dealer_notice( 'yes' ) );
		$this->assertSame( 'no', FFL_Bridge_Settings::sanitize_dealer_notice( 'no' ) );
	}

	public function test_both_checkouts_send_the_notice_after_the_order_is_saved(): void {
		FFL_Bridge_Checkout::init();

		$this->assertContains( array( FFL_Bridge_Checkout::class, 'notify_dealer_classic' ), $GLOBALS['ffl_bridge_test_hooks']['woocommerce_checkout_order_processed'] ?? array() );
		$this->assertContains( array( FFL_Bridge_Checkout::class, 'notify_dealer' ), $GLOBALS['ffl_bridge_test_hooks']['woocommerce_store_api_checkout_order_processed'] ?? array() );
	}

	public function test_off_store_api_checkout_sends_no_order_request_and_no_shopper_data(): void {
		$order = $this->shopper_order();
		$this->select_dealer();

		FFL_Bridge_Checkout::save_store_api_order( $order );
		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertSame( self::DEALER_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
		$this->assertCount( 1, $GLOBALS['ffl_bridge_test_http'], 'Only the dealer lookup may be sent.' );
		$this->assertNoOrderRequest();
		$this->assertNoShopperData();
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	public function test_off_classic_checkout_sends_no_order_request(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'no';
		$order = $this->shopper_order();
		$this->select_dealer();

		FFL_Bridge_Checkout::save_classic_order( $order );
		FFL_Bridge_Checkout::notify_dealer_classic( $order->get_id(), array(), $order );

		$this->assertSame( self::DEALER_ID, $order->get_meta( '_ffl_bridge_dealer_id' ) );
		$this->assertNoOrderRequest();
		$this->assertNoShopperData();
	}

	public function test_on_store_api_checkout_posts_one_order_with_the_dealer_and_shopper(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order();
		$this->select_dealer();

		FFL_Bridge_Checkout::save_store_api_order( $order );
		$this->assertNoOrderRequest( 'The notice must wait until the order is processed.' );

		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, array( 'success' => true, 'data' => array( 'id' => 'ord_1' ) ) );
		FFL_Bridge_Checkout::notify_dealer( $order );

		$requests = $this->order_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( 'https://www.fflbridge.com/api/v1/orders', $requests[0]['url'] );
		$this->assertSame( 'Bearer ' . self::KEY, $requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $requests[0]['args']['headers']['Content-Type'] );
		$this->assertLessThanOrEqual( 6, $requests[0]['args']['timeout'] );
		$this->assertSame(
			array(
				'fflId'             => self::DEALER_ID,
				'customerName'      => 'Pat Shopper',
				'customerEmail'     => 'pat@example.test',
				'externalOrderId'   => '501',
				'sendFflEmail'      => true,
				'sendCustomerEmail' => false,
			),
			json_decode( $requests[0]['args']['body'], true )
		);
		$this->assertStringNotContainsString( '555', $requests[0]['args']['body'], 'The shopper phone is not needed for the notice.' );
		$this->assertSame( 'sent', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertGreaterThan( 0, $order->saves );
	}

	public function test_real_api_body_with_a_failed_dealer_email_is_recorded_as_failed(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order();
		$this->select_dealer();
		FFL_Bridge_Checkout::save_store_api_order( $order );

		// The 201 body observed on the E2E preview: no data wrapper, no
		// dealerNotice, and an fflEmail that reports a failure.
		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response(
			201,
			array(
				'success' => true,
				'order'   => array(
					'orderNumber'     => 'ORD-QW7VZYHJQW',
					'externalOrderId' => '501',
				),
				'ffl'     => array( 'id' => self::DEALER_ID ),
				'emails'  => array(
					'customerEmail' => null,
					'fflEmail'      => array( 'success' => false ),
				),
			)
		);
		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertCount( 1, $this->order_requests() );
		$this->assertSame( 'failed', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( 'FFL Bridge could not email the selected dealer. The order is not affected. Contact the dealer directly if needed.', end( $order->notes ) );
	}

	public function test_on_classic_checkout_posts_one_order(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order();
		$this->select_dealer();

		FFL_Bridge_Checkout::save_classic_order( $order );
		$this->assertNoOrderRequest( 'The classic order has no ID yet when its metadata is saved.' );

		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, array( 'success' => true, 'data' => array( 'id' => 'ord_1' ) ) );
		FFL_Bridge_Checkout::notify_dealer_classic( $order->get_id(), array(), $order );

		$requests = $this->order_requests();
		$this->assertCount( 1, $requests );
		$body = json_decode( $requests[0]['args']['body'], true );
		$this->assertTrue( $body['sendFflEmail'] );
		$this->assertFalse( $body['sendCustomerEmail'] );
		$this->assertSame( self::DEALER_ID, $body['fflId'] );
		$this->assertSame( '501', $body['externalOrderId'] );
		$this->assertSame( 'sent', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	public function test_notice_is_sent_once_even_if_the_order_is_processed_again(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order();
		$this->select_dealer();
		FFL_Bridge_Checkout::save_store_api_order( $order );

		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, array( 'success' => true ) );
		FFL_Bridge_Checkout::notify_dealer( $order );
		FFL_Bridge_Checkout::notify_dealer( $order );
		FFL_Bridge_Checkout::save_store_api_order( $order );
		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertCount( 1, $this->order_requests() );
	}

	public function test_a_second_hook_with_a_stale_copy_of_the_order_does_not_send_again(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$meta  = array( '_ffl_bridge_dealer_id' => self::DEALER_ID, '_ffl_bridge_dealer_notice' => 'queued' );
		$first = $this->shopper_order( $meta );
		$stale = $this->shopper_order( $meta );

		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, array( 'success' => true ) );
		FFL_Bridge_Checkout::notify_dealer( $first );
		FFL_Bridge_Checkout::notify_dealer_classic( $stale->get_id(), array(), $stale );

		$this->assertCount( 1, $this->order_requests() );
	}

	public function test_turning_the_setting_off_before_processing_cancels_a_queued_notice(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order();
		$this->select_dealer();
		FFL_Bridge_Checkout::save_store_api_order( $order );

		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'no';
		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertNoOrderRequest();
	}

	public function test_an_order_without_a_checkout_selection_is_never_notified(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order( array( '_ffl_bridge_dealer_id' => self::DEALER_ID ) );

		FFL_Bridge_Checkout::notify_dealer( $order );
		FFL_Bridge_Checkout::notify_dealer_classic( $order->get_id(), array(), $order );

		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
	}

	public function test_an_optional_checkout_without_a_dealer_is_never_notified(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ]        = 'yes';
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_required'] = 'no';
		$order = $this->shopper_order();

		FFL_Bridge_Checkout::save_store_api_order( $order );
		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null}>
	 */
	public static function failures(): array {
		return array(
			'transport error or timeout' => array( null ),
			'400 validation error'       => array( array( 'status' => 400, 'body' => array( 'success' => false, 'error' => array( 'code' => 'VALIDATION_ERROR' ) ) ) ),
			'401 rejected key'           => array( array( 'status' => 401, 'body' => array( 'success' => false ) ) ),
			'404 no endpoint'            => array( array( 'status' => 404, 'body' => array( 'success' => false ) ) ),
			'500 server error'           => array( array( 'status' => 500, 'body' => array( 'success' => false ) ) ),
			'200 without success'        => array( array( 'status' => 200, 'body' => array( 'success' => false ) ) ),
		);
	}

	/**
	 * @param array{status: int, body: array<string, mixed>}|null $failure Queued response, or null for a WP_Error.
	 */
	#[DataProvider( 'failures' )]
	public function test_a_failed_notice_is_logged_and_never_fails_checkout( ?array $failure ): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order();
		$this->select_dealer();
		FFL_Bridge_Checkout::save_store_api_order( $order );

		$GLOBALS['ffl_bridge_test_http_responses'][] = null === $failure
			? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' )
			: $this->response( $failure['status'], $failure['body'] );
		$GLOBALS['ffl_bridge_test_logs'] = array();

		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertCount( 1, $this->order_requests(), 'A POST is sent once and never retried.' );
		$this->assertSame( self::DEALER_ID, $order->get_meta( '_ffl_bridge_dealer_id' ), 'The dealer stays on the order.' );
		$this->assertSame( 'failed', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertNotSame( array(), $GLOBALS['ffl_bridge_test_logs'], 'The failure is written to the WooCommerce log.' );
		$this->assertNoShopperData();
	}

	public function test_a_throwing_notice_does_not_escape_the_store_api_hook(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		// An order whose data cannot be read stands in for any unexpected error.
		$order = new class( array( '_ffl_bridge_dealer_id' => self::DEALER_ID, '_ffl_bridge_dealer_notice' => 'queued' ) ) extends WC_Order {
			public function get_billing_email(): string {
				throw new RuntimeException( 'Unexpected failure.' );
			}
		};

		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertNoOrderRequest();
		$this->assertSame( 'failed', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	public function test_on_without_an_api_key_skips_the_notice_safely(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order( array( '_ffl_bridge_dealer_id' => self::DEALER_ID, '_ffl_bridge_dealer_notice' => 'queued' ) );
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_api_key'] = '';

		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
		$this->assertSame( 'skipped', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	public function test_on_without_a_dealer_id_skips_the_notice_safely(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order( array( '_ffl_bridge_dealer_notice' => 'queued' ) );

		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
		$this->assertSame( 'skipped', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	public function test_on_without_a_shopper_email_skips_the_notice_safely(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order( array( '_ffl_bridge_dealer_id' => self::DEALER_ID, '_ffl_bridge_dealer_notice' => 'queued' ) );
		$order->billing['email'] = 'not-an-email';

		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
		$this->assertSame( 'skipped', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	public function test_api_client_rejects_a_bad_dealer_id_without_a_request(): void {
		$result = FFL_Bridge_API_Client::create_order(
			array(
				'fflId'           => '../search',
				'customerName'    => 'Pat Shopper',
				'customerEmail'   => 'pat@example.test',
				'externalOrderId' => '501',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
	}

	public function test_api_client_always_asks_for_the_dealer_email_only(): void {
		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, array( 'success' => true, 'data' => array( 'id' => 'ord_1' ) ) );

		$result = FFL_Bridge_API_Client::create_order(
			array(
				'fflId'             => strtoupper( self::DEALER_ID ),
				'customerName'      => 'Pat Shopper',
				'customerEmail'     => 'pat@example.test',
				'externalOrderId'   => '501',
				'sendCustomerEmail' => true,
				'sendFflEmail'      => false,
				'billingAddress'    => '1 Main Street',
			)
		);

		$this->assertSame( array( 'id' => 'ord_1' ), $result );
		$body = json_decode( $GLOBALS['ffl_bridge_test_http'][0]['args']['body'], true );
		$this->assertSame( self::DEALER_ID, $body['fflId'] );
		$this->assertTrue( $body['sendFflEmail'] );
		$this->assertFalse( $body['sendCustomerEmail'] );
		$this->assertArrayNotHasKey( 'billingAddress', $body );
	}

	/**
	 * A WooCommerce order for a shopper with billing details.
	 *
	 * @param array<string, mixed> $meta Initial metadata.
	 */
	private function shopper_order( array $meta = array() ): WC_Order {
		$order          = new WC_Order( $meta );
		$order->billing = array(
			'first_name' => 'Pat',
			'last_name'  => 'Shopper',
			'email'      => 'pat@example.test',
			'phone'      => '555-555-0100',
		);

		return $order;
	}

	/**
	 * Put a verified dealer in the checkout session and queue its lookup.
	 */
	private function select_dealer(): void {
		WC()->session->set(
			'ffl_bridge_selection_v1',
			array(
				'dealer'     => array(
					'id'                => self::DEALER_ID,
					'license'           => self::LICENSE,
					'checkout_verified' => true,
				),
				'cart_hash'  => 'cart-hash-1',
				'expires_at' => time() + 3600,
			)
		);

		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response(
			200,
			array(
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
			)
		);
	}

	/** @return array<int, array{url: string, args: array<string, mixed>}> */
	private function order_requests(): array {
		return array_values(
			array_filter(
				$GLOBALS['ffl_bridge_test_http'],
				static fn ( array $request ): bool => str_contains( $request['url'], '/orders' )
			)
		);
	}

	private function assertNoOrderRequest( string $message = '' ): void {
		$this->assertSame( array(), $this->order_requests(), $message );
	}

	/**
	 * Nothing sent to FFL Bridge or written to the log may carry the shopper.
	 */
	private function assertNoShopperData(): void {
		$sent = (string) json_encode( array( $GLOBALS['ffl_bridge_test_http'], $GLOBALS['ffl_bridge_test_logs'] ) );
		foreach ( array( 'Pat', 'Shopper', 'pat@example.test', '555-0100' ) as $value ) {
			if ( array() === $this->order_requests() ) {
				$this->assertStringNotContainsString( $value, $sent );
			} else {
				$this->assertStringNotContainsString( $value, (string) json_encode( $GLOBALS['ffl_bridge_test_logs'] ) );
			}
		}
	}

	/**
	 * @param array<string, mixed> $body JSON body.
	 * @return array<string, mixed>
	 */
	private function response( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) json_encode( $body ),
		);
	}
}
