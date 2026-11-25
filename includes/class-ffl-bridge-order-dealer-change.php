<?php
/**
 * Change the transfer dealer on an order, or add one to a manual order.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Store staff search FFL Bridge from the order screen and save a different
 * dealer. The browser sends only the chosen dealer's FFL Bridge ID; the dealer
 * record saved on the order is fetched again from FFL Bridge on the server.
 * Every change adds an order note. No dealer is emailed.
 */
final class FFL_Bridge_Order_Dealer_Change {

	private const NONCE_PREFIX = 'ffl_bridge_change_dealer_';

	/**
	 * Store confirmation meta that belongs to the previous dealer.
	 */
	private const CONFIRMATION_META = array(
		'_ffl_bridge_store_confirmed_at',
		'_ffl_bridge_store_confirmed_by',
		'_ffl_bridge_store_confirmed_by_name',
		'_ffl_bridge_api_confirmation',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_ffl_bridge_admin_dealer_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_ffl_bridge_admin_set_dealer', array( __CLASS__, 'ajax_set_dealer' ) );
	}

	/**
	 * Capability needed to change an order's dealer.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return FFL_Bridge_Transfer_Confirmation::capability();
	}

	/**
	 * Render the change or add controls on the order screen.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $has_dealer Whether a dealer is saved on the order.
	 * @return void
	 */
	public static function render_controls( WC_Order $order, bool $has_dealer ): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$order_id = (int) $order->get_id();
		$label    = $has_dealer ? __( 'Change dealer', 'ffl-bridge-for-woocommerce' ) : __( 'Add a transfer dealer', 'ffl-bridge-for-woocommerce' );
		?>
		<div class="ffl-bridge-change-dealer" data-ffl-bridge-change-dealer data-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_PREFIX . $order_id ) ); ?>">
			<p><button type="button" class="button" data-ffl-bridge-change-toggle aria-expanded="false"><?php echo esc_html( $label ); ?></button></p>
			<div data-ffl-bridge-change-form hidden>
				<p>
					<label for="ffl-bridge-change-zip-<?php echo esc_attr( (string) $order_id ); ?>"><?php echo esc_html__( 'ZIP code', 'ffl-bridge-for-woocommerce' ); ?></label>
					<input type="text" id="ffl-bridge-change-zip-<?php echo esc_attr( (string) $order_id ); ?>" data-ffl-bridge-change-zip inputmode="numeric" pattern="[0-9]{5}" maxlength="5" size="6">
					<label for="ffl-bridge-change-radius-<?php echo esc_attr( (string) $order_id ); ?>"><?php echo esc_html__( 'Radius', 'ffl-bridge-for-woocommerce' ); ?></label>
					<select id="ffl-bridge-change-radius-<?php echo esc_attr( (string) $order_id ); ?>" data-ffl-bridge-change-radius>
						<?php foreach ( FFL_Bridge_API_Client::ALLOWED_RADII as $miles ) : ?>
							<option value="<?php echo esc_attr( (string) $miles ); ?>"<?php echo 25 === $miles ? ' selected' : ''; ?>><?php echo esc_html( (string) $miles ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="ffl-bridge-change-name-<?php echo esc_attr( (string) $order_id ); ?>"><?php echo esc_html__( 'Dealer name (optional)', 'ffl-bridge-for-woocommerce' ); ?></label>
					<input type="search" id="ffl-bridge-change-name-<?php echo esc_attr( (string) $order_id ); ?>" data-ffl-bridge-change-name maxlength="60">
					<button type="button" class="button" data-ffl-bridge-change-search><?php echo esc_html__( 'Search dealers', 'ffl-bridge-for-woocommerce' ); ?></button>
				</p>
				<div data-ffl-bridge-change-results></div>
				<p data-ffl-bridge-change-status role="status" aria-live="polite"></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Search dealers for an order.
	 *
	 * @return void
	 */
	public static function ajax_search(): void {
		self::verify_request();

		$zip    = isset( $_POST['zip'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['zip'] ) ), 0, 10 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() checked the nonce.
		$radius = isset( $_POST['radius'] ) ? absint( wp_unslash( $_POST['radius'] ) ) : 25; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		$name   = isset( $_POST['name'] ) ? FFL_Bridge_API_Client::sanitize_name_filter( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- As above; sanitize_name_filter() sanitizes.

		$primary = FFL_Bridge_API_Client::search_with_meta( $zip, $radius, 20, true, true, $name );
		if ( is_wp_error( $primary ) ) {
			wp_send_json_error( array( 'message' => $primary->get_error_message() ), 400 );
		}

		// Staff can choose any listed dealer that has not declined transfers,
		// as in hybrid mode; the order then shows what still needs follow-up.
		$resolved = FFL_Bridge_Network::resolve(
			FFL_Bridge_Network::filter_search_result( $primary ),
			static fn () => FFL_Bridge_Network::filter_search_result( FFL_Bridge_API_Client::search_with_meta( $zip, $radius, 20, false, false, $name ) ),
			FFL_Bridge_Network::SCOPE_ALL,
			FFL_Bridge_Network::get_preferred_licenses(),
			true
		);

		$labels  = array(
			'verified'             => __( 'Confirmed transfer dealer', 'ffl-bridge-for-woocommerce' ),
			'license_unverified'   => __( 'License not verified', 'ffl-bridge-for-woocommerce' ),
			'transfer_unconfirmed' => __( 'Transfer not confirmed', 'ffl-bridge-for-woocommerce' ),
		);
		$dealers = array();
		foreach ( $resolved['dealers'] as $dealer ) {
			if ( empty( $dealer['selectable'] ) ) {
				continue;
			}
			$dealers[] = array(
				'id'       => (string) $dealer['id'],
				'name'     => (string) $dealer['name'],
				'license'  => (string) $dealer['license'],
				'address'  => FFL_Bridge_Order::format_address( $dealer ),
				'distance' => $dealer['distance'] ?? null,
				'status'   => $labels[ FFL_Bridge_Network::follow_up_state( $dealer ) ],
			);
		}

		wp_send_json_success(
			array(
				'dealers' => $dealers,
				'message' => array() === $dealers ? __( 'No dealers were found in that area.', 'ffl-bridge-for-woocommerce' ) : '',
			)
		);
	}

	/**
	 * Save the chosen dealer on the order.
	 *
	 * @return void
	 */
	public static function ajax_set_dealer(): void {
		$order_id = self::verify_request();
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'The order was not found.', 'ffl-bridge-for-woocommerce' ) ), 404 );
		}

		$dealer_id = isset( $_POST['dealer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dealer_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() checked the nonce.
		$dealer    = FFL_Bridge_API_Client::get_dealer( substr( $dealer_id, 0, 64 ), true );
		if ( is_wp_error( $dealer ) ) {
			$status = 'ffl_bridge_invalid_dealer' === $dealer->get_error_code() ? 400 : 409;
			wp_send_json_error( array( 'message' => $dealer->get_error_message() ), $status );
		}

		if ( FFL_Bridge_Network::is_blocked( $dealer['license'] ) ) {
			wp_send_json_error( array( 'message' => __( 'That dealer is on this store\'s blocklist. Remove it from the blocklist in WooCommerce > FFL Bridge first, or choose another dealer.', 'ffl-bridge-for-woocommerce' ) ), 409 );
		}

		$user = wp_get_current_user();
		self::change( $order, $dealer, (int) get_current_user_id(), (string) $user->display_name );

		wp_send_json_success( array( 'message' => __( 'Dealer saved on the order.', 'ffl-bridge-for-woocommerce' ) ) );
	}

	/**
	 * Replace or add the dealer on an order and note the change.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $dealer Canonical dealer from FFL Bridge.
	 * @param int                  $user_id Staff user ID.
	 * @param string               $user_name Staff display name.
	 * @return void
	 */
	public static function change( WC_Order $order, array $dealer, int $user_id, string $user_name ): void {
		$previous  = FFL_Bridge_Order::get_ffl_data( $order );
		$confirmed = null !== $previous && '' !== ( $previous['store_confirmed_at'] ?? '' );

		foreach ( self::CONFIRMATION_META as $key ) {
			$order->delete_meta_data( $key );
		}
		FFL_Bridge_Checkout::write_dealer_meta( $order, $dealer );
		$order->update_meta_data( '_ffl_bridge_changed_by', (string) $user_id );
		$order->update_meta_data( '_ffl_bridge_changed_at', gmdate( 'c' ) );

		// A pickup order has no shipping address to replace, and a dealer chosen
		// by staff is not the store's own pickup record.
		$shipping = FFL_Bridge_Ship_To_Dealer::enabled() && ! FFL_Bridge_Store_Pickup::order_is_pickup( $order );
		$order->update_meta_data( '_ffl_bridge_store_pickup', 'no' );
		if ( $shipping ) {
			FFL_Bridge_Ship_To_Dealer::apply_to_order( $order, $dealer, true );
		}

		$who = '' !== $user_name ? $user_name : __( 'store staff', 'ffl-bridge-for-woocommerce' );
		if ( null === $previous ) {
			/* translators: 1: user display name, 2: dealer name, 3: license number. */
			$note = sprintf( __( 'Transfer dealer added by %1$s: %2$s (%3$s).', 'ffl-bridge-for-woocommerce' ), $who, $dealer['name'], $dealer['license'] );
		} else {
			/* translators: 1: user display name, 2: previous dealer name, 3: previous license number, 4: new dealer name, 5: new license number. */
			$note = sprintf( __( 'Transfer dealer changed by %1$s from %2$s (%3$s) to %4$s (%5$s).', 'ffl-bridge-for-woocommerce' ), $who, $previous['name'], $previous['license'], $dealer['name'], $dealer['license'] );
		}
		if ( $confirmed ) {
			$note .= ' ' . __( 'Store confirmation for the previous dealer was cleared; confirm the transfer with the new dealer.', 'ffl-bridge-for-woocommerce' );
		}
		if ( $shipping ) {
			$note .= ' ' . __( 'The shipping address was set to the new dealer.', 'ffl-bridge-for-woocommerce' );
		}

		$order->add_order_note( $note );
		$order->save();
	}

	/**
	 * Check capability and the order-specific nonce. Returns the order ID.
	 *
	 * @return int
	 */
	private static function verify_request(): int {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to change this order\'s dealer.', 'ffl-bridge-for-woocommerce' ) ), 403 );
		}

		// The nonce action includes the order ID, so the ID is read first and
		// the nonce is verified before anything else is used.
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked next.
		if ( 0 === $order_id || ! check_ajax_referer( self::NONCE_PREFIX . $order_id, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'The security token expired. Reload the order and try again.', 'ffl-bridge-for-woocommerce' ) ), 403 );
		}

		return $order_id;
	}
}
