<?php
/**
 * "Mark transfer confirmed" action, license files, and API reporting.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class TransferConfirmationTest extends FFL_Bridge_TestCase {
	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/ffl-bridge-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
		$GLOBALS['ffl_bridge_test_upload_dir']                 = $this->dir;
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_api_key'] = 'ffl_live_' . str_repeat( 'A1', 16 );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/{,.}*[!.]*', GLOB_BRACE ) as $path ) {
			if ( is_dir( (string) $path ) ) {
				array_map( 'unlink', (array) glob( $path . '/{,.}*[!.]*', GLOB_BRACE ) );
				rmdir( (string) $path );
			} else {
				unlink( (string) $path );
			}
		}
		rmdir( $this->dir );
		parent::tearDown();
	}

	public function test_action_requires_capability(): void {
		$GLOBALS['ffl_bridge_test_orders'][501] = $this->order();
		$_POST = array( 'order_id' => '501', 'nonce' => 'nonce-ffl_bridge_confirm_transfer_501' );

		$response = $this->call();

		$this->assertFalse( $response->success );
		$this->assertSame( 403, $response->status );
		$this->assertSame( '', (string) $GLOBALS['ffl_bridge_test_orders'][501]->get_meta( '_ffl_bridge_store_confirmed_at' ) );
	}

	public function test_action_requires_an_order_specific_nonce(): void {
		$GLOBALS['ffl_bridge_test_caps']        = array( 'edit_shop_orders' );
		$GLOBALS['ffl_bridge_test_orders'][501] = $this->order();
		$_POST = array( 'order_id' => '501', 'nonce' => 'nonce-ffl_bridge_confirm_transfer_999' );

		$response = $this->call();

		$this->assertSame( 403, $response->status );
		$this->assertStringContainsString( 'security token', $response->data['message'] );
	}

	public function test_action_rejects_orders_without_a_bridge_dealer_and_repeat_confirmations(): void {
		$GLOBALS['ffl_bridge_test_caps']        = array( 'edit_shop_orders' );
		$GLOBALS['ffl_bridge_test_orders'][501] = new WC_Order( array( '_ffl_license' => '1-23-456-78-9A-01234' ) );
		$_POST = array( 'order_id' => '501', 'nonce' => 'nonce-ffl_bridge_confirm_transfer_501' );
		$this->assertSame( 404, $this->call()->status );

		$GLOBALS['ffl_bridge_test_orders'][501] = $this->order( array( '_ffl_bridge_store_confirmed_at' => '2026-10-08T00:00:00+00:00' ) );
		$this->assertSame( 409, $this->call()->status );
	}

	public function test_action_saves_local_confirmation_and_reports_to_api(): void {
		$GLOBALS['ffl_bridge_test_caps']           = array( 'edit_shop_orders' );
		$GLOBALS['ffl_bridge_test_orders'][501]    = $this->order();
		$GLOBALS['ffl_bridge_test_http_responses'] = array( $this->response( 201, array( 'success' => true, 'data' => array( 'id' => 'tc_1' ) ) ) );
		$_POST = array(
			'order_id' => '501',
			'nonce'    => 'nonce-ffl_bridge_confirm_transfer_501',
			'note'     => 'Called the dealer.',
		);

		$response = $this->call();
		$order    = $GLOBALS['ffl_bridge_test_orders'][501];

		$this->assertTrue( $response->success );
		$this->assertStringContainsString( 'reported to FFL Bridge', $response->data['message'] );
		$this->assertSame( '7', $order->get_meta( '_ffl_bridge_store_confirmed_by' ) );
		$this->assertSame( 'Store Manager', $order->get_meta( '_ffl_bridge_store_confirmed_by_name' ) );
		$this->assertSame( 'confirmed', $order->get_meta( '_ffl_bridge_transfer_status' ) );
		$this->assertSame( 'sent', $order->get_meta( '_ffl_bridge_api_confirmation' ) );
		$this->assertStringContainsString( 'Transfer confirmed by Store Manager for Example Arms (1-23-456-78-9A-01234). License copy attached: no.', $order->notes[0] );
		$this->assertStringContainsString( 'Note: Called the dealer.', $order->notes[0] );

		$request = $GLOBALS['ffl_bridge_test_http'][0];
		$this->assertSame( 'https://www.fflbridge.com/api/v1/dealers/123e4567-e89b-12d3-a456-426614174000/transfer-confirmations', $request['url'] );
		$this->assertSame(
			array(
				'licenseNumber'    => '1-23-456-78-9A-01234',
				'acceptsTransfers' => true,
				'orderReference'   => '501',
				'note'             => 'Called the dealer.',
			),
			json_decode( $request['args']['body'], true )
		);
		$this->assertSame( 'Bearer ffl_live_' . str_repeat( 'A1', 16 ), $request['args']['headers']['Authorization'] );
	}

	public function test_missing_endpoint_keeps_local_confirmation_and_says_so(): void {
		$GLOBALS['ffl_bridge_test_http_responses'] = array( $this->response( 404, array( 'success' => false ) ) );
		$order                                     = $this->order();

		$result = FFL_Bridge_Transfer_Confirmation::confirm( $order, FFL_Bridge_Order::get_ffl_data( $order ), 7, 'Store Manager', null, '' );

		$this->assertSame( 'unsupported', $result['api'] );
		$this->assertStringContainsString( 'does not accept confirmations yet', $result['message'] );
		$this->assertNotSame( '', $order->get_meta( '_ffl_bridge_store_confirmed_at' ) );
		$this->assertStringContainsString( 'only this store\'s record was updated', $order->notes[0] );
		$this->assertSame( 1, $order->saves );
	}

	public function test_api_failure_or_transport_error_keeps_local_confirmation(): void {
		$GLOBALS['ffl_bridge_test_http_responses'] = array( $this->response( 500, array( 'success' => false ) ) );
		$order  = $this->order();
		$result = FFL_Bridge_Transfer_Confirmation::confirm( $order, FFL_Bridge_Order::get_ffl_data( $order ), 7, 'Store Manager', null, '' );
		$this->assertSame( 'failed', $result['api'] );
		$this->assertSame( 'failed', $order->get_meta( '_ffl_bridge_api_confirmation' ) );

		$order  = $this->order();
		$result = FFL_Bridge_Transfer_Confirmation::confirm( $order, FFL_Bridge_Order::get_ffl_data( $order ), 7, 'Store Manager', null, '' );
		$this->assertSame( 'failed', $result['api'] );
		$this->assertNotSame( '', $order->get_meta( '_ffl_bridge_store_confirmed_at' ) );
		$this->assertCount( 2, $GLOBALS['ffl_bridge_test_http'] );
	}

	public function test_post_is_sent_once_without_retries(): void {
		$order = $this->order();
		FFL_Bridge_Transfer_Confirmation::confirm( $order, FFL_Bridge_Order::get_ffl_data( $order ), 7, 'Store Manager', null, '' );

		$this->assertCount( 1, $GLOBALS['ffl_bridge_test_http'] );
	}

	public function test_license_file_is_sent_and_stored_without_server_path(): void {
		$GLOBALS['ffl_bridge_test_http_responses'] = array( $this->response( 201, array( 'success' => true ) ) );
		$path  = $this->write( 'license.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n" );
		$order = $this->order();
		$file  = array(
			'stored'      => str_repeat( 'a', 32 ) . '.pdf',
			'filename'    => 'license.pdf',
			'contentType' => 'application/pdf',
			'path'        => $path,
		);

		FFL_Bridge_Transfer_Confirmation::confirm( $order, FFL_Bridge_Order::get_ffl_data( $order ), 7, 'Store Manager', $file, '' );

		$body = json_decode( $GLOBALS['ffl_bridge_test_http'][0]['args']['body'], true );
		$this->assertSame( 'license.pdf', $body['licenseFile']['filename'] );
		$this->assertSame( 'application/pdf', $body['licenseFile']['contentType'] );
		$this->assertSame( (string) file_get_contents( $path ), base64_decode( $body['licenseFile']['contentBase64'] ) );
		$this->assertArrayNotHasKey( 'path', $order->get_meta( '_ffl_bridge_license_file' ) );
		$this->assertStringContainsString( 'License copy attached: yes.', $order->notes[0] );
	}

	public function test_valid_pdf_and_png_uploads_are_accepted(): void {
		$pdf = FFL_Bridge_Transfer_Confirmation::validate_upload( $this->upload( 'license.pdf', "%PDF-1.4\n%%EOF\n" ) );
		$this->assertIsArray( $pdf );
		$this->assertSame( 'pdf', $pdf['ext'] );
		$this->assertSame( 'application/pdf', $pdf['type'] );

		$png = FFL_Bridge_Transfer_Confirmation::validate_upload( $this->upload( 'license.png', (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) ) );
		$this->assertIsArray( $png );
		$this->assertSame( 'image/png', $png['type'] );
	}

	public function test_wrong_type_is_rejected_even_with_an_allowed_extension(): void {
		$disguised = FFL_Bridge_Transfer_Confirmation::validate_upload( $this->upload( 'license.pdf', "<?php echo 'x';\n" ) );
		$this->assertInstanceOf( WP_Error::class, $disguised );
		$this->assertSame( 'ffl_bridge_upload_type', $disguised->get_error_code() );

		$html = FFL_Bridge_Transfer_Confirmation::validate_upload( $this->upload( 'license.html', '<html></html>' ) );
		$this->assertSame( 'ffl_bridge_upload_type', $html->get_error_code() );
	}

	public function test_oversized_empty_and_failed_uploads_are_rejected(): void {
		$big = $this->upload( 'license.pdf', "%PDF-1.4\n" . str_repeat( 'a', FFL_Bridge_Transfer_Confirmation::MAX_FILE_BYTES ) );
		$this->assertSame( 'ffl_bridge_upload_size', FFL_Bridge_Transfer_Confirmation::validate_upload( $big )->get_error_code() );

		$empty = $this->upload( 'license.pdf', '' );
		$this->assertSame( 'ffl_bridge_upload_size', FFL_Bridge_Transfer_Confirmation::validate_upload( $empty )->get_error_code() );

		$failed          = $this->upload( 'license.pdf', "%PDF-1.4\n" );
		$failed['error'] = UPLOAD_ERR_PARTIAL;
		$this->assertSame( 'ffl_bridge_upload_failed', FFL_Bridge_Transfer_Confirmation::validate_upload( $failed )->get_error_code() );
	}

	public function test_private_folder_is_guarded_and_paths_cannot_escape_it(): void {
		$dir = FFL_Bridge_Transfer_Confirmation::private_dir();

		$this->assertIsString( $dir );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $dir . '/.htaccess' ) );
		$this->assertFileExists( $dir . '/index.php' );

		$name = str_repeat( 'b', 32 ) . '.png';
		file_put_contents( $dir . '/' . $name, 'x' );
		$this->assertSame( $dir . '/' . $name, FFL_Bridge_Transfer_Confirmation::resolve_path( array( 'stored' => $name ) ) );
		$this->assertSame( '', FFL_Bridge_Transfer_Confirmation::resolve_path( array( 'stored' => '../../wp-config.php' ) ) );
		$this->assertSame( '', FFL_Bridge_Transfer_Confirmation::resolve_path( array( 'stored' => str_repeat( 'c', 32 ) . '.php' ) ) );
		$this->assertSame( '', FFL_Bridge_Transfer_Confirmation::resolve_path( 'not a record' ) );
	}

	private function call(): FFL_Bridge_Test_Json {
		try {
			FFL_Bridge_Transfer_Confirmation::ajax_confirm();
		} catch ( FFL_Bridge_Test_Json $response ) {
			return $response;
		}

		$this->fail( 'The handler did not send a JSON response.' );
	}

	/**
	 * @param array<string, mixed> $body Response body.
	 * @return array<string, mixed>
	 */
	private function response( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) json_encode( $body ),
		);
	}

	private function write( string $name, string $contents ): string {
		$path = $this->dir . '/' . bin2hex( random_bytes( 4 ) ) . '-' . $name;
		file_put_contents( $path, $contents );
		return $path;
	}

	/**
	 * @return array{name: string, tmp_name: string, size: int, error: int}
	 */
	private function upload( string $name, string $contents ): array {
		$path = $this->write( 'upload.tmp', $contents );
		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'size'     => strlen( $contents ),
			'error'    => UPLOAD_ERR_OK,
		);
	}

	/**
	 * @param array<string, string> $meta Extra metadata.
	 */
	private function order( array $meta = array() ): WC_Order {
		return new WC_Order(
			array_merge(
				array(
					'_ffl_bridge_dealer_id'       => '123e4567-e89b-12d3-a456-426614174000',
					'_ffl_bridge_license'         => '1-23-456-78-9A-01234',
					'_ffl_bridge_name'            => 'Example Arms',
					'_ffl_bridge_source'          => 'ffl_bridge_api',
					'_ffl_bridge_selection_basis' => 'hybrid',
				),
				$meta
			)
		);
	}
}
