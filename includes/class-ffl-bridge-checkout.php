<?php
/**
 * Secure classic and Checkout Block dealer-selection workflow.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the browser-to-session flow and order persistence.
 */
final class FFL_Bridge_Checkout {

	private const SESSION_KEY       = 'ffl_bridge_selection_v1';
	private const SESSION_TTL       = 14400;
	private const AJAX_NONCE_ACTION = 'ffl_bridge_checkout';
	private const SEARCH_LIMIT      = 12;
	private const SEARCH_WINDOW     = 60;
	private const SELECT_LIMIT      = 20;
	private const SELECT_WINDOW     = 60;
	private const ORDER_META_SCHEMA = '1';

	/**
	 * Request-local cache of canonical dealer lookups.
	 *
	 * @var array<string, array<string, mixed>|WP_Error>
	 */
	private static array $verification_cache = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render_classic_selector' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_classic_checkout' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_classic_order' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'save_store_api_order' ), 10, 1 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'clear_selection' ), 50 );

		add_action( 'wp_ajax_ffl_bridge_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_ffl_bridge_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_ffl_bridge_select', array( __CLASS__, 'ajax_select' ) );
		add_action( 'wp_ajax_nopriv_ffl_bridge_select', array( __CLASS__, 'ajax_select' ) );
		add_action( 'wp_ajax_ffl_bridge_clear', array( __CLASS__, 'ajax_clear' ) );
		add_action( 'wp_ajax_nopriv_ffl_bridge_clear', array( __CLASS__, 'ajax_clear' ) );
	}

	/**
	 * Determine whether the current cart contains an applicable product.
	 *
	 * Empty category settings intentionally apply the workflow to every cart
	 * product so a fresh installation fails safely until configured.
	 *
	 * @return bool
	 */
	public static function cart_requires_ffl(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return false;
		}

		$categories = get_option( 'ffl_bridge_categories', array() );
		$categories = is_array( $categories ) ? array_values( array_filter( array_map( 'absint', $categories ) ) ) : array();
		if ( empty( $categories ) ) {
			return true;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
			if ( 0 === $product_id ) {
				continue;
			}

			$product_categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $product_categories ) && array_intersect( $categories, array_map( 'absint', $product_categories ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether checkout must fail without a valid selection.
	 *
	 * @return bool
	 */
	public static function selection_is_required(): bool {
		return self::cart_requires_ffl() && 'yes' === get_option( 'ffl_bridge_required', 'yes' );
	}

	/**
	 * Load local checkout assets for classic checkout.
	 *
	 * Checkout Blocks register the same base script through the integration
	 * registry, so this path intentionally skips block checkout pages.
	 *
	 * @return void
	 */
	public static function enqueue_scripts(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || ! self::cart_requires_ffl() ) {
			return;
		}

		wp_enqueue_style(
			'ffl-bridge-checkout',
			FFL_BRIDGE_PLUGIN_URL . 'assets/css/checkout.css',
			array(),
			FFL_BRIDGE_VERSION
		);
		wp_enqueue_script(
			'ffl-bridge-checkout',
			FFL_BRIDGE_PLUGIN_URL . 'assets/js/checkout.js',
			array( 'jquery' ),
			FFL_BRIDGE_VERSION,
			true
		);
		wp_localize_script( 'ffl-bridge-checkout', 'fflBridgeData', self::get_frontend_config() );
	}

	/**
	 * Render the mount point for classic checkout.
	 *
	 * @param mixed $checkout WooCommerce checkout object.
	 * @return void
	 */
	public static function render_classic_selector( mixed $checkout = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! self::cart_requires_ffl() ) {
			return;
		}

		echo '<div class="ffl-bridge-section">';
		echo '<h3>' . esc_html__( 'Select a transfer dealer', 'ffl-bridge-for-woocommerce' );
		if ( self::selection_is_required() ) {
			echo ' <span class="ffl-bridge-required" aria-hidden="true">*</span><span class="screen-reader-text">' . esc_html__( 'Required', 'ffl-bridge-for-woocommerce' ) . '</span>';
		}
		echo '</h3>';
		echo '<p>' . esc_html__( 'Search for a dealer, then contact that dealer to confirm current licensing, transfer acceptance, fees, and shipment instructions.', 'ffl-bridge-for-woocommerce' ) . '</p>';
		echo '<div class="ffl-bridge-selector" data-ffl-bridge-selector></div>';
		echo '<noscript><p class="woocommerce-error">' . esc_html__( 'JavaScript is required to select a transfer dealer.', 'ffl-bridge-for-woocommerce' ) . '</p></noscript>';
		echo '</div>';
	}

	/**
	 * Return non-secret configuration for either checkout frontend.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_frontend_config(): array {
		$selection = self::get_current_selection();
		$dealer    = is_array( $selection ) && isset( $selection['dealer'] ) && is_array( $selection['dealer'] ) ? self::public_dealer( $selection['dealer'] ) : null;

		return array(
			'enabled'      => self::cart_requires_ffl(),
			'configured'   => FFL_Bridge_API_Client::is_configured(),
			'required'     => self::selection_is_required(),
			'theme'        => 'dark' === get_option( 'ffl_bridge_theme', 'light' ) ? 'dark' : 'light',
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( self::AJAX_NONCE_ACTION ),
			'requestToken' => FFL_Bridge_Selection::get_request_token(),
			'initialZip'   => self::get_customer_zip(),
			'selected'     => $dealer,
			'messages'     => array(
				'heading'       => esc_html__( 'Select a transfer dealer', 'ffl-bridge-for-woocommerce' ),
				'description'   => esc_html__( 'Search for a dealer, then contact that dealer to confirm current licensing, transfer acceptance, fees, and shipment instructions.', 'ffl-bridge-for-woocommerce' ),
				'notConfigured' => esc_html__( 'Dealer selection is unavailable because the store connection is not configured. Contact the store before ordering.', 'ffl-bridge-for-woocommerce' ),
				'zipLabel'      => esc_html__( 'ZIP code', 'ffl-bridge-for-woocommerce' ),
				'radiusLabel'   => esc_html__( 'Radius', 'ffl-bridge-for-woocommerce' ),
				'search'        => esc_html__( 'Search dealers', 'ffl-bridge-for-woocommerce' ),
				'searching'     => esc_html__( 'Searching…', 'ffl-bridge-for-woocommerce' ),
				'select'        => esc_html__( 'Select dealer', 'ffl-bridge-for-woocommerce' ),
				'selecting'     => esc_html__( 'Verifying…', 'ffl-bridge-for-woocommerce' ),
				'clear'         => esc_html__( 'Choose a different dealer', 'ffl-bridge-for-woocommerce' ),
				'noResults'     => esc_html__( 'No eligible dealers were found in that area.', 'ffl-bridge-for-woocommerce' ),
				'genericError'  => esc_html__( 'The dealer request could not be completed. Try again.', 'ffl-bridge-for-woocommerce' ),
				'selectedTitle' => esc_html__( 'Selected transfer dealer', 'ffl-bridge-for-woocommerce' ),
				'confirmNotice' => esc_html__( 'Selection does not guarantee that the dealer will accept this transfer. Contact the dealer before the order ships.', 'ffl-bridge-for-woocommerce' ),
				'miles'         => esc_html__( 'miles away', 'ffl-bridge-for-woocommerce' ),
				'verifiedBadge' => esc_html__( 'Verified checkout network', 'ffl-bridge-for-woocommerce' ),
				'directoryOnly' => esc_html__( 'Directory listing only', 'ffl-bridge-for-woocommerce' ),
				'directoryNote' => esc_html__( 'This dealer is listed in the public FFL directory but is not yet verified for checkout, so it cannot be selected here.', 'ffl-bridge-for-woocommerce' ),
				'preferred'     => esc_html__( 'Store preferred dealer', 'ffl-bridge-for-woocommerce' ),
				'unconfirmed'   => esc_html__( 'Transfer not confirmed', 'ffl-bridge-for-woocommerce' ),
				'contactDealer' => esc_html__( 'Contact this dealer to confirm they will accept the transfer before you order.', 'ffl-bridge-for-woocommerce' ),
				'licenseBadge'  => esc_html__( 'License not verified', 'ffl-bridge-for-woocommerce' ),
				'licenseNote'   => esc_html__( 'This dealer has confirmed transfers with FFL Bridge, but its license copy is not verified yet. Contact the dealer before you order.', 'ffl-bridge-for-woocommerce' ),
			),
		);
	}

	/**
	 * Search dealers from a same-origin, session-bound request.
	 *
	 * @return void
	 */
	public static function ajax_search(): void {
		$error = self::validate_ajax_request();
		if ( is_wp_error( $error ) ) {
			self::send_ajax_error( $error );
		}

		if ( ! self::cart_requires_ffl() ) {
			self::send_ajax_error( new WP_Error( 'ffl_bridge_not_applicable', __( 'This cart does not require dealer selection.', 'ffl-bridge-for-woocommerce' ) ), 400 );
		}

		$rate_error = self::enforce_rate_limit( 'search', self::SEARCH_LIMIT, self::SEARCH_WINDOW );
		if ( is_wp_error( $rate_error ) ) {
			self::send_ajax_error( $rate_error, 429 );
		}

		$zip      = self::post_text( 'zip', 10 );
		$radius   = isset( $_POST['radius'] ) ? absint( wp_unslash( $_POST['radius'] ) ) : 25; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$fallback = FFL_Bridge_Network::fallback_enabled();
		$primary  = FFL_Bridge_API_Client::search_with_meta( $zip, $radius, 20, true, $fallback );
		if ( is_wp_error( $primary ) ) {
			self::send_ajax_error( $primary );
		}

		$resolved = FFL_Bridge_Network::resolve(
			$primary,
			static fn () => FFL_Bridge_API_Client::search_with_meta( $zip, $radius, 20, false ),
			FFL_Bridge_Network::get_result_scope(),
			FFL_Bridge_Network::get_preferred_licenses(),
			$fallback
		);

		if ( FFL_Bridge_Network::OUTCOME_CONFIRMED !== $resolved['outcome'] ) {
			FFL_Bridge_Coverage::record_gap( $zip, $radius, FFL_Bridge_Network::OUTCOME_FALLBACK === $resolved['outcome'], $primary['reason'] );
		}

		$dealers = array();
		foreach ( $resolved['dealers'] as $dealer ) {
			$public = self::public_dealer( $dealer );

			// Directory-only listings never receive a selection handle, because
			// FFL Bridge rejects them when the selection is re-verified.
			if ( $dealer['selectable'] ) {
				$token = FFL_Bridge_Selection::create( $dealer );
				if ( is_wp_error( $token ) ) {
					continue;
				}
				$public['selectionToken'] = $token;
			}

			$public['network']        = $dealer['network'];
			$public['storePreferred'] = $dealer['store_preferred'];
			$dealers[]                = $public;
		}

		wp_send_json_success(
			array(
				'dealers' => $dealers,
				'outcome' => $resolved['outcome'],
				'notice'  => self::search_notice( $resolved['outcome'], $zip, $radius, self::selection_is_required(), $primary['coverage'], $primary['reason'] ),
			)
		);
	}

	/**
	 * Build the shopper message for a search without a confirmed dealer.
	 *
	 * Coverage counts and the empty-result reason code are used when the API
	 * sends them, and the message falls back to plain wording when it does
	 * not. The API's own reason message is not shown to shoppers because it
	 * is written for integrators and is not translated.
	 *
	 * @param string                  $outcome Search outcome.
	 * @param string                  $zip Searched ZIP code.
	 * @param int                     $radius Searched radius.
	 * @param bool                    $required Whether checkout requires a dealer.
	 * @param array<string, int>|null $coverage Optional API coverage counts.
	 * @param string                  $reason Optional API zero-result reason.
	 * @return string
	 */
	public static function search_notice( string $outcome, string $zip, int $radius, bool $required, ?array $coverage, string $reason ): string {
		if ( FFL_Bridge_Network::OUTCOME_CONFIRMED === $outcome ) {
			return '';
		}

		$parts = array(
			sprintf(
				/* translators: 1: radius in miles, 2: ZIP code. */
				__( 'No dealers within %1$d miles of %2$s are confirmed to accept transfers.', 'ffl-bridge-for-woocommerce' ),
				$radius,
				$zip
			),
		);

		$detail = self::coverage_detail( $coverage, $reason );
		if ( '' !== $detail ) {
			$parts[] = $detail;
		}

		if ( FFL_Bridge_Network::OUTCOME_FALLBACK === $outcome ) {
			$parts[] = __( 'The nearby licensed dealers below are shown so you can choose one, but their transfer acceptance is not confirmed. Contact the dealer to confirm they will accept the transfer before you order.', 'ffl-bridge-for-woocommerce' );
		} else {
			if ( $radius < max( FFL_Bridge_API_Client::ALLOWED_RADII ) ) {
				$parts[] = __( 'Try a larger radius.', 'ffl-bridge-for-woocommerce' );
			}
			$parts[] = $required
				? __( 'This order needs a transfer dealer before it can be placed. Contact the store for help arranging one.', 'ffl-bridge-for-woocommerce' )
				: __( 'You can still place the order. Contact the store to arrange a transfer dealer before it ships.', 'ffl-bridge-for-woocommerce' );
		}

		$message = implode( ' ', $parts );

		/**
		 * Filter the shopper message shown when no confirmed dealer is found.
		 *
		 * @param string $message Message text. Plain text, escaped on output.
		 * @param array  $context Outcome, ZIP, radius, required flag, coverage, and reason.
		 */
		$filtered = apply_filters(
			'ffl_bridge_search_notice',
			$message,
			array(
				'outcome'  => $outcome,
				'zip'      => $zip,
				'radius'   => $radius,
				'required' => $required,
				'coverage' => $coverage,
				'reason'   => $reason,
			)
		);

		return is_string( $filtered ) ? sanitize_text_field( $filtered ) : $message;
	}

	/**
	 * Explain a coverage gap from optional API data.
	 *
	 * @param array<string, int>|null $coverage Optional API coverage counts.
	 * @param string                  $reason Optional API zero-result reason.
	 * @return string
	 */
	private static function coverage_detail( ?array $coverage, string $reason ): string {
		$in_radius = $coverage['dealers_in_radius'] ?? null;
		$accepting = $coverage['accepting_transfers'] ?? null;

		if ( 0 === $in_radius || ( null === $in_radius && 'NO_DEALERS_IN_RADIUS' === $reason ) ) {
			return __( 'FFL Bridge lists no licensed dealers in this area.', 'ffl-bridge-for-woocommerce' );
		}

		if ( null !== $in_radius && 0 === $accepting ) {
			return sprintf(
				/* translators: %d: number of licensed dealers in the search area. */
				_n(
					'FFL Bridge lists %d licensed dealer in this area, but it is not confirmed to accept transfers yet.',
					'FFL Bridge lists %d licensed dealers in this area, but none are confirmed to accept transfers yet.',
					$in_radius,
					'ffl-bridge-for-woocommerce'
				),
				$in_radius
			);
		}

		$verified = $coverage['checkout_eligible'] ?? null;
		if ( ( null !== $accepting && $accepting > 0 && 0 === $verified ) || 'NO_VERIFIED_CHECKOUT_DEALERS_IN_RADIUS' === $reason ) {
			return __( 'Dealers in this area accept transfers, but none are verified for checkout yet.', 'ffl-bridge-for-woocommerce' );
		}

		if ( 'NO_TRANSFER_CONFIRMED_DEALERS_IN_RADIUS' === $reason ) {
			return __( 'Licensed dealers are listed in this area, but none are confirmed to accept transfers yet.', 'ffl-bridge-for-woocommerce' );
		}

		return '';
	}

	/**
	 * Verify an opaque result handle, re-fetch the canonical dealer, and save
	 * only that trusted object in the WooCommerce session.
	 *
	 * @return void
	 */
	public static function ajax_select(): void {
		$error = self::validate_ajax_request();
		if ( is_wp_error( $error ) ) {
			self::send_ajax_error( $error );
		}

		$rate_error = self::enforce_rate_limit( 'select', self::SELECT_LIMIT, self::SELECT_WINDOW );
		if ( is_wp_error( $rate_error ) ) {
			self::send_ajax_error( $rate_error, 429 );
		}

		$payload = FFL_Bridge_Selection::verify( self::post_text( 'selection', 2048 ) );
		if ( is_wp_error( $payload ) ) {
			self::send_ajax_error( $payload, 400 );
		}

		$allow_unconfirmed = ! empty( $payload['fb'] ) && FFL_Bridge_Network::fallback_enabled();
		$dealer            = FFL_Bridge_API_Client::get_dealer( $payload['id'], $allow_unconfirmed );
		if ( is_wp_error( $dealer ) ) {
			self::send_ajax_error( $dealer );
		}

		if ( ! hash_equals( $payload['lic'], $dealer['license'] ) || ! self::dealer_still_acceptable( $dealer ) ) {
			self::send_ajax_error( new WP_Error( 'ffl_bridge_dealer_unavailable', __( 'That dealer is not currently selectable. Choose another dealer or contact the store.', 'ffl-bridge-for-woocommerce' ) ), 409 );
		}

		$cart_hash = FFL_Bridge_Selection::get_cart_hash();
		if ( is_wp_error( $cart_hash ) || ! WC()->session ) {
			self::send_ajax_error( new WP_Error( 'ffl_bridge_session_unavailable', __( 'The checkout session is unavailable. Refresh and try again.', 'ffl-bridge-for-woocommerce' ) ), 409 );
		}

		$now = time();
		WC()->session->set(
			self::SESSION_KEY,
			array(
				'schema'      => self::ORDER_META_SCHEMA,
				'dealer'      => $dealer,
				'cart_hash'   => $cart_hash,
				'selected_at' => $now,
				'verified_at' => $now,
				'expires_at'  => $now + self::SESSION_TTL,
			)
		);

		wp_send_json_success(
			array(
				'dealer'  => self::public_dealer( $dealer ),
				'message' => empty( $dealer['transfer_confirmed'] )
					? esc_html__( 'Dealer selected. Transfer acceptance is not confirmed.', 'ffl-bridge-for-woocommerce' )
					: esc_html__( 'Dealer selected and verified for this cart.', 'ffl-bridge-for-woocommerce' ),
			)
		);
	}

	/**
	 * Clear a current selection from the checkout session.
	 *
	 * @return void
	 */
	public static function ajax_clear(): void {
		$error = self::validate_ajax_request();
		if ( is_wp_error( $error ) ) {
			self::send_ajax_error( $error );
		}

		self::clear_selection();
		wp_send_json_success( array( 'message' => esc_html__( 'Dealer selection cleared.', 'ffl-bridge-for-woocommerce' ) ) );
	}

	/**
	 * Add classic-checkout validation notices.
	 *
	 * @return void
	 */
	public static function validate_classic_checkout(): void {
		$result = self::verify_selection_for_checkout();
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		}
	}

	/**
	 * Save trusted dealer metadata during classic checkout.
	 *
	 * @param WC_Order $order New order object.
	 * @param mixed    $data Posted checkout data.
	 * @throws Exception If a required or selected dealer cannot be verified.
	 * @return void
	 */
	public static function save_classic_order( WC_Order $order, mixed $data = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WooCommerce hook signature.
		$result = self::verify_selection_for_checkout();
		if ( is_wp_error( $result ) ) {
			throw new Exception( esc_html( $result->get_error_message() ) );
		}

		if ( is_array( $result ) ) {
			self::persist_order_selection( $order, $result );
		}
	}

	/**
	 * Validate and save metadata for Checkout Blocks / Store API orders.
	 *
	 * Throwing here causes the Store API checkout to fail with the message,
	 * instead of accepting client-supplied extension data.
	 *
	 * @param WC_Order $order Store API order.
	 * @throws Exception If a required or selected dealer cannot be verified.
	 * @return void
	 */
	public static function save_store_api_order( WC_Order $order ): void {
		$result = self::verify_selection_for_checkout();
		if ( is_wp_error( $result ) ) {
			throw new Exception( esc_html( $result->get_error_message() ) );
		}

		if ( is_array( $result ) ) {
			self::persist_order_selection( $order, $result );
			$order->save();
		}
	}

	/**
	 * Return the current selection only if it still belongs to this cart.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_current_selection(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		$selection = WC()->session->get( self::SESSION_KEY );
		if ( ! is_array( $selection ) || ! isset( $selection['dealer'], $selection['cart_hash'], $selection['expires_at'] ) || ! is_array( $selection['dealer'] ) ) {
			return null;
		}

		$cart_hash = FFL_Bridge_Selection::get_cart_hash();
		if (
			is_wp_error( $cart_hash ) ||
			! is_string( $selection['cart_hash'] ) ||
			! hash_equals( $cart_hash, $selection['cart_hash'] ) ||
			(int) $selection['expires_at'] < time()
		) {
			self::clear_selection();
			return null;
		}

		return $selection;
	}

	/**
	 * Clear the session selection.
	 *
	 * @param mixed $unused Optional hook argument.
	 * @return void
	 */
	public static function clear_selection( mixed $unused = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( self::SESSION_KEY );
		}
	}

	/**
	 * Verify current session state and re-fetch the exact dealer before order
	 * creation. Optional unselected carts return null; an invalid saved
	 * selection always fails rather than silently disappearing.
	 *
	 * @return array<string, mixed>|WP_Error|null
	 */
	private static function verify_selection_for_checkout(): array|WP_Error|null {
		if ( ! self::cart_requires_ffl() ) {
			return null;
		}

		$required  = self::selection_is_required();
		$selection = self::get_current_selection();
		if ( null === $selection ) {
			return $required
				? new WP_Error( 'ffl_bridge_selection_required', __( 'Select and verify a transfer dealer before placing the order.', 'ffl-bridge-for-woocommerce' ) )
				: null;
		}

		$stored = $selection['dealer'];
		$id     = isset( $stored['id'] ) && is_string( $stored['id'] ) ? $stored['id'] : '';
		if ( '' === $id ) {
			return new WP_Error( 'ffl_bridge_invalid_selection', __( 'The saved dealer selection is invalid. Choose the dealer again.', 'ffl-bridge-for-woocommerce' ) );
		}

		// A fallback selection stays valid only while the merchant keeps the
		// fallback enabled. Selections from 1.1.0 have no flag and are confirmed.
		$unconfirmed = false === ( $stored['transfer_confirmed'] ?? true );
		if ( $unconfirmed && ! FFL_Bridge_Network::fallback_enabled() ) {
			return new WP_Error( 'ffl_bridge_fallback_disabled', __( 'The selected dealer is not confirmed to accept transfers. Search and select a confirmed dealer, or contact the store.', 'ffl-bridge-for-woocommerce' ) );
		}

		$cache_key = $id . ( $unconfirmed ? '|unconfirmed' : '' );
		if ( ! array_key_exists( $cache_key, self::$verification_cache ) ) {
			self::$verification_cache[ $cache_key ] = FFL_Bridge_API_Client::get_dealer( $id, $unconfirmed );
		}

		$dealer = self::$verification_cache[ $cache_key ];
		if ( is_wp_error( $dealer ) ) {
			return $dealer;
		}

		$stored_license = isset( $stored['license'] ) && is_string( $stored['license'] ) ? $stored['license'] : '';
		if ( '' === $stored_license || ! hash_equals( $stored_license, $dealer['license'] ) || ! self::dealer_still_acceptable( $dealer ) ) {
			return new WP_Error( 'ffl_bridge_dealer_changed', __( 'The selected dealer can no longer be verified. Search and select a dealer again.', 'ffl-bridge-for-woocommerce' ) );
		}

		return $dealer;
	}

	/**
	 * Check the canonical dealer flags that must hold for a selection.
	 *
	 * A fallback dealer must still be active, but its transfer acceptance is
	 * by definition unconfirmed.
	 *
	 * @param array<string, mixed> $dealer Canonical dealer.
	 * @return bool
	 */
	public static function dealer_still_acceptable( array $dealer ): bool {
		if ( empty( $dealer['is_active'] ) ) {
			return false;
		}

		return false === ( $dealer['transfer_confirmed'] ?? true ) || ! empty( $dealer['accepts_transfers'] );
	}

	/**
	 * Persist a strict, namespaced metadata allowlist.
	 *
	 * @param WC_Order             $order Order object.
	 * @param array<string, mixed> $dealer Canonical dealer.
	 * @return void
	 */
	private static function persist_order_selection( WC_Order $order, array $dealer ): void {
		if ( '' !== (string) $order->get_meta( '_ffl_bridge_dealer_id' ) ) {
			return;
		}

		$confirmed = false !== ( $dealer['transfer_confirmed'] ?? true );
		$meta      = array(
			'_ffl_bridge_dealer_id'          => $dealer['id'],
			'_ffl_bridge_license'            => $dealer['license'],
			'_ffl_bridge_license_type'       => $dealer['license_type'],
			'_ffl_bridge_name'               => $dealer['name'],
			'_ffl_bridge_business_name'      => $dealer['business_name'],
			'_ffl_bridge_address'            => $dealer['address'],
			'_ffl_bridge_city'               => $dealer['city'],
			'_ffl_bridge_state'              => $dealer['state'],
			'_ffl_bridge_zip'                => $dealer['zip'],
			'_ffl_bridge_phone'              => $dealer['phone'],
			'_ffl_bridge_license_on_file'    => ! empty( $dealer['license_on_file'] ) ? 'yes' : 'no',
			'_ffl_bridge_license_verified'   => ! empty( $dealer['license_verified'] ) ? 'yes' : 'no',
			'_ffl_bridge_transfer_confirmed' => $confirmed ? 'yes' : 'no',
			'_ffl_bridge_verified_at'        => gmdate( 'c' ),
			'_ffl_bridge_source'             => 'ffl_bridge_api',
			'_ffl_bridge_schema'             => self::ORDER_META_SCHEMA,
		);

		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$note = $confirmed
			/* translators: 1: dealer name, 2: license number. */
			? __( 'FFL Bridge recorded a server-verified dealer selection: %1$s (%2$s). Confirm licensing, acceptance, fees, and shipment instructions before fulfillment.', 'ffl-bridge-for-woocommerce' )
			/* translators: 1: dealer name, 2: license number. */
			: ( ! empty( $dealer['accepts_transfers'] )
				/* translators: 1: dealer name, 2: license number. */
				? __( 'FFL Bridge recorded a fallback dealer selection: %1$s (%2$s). The dealer has confirmed transfers with FFL Bridge, but FFL Bridge has NOT verified its license copy. Contact the dealer to confirm acceptance and obtain a license copy before fulfillment.', 'ffl-bridge-for-woocommerce' )
				/* translators: 1: dealer name, 2: license number. */
				: __( 'FFL Bridge recorded a fallback dealer selection: %1$s (%2$s). FFL Bridge has NOT confirmed that this dealer accepts transfers. Contact the dealer to confirm acceptance and obtain a license copy before fulfillment.', 'ffl-bridge-for-woocommerce' ) );

		$order->add_order_note( sprintf( $note, $dealer['name'], $dealer['license'] ) );
	}

	/**
	 * Convert canonical data to the smaller browser-facing allowlist.
	 *
	 * @param array<string, mixed> $dealer Canonical dealer.
	 * @return array<string, mixed>
	 */
	private static function public_dealer( array $dealer ): array {
		return array(
			'name'              => (string) ( $dealer['name'] ?? '' ),
			'address'           => (string) ( $dealer['address'] ?? '' ),
			'city'              => (string) ( $dealer['city'] ?? '' ),
			'state'             => (string) ( $dealer['state'] ?? '' ),
			'zip'               => (string) ( $dealer['zip'] ?? '' ),
			'phone'             => (string) ( $dealer['phone'] ?? '' ),
			'distance'          => isset( $dealer['distance'] ) && is_numeric( $dealer['distance'] ) ? (float) $dealer['distance'] : null,
			'transferConfirmed' => false !== ( $dealer['transfer_confirmed'] ?? true ) && FFL_Bridge_Network::NETWORK_UNCONFIRMED !== ( $dealer['network'] ?? '' ),
			'transferStatus'    => is_string( $dealer['transfer_status'] ?? null ) ? $dealer['transfer_status'] : '',
		);
	}

	/**
	 * Verify nonce plus the independent WooCommerce-session request token.
	 *
	 * @return true|WP_Error
	 */
	private static function validate_ajax_request(): bool|WP_Error {
		if ( ! check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce', false ) ) {
			return new WP_Error( 'ffl_bridge_bad_nonce', __( 'The checkout security token expired. Reload the page and try again.', 'ffl-bridge-for-woocommerce' ) );
		}

		if ( ! FFL_Bridge_Selection::verify_request_token( self::post_text( 'requestToken', 128 ) ) ) {
			return new WP_Error( 'ffl_bridge_bad_session', __( 'The checkout session changed. Reload the page and try again.', 'ffl-bridge-for-woocommerce' ) );
		}

		return true;
	}

	/**
	 * Apply a small per-session sliding-window limit.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit Maximum requests.
	 * @param int    $window Window in seconds.
	 * @return true|WP_Error
	 */
	private static function enforce_rate_limit( string $bucket, int $limit, int $window ): bool|WP_Error {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return new WP_Error( 'ffl_bridge_session_unavailable', __( 'The checkout session is unavailable. Refresh and try again.', 'ffl-bridge-for-woocommerce' ) );
		}

		$key        = 'ffl_bridge_rate_' . $bucket;
		$now        = time();
		$timestamps = WC()->session->get( $key, array() );
		$timestamps = is_array( $timestamps ) ? array_values( array_filter( array_map( 'absint', $timestamps ), static fn ( int $time ): bool => $time > $now - $window ) ) : array();

		if ( count( $timestamps ) >= $limit ) {
			return new WP_Error( 'ffl_bridge_rate_limited', __( 'Too many dealer requests were made. Wait a minute and try again.', 'ffl-bridge-for-woocommerce' ) );
		}

		$timestamps[] = $now;
		WC()->session->set( $key, $timestamps );
		return true;
	}

	/**
	 * Read and bound one posted text field after nonce verification.
	 *
	 * @param string $key Field key.
	 * @param int    $max_length Maximum bytes.
	 * @return string
	 */
	private static function post_text( string $key, int $max_length ): string {
		$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies before use.
		return substr( $value, 0, $max_length );
	}

	/**
	 * Send a consistent safe JSON error.
	 *
	 * @param WP_Error $error Error object.
	 * @param int      $status HTTP status.
	 * @return never
	 */
	private static function send_ajax_error( WP_Error $error, int $status = 400 ): never {
		wp_send_json_error( array( 'message' => $error->get_error_message() ), $status );
	}

	/**
	 * Prefill a valid customer postcode without sending it anywhere.
	 *
	 * @return string
	 */
	private static function get_customer_zip(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}

		$zip = (string) WC()->customer->get_shipping_postcode();
		if ( '' === $zip ) {
			$zip = (string) WC()->customer->get_billing_postcode();
		}

		return 1 === preg_match( '/\A\d{5}\z/', $zip ) ? $zip : '';
	}
}
