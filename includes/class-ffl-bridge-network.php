<?php
/**
 * Directory versus verified checkout network decisions.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classifies dealer search results and applies merchant result settings.
 *
 * FFL Bridge search returns ATF-listed dealers from the public directory and
 * marks the subset that is eligible for checkout. Only that verified checkout
 * network can be selected, because the API rejects any other dealer when the
 * selection is re-verified on the server.
 */
final class FFL_Bridge_Network {

	public const NETWORK_VERIFIED  = 'verified';
	public const NETWORK_DIRECTORY = 'directory';
	public const NETWORK_UNKNOWN   = 'unknown';

	public const SCOPE_ALL      = 'all';
	public const SCOPE_VERIFIED = 'verified';

	public const MAX_PREFERRED = 100;

	/**
	 * Return the configured result scope.
	 *
	 * @return string
	 */
	public static function get_result_scope(): string {
		return self::sanitize_scope( get_option( 'ffl_bridge_result_scope', self::SCOPE_ALL ) );
	}

	/**
	 * Sanitize a result scope value.
	 *
	 * @param mixed $input Raw value.
	 * @return string
	 */
	public static function sanitize_scope( mixed $input ): string {
		return self::SCOPE_VERIFIED === $input ? self::SCOPE_VERIFIED : self::SCOPE_ALL;
	}

	/**
	 * Return the merchant's preferred dealer license numbers.
	 *
	 * @return array<int, string>
	 */
	public static function get_preferred_licenses(): array {
		return self::parse_license_list( get_option( 'ffl_bridge_preferred_licenses', array() ) );
	}

	/**
	 * Parse license numbers from a textarea string or a stored list.
	 *
	 * Each license may be entered with or without dashes. Invalid entries are
	 * dropped, duplicates are removed, and the list is bounded.
	 *
	 * @param mixed $input Raw value.
	 * @return array<int, string>
	 */
	public static function parse_license_list( mixed $input ): array {
		if ( is_string( $input ) ) {
			$input = preg_split( '/[\s,;]+/', $input );
		}

		if ( ! is_array( $input ) ) {
			return array();
		}

		$licenses = array();
		foreach ( $input as $value ) {
			$license = is_string( $value ) ? self::normalize_license( $value ) : '';
			if ( '' !== $license && ! in_array( $license, $licenses, true ) ) {
				$licenses[] = $license;
			}

			if ( count( $licenses ) >= self::MAX_PREFERRED ) {
				break;
			}
		}

		return $licenses;
	}

	/**
	 * Normalize one license number to X-XX-XXX-XX-XX-XXXXX.
	 *
	 * @param string $value Raw license number.
	 * @return string Normalized license, or an empty string when invalid.
	 */
	public static function normalize_license( string $value ): string {
		$compact = strtoupper( (string) preg_replace( '/[^0-9A-Za-z]/', '', $value ) );
		if ( 1 !== preg_match( '/\A([0-9])([0-9]{2})([0-9]{3})([0-9]{2})([0-9A-Z]{2})([0-9]{5})\z/', $compact, $parts ) ) {
			return '';
		}

		return implode( '-', array_slice( $parts, 1 ) );
	}

	/**
	 * Classify one normalized dealer.
	 *
	 * A missing flag means the API response predates the checkout-network flag,
	 * so the dealer keeps the earlier behavior and is verified on selection.
	 *
	 * @param array<string, mixed> $dealer Normalized dealer.
	 * @return string
	 */
	public static function classify( array $dealer ): string {
		$eligible = $dealer['checkout_eligible'] ?? null;
		if ( true === $eligible ) {
			return self::NETWORK_VERIFIED;
		}

		return false === $eligible ? self::NETWORK_DIRECTORY : self::NETWORK_UNKNOWN;
	}

	/**
	 * Determine whether a shopper may attempt to select a classified dealer.
	 *
	 * @param string $network Network classification.
	 * @return bool
	 */
	public static function is_selectable( string $network ): bool {
		return self::NETWORK_DIRECTORY !== $network;
	}

	/**
	 * Classify, filter, and order search results for checkout.
	 *
	 * Selectable dealers come first, store-preferred dealers lead each group,
	 * and the API's distance ordering is otherwise preserved.
	 *
	 * @param array<int, array<string, mixed>> $dealers Normalized dealers.
	 * @param string                           $scope Result scope.
	 * @param array<int, string>               $preferred Preferred license numbers.
	 * @return array<int, array<string, mixed>>
	 */
	public static function prepare_results( array $dealers, string $scope, array $preferred ): array {
		$prepared = array();
		foreach ( array_values( $dealers ) as $position => $dealer ) {
			$network = self::classify( $dealer );
			if ( self::SCOPE_VERIFIED === $scope && self::NETWORK_DIRECTORY === $network ) {
				continue;
			}

			$license                     = isset( $dealer['license'] ) && is_string( $dealer['license'] ) ? $dealer['license'] : '';
			$dealer['network']           = $network;
			$dealer['selectable']        = self::is_selectable( $network );
			$dealer['store_preferred']   = '' !== $license && in_array( $license, $preferred, true );
			$dealer['original_position'] = $position;
			$prepared[]                  = $dealer;
		}

		usort(
			$prepared,
			static function ( array $a, array $b ): int {
				return array( ! $a['selectable'], ! $a['store_preferred'], $a['original_position'] )
					<=> array( ! $b['selectable'], ! $b['store_preferred'], $b['original_position'] );
			}
		);

		return array_map(
			static function ( array $dealer ): array {
				unset( $dealer['original_position'] );
				return $dealer;
			},
			$prepared
		);
	}

	/**
	 * Count normalized dealers by network classification.
	 *
	 * @param array<int, array<string, mixed>> $dealers Normalized dealers.
	 * @return array{verified: int, directory: int, unknown: int}
	 */
	public static function count_by_network( array $dealers ): array {
		$counts = array(
			self::NETWORK_VERIFIED  => 0,
			self::NETWORK_DIRECTORY => 0,
			self::NETWORK_UNKNOWN   => 0,
		);

		foreach ( $dealers as $dealer ) {
			++$counts[ self::classify( $dealer ) ];
		}

		return $counts;
	}
}
