<?php
/**
 * 1.3.0 item 2: per-product "Requires FFL transfer" flag.
 *
 * Precedence: with no categories selected and no product flagged anywhere,
 * every product uses the dealer workflow (the safe default). Otherwise a cart
 * item uses it when its product or variation is flagged, or it is in a
 * selected category (child categories included).
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class ProductFlagTest extends FFL_Bridge_TestCase {
	private const META = '_ffl_bridge_requires_ffl';

	public function test_no_categories_and_no_flags_keeps_every_product_applicable(): void {
		$this->cart( array( 'product_id' => 200 ) );
		$this->assertTrue( FFL_Bridge_Checkout::cart_requires_ffl() );
	}

	public function test_once_a_product_is_flagged_only_flagged_products_apply_without_categories(): void {
		$this->flag( 100 );

		$this->cart( array( 'product_id' => 200 ) );
		$this->assertFalse( FFL_Bridge_Checkout::cart_requires_ffl() );

		$this->cart( array( 'product_id' => 200 ), array( 'product_id' => 100 ) );
		$this->assertTrue( FFL_Bridge_Checkout::cart_requires_ffl() );
	}

	public function test_a_flagged_variation_applies_and_its_unflagged_sibling_does_not(): void {
		$this->flag( 301 );

		$this->cart( array( 'product_id' => 300, 'variation_id' => 301 ) );
		$this->assertTrue( FFL_Bridge_Checkout::cart_requires_ffl() );

		$this->cart( array( 'product_id' => 300, 'variation_id' => 302 ) );
		$this->assertFalse( FFL_Bridge_Checkout::cart_requires_ffl() );
	}

	public function test_a_flagged_parent_covers_all_of_its_variations(): void {
		$this->flag( 300 );

		$this->cart( array( 'product_id' => 300, 'variation_id' => 302 ) );
		$this->assertTrue( FFL_Bridge_Checkout::cart_requires_ffl() );
	}

	public function test_flags_and_categories_combine(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_categories'] = array( 7 );
		$GLOBALS['ffl_bridge_test_term_map'][200]                    = array( 7 );
		$GLOBALS['ffl_bridge_test_term_map'][100]                    = array( 9 );
		$GLOBALS['ffl_bridge_test_term_map'][400]                    = array( 9 );
		$this->flag( 100 );

		$this->cart( array( 'product_id' => 200 ) );
		$this->assertTrue( FFL_Bridge_Checkout::cart_requires_ffl(), 'in a selected category' );

		$this->cart( array( 'product_id' => 100 ) );
		$this->assertTrue( FFL_Bridge_Checkout::cart_requires_ffl(), 'flagged, outside the categories' );

		$this->cart( array( 'product_id' => 400 ) );
		$this->assertFalse( FFL_Bridge_Checkout::cart_requires_ffl(), 'neither' );
	}

	public function test_categories_without_flags_work_as_before(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_categories'] = array( 7 );
		$GLOBALS['ffl_bridge_test_term_map'][200]                    = array( 9 );

		$this->cart( array( 'product_id' => 200 ) );
		$this->assertFalse( FFL_Bridge_Checkout::cart_requires_ffl() );
	}

	public function test_saving_a_simple_product_sets_and_clears_the_flag(): void {
		$GLOBALS['ffl_bridge_test_caps'] = array( 'edit_products' );
		$product                         = new WC_Product( 100 );

		$_POST = array( self::META => 'yes' );
		FFL_Bridge_Product_Flags::save_product( $product );
		$this->assertSame( 'yes', $product->get_meta( self::META ) );
		$this->assertTrue( FFL_Bridge_Product_Flags::any_flagged() );

		$_POST = array();
		FFL_Bridge_Product_Flags::save_product( $product );
		$this->assertSame( '', $product->get_meta( self::META ) );
		$this->assertFalse( FFL_Bridge_Product_Flags::any_flagged(), 'the safe default returns when no flag remains' );
	}

	public function test_saving_a_variation_sets_and_clears_its_flag(): void {
		$GLOBALS['ffl_bridge_test_caps'] = array( 'edit_products' );

		$_POST = array( '_ffl_bridge_requires_ffl_variation' => array( 2 => 'yes' ) );
		FFL_Bridge_Product_Flags::save_variation( 301, 2 );
		$this->assertSame( 'yes', get_post_meta( 301, self::META, true ) );
		$this->assertTrue( FFL_Bridge_Product_Flags::any_flagged() );

		$_POST = array( '_ffl_bridge_requires_ffl_variation' => array( 0 => 'yes' ) );
		FFL_Bridge_Product_Flags::save_variation( 301, 2 );
		$this->assertSame( '', get_post_meta( 301, self::META, true ) );
		$this->assertFalse( FFL_Bridge_Product_Flags::any_flagged() );
	}

	public function test_saving_without_capability_changes_nothing(): void {
		$product = new WC_Product( 100 );
		$_POST   = array( self::META => 'yes' );

		FFL_Bridge_Product_Flags::save_product( $product );
		FFL_Bridge_Product_Flags::save_variation( 301, 0 );

		$this->assertSame( '', $product->get_meta( self::META ) );
		$this->assertSame( '', get_post_meta( 301, self::META, true ) );
	}

	public function test_bulk_actions_are_offered_on_the_products_list(): void {
		$actions = FFL_Bridge_Product_Flags::register_bulk_actions( array( 'edit' => 'Edit' ) );

		$this->assertSame( 'Edit', $actions['edit'] );
		$this->assertSame( 'Mark as requiring an FFL transfer', $actions['ffl_bridge_require_ffl'] );
		$this->assertSame( 'Remove the FFL transfer requirement', $actions['ffl_bridge_unrequire_ffl'] );
	}

	public function test_bulk_action_flags_only_products_the_user_can_edit(): void {
		$GLOBALS['ffl_bridge_test_caps']      = array( 'edit_products', 'edit_post:100', 'edit_post:200' );
		$GLOBALS['ffl_bridge_test_post_types'] = array( 100 => 'product', 200 => 'product', 300 => 'product', 999 => 'post' );

		$url = FFL_Bridge_Product_Flags::handle_bulk_action( 'https://store.example.test/wp-admin/edit.php?post_type=product', 'ffl_bridge_require_ffl', array( 100, 200, 300, 999 ) );

		$this->assertSame( 'yes', get_post_meta( 100, self::META, true ) );
		$this->assertSame( 'yes', get_post_meta( 200, self::META, true ) );
		$this->assertSame( '', get_post_meta( 300, self::META, true ), 'no edit_post capability' );
		$this->assertSame( '', get_post_meta( 999, self::META, true ), 'not a product' );
		$this->assertStringContainsString( 'ffl_bridge_flagged=2', $url );
		$this->assertTrue( FFL_Bridge_Product_Flags::any_flagged() );

		$url = FFL_Bridge_Product_Flags::handle_bulk_action( $url, 'ffl_bridge_unrequire_ffl', array( 100, 200 ) );
		$this->assertSame( '', get_post_meta( 100, self::META, true ) );
		$this->assertStringContainsString( 'ffl_bridge_unflagged=2', $url );
		$this->assertFalse( FFL_Bridge_Product_Flags::any_flagged() );
	}

	public function test_other_bulk_actions_are_left_alone(): void {
		$this->assertSame( 'https://x.test/', FFL_Bridge_Product_Flags::handle_bulk_action( 'https://x.test/', 'trash', array( 100 ) ) );
		$this->assertSame( array(), $GLOBALS['ffl_bridge_test_post_meta'] );
	}

	public function test_product_and_variation_checkboxes_are_rendered(): void {
		$GLOBALS['ffl_bridge_test_post_meta'][100][ self::META ] = 'yes';
		$GLOBALS['ffl_bridge_test_current_post_id']              = 100;

		ob_start();
		FFL_Bridge_Product_Flags::render_product_field();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Requires FFL transfer', $html );
		$this->assertStringContainsString( 'checked', $html );

		ob_start();
		FFL_Bridge_Product_Flags::render_variation_field( 3, array(), (object) array( 'ID' => 301 ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Requires FFL transfer', $html );
		$this->assertStringContainsString( 'name="_ffl_bridge_requires_ffl_variation[3]"', $html );
		$this->assertStringNotContainsString( 'checked', $html );
	}

	private function flag( int $id ): void {
		$GLOBALS['ffl_bridge_test_post_meta'][ $id ][ self::META ] = 'yes';
		FFL_Bridge_Product_Flags::refresh_index();
	}

	/** @param array<string, int> ...$items Cart items. */
	private function cart( array ...$items ): void {
		WC()->cart = new FFL_Bridge_Test_Cart( $items );
	}
}
