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
	private const LOG_ID    = '0b6f1f8e-6d0a-4c43-9a43-3f2c1b7d9e10';
	private const ORDER_NO  = 'ORD-QW7VZYHJQW';

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

		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, $this->order_body( array( 'status' => 'sent', 'reason' => null, 'logId' => self::LOG_ID, 'retryable' => false ) ) );
		FFL_Bridge_Checkout::notify_dealer( $order );

		$requests = $this->order_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( 'https://www.fflbridge.com/api/v1/orders', $requests[0]['url'] );
		$this->assertSame( 'Bearer ' . self::KEY, $requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $requests[0]['args']['headers']['Content-Type'] );
		$this->assertSame( 20, $requests[0]['args']['timeout'], 'FFL Bridge sends the dealer email before it answers.' );
		$this->assertSame(
			array(
				'fflId'             => self::DEALER_ID,
				'customerName'      => 'Pat Shopper',
				'customerEmail'     => 'pat@example.test',
				'externalOrderId'   => '501',
				'sendFflEmail'      => true,
				'sendCustomerEmail' => false,
				'orderKey'          => 'wc1-' . substr( hash( 'sha256', 'https://store.example.test/|501|2026-11-13T12:00:00+00:00' ), 0, 40 ),
			),
			json_decode( $requests[0]['args']['body'], true )
		);
		$this->assertStringNotContainsString( '555', $requests[0]['args']['body'], 'The shopper phone is not needed for the notice.' );
		$this->assertSame( 'sent', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( self::ORDER_NO, $order->get_meta( '_ffl_bridge_dealer_notice_order' ) );
		$this->assertSame( 'FFL Bridge emailed the selected dealer about this order.', end( $order->notes ) );
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

		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, $this->order_body( array( 'status' => 'sent', 'reason' => null, 'logId' => self::LOG_ID, 'retryable' => false ) ) );
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

		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, $this->order_body() );
		FFL_Bridge_Checkout::notify_dealer( $order );
		FFL_Bridge_Checkout::notify_dealer( $order );
		FFL_Bridge_Checkout::save_store_api_order( $order );
		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertCount( 1, $this->order_requests() );
	}

	public function test_a_second_hook_with_a_stale_copy_of_the_order_does_not_send_again(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$meta  = array( '_ffl_bridge_dealer_id' => self::DEALER_ID, '_ffl_bridge_dealer_notice' => 'pending' );
		$first = $this->shopper_order( $meta );
		$stale = $this->shopper_order( $meta );

		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, $this->order_body() );
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
			'connection refused'         => array( null ),
			'dns failure'                => array( array( 'error' => 'cURL error 6: Could not resolve host: www.fflbridge.com' ) ),
			'connect timeout'            => array( array( 'error' => 'cURL error 28: Connection timed out after 6001 milliseconds' ) ),
			'tls failure'                => array( array( 'error' => 'cURL error 60: SSL certificate problem' ) ),
			'400 validation error'       => array( array( 'status' => 400, 'body' => array( 'success' => false, 'error' => array( 'code' => 'VALIDATION_ERROR' ) ) ) ),
			'401 rejected key'           => array( array( 'status' => 401, 'body' => array( 'success' => false ) ) ),
			'404 no endpoint'            => array( array( 'status' => 404, 'body' => array( 'success' => false ) ) ),
			'500 server error'           => array( array( 'status' => 500, 'body' => array( 'success' => false ) ) ),
			'200 without success'        => array( array( 'status' => 200, 'body' => array( 'success' => false ) ) ),
		);
	}

	/**
	 * @param array{status?: int, body?: array<string, mixed>, error?: string}|null $failure Queued response, a transport error message, or null for a refused connection.
	 */
	#[DataProvider( 'failures' )]
	public function test_a_failed_notice_is_logged_and_never_fails_checkout( ?array $failure ): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order();
		$this->select_dealer();
		FFL_Bridge_Checkout::save_store_api_order( $order );

		if ( null === $failure ) {
			$failure = array( 'error' => 'cURL error 7: Failed to connect to www.fflbridge.com port 443: Connection refused' );
		}
		$GLOBALS['ffl_bridge_test_http_responses'][] = isset( $failure['error'] )
			? new WP_Error( 'http_request_failed', $failure['error'] )
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
		$order = new class( array( '_ffl_bridge_dealer_id' => self::DEALER_ID, '_ffl_bridge_dealer_notice' => 'pending' ) ) extends WC_Order {
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
		$order = $this->shopper_order( array( '_ffl_bridge_dealer_id' => self::DEALER_ID, '_ffl_bridge_dealer_notice' => 'pending' ) );
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_api_key'] = '';

		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
		$this->assertSame( 'skipped', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	public function test_on_without_a_dealer_id_skips_the_notice_safely(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order( array( '_ffl_bridge_dealer_notice' => 'pending' ) );

		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
		$this->assertSame( 'skipped', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	public function test_on_without_a_shopper_email_skips_the_notice_safely(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order( array( '_ffl_bridge_dealer_id' => self::DEALER_ID, '_ffl_bridge_dealer_notice' => 'pending' ) );
		$order->billing['email'] = 'not-an-email';

		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
		$this->assertSame( 'skipped', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string, 4: string}>
	 */
	public static function api_notice_outcomes(): array {
		$log = self::LOG_ID;
		return array(
			'sent'                    => array( array( 'status' => 'sent', 'reason' => null, 'logId' => $log, 'retryable' => false ), 'sent', '', $log, 'FFL Bridge emailed the selected dealer about this order.' ),
			// already_sent always means FFL Bridge matched a different order with the
			// same number (this plugin never retries), so it is a skip, whatever the
			// status, and the other order's log ID is never stored.
			'sent, already sent'      => array( array( 'status' => 'sent', 'reason' => 'already_sent', 'logId' => $log, 'retryable' => false ), 'skipped', 'already_sent', '', 'FFL Bridge did not email the dealer about this order: it already emailed this dealer about an earlier order with the same order number. Contact the dealer directly if this is a different order.' ),
			'queued'                  => array( array( 'status' => 'queued', 'reason' => 'MAIL_GATEWAY_REVIEW_REQUIRED', 'logId' => $log, 'retryable' => false ), 'queued', 'MAIL_GATEWAY_REVIEW_REQUIRED', $log, 'FFL Bridge accepted the dealer email but is holding it for review, so it has not been confirmed as sent. Contact the dealer directly if needed.' ),
			'queued, already sent'    => array( array( 'status' => 'queued', 'reason' => 'already_sent', 'logId' => $log, 'retryable' => false ), 'skipped', 'already_sent', '', 'FFL Bridge did not email the dealer about this order: it already emailed this dealer about an earlier order with the same order number. Contact the dealer directly if this is a different order.' ),
			'failed'                  => array( array( 'status' => 'failed', 'reason' => 'MAIL_GATEWAY_NOT_CONFIGURED', 'logId' => $log, 'retryable' => true ), 'failed', 'MAIL_GATEWAY_NOT_CONFIGURED', $log, 'FFL Bridge could not email the selected dealer. The order is not affected. Contact the dealer directly if needed.' ),
			'skipped, opted out'      => array( array( 'status' => 'skipped', 'reason' => 'email_opted_out', 'logId' => null, 'retryable' => false ), 'skipped', 'email_opted_out', '', 'FFL Bridge did not email the dealer: the dealer opted out of these emails. Contact the dealer directly if needed.' ),
			'skipped, listing hidden' => array( array( 'status' => 'skipped', 'reason' => 'listing_opted_out', 'logId' => null, 'retryable' => false ), 'skipped', 'listing_opted_out', '', 'FFL Bridge did not email the dealer: the dealer hid its listing. Contact the dealer directly if needed.' ),
			'skipped, no email'       => array( array( 'status' => 'skipped', 'reason' => 'no_email_on_file', 'logId' => null, 'retryable' => false ), 'skipped', 'no_email_on_file', '', 'FFL Bridge did not email the dealer: no dealer email is on file. Contact the dealer directly if needed.' ),
			'skipped, suppressed'     => array( array( 'status' => 'skipped', 'reason' => 'email_suppressed', 'logId' => null, 'retryable' => false ), 'skipped', 'email_suppressed', '', "FFL Bridge did not email the dealer: the dealer's email address is suppressed. Contact the dealer directly if needed." ),
			'skipped, disabled'       => array( array( 'status' => 'skipped', 'reason' => 'disabled', 'logId' => null, 'retryable' => false ), 'skipped', 'disabled', '', 'FFL Bridge did not email the dealer: dealer emails are turned off at FFL Bridge. Contact the dealer directly if needed.' ),
			'skipped, no address'     => array( array( 'status' => 'skipped', 'reason' => 'company_address_missing', 'logId' => null, 'retryable' => false ), 'skipped', 'company_address_missing', '', 'FFL Bridge did not email the dealer: FFL Bridge could not prepare the email. Contact the dealer directly if needed.' ),
			'skipped, no links'       => array( array( 'status' => 'skipped', 'reason' => 'preference_links_unavailable', 'logId' => null, 'retryable' => false ), 'skipped', 'preference_links_unavailable', '', 'FFL Bridge did not email the dealer: FFL Bridge could not prepare the email. Contact the dealer directly if needed.' ),
			'skipped, not requested'  => array( array( 'status' => 'skipped', 'reason' => 'not_requested', 'logId' => null, 'retryable' => false ), 'skipped', 'not_requested', '', 'FFL Bridge did not email the dealer: the email was not requested. Contact the dealer directly if needed.' ),
			'skipped, unknown reason' => array( array( 'status' => 'skipped', 'reason' => 'something_new', 'logId' => null, 'retryable' => false ), 'skipped', 'something_new', '', 'FFL Bridge did not email the dealer: it was skipped. Contact the dealer directly if needed.' ),
			'skipped, already sent'   => array( array( 'status' => 'skipped', 'reason' => 'already_sent', 'logId' => $log, 'retryable' => false ), 'skipped', 'already_sent', '', 'FFL Bridge did not email the dealer about this order: it already emailed this dealer about an earlier order with the same order number. Contact the dealer directly if this is a different order.' ),
		);
	}

	/**
	 * @param array<string, mixed> $notice dealerNotice field.
	 */
	#[DataProvider( 'api_notice_outcomes' )]
	public function test_dealer_notice_status_from_the_api_is_recorded( array $notice, string $status, string $reason, string $log_id, string $note ): void {
		$order = $this->process_order( $this->order_body( $notice ) );

		$this->assertCount( 1, $this->order_requests() );
		$this->assertSame( $status, $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( $reason, $order->get_meta( '_ffl_bridge_dealer_notice_reason' ) );
		$this->assertSame( $log_id, $order->get_meta( '_ffl_bridge_dealer_notice_log_id' ) );
		$this->assertSame( self::ORDER_NO, $order->get_meta( '_ffl_bridge_dealer_notice_order' ) );
		$this->assertSame( $note, end( $order->notes ) );
		if ( in_array( $status, array( 'failed', 'queued' ), true ) ) {
			$this->assertStringContainsString( $reason, (string) json_encode( $GLOBALS['ffl_bridge_test_logs'] ) );
		}
		$this->assertNoShopperData();
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string}>
	 */
	public static function legacy_bodies(): array {
		$order = array( 'orderNumber' => self::ORDER_NO, 'externalOrderId' => '501' );
		return array(
			'fflEmail success'       => array( array( 'success' => true, 'order' => $order, 'emails' => array( 'fflEmail' => array( 'success' => true, 'messageId' => 'msg_1' ) ) ), 'requested', '', 'FFL Bridge was asked to email the selected dealer. Delivery was not confirmed.' ),
			'fflEmail skipped'       => array( array( 'success' => true, 'order' => $order, 'emails' => array( 'fflEmail' => array( 'success' => false, 'skipped' => true, 'reason' => 'no_email_on_file' ) ) ), 'skipped', 'no_email_on_file', 'FFL Bridge did not email the dealer: no dealer email is on file. Contact the dealer directly if needed.' ),
			'fflEmail already sent'  => array( array( 'success' => true, 'order' => $order, 'emails' => array( 'fflEmail' => array( 'success' => false, 'skipped' => true, 'reason' => 'already_sent' ) ) ), 'skipped', 'already_sent', 'FFL Bridge did not email the dealer about this order: it already emailed this dealer about an earlier order with the same order number. Contact the dealer directly if this is a different order.' ),
			'fflEmail failed'        => array( array( 'success' => true, 'order' => $order, 'emails' => array( 'customerEmail' => null, 'fflEmail' => array( 'success' => false ) ) ), 'failed', '', 'FFL Bridge could not email the selected dealer. The order is not affected. Contact the dealer directly if needed.' ),
			'no emails'              => array( array( 'success' => true, 'order' => $order ), 'requested', '', 'FFL Bridge was asked to email the selected dealer. Delivery was not confirmed.' ),
			'unknown notice status'  => array( array( 'success' => true, 'order' => $order, 'dealerNotice' => array( 'status' => 'delivered' ) ), 'requested', '', 'FFL Bridge was asked to email the selected dealer. Delivery was not confirmed.' ),
			'old data wrapper'       => array( array( 'success' => true, 'data' => array( 'id' => 'ord_1', 'dealerNotice' => array( 'status' => 'sent' ) ) ), 'requested', '', 'FFL Bridge was asked to email the selected dealer. Delivery was not confirmed.' ),
		);
	}

	/**
	 * @param array<string, mixed> $body Response body without dealerNotice.
	 */
	#[DataProvider( 'legacy_bodies' )]
	public function test_responses_without_dealer_notice_are_never_recorded_as_sent( array $body, string $status, string $reason, string $note ): void {
		$order = $this->process_order( $body );

		$this->assertSame( $status, $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertNotSame( 'sent', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( $reason, $order->get_meta( '_ffl_bridge_dealer_notice_reason' ) );
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_notice_log_id' ) );
		$this->assertSame( $note, end( $order->notes ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: mixed, 2: mixed}>
	 */
	public static function hostile_values(): array {
		return array(
			'html'          => array( '<script>alert(1)</script>', '<b>0b6f1f8e</b>', '<ORD-1>' ),
			'shopper email' => array( 'pat@example.test', 'pat@example.test', 'ORD-pat@example.test' ),
			'too long'      => array( str_repeat( 'a', 65 ), self::LOG_ID . 'x', 'ORD-' . str_repeat( 'A', 33 ) ),
			'spaces'        => array( 'no email', ' ' . self::LOG_ID, 'ORD-AB CD' ),
			'newline'       => array( "disabled\n", self::LOG_ID . "\n", self::ORDER_NO . "\n" ),
			'wrong prefix'  => array( '', 'not-a-uuid', 'ord-qw7vzyhjqw' ),
			'not strings'   => array( array( 'disabled' ), 12345, array( self::ORDER_NO ) ),
			'numbers'       => array( 42, true, 7 ),
		);
	}

	#[DataProvider( 'hostile_values' )]
	public function test_invalid_reason_log_id_and_order_number_are_dropped( mixed $reason, mixed $log_id, mixed $order_number ): void {
		$body          = $this->order_body( array( 'status' => 'failed', 'reason' => $reason, 'logId' => $log_id, 'retryable' => true ) );
		$body['order'] = array( 'orderNumber' => $order_number );

		$order = $this->process_order( $body );

		$this->assertSame( 'failed', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_notice_reason' ) );
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_notice_log_id' ) );
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_notice_order' ) );
		$this->assertSame( 'FFL Bridge could not email the selected dealer. The order is not affected. Contact the dealer directly if needed.', end( $order->notes ) );
		$this->assertNoShopperData();
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function final_bodies(): array {
		$bodies = array();
		foreach ( array( 'sent', 'queued', 'failed', 'skipped' ) as $status ) {
			$bodies[ $status ] = array( array( 'dealerNotice' => array( 'status' => $status, 'reason' => null, 'logId' => null, 'retryable' => false ) ) );
		}
		$bodies['requested'] = array( array( 'emails' => array( 'fflEmail' => array( 'success' => true ) ) ) );
		$bodies['no order number'] = array( array( 'order' => null, 'dealerNotice' => array( 'status' => 'queued' ) ) );

		return $bodies;
	}

	/**
	 * @param array<string, mixed> $extra Fields merged into the response body.
	 */
	#[DataProvider( 'final_bodies' )]
	public function test_refiring_either_processed_hook_after_a_final_status_sends_no_second_post( array $extra ): void {
		$order = $this->process_order( array_merge( $this->order_body(), $extra ) );
		$this->assertCount( 1, $this->order_requests() );
		$status = $order->get_meta( '_ffl_bridge_dealer_notice' );
		$this->assertNotSame( 'pending', $status );

		// A later request starts with an empty per-request guard.
		( new ReflectionProperty( FFL_Bridge_Checkout::class, 'notices_handled' ) )->setValue( null, array() );
		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, $this->order_body() );
		FFL_Bridge_Checkout::notify_dealer( $order );
		FFL_Bridge_Checkout::notify_dealer_classic( $order->get_id(), array(), $order );

		$this->assertCount( 1, $this->order_requests() );
		$this->assertSame( $status, $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	public function test_an_order_with_a_recorded_ffl_bridge_order_is_never_posted_again(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order(
			array(
				'_ffl_bridge_dealer_id'           => self::DEALER_ID,
				'_ffl_bridge_dealer_notice'       => 'pending',
				'_ffl_bridge_dealer_notice_order' => self::ORDER_NO,
			)
		);

		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertNoOrderRequest();
	}

	public function test_a_legacy_pre_call_queued_order_is_never_posted(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order( array( '_ffl_bridge_dealer_id' => self::DEALER_ID, '_ffl_bridge_dealer_notice' => 'queued' ) );

		FFL_Bridge_Checkout::notify_dealer( $order );
		FFL_Bridge_Checkout::notify_dealer_classic( $order->get_id(), array(), $order );

		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_http'] );
		$this->assertSame( 'queued', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( array(), $order->notes );
	}

	public function test_checkout_marks_the_notice_pending_before_the_call(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order();
		$this->select_dealer();

		FFL_Bridge_Checkout::save_store_api_order( $order );

		$this->assertSame( 'pending', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	public function test_already_sent_for_a_reused_order_number_is_skipped_not_sent(): void {
		// FFL Bridge Support's E2E case: a fresh site's order 12 repeated an
		// earlier site's order 12. FFL Bridge created a new order but answered
		// with the earlier order's notice, and no email went out for this one.
		$body          = $this->order_body(
			array(
				'status'              => 'sent',
				'reason'              => 'already_sent',
				'logId'               => 'e1ce28ea-94e6-4a0e-8021-de0f187cc38b',
				'retryable'           => false,
				'existingOrderNumber' => 'ORD-1QZKZISGCP',
			)
		);
		$body['order'] = array( 'orderNumber' => 'ORD-HQVAPCLLJ1', 'externalOrderId' => '501' );

		$order = $this->process_order( $body );

		$this->assertSame( 'skipped', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( 'already_sent', $order->get_meta( '_ffl_bridge_dealer_notice_reason' ) );
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_notice_log_id' ), "The other order's log ID is never stored." );
		$this->assertSame( 'ORD-HQVAPCLLJ1', $order->get_meta( '_ffl_bridge_dealer_notice_order' ) );
		$this->assertSame( 'ORD-1QZKZISGCP', $order->get_meta( '_ffl_bridge_dealer_notice_earlier_order' ) );
		$this->assertSame( 'FFL Bridge did not email the dealer about this order: it already emailed this dealer about an earlier order with the same order number (FFL Bridge order ORD-1QZKZISGCP). Contact the dealer directly if this is a different order.', end( $order->notes ) );
		$this->assertStringNotContainsString( 'e1ce28ea', (string) json_encode( $order->all_meta() ) );
		$this->assertNoShopperData();
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function bad_earlier_orders(): array {
		return array(
			'missing'   => array( null ),
			'lowercase' => array( 'ord-1qzkzisgcp' ),
			'html'      => array( '<b>ORD-1QZKZISGCP</b>' ),
			'too long'  => array( 'ORD-' . str_repeat( 'A', 33 ) ),
			'array'     => array( array( 'ORD-1QZKZISGCP' ) ),
		);
	}

	#[DataProvider( 'bad_earlier_orders' )]
	public function test_already_sent_without_a_valid_earlier_order_uses_the_note_without_a_number( mixed $earlier ): void {
		$notice = array( 'status' => 'sent', 'reason' => 'already_sent', 'logId' => self::LOG_ID );
		if ( null !== $earlier ) {
			$notice['existingOrderNumber'] = $earlier;
		}

		$order = $this->process_order( $this->order_body( $notice ) );

		$this->assertSame( 'skipped', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_notice_earlier_order' ) );
		$this->assertSame( 'FFL Bridge did not email the dealer about this order: it already emailed this dealer about an earlier order with the same order number. Contact the dealer directly if this is a different order.', end( $order->notes ) );
	}

	public function test_order_key_is_a_per_site_per_order_hash_without_shopper_data(): void {
		$key = $this->posted_order_key( $this->shopper_order() );

		$this->assertMatchesRegularExpression( '/\Awc1-[0-9a-f]{40}\z/', $key );
		$this->assertSame( $key, $this->posted_order_key( $this->shopper_order() ), 'Stable for one order.' );

		$reset               = $this->shopper_order();
		$reset->date_created = '2026-11-13T15:30:00+00:00';
		$this->assertNotSame( $key, $this->posted_order_key( $reset ), 'A reset site reuses the ID with a new creation time.' );

		$home = $GLOBALS['ffl_bridge_test_home_url'];
		try {
			$GLOBALS['ffl_bridge_test_home_url'] = 'https://staging.store.example.test/';
			$this->assertNotSame( $key, $this->posted_order_key( $this->shopper_order() ), 'A staging clone has another home URL.' );
		} finally {
			$GLOBALS['ffl_bridge_test_home_url'] = $home;
		}

		foreach ( array( 'Pat', 'Shopper', 'pat@example.test' ) as $value ) {
			$this->assertStringNotContainsString( $value, $key );
		}
	}

	public function test_order_key_adds_no_other_field_to_the_order_body(): void {
		$this->posted_order_key( $this->shopper_order() );

		$body = json_decode( $this->order_requests()[0]['args']['body'], true );
		$this->assertSame( array( 'fflId', 'customerName', 'customerEmail', 'externalOrderId', 'sendFflEmail', 'sendCustomerEmail', 'orderKey' ), array_keys( $body ) );
		$this->assertSame( '501', $body['externalOrderId'], 'The human order number stays the external reference.' );
	}

	public function test_a_timeout_on_order_create_records_requested_not_failed(): void {
		// The API sends the dealer email inside POST /orders, so a read timeout
		// can come after the email went out: the outcome is unknown.
		$order = $this->process_order( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 6001 milliseconds with 0 bytes received' ) );

		$this->assertCount( 1, $this->order_requests() );
		$this->assertSame( 'requested', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( 'FFL Bridge was asked to email the selected dealer but did not answer in time, so delivery could not be confirmed. Contact the dealer directly if needed.', end( $order->notes ) );
	}

	/**
	 * @return array<string, array{0: WP_Error|int}>
	 */
	public static function unknown_outcomes(): array {
		return array(
			'read timeout'         => array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received' ) ),
			'empty reply'          => array( new WP_Error( 'http_request_failed', 'cURL error 52: Empty reply from server' ) ),
			'connection reset'     => array( new WP_Error( 'http_request_failed', 'cURL error 56: Recv failure: Connection reset by peer' ) ),
			'socket transport'     => array( new WP_Error( 'http_request_failed', 'fread(): read timed out' ) ),
			'504 gateway timeout'  => array( 504 ),
		);
	}

	#[DataProvider( 'unknown_outcomes' )]
	public function test_no_answer_after_the_request_was_sent_records_requested( WP_Error|int $outcome ): void {
		$order = $this->process_order( $outcome instanceof WP_Error ? $outcome : array( '__status' => $outcome ) );

		$this->assertCount( 1, $this->order_requests(), 'Never retried.' );
		$this->assertSame( 'requested', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( 'no_response', $order->get_meta( '_ffl_bridge_dealer_notice_reason' ) );
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_notice_order' ) );
		$this->assertSame( 'FFL Bridge was asked to email the selected dealer but did not answer in time, so delivery could not be confirmed. Contact the dealer directly if needed.', end( $order->notes ) );
		$this->assertNotSame( array(), $GLOBALS['ffl_bridge_test_logs'] );
		$this->assertNoShopperData();
	}

	public function test_refiring_either_hook_after_a_timeout_sends_no_second_post(): void {
		$order = $this->process_order( null );
		$this->assertSame( 'requested', $order->get_meta( '_ffl_bridge_dealer_notice' ) );

		( new ReflectionProperty( FFL_Bridge_Checkout::class, 'notices_handled' ) )->setValue( null, array() );
		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, $this->order_body() );
		FFL_Bridge_Checkout::notify_dealer( $order );
		FFL_Bridge_Checkout::notify_dealer_classic( $order->get_id(), array(), $order );

		$this->assertCount( 1, $this->order_requests() );
		$this->assertSame( 'requested', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
	}

	public function test_the_in_progress_marker_is_saved_before_the_post(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = new class( array( '_ffl_bridge_dealer_id' => self::DEALER_ID, '_ffl_bridge_dealer_notice' => 'pending' ) ) extends WC_Order {
			/** @var array<int, string> */
			public array $saved_statuses = array();

			public function save(): void {
				$this->saved_statuses[] = (string) $this->get_meta( '_ffl_bridge_dealer_notice' );
				parent::save();
			}
		};
		$order->billing = array( 'first_name' => 'Pat', 'last_name' => 'Shopper', 'email' => 'pat@example.test' );

		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, $this->order_body( array( 'status' => 'sent' ) ) );
		FFL_Bridge_Checkout::notify_dealer( $order );

		$this->assertSame( array( 'sending', 'sent' ), $order->saved_statuses );
	}

	public function test_an_order_left_sending_by_a_request_that_died_is_never_posted_again(): void {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order( array( '_ffl_bridge_dealer_id' => self::DEALER_ID, '_ffl_bridge_dealer_notice' => 'sending' ) );

		FFL_Bridge_Checkout::notify_dealer( $order );
		FFL_Bridge_Checkout::notify_dealer_classic( $order->get_id(), array(), $order );

		$this->assertNoOrderRequest();
	}

	public function test_a_failed_request_keeps_the_request_failure_note(): void {
		$order = $this->process_order( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to www.fflbridge.com port 443: Connection refused' ) );

		$this->assertSame( 'failed', $order->get_meta( '_ffl_bridge_dealer_notice' ) );
		$this->assertSame( 'FFL Bridge could not be asked to email the dealer. The order is not affected. Contact the dealer directly if needed.', end( $order->notes ) );
		$this->assertSame( '', $order->get_meta( '_ffl_bridge_dealer_notice_order' ) );
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
		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, $this->order_body( array( 'status' => 'sent', 'reason' => null, 'logId' => self::LOG_ID, 'retryable' => false ) ) );

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

		$this->assertSame(
			array(
				'order_number'  => self::ORDER_NO,
				'notice_status' => 'sent',
				'reason'        => '',
				'log_id'        => self::LOG_ID,
			),
			$result
		);
		$body = json_decode( $GLOBALS['ffl_bridge_test_http'][0]['args']['body'], true );
		$this->assertSame( self::DEALER_ID, $body['fflId'] );
		$this->assertTrue( $body['sendFflEmail'] );
		$this->assertFalse( $body['sendCustomerEmail'] );
		$this->assertArrayNotHasKey( 'billingAddress', $body );
	}

	/**
	 * Place an on-checkout order with a dealer and answer POST /orders.
	 *
	 * @param array<string, mixed>|WP_Error|null $body Response body for a 201, a transport error, or null for a read timeout.
	 */
	private function process_order( array|WP_Error|null $body ): WC_Order {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order = $this->shopper_order();
		$this->select_dealer();
		FFL_Bridge_Checkout::save_store_api_order( $order );

		if ( null === $body ) {
			$body = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		$GLOBALS['ffl_bridge_test_http_responses'][] = $body instanceof WP_Error ? $body : $this->response( (int) ( $body['__status'] ?? 201 ), $body );
		$GLOBALS['ffl_bridge_test_logs'] = array();
		FFL_Bridge_Checkout::notify_dealer( $order );

		return $order;
	}

	/**
	 * Run the dealer notice for an order and return the orderKey it posted.
	 */
	private function posted_order_key( WC_Order $order ): string {
		$GLOBALS['ffl_bridge_test_options'][ self::OPTION ] = 'yes';
		$order->update_meta_data( '_ffl_bridge_dealer_id', self::DEALER_ID );
		$order->update_meta_data( '_ffl_bridge_dealer_notice', 'pending' );
		( new ReflectionProperty( FFL_Bridge_Checkout::class, 'notices_handled' ) )->setValue( null, array() );
		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->response( 201, $this->order_body() );
		$before = count( $this->order_requests() );

		FFL_Bridge_Checkout::notify_dealer( $order );

		$requests = $this->order_requests();
		$this->assertCount( $before + 1, $requests );
		$body = json_decode( end( $requests )['args']['body'], true );

		return (string) ( $body['orderKey'] ?? '' );
	}

	/**
	 * A POST /orders 201 body in the shape the real API sends.
	 *
	 * @param array<string, mixed>|null $notice Optional dealerNotice field.
	 * @return array<string, mixed>
	 */
	private function order_body( ?array $notice = null ): array {
		$body = array(
			'success' => true,
			'order'   => array(
				'orderNumber'     => self::ORDER_NO,
				'externalOrderId' => '501',
			),
			'ffl'     => array( 'id' => self::DEALER_ID ),
			'emails'  => array(
				'customerEmail' => null,
				'fflEmail'      => array( 'success' => true ),
			),
		);
		if ( null !== $notice ) {
			$body['dealerNotice'] = $notice;
		}

		return $body;
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
