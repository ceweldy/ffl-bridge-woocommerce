<?php
/**
 * Shared dealer, order and HTTP fixtures for the 1.3.0 tests.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

trait FFL_Bridge_Dealer_Fixtures {
	protected const FIX_KEY       = 'ffl_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
	protected const FIX_DEALER_ID = '123e4567-e89b-12d3-a456-426614174000';
	protected const FIX_LICENSE   = '1-23-456-78-9A-01234';

	/** Configure the API key and reset request-local caches, as a new request would. */
	protected function fresh_request(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_api_key'] = self::FIX_KEY;
		foreach ( array( 'verification_cache', 'notices_handled' ) as $property ) {
			( new ReflectionProperty( FFL_Bridge_Checkout::class, $property ) )->setValue( null, array() );
		}
	}

	/**
	 * The dealer record FFL Bridge returns, as the API sends it.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	protected function raw_dealer( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'               => self::FIX_DEALER_ID,
				'licenseNumber'    => self::FIX_LICENSE,
				'licenseType'      => '01',
				'tradeName'        => 'Example Arms',
				'businessName'     => 'Example Arms LLC',
				'address'          => '123 Main Street',
				'city'             => 'Orlando',
				'state'            => 'FL',
				'zip'              => '32801',
				'phone'            => '407-555-0100',
				'acceptsTransfers' => true,
				'checkoutEligible' => true,
				'transferStatus'   => 'confirmed',
			),
			$overrides
		);
	}

	/**
	 * Queue the GET /ffls/{id} answer for one dealer lookup.
	 *
	 * @param array<string, mixed> $overrides Dealer field overrides.
	 */
	protected function queue_dealer_lookup( array $overrides = array() ): void {
		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->http_json(
			200,
			array(
				'success' => true,
				'data'    => array(
					'dealer'      => $this->raw_dealer( $overrides ),
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

	/**
	 * Queue a GET /search answer.
	 *
	 * @param array<int, array<string, mixed>> $dealers Raw dealers.
	 */
	protected function queue_search( array $dealers ): void {
		$GLOBALS['ffl_bridge_test_http_responses'][] = $this->http_json(
			200,
			array(
				'success' => true,
				'data'    => array( 'results' => $dealers ),
			)
		);
	}

	/**
	 * Put a selected dealer in the WooCommerce session, as ajax_select does.
	 *
	 * @param array<string, mixed> $dealer Normalized dealer overrides.
	 */
	protected function select_in_session( array $dealer = array() ): void {
		$normalized = FFL_Bridge_API_Client::normalize_dealer( $this->raw_dealer() );
		WC()->session->set(
			'ffl_bridge_selection_v1',
			array(
				'dealer'     => array_merge( $normalized, array( 'checkout_verified' => true, 'is_active' => true ), $dealer ),
				'cart_hash'  => FFL_Bridge_Selection::get_cart_hash(),
				'expires_at' => time() + 3600,
			)
		);
	}

	/** A new order whose shipping and billing fields are what the shopper typed. */
	protected function shopper_order(): WC_Order {
		$order           = new WC_Order();
		$order->billing  = array(
			'first_name' => 'Pat',
			'last_name'  => 'Shopper',
			'email'      => 'pat@example.test',
			'address_1'  => '9 Home Road',
			'city'       => 'Tampa',
			'state'      => 'FL',
			'postcode'   => '33601',
			'country'    => 'US',
		);
		$order->shipping = array(
			'first_name' => 'Pat',
			'last_name'  => 'Shopper',
			'company'    => '',
			'address_1'  => '9 Home Road',
			'address_2'  => 'Apt 4',
			'city'       => 'Tampa',
			'state'      => 'FL',
			'postcode'   => '33601',
			'country'    => 'US',
		);
		return $order;
	}

	/**
	 * Call an AJAX handler and return its JSON response.
	 *
	 * @param callable             $handler Handler.
	 * @param array<string, mixed> $post Posted fields.
	 */
	protected function ajax( callable $handler, array $post = array() ): FFL_Bridge_Test_Json {
		$_POST = array_merge(
			array(
				'nonce'        => wp_create_nonce( 'ffl_bridge_checkout' ),
				'requestToken' => FFL_Bridge_Selection::get_request_token(),
			),
			$post
		);
		try {
			$handler();
		} catch ( FFL_Bridge_Test_Json $response ) {
			return $response;
		}
		$this->fail( 'The handler did not send a JSON response.' );
	}

	/**
	 * A wp_remote_* style response with a JSON body.
	 *
	 * @param array<string, mixed> $body Body.
	 * @return array<string, mixed>
	 */
	protected function http_json( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) json_encode( $body ),
		);
	}

	/** Restrict the dealer workflow to category 7 and put product 100 in it or not. */
	protected function cart_with_product_in_ffl_category( bool $in_category ): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_categories'] = array( 7 );
		$GLOBALS['ffl_bridge_test_term_map'][100]                    = $in_category ? array( 7 ) : array( 9 );
	}
}
