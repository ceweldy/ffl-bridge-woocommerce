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

	private const PAGE_SLUG = 'ffl-bridge-settings';

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

		$result = FFL_Bridge_API_Client::test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => esc_html__( 'Connection successful.', 'ffl-bridge-for-woocommerce' ) ) );
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
								<option value="yes" <?php selected( $required, 'yes' ); ?>><?php echo esc_html__( 'Yes — block checkout', 'ffl-bridge-for-woocommerce' ); ?></option>
								<option value="no" <?php selected( $required, 'no' ); ?>><?php echo esc_html__( 'No — selection is optional', 'ffl-bridge-for-woocommerce' ); ?></option>
							</select>
							<p class="description"><?php echo esc_html__( 'A required checkout fails closed if the API is unavailable or the saved dealer can no longer be verified.', 'ffl-bridge-for-woocommerce' ); ?></p>
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
			<p><?php echo esc_html__( 'Save changes before testing. The request is made by your WordPress server; the key is not returned to this page.', 'ffl-bridge-for-woocommerce' ); ?></p>
			<p>
				<button type="button" class="button" id="ffl-bridge-test" <?php disabled( ! FFL_Bridge_API_Client::is_configured() ); ?>><?php echo esc_html__( 'Test saved connection', 'ffl-bridge-for-woocommerce' ); ?></button>
				<span id="ffl-bridge-test-result" role="status" aria-live="polite"></span>
			</p>
		</div>
		<?php
	}
}
