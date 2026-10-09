<?php
/**
 * FFL Bridge order display with legacy read compatibility.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Displays the selected transfer dealer without treating it as a shipping
 * address or a substitute for merchant verification.
 */
final class FFL_Bridge_Order {

	/**
	 * Register order display hooks for legacy orders and HPOS.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'display_admin_order_ffl' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'display_thankyou_ffl' ), 5 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'display_email_ffl' ), 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'display_order_details_ffl' ) );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_column' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_column' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_order_column_hpos' ), 10, 2 );
	}

	/**
	 * Read current namespaced metadata, with read-only fallback for older orders.
	 *
	 * Status fields:
	 * - transfer_status: confirmed or unconfirmed, as known when the order was
	 *   placed. Orders from 1.1.0 were always verified network selections.
	 *   Orders from the unreleased fallback stored only a combined flag, which
	 *   is read as unconfirmed.
	 * - verified: whether FFL Bridge had verified a license copy.
	 * - basis: verified_network or hybrid.
	 * - store_confirmed_at and store_confirmed_by: the store's own confirmation.
	 *
	 * @param WC_Order|false|null $order Order object.
	 * @return array<string, string>|null
	 */
	public static function get_ffl_data( WC_Order|false|null $order ): ?array {
		if ( ! $order ) {
			return null;
		}

		$license = (string) $order->get_meta( '_ffl_bridge_license' );
		$legacy  = '' === $license;
		if ( $legacy ) {
			$license = (string) $order->get_meta( '_ffl_license' );
		}
		if ( '' === $license ) {
			return null;
		}

		$prefix = $legacy ? '_ffl_' : '_ffl_bridge_';
		$data   = array(
			'dealer_id'          => $legacy ? '' : (string) $order->get_meta( '_ffl_bridge_dealer_id' ),
			'license'            => $license,
			'license_type'       => $legacy ? '' : (string) $order->get_meta( '_ffl_bridge_license_type' ),
			'name'               => (string) $order->get_meta( $prefix . 'name' ),
			'business_name'      => $legacy ? '' : (string) $order->get_meta( '_ffl_bridge_business_name' ),
			'address'            => (string) $order->get_meta( $prefix . 'address' ),
			'city'               => (string) $order->get_meta( $prefix . 'city' ),
			'state'              => (string) $order->get_meta( $prefix . 'state' ),
			'zip'                => (string) $order->get_meta( $prefix . 'zip' ),
			'phone'              => (string) $order->get_meta( $prefix . 'phone' ),
			'license_on_file'    => $legacy ? '' : (string) $order->get_meta( '_ffl_bridge_license_on_file' ),
			'verified'           => $legacy ? '' : (string) $order->get_meta( '_ffl_bridge_license_verified' ),
			'verified_at'        => $legacy ? '' : (string) $order->get_meta( '_ffl_bridge_verified_at' ),
			'source'             => $legacy ? 'legacy' : (string) $order->get_meta( '_ffl_bridge_source' ),
			'transfer_status'    => '',
			'basis'              => '',
			'store_confirmed_at' => (string) $order->get_meta( '_ffl_bridge_store_confirmed_at' ),
			'store_confirmed_by' => (string) $order->get_meta( '_ffl_bridge_store_confirmed_by_name' ),
			'license_file'       => ! empty( $order->get_meta( '_ffl_bridge_license_file' ) ) ? 'yes' : 'no',
		);

		if ( $legacy ) {
			return $data;
		}

		$status = (string) $order->get_meta( '_ffl_bridge_transfer_status' );
		$basis  = (string) $order->get_meta( '_ffl_bridge_selection_basis' );
		if ( '' === $basis ) {
			$combined = (string) $order->get_meta( '_ffl_bridge_transfer_confirmed' );
			$basis    = 'no' === $combined ? FFL_Bridge_Network::SELECTION_HYBRID : FFL_Bridge_Network::SELECTION_NETWORK;
			$status   = 'no' === $combined ? 'unconfirmed' : 'confirmed';
		}

		$data['transfer_status'] = 'confirmed' === $status ? 'confirmed' : 'unconfirmed';
		$data['basis']           = FFL_Bridge_Network::SELECTION_HYBRID === $basis ? FFL_Bridge_Network::SELECTION_HYBRID : FFL_Bridge_Network::SELECTION_NETWORK;
		return $data;
	}

