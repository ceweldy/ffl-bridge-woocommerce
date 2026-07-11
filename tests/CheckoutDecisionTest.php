<?php
/**
 * Cart/category applicability tests.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class CheckoutDecisionTest extends FFL_Bridge_TestCase {
	public function test_empty_cart_never_requires_selection(): void {
		WC()->cart = new FFL_Bridge_Test_Cart();

		$this->assertFalse( FFL_Bridge_Checkout::cart_requires_ffl() );
		$this->assertFalse( FFL_Bridge_Checkout::selection_is_required() );
	}

	public function test_no_category_configuration_applies_to_every_nonempty_cart(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_categories'] = array();

		$this->assertTrue( FFL_Bridge_Checkout::cart_requires_ffl() );
	}

	public function test_matching_product_category_applies_workflow(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_categories'] = array( 7, 9 );
		$GLOBALS['ffl_bridge_test_term_map'][100]                   = array( 2, 9 );

		$this->assertTrue( FFL_Bridge_Checkout::cart_requires_ffl() );
	}

	public function test_nonmatching_product_category_skips_workflow(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_categories'] = array( 7, 9 );
		$GLOBALS['ffl_bridge_test_term_map'][100]                   = array( 2, 3 );

		$this->assertFalse( FFL_Bridge_Checkout::cart_requires_ffl() );
	}

	public function test_any_matching_item_in_mixed_cart_applies_workflow(): void {
		WC()->cart = new FFL_Bridge_Test_Cart(
			array(
				array( 'product_id' => 100 ),
				array( 'product_id' => 200 ),
			)
		);
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_categories'] = array( 7 );
		$GLOBALS['ffl_bridge_test_term_map']                         = array( 100 => array( 2 ), 200 => array( 7 ) );

		$this->assertTrue( FFL_Bridge_Checkout::cart_requires_ffl() );
	}

	public function test_required_setting_only_blocks_applicable_carts(): void {
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_categories'] = array( 7 );
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_required']   = 'yes';
		$GLOBALS['ffl_bridge_test_term_map'][100]                   = array( 7 );
		$this->assertTrue( FFL_Bridge_Checkout::selection_is_required() );

		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_required'] = 'no';
		$this->assertFalse( FFL_Bridge_Checkout::selection_is_required() );

		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_required'] = 'yes';
		$GLOBALS['ffl_bridge_test_term_map'][100]                 = array( 3 );
		$this->assertFalse( FFL_Bridge_Checkout::selection_is_required() );
	}
}
