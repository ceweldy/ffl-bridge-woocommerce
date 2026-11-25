<?php
/**
 * 1.3.0 checkout settings: new-install defaults and the offer to existing stores.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Each setting here changes what shoppers see. A new store gets it on. A store
 * that installed an earlier version keeps its current checkout and sees an
 * admin notice offering the setting, the same way 1.2.0 offered hybrid mode.
 */
final class FFL_Bridge_Features {

	/**
	 * Pending or answered offers, keyed by feature.
	 */
	public const OFFERS_OPTION = 'ffl_bridge_feature_offers';

	private const NONCE_ACTION = 'ffl_bridge_feature_offer';

	/**
	 * Offered features and the option each one controls.
	 *
	 * @return array<string, string>
	 */
	public static function features(): array {
		return array(
			'ship_to_dealer' => 'ffl_bridge_ship_to_dealer',
			'last_dealer'    => 'ffl_bridge_last_dealer_first',
			'name_filter'    => 'ffl_bridge_name_filter',
		);
	}

	/**
	 * Admin notice text for each offered feature.
	 *
	 * @return array<string, array{title: string, text: string}>
	 */
	private static function offer_copy(): array {
		return array(
			'ship_to_dealer' => array(
				'title' => __( 'Use the chosen dealer as the shipping address', 'ffl-bridge-for-woocommerce' ),
				'text'  => __( 'When a shopper selects a transfer dealer for an order that needs one, checkout fills the shipping address with the dealer\'s premises address and name and keeps it there. The order is saved with the address from the dealer record FFL Bridge returns at order time, not the address typed in the browser. Billing details are not changed. Your store currently keeps the shopper\'s own shipping address.', 'ffl-bridge-for-woocommerce' ),
			),
			'last_dealer'    => array(
				'title' => __( 'List a returning customer\'s last dealer first', 'ffl-bridge-for-woocommerce' ),
				'text'  => __( 'For logged-in customers, the plugin saves the FFL Bridge ID of the dealer on their last order in their account on this site, and lists that dealer first, labeled "Your last dealer", when it appears in their next search. Nothing extra is sent to FFL Bridge, and nothing is saved for guests.', 'ffl-bridge-for-woocommerce' ),
			),
			'name_filter'    => array(
				'title' => __( 'Let shoppers search dealers by name', 'ffl-bridge-for-woocommerce' ),
				'text'  => __( 'Adds an optional "Dealer name" box next to the ZIP code in the dealer picker, so a shopper who knows their dealer can find it quickly. The name is sent to FFL Bridge with the ZIP code search.', 'ffl-bridge-for-woocommerce' ),
			),
		);
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_offers' ) );
		add_action( 'admin_post_ffl_bridge_feature_offer', array( __CLASS__, 'handle_offer' ) );
	}

	/**
	 * Give each feature option a value once. A site with no saved plugin
	 * settings is a new install and gets the feature on; any other site keeps
	 * its current behavior and is offered the feature.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		$new_install = false === get_option( 'ffl_bridge_settings_version', false );
		$offers      = self::offers();
		$changed     = false;

		foreach ( self::features() as $feature => $option ) {
			if ( false !== get_option( $option, false ) ) {
				continue;
			}

			update_option( $option, $new_install ? 'yes' : 'no', false );
			if ( ! $new_install && ! isset( $offers[ $feature ] ) ) {
				$offers[ $feature ] = 'pending';
				$changed            = true;
			}
		}

		if ( $changed ) {
			update_option( self::OFFERS_OPTION, $offers, false );
		}
	}

	/**
	 * Features whose offer is pending and that are still off.
	 *
	 * @return array<int, string>
	 */
	public static function pending_offers(): array {
		$pending = array();
		foreach ( self::offers() as $feature => $state ) {
			$option = self::features()[ $feature ] ?? '';
			if ( 'pending' === $state && '' !== $option && 'yes' !== get_option( $option, 'no' ) ) {
				$pending[] = $feature;
			}
		}

		return $pending;
	}

	/**
	 * Record the store's answer to one offer.
	 *
	 * @param string $feature Feature key.
	 * @param string $choice switch or keep.
	 * @return bool Whether the answer was valid.
	 */
	public static function apply_offer( string $feature, string $choice ): bool {
		$option = self::features()[ $feature ] ?? '';
		if ( '' === $option || ! in_array( $choice, array( 'switch', 'keep' ), true ) ) {
			return false;
		}

		if ( 'switch' === $choice ) {
			update_option( $option, 'yes', false );
		}

		$offers             = self::offers();
		$offers[ $feature ] = 'answered';
		update_option( self::OFFERS_OPTION, $offers, false );
		return true;
	}

	/**
	 * Show pending offers on the plugin settings page.
	 *
	 * @return void
	 */
	public static function render_offers(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! FFL_Bridge_Settings::is_plugin_screen() ) {
			return;
		}

		$pending = self::pending_offers();
		if ( array() === $pending ) {
			return;
		}

		$copy = self::offer_copy();
		?>
		<div class="notice notice-info ffl-bridge-feature-offers">
			<p><strong><?php echo esc_html__( 'FFL Bridge 1.3.0 adds checkout options. Your checkout is unchanged until you turn them on.', 'ffl-bridge-for-woocommerce' ); ?></strong></p>
			<?php foreach ( $pending as $feature ) : ?>
				<?php
				$switch = wp_nonce_url( admin_url( 'admin-post.php?action=ffl_bridge_feature_offer&offer=' . rawurlencode( $feature ) . '&choice=switch' ), self::NONCE_ACTION );
				$keep   = wp_nonce_url( admin_url( 'admin-post.php?action=ffl_bridge_feature_offer&offer=' . rawurlencode( $feature ) . '&choice=keep' ), self::NONCE_ACTION );
				?>
				<p><strong><?php echo esc_html( $copy[ $feature ]['title'] ); ?>.</strong> <?php echo esc_html( $copy[ $feature ]['text'] ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $switch ); ?>"><?php echo esc_html__( 'Turn on', 'ffl-bridge-for-woocommerce' ); ?></a>
					<a class="button" href="<?php echo esc_url( $keep ); ?>"><?php echo esc_html__( 'Keep current behavior', 'ffl-bridge-for-woocommerce' ); ?></a>
				</p>
			<?php endforeach; ?>
			<p class="description"><?php echo esc_html__( 'These settings support your fulfillment workflow. Your store remains responsible for its shipping and transfer requirements. This is not legal advice.', 'ffl-bridge-for-woocommerce' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Handle an offer button.
	 *
	 * @return void
	 */
	public static function handle_offer(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'ffl-bridge-for-woocommerce' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );
		$feature = isset( $_GET['offer'] ) ? sanitize_key( wp_unslash( $_GET['offer'] ) ) : '';
		$choice  = isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '';
		if ( ! self::apply_offer( $feature, $choice ) ) {
			wp_die( esc_html__( 'Unknown choice.', 'ffl-bridge-for-woocommerce' ), 400 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ffl-bridge-settings' ) );
		exit;
	}

	/**
	 * Saved offers.
	 *
	 * @return array<string, string>
	 */
	private static function offers(): array {
		$offers = get_option( self::OFFERS_OPTION, array() );
		if ( ! is_array( $offers ) ) {
			return array();
		}

		return array_filter(
			$offers,
			static fn ( mixed $state, mixed $feature ): bool => is_string( $feature ) && in_array( $state, array( 'pending', 'answered' ), true ),
			ARRAY_FILTER_USE_BOTH
		);
	}
}