	/**
	 * Determine whether transfer acceptance is confirmed for this order.
	 *
	 * @param array<string, string> $ffl Dealer values.
	 * @return bool
	 */
	public static function is_transfer_confirmed( array $ffl ): bool {
		return 'confirmed' === ( $ffl['transfer_status'] ?? '' ) || '' !== ( $ffl['store_confirmed_at'] ?? '' );
	}

	/**
	 * Determine whether the order's dealer still needs transfer follow-up.
	 *
	 * @param array<string, string> $ffl Dealer values.
	 * @return bool
	 */
	public static function is_unconfirmed( array $ffl ): bool {
		return FFL_Bridge_Followup::order_needs_follow_up( $ffl );
	}

	/**
	 * Short status labels for the order screen and order list.
	 *
	 * @param array<string, string> $ffl Dealer values.
	 * @return array{transfer: string, license: string, basis: string}
	 */
	public static function status_labels( array $ffl ): array {
		if ( '' !== ( $ffl['store_confirmed_at'] ?? '' ) ) {
			$transfer = __( 'Confirmed by the store', 'ffl-bridge-for-woocommerce' );
		} elseif ( 'confirmed' === ( $ffl['transfer_status'] ?? '' ) ) {
			$transfer = __( 'Confirmed by the dealer with FFL Bridge', 'ffl-bridge-for-woocommerce' );
		} else {
			$transfer = __( 'Not confirmed', 'ffl-bridge-for-woocommerce' );
		}

		return array(
			'transfer' => $transfer,
			'license'  => 'yes' === ( $ffl['verified'] ?? '' ) ? __( 'Verified by FFL Bridge', 'ffl-bridge-for-woocommerce' ) : __( 'Not verified', 'ffl-bridge-for-woocommerce' ),
			'basis'    => FFL_Bridge_Network::SELECTION_HYBRID === ( $ffl['basis'] ?? '' )
				? __( 'Hybrid selection (dealer needed follow-up at checkout)', 'ffl-bridge-for-woocommerce' )
				: __( 'Verified checkout network', 'ffl-bridge-for-woocommerce' ),
		);
	}

	/**
	 * Format the stored dealer location.
	 *
	 * @param array<string, string> $ffl Dealer values.
	 * @return string
	 */
	public static function format_address( array $ffl ): string {
		$locality = trim( ( $ffl['city'] ?? '' ) . ', ' . ( $ffl['state'] ?? '' ) . ' ' . ( $ffl['zip'] ?? '' ), ' ,' );
		return implode( ', ', array_filter( array( $ffl['address'] ?? '', $locality ) ) );
	}

