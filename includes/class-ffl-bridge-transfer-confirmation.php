<?php
/**
 * Store-side transfer confirmation for an order's selected dealer.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets store staff mark an order's dealer transfer as confirmed, optionally
 * with a private copy of the dealer's license, and reports the confirmation
 * to FFL Bridge when the API supports it.
 *
 * Nothing here contacts dealers.
 */
final class FFL_Bridge_Transfer_Confirmation {

	public const MAX_FILE_BYTES = 5242880;
	public const PRIVATE_DIR    = 'ffl-bridge-private';
	private const NONCE_PREFIX  = 'ffl_bridge_confirm_transfer_';
	private const FILE_NONCE    = 'ffl_bridge_license_file_';
	private const NOTE_LENGTH   = 500;

	/**
	 * Allowed license file extensions and their MIME types.
	 *
	 * @var array<string, string>
	 */
	public const ALLOWED_TYPES = array(
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_ffl_bridge_confirm_transfer', array( __CLASS__, 'ajax_confirm' ) );
		add_action( 'admin_post_ffl_bridge_license_file', array( __CLASS__, 'download_license_file' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * The capability needed to confirm transfers and read license files.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return 'edit_shop_orders';
	}

	/**
	 * Load the confirm script on order edit screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'ffl-bridge-admin-order',
			FFL_BRIDGE_PLUGIN_URL . 'assets/js/admin-order.js',
			array(),
			FFL_BRIDGE_VERSION,
			true
		);
		wp_localize_script(
			'ffl-bridge-admin-order',
			'fflBridgeAdminOrder',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'working' => esc_html__( 'Saving confirmation…', 'ffl-bridge-for-woocommerce' ),
				'failed'  => esc_html__( 'The confirmation could not be saved.', 'ffl-bridge-for-woocommerce' ),
			)
		);
	}

	/**
	 * Render the confirmation controls inside the order dealer panel.
	 *
	 * The controls are plain elements, not a nested form, because the order
	 * screen is already a form. The script posts them separately.
	 *
	 * @param WC_Order              $order Order object.
	 * @param array<string, string> $ffl Order dealer values.
	 * @return void
	 */
	public static function render_controls( WC_Order $order, array $ffl ): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$order_id = (int) $order->get_id();
		if ( 'yes' === ( $ffl['license_file'] ?? 'no' ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=ffl_bridge_license_file&order_id=' . $order_id ), self::FILE_NONCE . $order_id );
			echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Download license copy', 'ffl-bridge-for-woocommerce' ) . '</a></p>';
		}

		if ( '' !== ( $ffl['store_confirmed_at'] ?? '' ) ) {
			return;
		}
		?>
		<div class="ffl-bridge-confirm" data-ffl-bridge-confirm data-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_PREFIX . $order_id ) ); ?>">
			<p>
				<label for="ffl-bridge-license-file-<?php echo esc_attr( (string) $order_id ); ?>"><?php echo esc_html__( 'License copy (optional, PDF, JPG, or PNG, up to 5 MB)', 'ffl-bridge-for-woocommerce' ); ?></label><br>
				<input type="file" id="ffl-bridge-license-file-<?php echo esc_attr( (string) $order_id ); ?>" data-ffl-bridge-file accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
			</p>
			<p>
				<label for="ffl-bridge-confirm-note-<?php echo esc_attr( (string) $order_id ); ?>"><?php echo esc_html__( 'Note (optional)', 'ffl-bridge-for-woocommerce' ); ?></label><br>
				<input type="text" class="widefat" id="ffl-bridge-confirm-note-<?php echo esc_attr( (string) $order_id ); ?>" data-ffl-bridge-note maxlength="500">
			</p>
			<p>
				<button type="button" class="button button-primary" data-ffl-bridge-confirm-button><?php echo esc_html__( 'Mark transfer confirmed', 'ffl-bridge-for-woocommerce' ); ?></button>
				<span data-ffl-bridge-confirm-status role="status" aria-live="polite"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the confirm request from the order screen.
	 *
	 * @return void
	 */
	public static function ajax_confirm(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is order-specific and checked next.

		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You are not allowed to confirm transfers.', 'ffl-bridge-for-woocommerce' ) ), 403 );
		}

		if ( 0 === $order_id || ! check_ajax_referer( self::NONCE_PREFIX . $order_id, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'The security token expired. Reload the order and try again.', 'ffl-bridge-for-woocommerce' ) ), 403 );
		}

		$order = wc_get_order( $order_id );
		$ffl   = FFL_Bridge_Order::get_ffl_data( $order );
		if ( ! $order instanceof WC_Order || null === $ffl || 'legacy' === $ffl['source'] ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This order has no FFL Bridge dealer to confirm.', 'ffl-bridge-for-woocommerce' ) ), 404 );
		}

		if ( '' !== $ffl['store_confirmed_at'] ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This transfer is already confirmed.', 'ffl-bridge-for-woocommerce' ) ), 409 );
		}

		$file   = null;
		$fields = self::uploaded_file( isset( $_FILES['license_file'] ) && is_array( $_FILES['license_file'] ) ? $_FILES['license_file'] : array() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified above; uploaded_file() sanitizes each field.
		if ( UPLOAD_ERR_NO_FILE !== $fields['error'] ) {
			$upload = self::validate_upload( $fields );
			if ( is_wp_error( $upload ) ) {
				wp_send_json_error( array( 'message' => $upload->get_error_message() ), 400 );
			}

			$file = self::store_file( $upload );
			if ( is_wp_error( $file ) ) {
				wp_send_json_error( array( 'message' => $file->get_error_message() ), 500 );
			}
		}

		$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$user   = wp_get_current_user();
		$result = self::confirm( $order, $ffl, (int) get_current_user_id(), (string) $user->display_name, $file, $note );

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	/**
	 * Record the confirmation on the order and report it to FFL Bridge.
	 *
	 * The local confirmation is always saved. A missing endpoint or a failed
	 * API call is recorded in the order note and returned as the message.
	 *
	 * @param WC_Order                   $order Order.
	 * @param array<string, string>      $ffl Order dealer values.
	 * @param int                        $user_id Confirming user ID.
	 * @param string                     $user_name Confirming user display name.
	 * @param array<string, string>|null $file Stored license file, if any.
	 * @param string                     $note Optional note.
	 * @param int|null                   $now Optional timestamp for testing.
	 * @return array{api: string, message: string}
	 */
	public static function confirm( WC_Order $order, array $ffl, int $user_id, string $user_name, ?array $file, string $note, ?int $now = null ): array {
		$note = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, self::NOTE_LENGTH ) : substr( $note, 0, self::NOTE_LENGTH );
		$at   = gmdate( 'c', $now ?? time() );

		$order->update_meta_data( '_ffl_bridge_store_confirmed_at', $at );
		$order->update_meta_data( '_ffl_bridge_store_confirmed_by', (string) $user_id );
		$order->update_meta_data( '_ffl_bridge_store_confirmed_by_name', $user_name );
		$order->update_meta_data( '_ffl_bridge_transfer_status', 'confirmed' );
		if ( null !== $file ) {
			$order->update_meta_data( '_ffl_bridge_license_file', array_diff_key( $file, array( 'path' => true ) ) );
		}

		$api = FFL_Bridge_API_Client::confirm_transfer( $ffl['dealer_id'], $ffl['license'], (string) $order->get_order_number(), $note, $file );
		if ( is_wp_error( $api ) ) {
			$state   = 'ffl_bridge_endpoint_missing' === $api->get_error_code() ? 'unsupported' : 'failed';
			$message = 'unsupported' === $state
				? __( 'Transfer confirmed for this order. FFL Bridge does not accept confirmations yet, so only this store\'s record was updated.', 'ffl-bridge-for-woocommerce' )
				: __( 'Transfer confirmed for this order. FFL Bridge could not be updated, so only this store\'s record was updated.', 'ffl-bridge-for-woocommerce' );
		} else {
			$state   = 'sent';
			$message = __( 'Transfer confirmed for this order and reported to FFL Bridge.', 'ffl-bridge-for-woocommerce' );
		}
		$order->update_meta_data( '_ffl_bridge_api_confirmation', $state );

		$order->add_order_note(
			sprintf(
				/* translators: 1: user display name, 2: dealer name, 3: license number, 4: yes or no, 5: FFL Bridge sync result, 6: optional staff note. */
				__( 'Transfer confirmed by %1$s for %2$s (%3$s). License copy attached: %4$s. %5$s%6$s', 'ffl-bridge-for-woocommerce' ),
				'' !== $user_name ? $user_name : __( 'store staff', 'ffl-bridge-for-woocommerce' ),
				$ffl['name'],
				$ffl['license'],
				null !== $file ? __( 'yes', 'ffl-bridge-for-woocommerce' ) : __( 'no', 'ffl-bridge-for-woocommerce' ),
				$message,
				'' !== $note ? ' ' . sprintf(
					/* translators: %s: staff note. */
					__( 'Note: %s', 'ffl-bridge-for-woocommerce' ),
					$note
				) : ''
			)
		);
		$order->save();

		return array(
			'api'     => $state,
			'message' => $message,
		);
	}

	/**
	 * Copy the PHP upload fields into a plain array.
	 *
	 * @param array<string, mixed> $raw One entry from $_FILES.
	 * @return array{name: string, tmp_name: string, size: int, error: int}
	 */
	private static function uploaded_file( array $raw ): array {
		$tmp = is_string( $raw['tmp_name'] ?? null ) ? $raw['tmp_name'] : '';
		return array(
			'name'     => is_string( $raw['name'] ?? null ) ? sanitize_file_name( $raw['name'] ) : '',
			'tmp_name' => '' !== $tmp && is_uploaded_file( $tmp ) ? $tmp : '',
			'size'     => (int) ( $raw['size'] ?? 0 ),
			'error'    => (int) ( $raw['error'] ?? UPLOAD_ERR_NO_FILE ),
		);
	}

	/**
	 * Validate an uploaded license file by error code, size, and real type.
	 *
	 * @param array{name: string, tmp_name: string, size: int, error: int} $file Upload fields.
	 * @return array{name: string, tmp_name: string, size: int, ext: string, type: string}|WP_Error
	 */
	public static function validate_upload( array $file ): array|WP_Error {
		if ( UPLOAD_ERR_OK !== $file['error'] || '' === $file['tmp_name'] ) {
			return new WP_Error( 'ffl_bridge_upload_failed', __( 'The license file did not upload. Try again.', 'ffl-bridge-for-woocommerce' ) );
		}

		$size = (int) filesize( $file['tmp_name'] );
		if ( $size <= 0 || $size > self::MAX_FILE_BYTES || $file['size'] > self::MAX_FILE_BYTES ) {
			return new WP_Error( 'ffl_bridge_upload_size', __( 'The license file must be 5 MB or smaller.', 'ffl-bridge-for-woocommerce' ) );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::ALLOWED_TYPES );
		$ext   = is_string( $check['ext'] ?? null ) ? strtolower( $check['ext'] ) : '';
		$type  = is_string( $check['type'] ?? null ) ? $check['type'] : '';
		if ( ! isset( self::ALLOWED_TYPES[ $ext ] ) || self::ALLOWED_TYPES[ $ext ] !== $type ) {
			return new WP_Error( 'ffl_bridge_upload_type', __( 'The license file must be a PDF, JPG, or PNG.', 'ffl-bridge-for-woocommerce' ) );
		}

		return array(
			'name'     => $file['name'],
			'tmp_name' => $file['tmp_name'],
			'size'     => $size,
			'ext'      => $ext,
			'type'     => $type,
		);
	}

	/**
	 * Return the private storage directory, creating it with deny rules.
	 *
	 * Files get random names in a folder that blocks direct web access on
	 * Apache and LiteSpeed. Other servers need an equivalent deny rule; files
	 * are only served through the capability-checked download action.
	 *
	 * @return string|WP_Error
	 */
	public static function private_dir(): string|WP_Error {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return new WP_Error( 'ffl_bridge_storage', __( 'The uploads folder is not available.', 'ffl-bridge-for-woocommerce' ) );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::PRIVATE_DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'ffl_bridge_storage', __( 'The license file could not be stored.', 'ffl-bridge-for-woocommerce' ) );
		}

		$guards = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $guards as $name => $contents ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small guard file in a plugin-owned folder.
			}
		}

		return $dir;
	}

	/**
	 * Move a validated upload into private storage.
	 *
	 * @param array{name: string, tmp_name: string, size: int, ext: string, type: string} $upload Validated upload.
	 * @return array<string, string>|WP_Error Stored file record.
	 */
	private static function store_file( array $upload ): array|WP_Error {
		$dir = self::private_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$stored = bin2hex( random_bytes( 16 ) ) . '.' . $upload['ext'];
		if ( ! move_uploaded_file( $upload['tmp_name'], $dir . '/' . $stored ) ) {
			return new WP_Error( 'ffl_bridge_storage', __( 'The license file could not be stored.', 'ffl-bridge-for-woocommerce' ) );
		}

		return array(
			'stored'      => $stored,
			'filename'    => $upload['name'],
			'contentType' => $upload['type'],
			'size'        => (string) $upload['size'],
			'sha256'      => (string) hash_file( 'sha256', $dir . '/' . $stored ),
			'path'        => $dir . '/' . $stored,
		);
	}

	/**
	 * Resolve a stored file record to a path inside the private folder.
	 *
	 * @param mixed $record Stored file record from order meta.
	 * @return string Empty when the record is missing or points elsewhere.
	 */
	public static function resolve_path( mixed $record ): string {
		$dir = self::private_dir();
		if ( is_wp_error( $dir ) || ! is_array( $record ) || ! is_string( $record['stored'] ?? null ) ) {
			return '';
		}

		if ( 1 !== preg_match( '/\A[0-9a-f]{32}\.(pdf|jpe?g|png)\z/', $record['stored'] ) ) {
			return '';
		}

		$path = $dir . '/' . $record['stored'];
		return is_file( $path ) ? $path : '';
	}

	/**
	 * Stream a private license file to an authorized user.
	 *
	 * @return void
	 */
	public static function download_license_file(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is order-specific and checked next.
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to view this file.', 'ffl-bridge-for-woocommerce' ), 403 );
		}

		check_admin_referer( self::FILE_NONCE . $order_id );

		$order  = wc_get_order( $order_id );
		$record = $order ? $order->get_meta( '_ffl_bridge_license_file' ) : null;
		$path   = self::resolve_path( $record );
		if ( '' === $path ) {
			wp_die( esc_html__( 'The license file was not found.', 'ffl-bridge-for-woocommerce' ), 404 );
		}

		$type = self::ALLOWED_TYPES[ strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ] ?? 'application/octet-stream';
		$name = sanitize_file_name( is_string( $record['filename'] ?? null ) ? $record['filename'] : basename( $path ) );

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a private file to an authorized user.
		exit;
	}
}
