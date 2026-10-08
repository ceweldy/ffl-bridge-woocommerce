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

	/**
	 * A nearby listed dealer offered in hybrid mode. It is not in the verified
	 * checkout network: its transfer acceptance, its license copy, or both
	 * still need follow-up.
	 */
	public const NETWORK_UNCONFIRMED = 'unconfirmed';

	public const SCOPE_ALL      = 'all';
	public const SCOPE_VERIFIED = 'verified';

	public const MAX_PREFERRED = 100;

	public const MODE_OPTION         = 'ffl_bridge_dealer_mode';
	public const OFFER_OPTION        = 'ffl_bridge_hybrid_offer';
	public const MODE_HYBRID         = 'hybrid';
	public const MODE_CONFIRMED_ONLY = 'confirmed_only';
	public const SELECTION_NETWORK   = 'verified_network';
	public const SELECTION_HYBRID    = 'hybrid';

	public const OUTCOME_CONFIRMED = 'confirmed';
	public const OUTCOME_FALLBACK  = 'fallback';
	public const OUTCOME_NONE      = 'none';

	/**
	 * Return the configured result scope.
	 *
	 * @return string
	 */
	public static function get_result_scope(): string {
		return self::sanitize_scope( get_option( 'ffl_bridge_result_scope', self::SCOPE_ALL ) );
	}

	/**
	 * Return the dealer selection mode.
	 *
	 * Hybrid lets shoppers select any nearby ATF-listed dealer that has not
	 * declined transfers, with confirmed dealers listed first and the rest
	 * labeled for follow-up. Confirmed-only offers just the verified network.
	 * A missing option means hybrid, the default for new installs. Existing
	 * installs get an explicit value from maybe_migrate().
	 *
	 * @return string
	 */
	public static function get_dealer_mode(): string {
		return self::sanitize_dealer_mode( get_option( self::MODE_OPTION, self::MODE_HYBRID ) );
	}

	/**
	 * Determine whether shoppers may select dealers that need follow-up.
	 *
	 * @return bool
	 */
	public static function hybrid_enabled(): bool {
		return self::MODE_HYBRID === self::get_dealer_mode();
	}

	/**
	 * Sanitize the dealer selection mode.
	 *
	 * @param mixed $input Raw value.
	 * @return string
	 */
	public static function sanitize_dealer_mode( mixed $input ): string {
		return self::MODE_CONFIRMED_ONLY === $input ? self::MODE_CONFIRMED_ONLY : self::MODE_HYBRID;
	}

	/**
	 * Give an install without a saved dealer mode an explicit one.
	 *
	 * A site that has never stored plugin settings is new and gets hybrid.
	 * A site that already had settings keeps its saved behavior: the earlier
	 * fallback setting maps to hybrid when it was on and to confirmed-only
	 * otherwise, and a confirmed-only site gets an admin offer to switch.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		if ( false !== get_option( self::MODE_OPTION, false ) ) {
			return;
		}

		if ( false === get_option( 'ffl_bridge_settings_version', false ) ) {
			update_option( self::MODE_OPTION, self::MODE_HYBRID, false );
			return;
		}

		$mode = 'yes' === get_option( 'ffl_bridge_fallback', 'no' ) ? self::MODE_HYBRID : self::MODE_CONFIRMED_ONLY;
		update_option( self::MODE_OPTION, $mode, false );
		if ( self::MODE_CONFIRMED_ONLY === $mode ) {
			update_option( self::OFFER_OPTION, 'pending', false );
		}
	}

	/**
	 * Determine whether the switch-to-hybrid offer should be shown.
	 *
	 * @return bool
	 */
	public static function hybrid_offer_pending(): bool {
		return 'pending' === get_option( self::OFFER_OPTION, '' ) && ! self::hybrid_enabled();
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
	 * Determine whether prepared results include a dealer the shopper can
	 * select without the fallback.
	 *
	 * @param array<int, array<string, mixed>> $prepared Prepared results.
	 * @return bool
	 */
	public static function has_confirmed( array $prepared ): bool {
		foreach ( $prepared as $dealer ) {
			if ( ! empty( $dealer['selectable'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Collect hybrid candidates without duplicates or declined dealers.
	 *
	 * @param array<int, array<string, mixed>> $found Transfer-accepting results.
	 * @param mixed                            $tier The API unconfirmed tier, or null when absent.
	 * @param callable|null                    $fetch_nearby Unfiltered search for older APIs.
	 * @return array<int, array<string, mixed>>
	 */
	private static function hybrid_candidates( array $found, mixed $tier, ?callable $fetch_nearby ): array {
		$candidates = $found;
		if ( is_array( $tier ) ) {
			$candidates = array_merge( $candidates, $tier );
		} elseif ( array() === $found && null !== $fetch_nearby ) {
			$nearby     = $fetch_nearby();
			$candidates = is_array( $nearby ) && is_array( $nearby['dealers'] ?? null ) ? $nearby['dealers'] : array();
		}

		$unique = array();
		foreach ( $candidates as $dealer ) {
			$id = is_string( $dealer['id'] ?? null ) ? $dealer['id'] : '';
			if ( 'declined' !== ( $dealer['transfer_status'] ?? null ) && ( '' === $id || ! isset( $unique[ $id ] ) ) ) {
				$unique[ '' === $id ? count( $unique ) . '#' : $id ] = $dealer;
			}
		}

		return array_values( $unique );
	}

	/**
	 * Prepare the hybrid list, in which every candidate is selectable.
	 *
	 * Order: verified network dealers (and unknown ones, from an API without
	 * the flag), then dealers that confirmed transfers but have no verified
	 * license copy, then dealers whose transfer acceptance is unconfirmed.
	 * Store-preferred dealers lead each group, and the API order is otherwise
	 * preserved.
	 *
	 * @param array<int, array<string, mixed>> $dealers Normalized dealers.
	 * @param array<int, string>               $preferred Preferred license numbers.
	 * @return array<int, array<string, mixed>>
	 */
	public static function prepare_hybrid( array $dealers, array $preferred ): array {
		$prepared = self::prepare_results( $dealers, self::SCOPE_ALL, $preferred );
		foreach ( $prepared as $position => &$dealer ) {
			if ( self::NETWORK_DIRECTORY === $dealer['network'] ) {
				$dealer['network'] = self::NETWORK_UNCONFIRMED;
			}
			$dealer['selectable'] = true;
			$dealer['rank']       = self::hybrid_rank( $dealer );
			$dealer['position']   = $position;
		}
		unset( $dealer );

		usort(
			$prepared,
			static fn ( array $a, array $b ): int => array( $a['rank'], ! $a['store_preferred'], $a['position'] )
				<=> array( $b['rank'], ! $b['store_preferred'], $b['position'] )
		);

		return array_map(
			static function ( array $dealer ): array {
				unset( $dealer['rank'], $dealer['position'] );
				return $dealer;
			},
			$prepared
		);
	}

	/**
	 * Rank a dealer for the hybrid list.
	 *
	 * @param array<string, mixed> $dealer Prepared dealer.
	 * @return int
	 */
	private static function hybrid_rank( array $dealer ): int {
		if ( self::NETWORK_UNCONFIRMED !== $dealer['network'] ) {
			return 0;
		}

		return 'confirmed' === ( $dealer['transfer_status'] ?? null ) ? 1 : 2;
	}

	/**
	 * Describe what a selected dealer still needs.
	 *
	 * Transfer acceptance and license verification are independent facts, so
	 * a dealer can have confirmed transfers without a verified license copy.
	 *
	 * @param array<string, mixed> $dealer Canonical or prepared dealer.
	 * @return string One of verified, license_unverified, transfer_unconfirmed.
	 */
	public static function follow_up_state( array $dealer ): string {
		if ( ! empty( $dealer['checkout_verified'] ) || self::NETWORK_VERIFIED === ( $dealer['network'] ?? '' ) ) {
			return 'verified';
		}

		return ! empty( $dealer['transfer_confirmed'] ) || 'confirmed' === ( $dealer['transfer_status'] ?? null )
			? 'license_unverified'
			: 'transfer_unconfirmed';
	}

	/**
	 * Decide which dealers a shopper sees for one search.
	 *
	 * Outcomes:
	 * - confirmed: at least one verified network dealer was found. In hybrid
	 *   mode the dealers that need follow-up come after it in the same list.
	 * - fallback: hybrid mode found no verified dealer, so only dealers that
	 *   need follow-up are offered.
	 * - none: no dealer can be selected. In confirmed-only mode directory
	 *   listings may still be shown, labeled and without a select button.
	 *
	 * Hybrid candidates come from the transfer-accepting results and the
	 * API's unconfirmed tier. With an older API that has no tier, an
	 * unfiltered search runs instead, only when nothing was found.
	 *
	 * @param array<string, mixed> $primary Result of the transfer-accepting search.
	 * @param callable|null        $fetch_nearby Returns an unfiltered search result or WP_Error. Used only without a tier.
	 * @param string               $scope Result scope, used in confirmed-only mode.
	 * @param array<int, string>   $preferred Preferred license numbers.
	 * @param bool                 $hybrid Whether dealers that need follow-up may be selected.
	 * @return array{outcome: string, dealers: array<int, array<string, mixed>>}
	 */
	public static function resolve( array $primary, ?callable $fetch_nearby, string $scope, array $preferred, bool $hybrid ): array {
		$found     = is_array( $primary['dealers'] ?? null ) ? $primary['dealers'] : array();
		$prepared  = self::prepare_results( $found, $scope, $preferred );
		$confirmed = self::has_confirmed( $prepared );

		if ( $hybrid ) {
			$candidates = self::hybrid_candidates( $found, $primary['unconfirmed'] ?? null, $confirmed ? null : $fetch_nearby );
			if ( array() !== $candidates ) {
				return array(
					'outcome' => $confirmed ? self::OUTCOME_CONFIRMED : self::OUTCOME_FALLBACK,
					'dealers' => self::prepare_hybrid( $candidates, $preferred ),
				);
			}
		}

		return array(
			'outcome' => $confirmed ? self::OUTCOME_CONFIRMED : self::OUTCOME_NONE,
			'dealers' => $prepared,
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
