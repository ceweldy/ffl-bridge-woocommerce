<?php
/**
 * Optional setting: list a logged-in customer's last-used dealer first.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remembers the FFL Bridge dealer ID of a logged-in customer's last order in
 * that customer's user meta on this site, and lists that dealer first in the
 * customer's next search when it is among the results. Nothing is sent to FFL
 * Bridge and nothing is stored for guests.
 */
final class FFL_Bridge_Last_Dealer {

	public const OPTION = 'ffl_bridge_last_dealer_first';
	public const META   = '_ffl_bridge_last_dealer_id';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
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
	 * Remember the dealer saved on a logged-in customer's order.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $dealer Canonical dealer.
	 * @return void
	 */
	public static function remember( WC_Order $order, array $dealer ): void {
		$customer_id = (int) $order->get_customer_id();
		$dealer_id   = is_string( $dealer['id'] ?? null ) ? $dealer['id'] : '';
		if ( self::enabled() && $customer_id > 0 && '' !== $dealer_id ) {
			update_user_meta( $customer_id, self::META, $dealer_id );
		}
	}

	/**
	 * Move the current customer's last-used dealer to the top of prepared
	 * results when it is there and selectable, and mark each dealer.
	 *
	 * @param array<int, array<string, mixed>> $dealers Prepared dealers in display order.
	 * @return array<int, array<string, mixed>>
	 */
	public static function pin( array $dealers ): array {
		$last = self::current_last_dealer_id();
		$pin  = null;
		foreach ( $dealers as $index => $dealer ) {
			$dealers[ $index ]['last_used'] = false;
			if ( null === $pin && '' !== $last && ! empty( $dealer['selectable'] ) && $last === ( $dealer['id'] ?? '' ) ) {
				$pin = $index;
			}
		}

		if ( null === $pin ) {
			return $dealers;
		}

		$pinned              = $dealers[ $pin ];
		$pinned['last_used'] = true;
		unset( $dealers[ $pin ] );
		return array_merge( array( $pinned ), array_values( $dealers ) );
	}

	/**
	 * The current customer's saved dealer ID, when the setting is on.
	 *
	 * @return string
	 */
	private static function current_last_dealer_id(): string {
		$user_id = get_current_user_id();
		if ( ! self::enabled() || $user_id <= 0 ) {
			return '';
		}

		$value = get_user_meta( $user_id, self::META, true );
		return is_string( $value ) ? strtolower( $value ) : '';
	}

	/**
	 * Register the personal data exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['ffl-bridge-last-dealer'] = array(
			'exporter_friendly_name' => __( 'FFL Bridge last-used transfer dealer', 'ffl-bridge-for-woocommerce' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal data eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['ffl-bridge-last-dealer'] = array(
			'eraser_friendly_name' => __( 'FFL Bridge last-used transfer dealer', 'ffl-bridge-for-woocommerce' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export the saved dealer ID for a user.
	 *
	 * @param string $email Email address.
	 * @param int    $page Page (unused).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_personal_data( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress exporter signature.
		$user  = get_user_by( 'email', $email );
		$value = $user ? get_user_meta( (int) $user->ID, self::META, true ) : '';
		$data  = array();
		if ( is_string( $value ) && '' !== $value ) {
			$data[] = array(
				'group_id'    => 'ffl-bridge',
				'group_label' => __( 'FFL Bridge', 'ffl-bridge-for-woocommerce' ),
				'item_id'     => 'ffl-bridge-last-dealer-' . (int) $user->ID,
				'data'        => array(
					array(
						'name'  => __( 'Last-used transfer dealer (FFL Bridge dealer ID)', 'ffl-bridge-for-woocommerce' ),
						'value' => $value,
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase the saved dealer ID for a user.
	 *
	 * @param string $email Email address.
	 * @param int    $page Page (unused).
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase_personal_data( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress eraser signature.
		$user = get_user_by( 'email', $email );

		return array(
			'items_removed'  => $user ? delete_user_meta( (int) $user->ID, self::META ) : false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
