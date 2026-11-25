<?php
/**
 * Per-product "Requires FFL transfer" flag.
 *
 * @package FFL_Bridge_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets a store mark single products and variations that use the dealer
 * workflow, alongside the category setting.
 *
 * Precedence for a cart item:
 * 1. No categories selected and no product or variation flagged anywhere:
 *    every product uses the workflow (the safe default for a new store).
 * 2. Otherwise the item uses it when its product is flagged, its variation is
 *    flagged, or the product is in a selected category or a child of one.
 */
final class FFL_Bridge_Product_Flags {

	public const META = '_ffl_bridge_requires_ffl';

	/**
	 * Option that records whether any product or variation is flagged, so
	 * checkout does not query for it on every cart check.
	 */
	public const INDEX_OPTION = 'ffl_bridge_product_flags';

	/**
	 * Variation checkboxes post under their own name: they share the product
	 * edit form with the product checkbox.
	 */
	private const VARIATION_FIELD = '_ffl_bridge_requires_ffl_variation';

	private const BULK_REQUIRE   = 'ffl_bridge_require_ffl';
	private const BULK_UNREQUIRE = 'ffl_bridge_unrequire_ffl';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );
		add_action( 'woocommerce_variation_options', array( __CLASS__, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'render_bulk_notice' ) );
		// Flags can change outside the product screen (REST, import, delete).
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status_change' ), 10, 3 );
	}

	/**
	 * Recompute the index when a flag is added, changed or deleted anywhere.
	 *
	 * @param mixed  $meta_id Meta ID or IDs (unused).
	 * @param mixed  $object_id Post ID (unused).
	 * @param mixed  $meta_key Meta key.
	 * @return void
	 */
	public static function on_meta_change( mixed $meta_id, mixed $object_id, mixed $meta_key ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WordPress hook signature.
		if ( self::META === $meta_key ) {
			self::refresh_index();
		}
	}

	/**
	 * Recompute the index when a product is published, trashed or restored.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post Post.
	 * @return void
	 */
	public static function on_status_change( mixed $new_status, mixed $old_status, mixed $post ): void {
		if ( $new_status !== $old_status && is_object( $post ) && in_array( $post->post_type ?? '', array( 'product', 'product_variation' ), true ) ) {
			self::refresh_index();
		}
	}

	/**
	 * Whether a cart item's product or variation is flagged.
	 *
	 * @param int $product_id Product ID.
	 * @param int $variation_id Variation ID, or 0.
	 * @return bool
	 */
	public static function is_flagged( int $product_id, int $variation_id = 0 ): bool {
		foreach ( array( $variation_id, $product_id ) as $id ) {
			if ( $id > 0 && 'yes' === get_post_meta( $id, self::META, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any product or variation is flagged.
	 *
	 * @return bool
	 */
	public static function any_flagged(): bool {
		return 'yes' === get_option( self::INDEX_OPTION, 'no' );
	}

	/**
	 * Recompute whether any product or variation is flagged.
	 *
	 * @param int  $changed_id A product whose flag was just set in memory, not yet saved.
	 * @param bool $changed_flag Its new flag.
	 * @return void
	 */
	public static function refresh_index( int $changed_id = 0, bool $changed_flag = false ): void {
		$any = $changed_flag;
		if ( ! $any ) {
			$found = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => array( 'publish', 'private' ),
					'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One indexed lookup when a flag changes, not at checkout.
					'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'exclude'        => $changed_id > 0 ? array( $changed_id ) : array(),
					'no_found_rows'  => true,
				)
			);
			$any   = array() !== $found;
		}

		update_option( self::INDEX_OPTION, $any ? 'yes' : 'no', true );
	}

	/**
	 * Checkbox on the simple product General tab.
	 *
	 * @return void
	 */
	public static function render_product_field(): void {
		$id = (int) get_the_ID();
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META,
				'label'       => __( 'Requires FFL transfer', 'ffl-bridge-for-woocommerce' ),
				'description' => __( 'Shoppers choose a transfer dealer at checkout when this product is in the cart. Works together with the categories in WooCommerce > FFL Bridge.', 'ffl-bridge-for-woocommerce' ),
				'value'       => $id > 0 && 'yes' === get_post_meta( $id, self::META, true ) ? 'yes' : 'no',
				'cbvalue'     => 'yes',
			)
		);
	}

	/**
	 * Save the simple product checkbox. WooCommerce has already checked the
	 * product edit nonce before this hook runs.
	 *
	 * @param WC_Product $product Product being saved.
	 * @return void
	 */
	public static function save_product( WC_Product $product ): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$flag = isset( $_POST[ self::META ] ) && is_string( $_POST[ self::META ] ) && 'yes' === sanitize_key( wp_unslash( $_POST[ self::META ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce before this hook.
		if ( $flag ) {
			$product->update_meta_data( self::META, 'yes' );
		} else {
			$product->delete_meta_data( self::META );
		}

		self::refresh_index( (int) $product->get_id(), $flag );
	}

	/**
	 * Checkbox in each variation's options row.
	 *
	 * @param int    $loop Variation index.
	 * @param mixed  $variation_data Variation data (unused).
	 * @param object $variation Variation post.
	 * @return void
	 */
	public static function render_variation_field( int $loop, mixed $variation_data, object $variation ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- WooCommerce hook signature.
		$id      = isset( $variation->ID ) ? (int) $variation->ID : 0;
		$checked = $id > 0 && 'yes' === get_post_meta( $id, self::META, true );
		?>
		<label class="tips" data-tip="<?php echo esc_attr__( 'Shoppers choose a transfer dealer at checkout when this variation is in the cart.', 'ffl-bridge-for-woocommerce' ); ?>">
			<?php echo esc_html__( 'Requires FFL transfer', 'ffl-bridge-for-woocommerce' ); ?>
			<input type="checkbox" class="checkbox" name="<?php echo esc_attr( self::VARIATION_FIELD . '[' . $loop . ']' ); ?>" value="yes"<?php echo $checked ? ' checked' : ''; ?>>
		</label>
		<?php
	}

	/**
	 * Save a variation checkbox. WooCommerce has already checked the
	 * save-variations nonce and edit_products before this hook runs.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop Variation index.
	 * @return void
	 */
	public static function save_variation( int $variation_id, int $loop ): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$posted = isset( $_POST[ self::VARIATION_FIELD ] ) && is_array( $_POST[ self::VARIATION_FIELD ] ) ? wp_unslash( $_POST[ self::VARIATION_FIELD ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified by WooCommerce; only compared to "yes".
		$flag   = isset( $posted[ $loop ] ) && 'yes' === $posted[ $loop ];
		if ( $flag ) {
			update_post_meta( $variation_id, self::META, 'yes' );
		} else {
			delete_post_meta( $variation_id, self::META );
		}

		self::refresh_index();
	}

	/**
	 * Products list bulk actions.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public static function register_bulk_actions( array $actions ): array {
		$actions[ self::BULK_REQUIRE ]   = __( 'Mark as requiring an FFL transfer', 'ffl-bridge-for-woocommerce' );
		$actions[ self::BULK_UNREQUIRE ] = __( 'Remove the FFL transfer requirement', 'ffl-bridge-for-woocommerce' );
		return $actions;
	}

	/**
	 * Apply a bulk action. WordPress checks the bulk-posts nonce before this
	 * filter runs; each product is also checked with edit_post.
	 *
	 * @param string          $redirect Redirect URL.
	 * @param string          $action Bulk action.
	 * @param array<int, int> $post_ids Selected IDs.
	 * @return string
	 */
	public static function handle_bulk_action( string $redirect, string $action, array $post_ids ): string {
		if ( ! in_array( $action, array( self::BULK_REQUIRE, self::BULK_UNREQUIRE ), true ) ) {
			return $redirect;
		}

		$changed = 0;
		foreach ( array_map( 'absint', $post_ids ) as $post_id ) {
			if ( 0 === $post_id || 'product' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			if ( self::BULK_REQUIRE === $action ) {
				update_post_meta( $post_id, self::META, 'yes' );
			} else {
				delete_post_meta( $post_id, self::META );
			}
			++$changed;
		}

		self::refresh_index();
		$key = self::BULK_REQUIRE === $action ? 'ffl_bridge_flagged' : 'ffl_bridge_unflagged';
		return add_query_arg( array( $key => $changed ), $redirect );
	}

	/**
	 * Confirm a bulk action on the products list.
	 *
	 * @return void
	 */
	public static function render_bulk_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-product' !== $screen->id || ! current_user_can( 'edit_products' ) ) {
			return;
		}

		// Display only: counts set by handle_bulk_action() after its nonce check.
		$flagged   = isset( $_GET['ffl_bridge_flagged'] ) ? absint( $_GET['ffl_bridge_flagged'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$unflagged = isset( $_GET['ffl_bridge_unflagged'] ) ? absint( $_GET['ffl_bridge_unflagged'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( null === $flagged && null === $unflagged ) {
			return;
		}

		$message = null !== $flagged
			/* translators: %d: number of products. */
			? sprintf( _n( '%d product now requires an FFL transfer.', '%d products now require an FFL transfer.', $flagged, 'ffl-bridge-for-woocommerce' ), $flagged )
			/* translators: %d: number of products. */
			: sprintf( _n( '%d product no longer requires an FFL transfer.', '%d products no longer require an FFL transfer.', (int) $unflagged, 'ffl-bridge-for-woocommerce' ), (int) $unflagged );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}
