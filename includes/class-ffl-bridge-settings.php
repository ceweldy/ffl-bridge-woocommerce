<?php
/**
 * FFL Bridge settings and connection diagnostics.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WooCommerce settings screen.
 */
final class FFL_Bridge_Settings {

	private const PAGE_SLUG     = 'ffl-bridge-settings';
	private const SAMPLE_ZIP    = '32174';
	private const SAMPLE_RADIUS = 25;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_ffl_bridge_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
	}

	/**
	 * Add the settings page beneath WooCommerce.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'FFL Bridge Settings', 'ffl-bridge-for-woocommerce' ),
			esc_html__( 'FFL Bridge', 'ffl-bridge-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'settings_page' )
		);
	}

	/**
	 * Register settings and strict sanitizers.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			'ffl_bridge_settings',
			'ffl_bridge_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_api_key' ),
				'default'           => '',
			)
		);
		register_setting(
			'ffl_bridge_settings',
			'ffl_bridge_theme',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_theme' ),
				'default'           => 'light',
			)
		);
		register_setting(
			'ffl_bridge_settings',
			'ffl_bridge_required',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_required' ),
				'default'           => 'yes',
			)
		);
		register_setting(
			'ffl_bridge_settings',
			'ffl_bridge_categories',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_categories' ),
				'default'           => array(),
			)
		);
		register_setting(
			'ffl_bridge_settings',
			'ffl_bridge_result_scope',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( 'FFL_Bridge_Network', 'sanitize_scope' ),
				'default'           => FFL_Bridge_Network::SCOPE_ALL,
			)
		);
		register_setting(
			'ffl_bridge_settings',
			'ffl_bridge_preferred_licenses',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_preferred_licenses' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Keep a saved key unless the merchant supplies a valid replacement or
	 * explicitly checks the remove box. A wp-config.php constant always wins.
	 *
	 * @param mixed $input Submitted value.
	 * @return string
	 */
	public static function sanitize_api_key( mixed $input ): string {
		$current = get_option( 'ffl_bridge_api_key', '' );
		$current = is_string( $current ) ? $current : '';

		if ( defined( 'FFL_BRIDGE_API_KEY' ) ) {
			return $current;
		}

		$remove = isset( $_POST['ffl_bridge_remove_api_key'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['ffl_bridge_remove_api_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies the settings nonce.
		if ( $remove ) {
			return '';
		}

		$value = is_string( $input ) ? trim( sanitize_text_field( wp_unslash( $input ) ) ) : '';
		if ( '' === $value ) {
			return $current;
		}

		if ( ! FFL_Bridge_API_Client::is_valid_api_key( $value ) ) {
			add_settings_error(
				'ffl_bridge_api_key',
				'ffl_bridge_invalid_api_key',
				esc_html__( 'The API key was not changed because its format is invalid.', 'ffl-bridge-for-woocommerce' ),
				'error'
			);
			return $current;
		}

		return $value;
	}

	/**
	 * Sanitize the visual theme.
	 *
	 * @param mixed $input Submitted value.
	 * @return string
	 */
	public static function sanitize_theme( mixed $input ): string {
		return 'dark' === $input ? 'dark' : 'light';
	}

	/**
	 * Sanitize the required-selection setting.
	 *
	 * @param mixed $input Submitted value.
	 * @return string
	 */
	public static function sanitize_required( mixed $input ): string {
		return 'no' === $input ? 'no' : 'yes';
	}

	/**
	 * Sanitize the merchant's preferred dealer license numbers.
	 *
	 * @param mixed $input Submitted textarea value or stored list.
	 * @return array<int, string>
	 */
	public static function sanitize_preferred_licenses( mixed $input ): array {
		$raw      = is_string( $input ) ? sanitize_textarea_field( wp_unslash( $input ) ) : $input;
		$licenses = FFL_Bridge_Network::parse_license_list( $raw );

		$entered = is_string( $raw ) ? count( array_filter( (array) preg_split( '/[\s,;]+/', $raw ) ) ) : 0;
		if ( $entered > count( $licenses ) ) {
			add_settings_error(
				'ffl_bridge_preferred_licenses',
				'ffl_bridge_invalid_preferred_licenses',
				esc_html__( 'Some preferred dealer entries were removed because they were duplicates, over the limit, or not valid FFL license numbers.', 'ffl-bridge-for-woocommerce' ),
				'warning'
			);
		}

		return $licenses;
	}

	/**
	 * Sanitize selected WooCommerce product-category IDs.
	 *
	 * @param mixed $input Submitted values.
	 * @return array<int, int>
	 */
	public static function sanitize_categories( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $input ) ) ) );
		return array_values(
			array_filter(
				$ids,
				static fn ( int $term_id ): bool => term_exists( $term_id, 'product_cat' ) !== null
			)
		);
	}

	/**
	 * Load the local settings script only on this plugin's page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'ffl-bridge-admin',
			FFL_BRIDGE_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			FFL_BRIDGE_VERSION,
			true
		);
		wp_enqueue_style(
			'ffl-bridge-admin',
			FFL_BRIDGE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			FFL_BRIDGE_VERSION
		);
		wp_localize_script(
			'ffl-bridge-admin',
			'fflBridgeAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ffl_bridge_admin' ),
				'testing' => esc_html__( 'Testing…', 'ffl-bridge-for-woocommerce' ),
				'failed'  => esc_html__( 'Connection test failed.', 'ffl-bridge-for-woocommerce' ),
			)
		);
	}

	/**
	 * Test the saved credential from WordPress, never from browser JavaScript.
	 *
	 * @return void
	 */
	public static function ajax_test_connection(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You are not allowed to test this connection.', 'ffl-bridge-for-woocommerce' ) ), 403 );
		}

		if ( ! check_ajax_referer( 'ffl_bridge_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'The security token expired. Reload the page and try again.', 'ffl-bridge-for-woocommerce' ) ), 403 );
		}

		$result = FFL_Bridge_API_Client::search( self::SAMPLE_ZIP, self::SAMPLE_RADIUS, 25 );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => self::connection_summary( FFL_Bridge_Network::count_by_network( $result ), count( $result ) ) ) );
	}

	/**
	 * Describe a sample search so the merchant can see how much of the public
	 * directory is in the verified checkout network.
	 *
	 * @param array{verified: int, directory: int, unknown: int} $counts Network counts.
	 * @param int                                                $total Result count.
	 * @return string
	 */
	public static function connection_summary( array $counts, int $total ): string {
		$message = __( 'Connection successful.', 'ffl-bridge-for-woocommerce' );
		if ( $counts[ FFL_Bridge_Network::NETWORK_UNKNOWN ] > 0 ) {
			return $message . ' ' . __( 'The API did not report checkout-network status, so each dealer is checked when the shopper selects it.', 'ffl-bridge-for-woocommerce' );
		}

		return $message . ' ' . sprintf(
			/* translators: 1: sample ZIP code, 2: radius in miles, 3: directory result count, 4: verified checkout network count. */
			__( 'Sample search near %1$s within %2$d miles: %3$d directory listings, %4$d in the verified checkout network.', 'ffl-bridge-for-woocommerce' ),
			self::SAMPLE_ZIP,
			self::SAMPLE_RADIUS,
			$total,
			$counts[ FFL_Bridge_Network::NETWORK_VERIFIED ]
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'ffl-bridge-for-woocommerce' ) );
		}

		$theme               = self::sanitize_theme( get_option( 'ffl_bridge_theme', 'light' ) );
		$required            = self::sanitize_required( get_option( 'ffl_bridge_required', 'yes' ) );
		$selected_categories = self::sanitize_categories( get_option( 'ffl_bridge_categories', array() ) );
		$result_scope        = FFL_Bridge_Network::get_result_scope();
		$preferred_licenses  = FFL_Bridge_Network::get_preferred_licenses();
		$constant_key        = defined( 'FFL_BRIDGE_API_KEY' );
		$key_suffix          = FFL_Bridge_API_Client::get_key_suffix();
		$categories          = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'FFL Bridge Settings', 'ffl-bridge-for-woocommerce' ); ?></h1>
			<p><?php echo esc_html__( 'FFL Bridge records a server-verified transfer-dealer selection on the WooCommerce order. It does not change the shipping address or replace merchant compliance checks.', 'ffl-bridge-for-woocommerce' ); ?></p>
			<h2><?php echo esc_html__( 'Public directory and verified checkout network', 'ffl-bridge-for-woocommerce' ); ?></h2>
			<p><?php echo esc_html__( 'Dealer search covers the public FFL directory, built from ATF listings (roughly 77,000 active licenses). Only dealers in the verified checkout network can be selected at checkout. A dealer joins that network when FFL Bridge has a current, verified license copy on file and the dealer accepts transfers. Directory-only dealers can be shown for reference but cannot be selected.', 'ffl-bridge-for-woocommerce' ); ?></p>

			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'ffl_bridge_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffl_bridge_api_key"><?php echo esc_html__( 'API key', 'ffl-bridge-for-woocommerce' ); ?></label></th>
						<td>
							<input
								type="password"
								id="ffl_bridge_api_key"
								name="ffl_bridge_api_key"
								value=""
								class="regular-text"
								placeholder="<?php echo '' !== $key_suffix ? esc_attr( 'Saved key ending in ' . $key_suffix ) : esc_attr( 'ffl_live_…' ); ?>"
								autocomplete="new-password"
								spellcheck="false"
								<?php disabled( $constant_key ); ?>
							>
							<p class="description">
								<?php if ( $constant_key ) : ?>
									<?php echo esc_html__( 'The FFL_BRIDGE_API_KEY constant in wp-config.php is active. Remove or replace it there.', 'ffl-bridge-for-woocommerce' ); ?>
								<?php else : ?>
									<?php echo wp_kses_post( __( 'Leave blank to keep the saved key. For stronger separation, define <code>FFL_BRIDGE_API_KEY</code> in <code>wp-config.php</code>.', 'ffl-bridge-for-woocommerce' ) ); ?>
								<?php endif; ?>
							</p>
							<?php if ( ! $constant_key && '' !== $key_suffix ) : ?>
								<label><input type="checkbox" name="ffl_bridge_remove_api_key" value="1"> <?php echo esc_html__( 'Remove the saved API key', 'ffl-bridge-for-woocommerce' ); ?></label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffl_bridge_theme"><?php echo esc_html__( 'Checkout theme', 'ffl-bridge-for-woocommerce' ); ?></label></th>
						<td>
							<select id="ffl_bridge_theme" name="ffl_bridge_theme">
								<option value="light" <?php selected( $theme, 'light' ); ?>><?php echo esc_html__( 'Light', 'ffl-bridge-for-woocommerce' ); ?></option>
								<option value="dark" <?php selected( $theme, 'dark' ); ?>><?php echo esc_html__( 'Dark', 'ffl-bridge-for-woocommerce' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffl_bridge_required"><?php echo esc_html__( 'Require a selection', 'ffl-bridge-for-woocommerce' ); ?></label></th>
						<td>
							<select id="ffl_bridge_required" name="ffl_bridge_required">
								<option value="yes" <?php selected( $required, 'yes' ); ?>><?php echo esc_html__( 'Yes, block checkout', 'ffl-bridge-for-woocommerce' ); ?></option>
								<option value="no" <?php selected( $required, 'no' ); ?>><?php echo esc_html__( 'No, selection is optional', 'ffl-bridge-for-woocommerce' ); ?></option>
							</select>
							<p class="description"><?php echo esc_html__( 'A required checkout fails closed if the API is unavailable or the saved dealer can no longer be verified. It also blocks shoppers who have no verified checkout network dealer nearby, so run the connection test below to check coverage before requiring a selection.', 'ffl-bridge-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffl_bridge_result_scope"><?php echo esc_html__( 'Dealers shown at checkout', 'ffl-bridge-for-woocommerce' ); ?></label></th>
						<td>
							<select id="ffl_bridge_result_scope" name="ffl_bridge_result_scope">
								<option value="<?php echo esc_attr( FFL_Bridge_Network::SCOPE_ALL ); ?>" <?php selected( $result_scope, FFL_Bridge_Network::SCOPE_ALL ); ?>><?php echo esc_html__( 'Verified network first, plus labeled directory listings', 'ffl-bridge-for-woocommerce' ); ?></option>
								<option value="<?php echo esc_attr( FFL_Bridge_Network::SCOPE_VERIFIED ); ?>" <?php selected( $result_scope, FFL_Bridge_Network::SCOPE_VERIFIED ); ?>><?php echo esc_html__( 'Verified checkout network only', 'ffl-bridge-for-woocommerce' ); ?></option>
							</select>
							<p class="description"><?php echo esc_html__( 'Directory listings are labeled "Directory listing only" and have no select button. Hiding them keeps results short but may leave shoppers with no visible dealers where the verified network is still small.', 'ffl-bridge-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffl_bridge_preferred_licenses"><?php echo esc_html__( 'Store preferred dealers', 'ffl-bridge-for-woocommerce' ); ?></label></th>
						<td>
							<textarea id="ffl_bridge_preferred_licenses" name="ffl_bridge_preferred_licenses" rows="5" cols="40" class="code" spellcheck="false"><?php echo esc_textarea( implode( "\n", $preferred_licenses ) ); ?></textarea>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: maximum number of preferred dealers. */
										__( 'One FFL license number per line, with or without dashes (up to %d). Matching dealers are labeled "Store preferred dealer" and listed first when they appear in a shopper\'s search. This list stays on your site. A preferred dealer can only be selected after FFL Bridge verifies it for the checkout network; until then it appears as a directory listing.', 'ffl-bridge-for-woocommerce' ),
										FFL_Bridge_Network::MAX_PREFERRED
									)
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Applicable product categories', 'ffl-bridge-for-woocommerce' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php echo esc_html__( 'Applicable product categories', 'ffl-bridge-for-woocommerce' ); ?></legend>
								<?php if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) : ?>
									<?php foreach ( $categories as $category ) : ?>
										<label>
											<input type="checkbox" name="ffl_bridge_categories[]" value="<?php echo esc_attr( (string) $category->term_id ); ?>" <?php checked( in_array( (int) $category->term_id, $selected_categories, true ) ); ?>>
											<?php echo esc_html( $category->name ); ?>
										</label><br>
									<?php endforeach; ?>
								<?php else : ?>
									<p><?php echo esc_html__( 'No product categories were found.', 'ffl-bridge-for-woocommerce' ); ?></p>
								<?php endif; ?>
							</fieldset>
							<p class="description"><?php echo esc_html__( 'Select every category that uses the dealer workflow. If none are checked, the workflow applies to every cart product.', 'ffl-bridge-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php echo esc_html__( 'Connection test', 'ffl-bridge-for-woocommerce' ); ?></h2>
			<p><?php echo esc_html__( 'Save changes before testing. The request is made by your WordPress server; the key is not returned to this page. The test runs one sample search and reports how many results are in the verified checkout network.', 'ffl-bridge-for-woocommerce' ); ?></p>
			<p>
				<button type="button" class="button" id="ffl-bridge-test" <?php disabled( ! FFL_Bridge_API_Client::is_configured() ); ?>><?php echo esc_html__( 'Test saved connection', 'ffl-bridge-for-woocommerce' ); ?></button>
				<span id="ffl-bridge-test-result" role="status" aria-live="polite"></span>
			</p>
		</div>
		<?php
	}
}
