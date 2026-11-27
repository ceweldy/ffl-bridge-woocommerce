<?php
/**
 * Shopper follow-up instructions for dealers that are not yet confirmed.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the instructions shoppers see after choosing a dealer that still
 * needs transfer confirmation or a license copy.
 *
 * The instructions ask the shopper to contact the dealer. The plugin itself
 * never contacts dealers.
 */
final class FFL_Bridge_Followup {

	public const EMAIL_OPTION = 'ffl_bridge_license_email';
	public const FAX_OPTION   = 'ffl_bridge_license_fax';

	/**
	 * Return the address dealers should send license copies to.
	 *
	 * An empty setting falls back to the site admin email, so the default
	 * follows the store's own contact address.
	 *
	 * @return string
	 */
	public static function contact_email(): string {
		$email = self::sanitize_email_setting( get_option( self::EMAIL_OPTION, '' ) );
		if ( '' === $email ) {
			$admin = get_option( 'admin_email', '' );
			$email = is_string( $admin ) ? sanitize_email( $admin ) : '';
		}

		return $email;
	}

	/**
	 * Return the optional fax number for license copies.
	 *
	 * @return string
	 */
	public static function contact_fax(): string {
		return self::sanitize_fax( get_option( self::FAX_OPTION, '' ) );
	}

	/**
	 * Sanitize the license contact email setting. Empty means the admin email.
	 *
	 * @param mixed $input Raw value.
	 * @return string
	 */
	public static function sanitize_email_setting( mixed $input ): string {
		if ( ! is_string( $input ) ) {
			return '';
		}

		$email = sanitize_email( trim( $input ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Sanitize a fax number to digits and common separators.
	 *
	 * @param mixed $input Raw value.
	 * @return string
	 */
	public static function sanitize_fax( mixed $input ): string {
		if ( ! is_string( $input ) ) {
			return '';
		}

		$fax = trim( (string) preg_replace( '/[^0-9+().\s-]/', '', $input ) );
		$fax = trim( (string) preg_replace( '/\s+/', ' ', $fax ) );
		return preg_match_all( '/\d/', $fax ) >= 7 ? substr( $fax, 0, 32 ) : '';
	}

	/**
	 * Build the plain-text follow-up instructions.
	 *
	 * @param string $dealer_name Selected dealer name, or empty for "your selected FFL".
	 * @param string $context Where the text appears: checkout, thankyou, account, or email.
	 * @return string
	 */
	public static function instructions( string $dealer_name, string $context ): string {
		$email  = self::contact_email();
		$fax    = self::contact_fax();
		$dealer = '' !== $dealer_name ? $dealer_name : __( 'your selected FFL dealer', 'ffl-bridge-for-woocommerce' );

		if ( '' !== $email && '' !== $fax ) {
			/* translators: 1: dealer name, 2: store email address, 3: store fax number. */
			$text = __( 'Next step: contact %1$s and confirm they will accept this transfer. Then ask them to email a copy of their current FFL to %2$s or fax it to %3$s. Your order is not on hold while you do this.', 'ffl-bridge-for-woocommerce' );
		} elseif ( '' !== $email ) {
			/* translators: 1: dealer name, 2: store email address. */
			$text = __( 'Next step: contact %1$s and confirm they will accept this transfer. Then ask them to email a copy of their current FFL to %2$s. Your order is not on hold while you do this.', 'ffl-bridge-for-woocommerce' );
		} else {
			/* translators: 1: dealer name. */
			$text = __( 'Next step: contact %1$s and confirm they will accept this transfer. Then ask them to send a copy of their current FFL to the store. Your order is not on hold while you do this.', 'ffl-bridge-for-woocommerce' );
		}

		$message = sprintf( $text, $dealer, $email, $fax );

		/**
		 * Filter the shopper follow-up instructions.
		 *
		 * @param string $message Plain text. Escaped on output.
		 * @param array  $context Dealer name, context, email, and fax.
		 */
		$filtered = apply_filters(
			'ffl_bridge_followup_instructions',
			$message,
			array(
				'dealer_name' => $dealer_name,
				'context'     => $context,
				'email'       => $email,
				'fax'         => $fax,
			)
		);

		return is_string( $filtered ) && '' !== trim( $filtered ) ? sanitize_text_field( $filtered ) : $message;
	}

	/**
	 * Determine whether an order's dealer still needs shopper follow-up.
	 *
	 * Verified network selections and orders the store already confirmed do
	 * not need it.
	 *
	 * @param array<string, string> $ffl Order dealer values.
	 * @return bool
	 */
	public static function order_needs_follow_up( array $ffl ): bool {
		return FFL_Bridge_Network::SELECTION_HYBRID === ( $ffl['basis'] ?? '' ) && '' === ( $ffl['store_confirmed_at'] ?? '' );
	}
}
