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
	 * Events are stored in one bucket per ZIP area and UTC day. A bucket is
	 * dropped as soon as its day starts more than 30 days ago, so every event
	 * expires on its own schedule even when an area keeps getting new ones.
	 *
	 * @param string   $zip Five-digit ZIP code.
	 * @param int      $radius Search radius in miles.
	 * @param bool     $fallback_shown Whether hybrid dealers were shown.
	 * @param string   $reason Optional API zero-result reason code.
	 * @param int|null $now Optional timestamp for testing.
	 * @return void
	 */
	public static function record_gap( string $zip, int $radius, bool $fallback_shown, string $reason = '', ?int $now = null ): void {
		if ( 1 !== preg_match( '/\A(\d{3})\d{2}\z/', $zip, $match ) ) {
			return;
		}

		$now     = $now ?? time();
		$buckets = self::stored_buckets( $now );
		$area    = 'a' . $match[1];
		$day     = 'd' . intdiv( $now, DAY_IN_SECONDS );
		$bucket  = $buckets[ $area ][ $day ] ?? array(
			'count'      => 0,
			'fallback'   => 0,
			'max_radius' => 0,
			'first'      => $now,
			'last'       => $now,
			'reason'     => '',
		);

		++$bucket['count'];
		$bucket['fallback']  += $fallback_shown ? 1 : 0;
		$bucket['max_radius'] = max( (int) $bucket['max_radius'], $radius );
		$bucket['last']       = $now;
		if ( '' !== $reason ) {
			$bucket['reason'] = $reason;
		}

		$buckets[ $area ][ $day ] = $bucket;
		uasort( $buckets, static fn ( array $a, array $b ): int => self::latest( $b ) <=> self::latest( $a ) );

		update_option(
			self::OPTION,
			array(
				'version' => 2,
				'areas'   => array_slice( $buckets, 0, self::MAX_AREAS, true ),
			),
			false
		);
	}

	/**
	 * Return per-area totals for events inside the window.
	 *
	 * @param int|null $now Optional timestamp for testing.
	 * @return array{areas: array<string, array<string, mixed>>, last: int}
	 */
	public static function get_log( ?int $now = null ): array {
		$areas = array();
		$last  = 0;
		foreach ( self::stored_buckets( $now ?? time() ) as $key => $days ) {
			$entry = array(
				'count'      => 0,
				'fallback'   => 0,
				'max_radius' => 0,
				'first'      => PHP_INT_MAX,
				'last'       => 0,
				'reason'     => '',
			);
			foreach ( $days as $bucket ) {
				$entry['count']     += $bucket['count'];
				$entry['fallback']  += $bucket['fallback'];
				$entry['max_radius'] = max( $entry['max_radius'], $bucket['max_radius'] );
				$entry['first']      = min( $entry['first'], $bucket['first'] );
				if ( $bucket['last'] >= $entry['last'] ) {
					$entry['last']   = $bucket['last'];
					$entry['reason'] = '' !== $bucket['reason'] ? $bucket['reason'] : $entry['reason'];
				}
			}

			$areas[ substr( $key, 1 ) ] = $entry;
			$last                       = max( $last, $entry['last'] );
		}

		return array(
			'areas' => $areas,
			'last'  => $last,
		);
	}

	/**
	 * Read stored day buckets, dropping malformed entries and expired days.
	 *
	 * Logs written by the earlier single-aggregate format are discarded,
	 * because their events cannot be expired individually.
	 *
	 * @param int $now Current timestamp.
	 * @return array<string, array<string, array{count: int, fallback: int, max_radius: int, first: int, last: int, reason: string}>>
	 */
	private static function stored_buckets( int $now ): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || 2 !== ( $stored['version'] ?? null ) || ! is_array( $stored['areas'] ?? null ) ) {
			return array();
		}

		$oldest  = $now - self::WINDOW;
		$buckets = array();
		foreach ( $stored['areas'] as $area => $days ) {
			if ( 1 !== preg_match( '/\Aa\d{3}\z/', (string) $area ) || ! is_array( $days ) ) {
				continue;
			}

			foreach ( $days as $day => $bucket ) {
				if ( 1 !== preg_match( '/\Ad(\d{1,7})\z/', (string) $day, $match ) || ! is_array( $bucket ) || ! isset( $bucket['count'], $bucket['last'] ) ) {
					continue;
				}

				if ( (int) $match[1] * DAY_IN_SECONDS < $oldest ) {
					continue;
				}

				$buckets[ (string) $area ][ (string) $day ] = array(
					'count'      => absint( $bucket['count'] ),
					'fallback'   => absint( $bucket['fallback'] ?? 0 ),
					'max_radius' => absint( $bucket['max_radius'] ?? 0 ),
					'first'      => absint( $bucket['first'] ?? $bucket['last'] ),
					'last'       => absint( $bucket['last'] ),
					'reason'     => is_string( $bucket['reason'] ?? null ) ? $bucket['reason'] : '',
				);
			}
		}

		return $buckets;
	}

	/**
	 * Return the most recent event time in an area's buckets.
	 *
	 * @param array<string, array<string, int|string>> $days Day buckets.
	 * @return int
	 */
	private static function latest( array $days ): int {
		return (int) max( array_column( $days, 'last' ) );
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