	/**
	 * Display administrative order details, status, and the confirm action.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public static function display_admin_order_ffl( WC_Order $order ): void {
		$ffl = self::get_ffl_data( $order );
		if ( null === $ffl ) {
			return;
		}

		$labels = self::status_labels( $ffl );
		?>
		<div class="ffl-bridge-admin-order">
			<h3><?php echo esc_html__( 'Selected transfer dealer', 'ffl-bridge-for-woocommerce' ); ?></h3>
			<p>
				<strong><?php echo esc_html( $ffl['name'] ); ?></strong><br>
				<?php echo esc_html( self::format_address( $ffl ) ); ?><br>
				<?php if ( '' !== $ffl['phone'] ) : ?>
					<?php echo esc_html__( 'Phone:', 'ffl-bridge-for-woocommerce' ); ?> <?php echo esc_html( $ffl['phone'] ); ?><br>
				<?php endif; ?>
				<strong><?php echo esc_html__( 'License:', 'ffl-bridge-for-woocommerce' ); ?></strong> <?php echo esc_html( $ffl['license'] ); ?>
			</p>
			<?php if ( 'legacy' === $ffl['source'] ) : ?>
				<p><strong><?php echo esc_html__( 'Legacy record:', 'ffl-bridge-for-woocommerce' ); ?></strong> <?php echo esc_html__( 'This dealer was saved by an older plugin version and was not server-verified under the current workflow.', 'ffl-bridge-for-woocommerce' ); ?></p>
			<?php else : ?>
				<p class="ffl-bridge-status <?php echo self::is_transfer_confirmed( $ffl ) ? 'is-confirmed' : 'is-unconfirmed'; ?>">
					<?php echo esc_html__( 'Transfer:', 'ffl-bridge-for-woocommerce' ); ?> <strong><?php echo esc_html( $labels['transfer'] ); ?></strong><br>
					<?php echo esc_html__( 'License copy:', 'ffl-bridge-for-woocommerce' ); ?> <strong><?php echo esc_html( $labels['license'] ); ?></strong><br>
					<?php echo esc_html__( 'Selection:', 'ffl-bridge-for-woocommerce' ); ?> <?php echo esc_html( $labels['basis'] ); ?>
					<?php if ( '' !== $ffl['store_confirmed_at'] ) : ?>
						<br>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: user display name, 2: date and time. */
								__( 'Store confirmation: %1$s on %2$s', 'ffl-bridge-for-woocommerce' ),
								'' !== $ffl['store_confirmed_by'] ? $ffl['store_confirmed_by'] : __( 'unknown user', 'ffl-bridge-for-woocommerce' ),
								$ffl['store_confirmed_at']
							)
						);
						?>
					<?php endif; ?>
				</p>
				<?php FFL_Bridge_Transfer_Confirmation::render_controls( $order, $ffl ); ?>
			<?php endif; ?>
			<p><strong><?php echo esc_html__( 'Fulfillment check required:', 'ffl-bridge-for-woocommerce' ); ?></strong> <?php echo esc_html__( 'Confirm current licensing, transfer acceptance, fees, and shipping instructions directly with the dealer. This metadata does not change the WooCommerce shipping address.', 'ffl-bridge-for-woocommerce' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Display dealer details after checkout.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function display_thankyou_ffl( int $order_id ): void {
		$order = wc_get_order( $order_id );
		$ffl   = self::get_ffl_data( $order );
		if ( null !== $ffl ) {
			self::render_customer_details( $ffl, 'thankyou' );
		}
	}

	/**
	 * Display dealer details in HTML or plain-text order email.
	 *
	 * Customer emails include the follow-up instructions while the dealer
	 * still needs confirmation. Admin emails show the transfer status instead.
	 *
	 * @param WC_Order $order Order object.
	 * @param bool     $sent_to_admin Whether sent to admin.
	 * @param bool     $plain_text Plain-text email flag.
	 * @param mixed    $email Email object.
	 * @return void
	 */
	public static function display_email_ffl( WC_Order $order, bool $sent_to_admin, bool $plain_text, mixed $email = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WooCommerce hook signature.
		$ffl = self::get_ffl_data( $order );
		if ( null === $ffl ) {
			return;
		}

		if ( $plain_text ) {
			echo esc_html( self::plain_text_details( $ffl, $sent_to_admin ) );
			return;
		}

		if ( $sent_to_admin ) {
			$labels = self::status_labels( $ffl );
			self::render_customer_details( $ffl, 'admin_email' );
			echo '<p>' . esc_html__( 'Transfer:', 'ffl-bridge-for-woocommerce' ) . ' ' . esc_html( $labels['transfer'] ) . '. ' . esc_html__( 'License copy:', 'ffl-bridge-for-woocommerce' ) . ' ' . esc_html( $labels['license'] ) . '.</p>';
			return;
		}

		self::render_customer_details( $ffl, 'email' );
	}

	/**
	 * Build the plain-text email block.
	 *
	 * @param array<string, string> $ffl Dealer values.
	 * @param bool                  $sent_to_admin Whether the email goes to the store.
	 * @return string
	 */
	public static function plain_text_details( array $ffl, bool $sent_to_admin ): string {
		$lines   = array( '', '', __( 'SELECTED TRANSFER DEALER', 'ffl-bridge-for-woocommerce' ) );
		$lines[] = $ffl['name'];
		$lines[] = self::format_address( $ffl );
		if ( '' !== $ffl['phone'] ) {
			$lines[] = __( 'Phone:', 'ffl-bridge-for-woocommerce' ) . ' ' . $ffl['phone'];
		}
		$lines[] = __( 'License:', 'ffl-bridge-for-woocommerce' ) . ' ' . $ffl['license'];

		if ( $sent_to_admin ) {
			$labels  = self::status_labels( $ffl );
			$lines[] = __( 'Transfer:', 'ffl-bridge-for-woocommerce' ) . ' ' . $labels['transfer'];
			$lines[] = __( 'License copy:', 'ffl-bridge-for-woocommerce' ) . ' ' . $labels['license'];
		} elseif ( FFL_Bridge_Followup::order_needs_follow_up( $ffl ) ) {
			$lines[] = FFL_Bridge_Followup::instructions( $ffl['name'], 'email' );
		} else {
			$lines[] = __( 'Contact the dealer to confirm acceptance, fees, and instructions.', 'ffl-bridge-for-woocommerce' );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Display dealer details in My Account order view.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public static function display_order_details_ffl( WC_Order $order ): void {
		$ffl = self::get_ffl_data( $order );
		if ( null !== $ffl ) {
			self::render_customer_details( $ffl, 'account' );
		}
	}

	/**
	 * Add the transfer-dealer order-list column.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public static function add_order_column( array $columns ): array {
		$new_columns = array();
		$inserted    = false;
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'shipping_address' === $key ) {
				$new_columns['ffl_bridge_dealer'] = esc_html__( 'Transfer dealer', 'ffl-bridge-for-woocommerce' );
				$inserted                         = true;
			}
		}

		if ( ! $inserted ) {
			$new_columns['ffl_bridge_dealer'] = esc_html__( 'Transfer dealer', 'ffl-bridge-for-woocommerce' );
		}

		return $new_columns;
	}

	/**
	 * Render the column for legacy order storage.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Order post ID.
	 * @return void
	 */
	public static function render_order_column( string $column, int $post_id ): void {
		if ( 'ffl_bridge_dealer' === $column ) {
			self::render_column_value( wc_get_order( $post_id ) );
		}
	}

	/**
	 * Render the column for HPOS.
	 *
	 * @param string   $column Column name.
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public static function render_order_column_hpos( string $column, WC_Order $order ): void {
		if ( 'ffl_bridge_dealer' === $column ) {
			self::render_column_value( $order );
		}
	}

	/**
	 * Render customer-facing dealer details.
	 *
	 * @param array<string, string> $ffl Dealer values.
	 * @param string                $context thankyou, account, email, or admin_email.
	 * @return void
	 */
	private static function render_customer_details( array $ffl, string $context ): void {
		$follow_up = 'admin_email' !== $context && FFL_Bridge_Followup::order_needs_follow_up( $ffl );
		?>
		<section class="woocommerce-ffl-details">
			<h2><?php echo esc_html__( 'Selected transfer dealer', 'ffl-bridge-for-woocommerce' ); ?></h2>
			<address>
				<strong><?php echo esc_html( $ffl['name'] ); ?></strong><br>
				<?php echo esc_html( self::format_address( $ffl ) ); ?><br>
				<?php if ( '' !== $ffl['phone'] ) : ?>
					<?php echo esc_html( $ffl['phone'] ); ?><br>
				<?php endif; ?>
				<?php echo esc_html__( 'License:', 'ffl-bridge-for-woocommerce' ); ?> <?php echo esc_html( $ffl['license'] ); ?>
			</address>
			<?php if ( $follow_up ) : ?>
				<p class="ffl-bridge-followup"><strong><?php echo esc_html( FFL_Bridge_Followup::instructions( $ffl['name'], $context ) ); ?></strong></p>
			<?php else : ?>
				<p><?php echo esc_html__( 'Contact the dealer to confirm current licensing, transfer acceptance, fees, and shipment instructions. Selection alone does not guarantee acceptance.', 'ffl-bridge-for-woocommerce' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render the order-list value.
	 *
	 * @param WC_Order|false $order Order object.
	 * @return void
	 */
	private static function render_column_value( WC_Order|false $order ): void {
		$ffl = self::get_ffl_data( $order );
		if ( null === $ffl ) {
			echo '<span class="na">&ndash;</span>';
			return;
		}

		echo '<span title="' . esc_attr( $ffl['license'] ) . '">' . esc_html( $ffl['name'] ) . '</span>';
		if ( 'legacy' !== $ffl['source'] && ! self::is_transfer_confirmed( $ffl ) ) {
			echo '<br><em>' . esc_html__( 'Transfer not confirmed', 'ffl-bridge-for-woocommerce' ) . '</em>';
		} elseif ( self::is_unconfirmed( $ffl ) ) {
			echo '<br><em>' . esc_html__( 'License not verified', 'ffl-bridge-for-woocommerce' ) . '</em>';
		}
	}
}
