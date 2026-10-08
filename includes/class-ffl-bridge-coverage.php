<?php
/**
 * Dealer coverage gaps reported to store administrators.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records shopper searches that found no confirmed transfer dealer and shows
 * store administrators a coverage notice.
 *
 * Only the first three digits of the searched ZIP code are kept, with counts,
 * for a rolling window. Nothing ties an entry to a shopper or an order.
 */
final class FFL_Bridge_Coverage {

	public const OPTION     = 'ffl_bridge_coverage_log';
	public const WINDOW     = 2592000;
	public const MAX_AREAS  = 50;
	private const DISMISS   = 'ffl_bridge_coverage_dismissed';
	private const SNOOZE    = 604800;
	private const NONCE_ACT = 'ffl_bridge_dismiss_coverage';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_action( 'admin_post_ffl_bridge_dismiss_coverage', array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * Record a search that found no confirmed transfer dealer.
	 *
	 * @param string   $zip Five-digit ZIP code.
	 * @param int      $radius Search radius in miles.
	 * @param bool     $fallback_shown Whether fallback dealers were shown.
	 * @param string   $reason Optional API zero-result reason code.
	 * @param int|null $now Optional timestamp for testing.
	 * @return void
	 */
	public static function record_gap( string $zip, int $radius, bool $fallback_shown, string $reason = '', ?int $now = null ): void {
		if ( 1 !== preg_match( '/\A(\d{3})\d{2}\z/', $zip, $match ) ) {
			return;
		}

		$now   = $now ?? time();
		$log   = self::get_log( $now );
		$area  = $match[1];
		$entry = $log['areas'][ $area ] ?? array(
			'count'      => 0,
			'fallback'   => 0,
			'max_radius' => 0,
			'first'      => $now,
			'last'       => $now,
			'reason'     => '',
		);

		++$entry['count'];
		$entry['fallback']  += $fallback_shown ? 1 : 0;
		$entry['max_radius'] = max( (int) $entry['max_radius'], $radius );
		$entry['last']       = $now;
		if ( '' !== $reason ) {
			$entry['reason'] = $reason;
		}

		$log['areas'][ $area ] = $entry;
		uasort( $log['areas'], static fn ( array $a, array $b ): int => $b['last'] <=> $a['last'] );
		$log['areas'] = array_slice( $log['areas'], 0, self::MAX_AREAS, true );
		$log['last']  = $now;

		update_option( self::OPTION, $log, false );
	}

	/**
	 * Return the coverage log with entries outside the window removed.
	 *
	 * @param int|null $now Optional timestamp for testing.
	 * @return array{areas: array<int|string, array<string, mixed>>, last: int}
	 */
	public static function get_log( ?int $now = null ): array {
		$now    = $now ?? time();
		$stored = get_option( self::OPTION, array() );
		$areas  = array();

		if ( is_array( $stored ) && isset( $stored['areas'] ) && is_array( $stored['areas'] ) ) {
			foreach ( $stored['areas'] as $area => $entry ) {
				if (
					1 === preg_match( '/\A\d{3}\z/', (string) $area ) &&
					is_array( $entry ) &&
					isset( $entry['count'], $entry['last'] ) &&
					(int) $entry['last'] > $now - self::WINDOW
				) {
					$areas[ (string) $area ] = array(
						'count'      => absint( $entry['count'] ),
						'fallback'   => absint( $entry['fallback'] ?? 0 ),
						'max_radius' => absint( $entry['max_radius'] ?? 0 ),
						'first'      => absint( $entry['first'] ?? $entry['last'] ),
						'last'       => absint( $entry['last'] ),
						'reason'     => is_string( $entry['reason'] ?? null ) ? $entry['reason'] : '',
					);
				}
			}
		}

		$last = 0;
		foreach ( $areas as $entry ) {
			$last = max( $last, $entry['last'] );
		}

		return array(
			'areas' => $areas,
			'last'  => $last,
		);
	}

	/**
	 * Count recorded gap searches in the window.
	 *
	 * @param array{areas: array<string, array<string, mixed>>, last: int} $log Coverage log.
	 * @return int
	 */
	public static function total( array $log ): int {
		return (int) array_sum( array_column( $log['areas'], 'count' ) );
	}

	/**
	 * Decide whether the coverage notice should be shown.
	 *
	 * A dismissal hides the notice for a week, and it returns afterward only
	 * when new gaps were recorded after the dismissal.
	 *
	 * @param array{areas: array<string, array<string, mixed>>, last: int} $log Coverage log.
	 * @param int                                                          $dismissed_at Dismissal timestamp, or 0.
	 * @param int                                                          $now Current timestamp.
	 * @return bool
	 */
	public static function should_notify( array $log, int $dismissed_at, int $now ): bool {
		if ( 0 === self::total( $log ) ) {
			return false;
		}

		return 0 === $dismissed_at || ( $log['last'] > $dismissed_at && $now - $dismissed_at >= self::SNOOZE );
	}

	/**
	 * Show the coverage notice on WooCommerce, plugin, and dashboard screens.
	 *
	 * @return void
	 */
	public static function render_admin_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		if ( 'dashboard' !== $id && 'plugins' !== $id && ! str_contains( $id, 'woocommerce' ) && ! str_contains( $id, 'shop_order' ) ) {
			return;
		}

		$log = self::get_log();
		if ( ! self::should_notify( $log, absint( get_user_meta( get_current_user_id(), self::DISMISS, true ) ), time() ) ) {
			return;
		}

		// PHP stores numeric area keys such as 480 as integers.
		$areas = array_map( static fn ( int|string $area ): string => $area . 'xx', array_slice( array_keys( $log['areas'] ), 0, 5 ) );
		$text  = sprintf(
			/* translators: 1: number of searches, 2: comma-separated ZIP areas such as 480xx. */
			_n(
				'FFL Bridge: in the last 30 days, %1$d shopper dealer search found no confirmed transfer-accepting dealer (ZIP areas: %2$s). Shoppers in these areas cannot select a confirmed dealer.',
				'FFL Bridge: in the last 30 days, %1$d shopper dealer searches found no confirmed transfer-accepting dealer (ZIP areas: %2$s). Shoppers in these areas cannot select a confirmed dealer.',
				self::total( $log ),
				'ffl-bridge-for-woocommerce'
			),
			self::total( $log ),
			implode( ', ', $areas )
		);
		$settings_url = admin_url( 'admin.php?page=ffl-bridge-settings#ffl-bridge-coverage' );
		$dismiss_url  = wp_nonce_url( admin_url( 'admin-post.php?action=ffl_bridge_dismiss_coverage' ), self::NONCE_ACT );
		?>
		<div class="notice notice-warning">
			<p><?php echo esc_html( $text ); ?></p>
			<p>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php echo esc_html__( 'Review dealer coverage', 'ffl-bridge-for-woocommerce' ); ?></a>
				|
				<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php echo esc_html__( 'Dismiss for a week', 'ffl-bridge-for-woocommerce' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Store a per-user dismissal and return to the previous screen.
	 *
	 * @return void
	 */
	public static function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ffl-bridge-for-woocommerce' ), 403 );
		}

		check_admin_referer( self::NONCE_ACT );
		update_user_meta( get_current_user_id(), self::DISMISS, time() );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
