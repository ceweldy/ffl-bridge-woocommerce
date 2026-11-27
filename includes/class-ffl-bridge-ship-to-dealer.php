<?php
/**
 * Optional setting: use the chosen dealer as the shipping address.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes the selected dealer's premises address and name into the shipping
 * fields and keeps them there.
 *
 * The browser only displays the address. The server sets it on the customer
 * when a dealer is selected, puts it back after every Checkout Block address
 * update, uses it for shipping rates, and sets it on the order at creation
 * from the dealer record FFL Bridge returns at that moment. Billing details and
 * the shipping first and last name (the shopper, for the label) are never
 * changed.
 */
final class FFL_Bridge_Ship_To_Dealer {

	public const OPTION = 'ffl_bridge_ship_to_dealer';

	/**
	 * Session key for the shopper's own shipping address, kept while a dealer
	 * address replaces it.
	 */
	private const BACKUP_KEY = 'ffl_bridge_shipping_backup';

	/**
	 * Shipping fields the dealer address replaces.
	 */
	public const FIELDS = array( 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'filter_packages' ), 20 );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'filter_posted_data' ), 20 );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( __CLASS__, 'store_api_update_customer' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'store_api_update_order' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'store_api_order_processed' ), 5 );
		// WooCommerce fires woocommerce_blocks_loaded during plugins_loaded at
		// priority 10, before this plugin initializes at 20.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api_callback();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_callback' ) );
		}
	}

	/**
	 * Whether the store turned the setting on.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION, 'no' );
	}

	/**
	 * Whether the dealer address applies to the current cart.
	 *
	 * @return bool
	 */
	public static function applies(): bool {
		// In-store pickup has no shipping address to replace.
		return self::enabled() && FFL_Bridge_Checkout::cart_requires_ffl() && ! FFL_Bridge_Store_Pickup::active();
	}

	/**
	 * Shipping field values for a dealer.
	 *
	 * @param array<string, mixed> $dealer Server-normalized dealer.
	 * @return array<string, string>
	 */
	public static function address_fields( array $dealer ): array {
		return array(
			'company'   => (string) ( $dealer['name'] ?? '' ),
			'address_1' => (string) ( $dealer['address'] ?? '' ),
			'address_2' => '',
			'city'      => (string) ( $dealer['city'] ?? '' ),
			'state'     => (string) ( $dealer['state'] ?? '' ),
			'postcode'  => (string) ( $dealer['zip'] ?? '' ),
			'country'   => 'US',
		);
	}

	/**
	 * The dealer address for the current session selection, if it applies.
	 *
	 * @return array<string, string>|null
	 */
	public static function current_fields(): ?array {
		if ( ! self::applies() ) {
			return null;
		}

		$selection = FFL_Bridge_Checkout::get_current_selection();
		return is_array( $selection ) && is_array( $selection['dealer'] ?? null ) ? self::address_fields( $selection['dealer'] ) : null;
	}

	/**
	 * Called after a dealer is selected: save the shopper's address once and
	 * write the dealer address onto the customer.
	 *
	 * @param array<string, mixed> $dealer Canonical dealer from FFL Bridge.
	 * @return array<string, string>|null The fields written, or null when the setting does not apply.
	 */
	public static function on_select( array $dealer ): ?array {
		if ( ! self::applies() || ! function_exists( 'WC' ) || ! WC()->customer ) {
			return null;
		}

		$customer = WC()->customer;
		if ( WC()->session && ! is_array( WC()->session->get( self::BACKUP_KEY ) ) ) {
			$backup = array();
			foreach ( self::FIELDS as $field ) {
				$backup[ $field ] = (string) $customer->{'get_shipping_' . $field}();
			}
			WC()->session->set( self::BACKUP_KEY, $backup );
		}

		$fields = self::address_fields( $dealer );
		self::write_customer( $customer, $fields );
		return $fields;
	}

	/**
	 * Put the shopper's own shipping address back after the dealer is cleared.
	 *
	 * @return array<string, string>|null The restored fields, or null when nothing was saved.
	 */
	public static function restore_customer(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		$backup = WC()->session->get( self::BACKUP_KEY );
		WC()->session->__unset( self::BACKUP_KEY );
		if ( ! is_array( $backup ) || ! WC()->customer ) {
			return null;
		}

		$fields = array();
		foreach ( self::FIELDS as $field ) {
			$fields[ $field ] = is_string( $backup[ $field ] ?? null ) ? $backup[ $field ] : '';
		}
		self::write_customer( WC()->customer, $fields );
		return $fields;
	}

	/**
	 * Classic checkout: before WooCommerce validates the posted fields, ship
	 * to a different address with the dealer address from the session, and
	 * give the label the shopper's name and phone when those shipping fields
	 * were left empty (WooCommerce requires them once shipping is separate).
	 * The order address is set again from the re-checked record at creation.
	 *
	 * @param mixed $data Posted checkout data.
	 * @return mixed
	 */
	public static function filter_posted_data( mixed $data ): mixed {
		$fields = is_array( $data ) ? self::current_fields() : null;
		if ( null === $fields ) {
			return $data;
		}

		$data['ship_to_different_address'] = true;
		foreach ( $fields as $field => $value ) {
			$data[ 'shipping_' . $field ] = $value;
		}
		foreach ( array( 'first_name', 'last_name', 'phone' ) as $field ) {
			if ( '' === trim( (string) ( $data[ 'shipping_' . $field ] ?? '' ) ) && isset( $data[ 'billing_' . $field ] ) ) {
				$data[ 'shipping_' . $field ] = (string) $data[ 'billing_' . $field ];
			}
		}

		return $data;
	}

	/**
	 * Use the dealer address for shipping rates.
	 *
	 * @param mixed $packages Cart shipping packages.
	 * @return mixed
	 */
	public static function filter_packages( mixed $packages ): mixed {
		$fields = is_array( $packages ) ? self::current_fields() : null;
		if ( null === $fields ) {
			return $packages;
		}

		foreach ( $packages as $index => $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}
			$destination              = is_array( $package['destination'] ?? null ) ? $package['destination'] : array();
			$destination['country']   = $fields['country'];
			$destination['state']     = $fields['state'];
			$destination['postcode']  = $fields['postcode'];
			$destination['city']      = $fields['city'];
			$destination['address']   = $fields['address_1'];
			$destination['address_1'] = $fields['address_1'];
			$destination['address_2'] = '';

			$packages[ $index ]['destination'] = $destination;
		}

		return $packages;
	}

	/**
	 * Checkout Block: put the dealer address back after the shopper or the
	 * block updates the customer address.
	 *
	 * @param object $customer WC_Customer.
	 * @param mixed  $request Store API request (unused).
	 * @return void
	 */
	public static function store_api_update_customer( object $customer, mixed $request = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WooCommerce hook signature.
		$fields = self::current_fields();
		if ( null !== $fields ) {
			self::write_customer( $customer, $fields );
		}
	}

	/**
	 * Checkout Block: after the Store API writes the request's addresses onto
	 * the order, set the shipping address from the re-checked dealer record.
	 *
	 * @param WC_Order $order Order.
	 * @param mixed    $request Store API request (unused).
	 * @return void
	 */
	public static function store_api_update_order( WC_Order $order, mixed $request = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WooCommerce hook signature.
		if ( ! self::applies() ) {
			return;
		}

		$dealer = FFL_Bridge_Checkout::verified_dealer();
		if ( is_array( $dealer ) ) {
			self::apply_to_order( $order, $dealer );
		}
	}

	/**
	 * Checkout Block: apply once more just before payment and save.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function store_api_order_processed( WC_Order $order ): void {
		if ( ! self::applies() ) {
			return;
		}

		$dealer = FFL_Bridge_Checkout::verified_dealer();
		if ( is_array( $dealer ) ) {
			self::apply_to_order( $order, $dealer );
			$order->save();
		}

		// The Store API has just copied the order addresses onto the account.
		self::restore_account( $order );
	}

	/**
	 * Put a logged-in customer's saved account shipping address back after
	 * checkout copied the dealer address onto it (classic process_customer,
	 * Store API sync_customer_data_with_order). The order keeps the dealer.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function restore_account( WC_Order $order ): void {
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id <= 0 || 'yes' !== $order->get_meta( '_ffl_bridge_ship_to_dealer' ) || ! function_exists( 'WC' ) || ! WC()->session || ! class_exists( 'WC_Customer' ) ) {
			return;
		}

		$backup = WC()->session->get( self::BACKUP_KEY );
		if ( ! is_array( $backup ) ) {
			return;
		}

		try {
			$account = new WC_Customer( $customer_id );
			foreach ( self::FIELDS as $field ) {
				$account->{'set_shipping_' . $field}( is_string( $backup[ $field ] ?? null ) ? $backup[ $field ] : '' );
			}
			$account->save();
		} catch ( Throwable $error ) {
			// The order is already placed; the account address is best effort.
			unset( $error );
		}
	}

	/**
	 * Set the order's shipping address from a canonical dealer record.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $dealer Canonical dealer re-fetched from FFL Bridge.
	 * @param bool                 $staff_change True when store staff change the dealer on
	 *                                           the order screen, where there is no cart; the
	 *                                           caller adds its own note.
	 * @return void
	 */
	public static function apply_to_order( WC_Order $order, array $dealer, bool $staff_change = false ): void {
		if ( $staff_change ? ! self::enabled() : ! self::applies() ) {
			return;
		}

		$fields = self::address_fields( $dealer );
		foreach ( $fields as $field => $value ) {
			$order->{'set_shipping_' . $field}( $value );
		}

		// The label still names the shopper; fill it from billing when empty.
		if ( '' === (string) $order->get_shipping_first_name() && '' === (string) $order->get_shipping_last_name() ) {
			$order->set_shipping_first_name( (string) $order->get_billing_first_name() );
			$order->set_shipping_last_name( (string) $order->get_billing_last_name() );
		}

		if ( $staff_change ) {
			$order->update_meta_data( '_ffl_bridge_ship_to_dealer', 'yes' );
		} elseif ( 'yes' !== $order->get_meta( '_ffl_bridge_ship_to_dealer' ) ) {
			$order->update_meta_data( '_ffl_bridge_ship_to_dealer', 'yes' );
			FFL_Bridge_Checkout::add_note(
				$order,
				sprintf(
					/* translators: 1: dealer name, 2: dealer address. */
					__( 'Shipping address set to the selected dealer: %1$s, %2$s.', 'ffl-bridge-for-woocommerce' ),
					$fields['company'],
					trim( $fields['address_1'] . ', ' . $fields['city'] . ', ' . $fields['state'] . ' ' . $fields['postcode'] )
				)
			);
		}
	}

	/**
	 * Checkout Block: the block calls this through extensionCartUpdate after
	 * a selection changes, so the cart it gets back shows the dealer address
	 * (or the shopper's address after a clear).
	 *
	 * @return void
	 */
	public static function register_store_api_callback(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'ffl-bridge',
				'callback'  => array( __CLASS__, 'sync_customer' ),
			)
		);
	}

	/**
	 * Write the dealer address onto the customer, or restore the shopper's.
	 *
	 * @param mixed $data Ignored; the browser cannot choose the address.
	 * @return void
	 */
	public static function sync_customer( mixed $data = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Store API callback signature.
		$fields = self::current_fields();
		if ( null !== $fields && function_exists( 'WC' ) && WC()->customer ) {
			self::write_customer( WC()->customer, $fields );
			return;
		}

		self::restore_customer();
	}

	/**
	 * Write shipping fields onto a customer and save it.
	 *
	 * @param object                $customer WC_Customer.
	 * @param array<string, string> $fields Fields.
	 * @return void
	 */
	private static function write_customer( object $customer, array $fields ): void {
		foreach ( $fields as $field => $value ) {
			$customer->{'set_shipping_' . $field}( $value );
		}

		// The label names the shopper: use the billing name when no shipping
		// name was saved, as classic checkout does.
		if ( '' === (string) $customer->get_shipping_first_name() && '' === (string) $customer->get_shipping_last_name() ) {
			$customer->set_shipping_first_name( (string) $customer->get_billing_first_name() );
			$customer->set_shipping_last_name( (string) $customer->get_billing_last_name() );
		}
		$customer->save();
	}
}
