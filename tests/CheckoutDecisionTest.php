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

	public function test_product_in_a_child_of_a_selected_category_applies_workflow(): void {
		// Firearms (7) > Rifles (12); only Firearms is selected.
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_categories'] = array( 7 );
		$GLOBALS['ffl_bridge_test_term_parents']                    = array( 12 => 7 );
		$GLOBALS['ffl_bridge_test_term_map'][100]                   = array( 12 );

		$this->assertTrue( FFL_Bridge_Checkout::cart_requires_ffl() );
	}

	public function test_product_in_a_grandchild_of_a_selected_category_applies_workflow(): void {
		// Firearms (7) > Rifles (12) > Bolt action (30).
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_categories'] = array( 7 );
		$GLOBALS['ffl_bridge_test_term_parents']                    = array( 30 => 12, 12 => 7 );
		$GLOBALS['ffl_bridge_test_term_map'][100]                   = array( 30 );

		$this->assertTrue( FFL_Bridge_Checkout::cart_requires_ffl() );
	}

	public function test_product_in_a_child_of_an_unselected_category_skips_workflow(): void {
		// Accessories (3) > Optics (14); only Firearms (7) is selected.
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_categories'] = array( 7 );
		$GLOBALS['ffl_bridge_test_term_parents']                    = array( 14 => 3, 12 => 7 );
		$GLOBALS['ffl_bridge_test_term_map'][100]                   = array( 14 );

		$this->assertFalse( FFL_Bridge_Checkout::cart_requires_ffl() );
	}

	public function test_selecting_only_a_child_category_does_not_apply_to_its_parent(): void {
		// Only Rifles (12) is selected; a product in Firearms (7) alone is not covered.
		$GLOBALS['ffl_bridge_test_options']['ffl_bridge_categories'] = array( 12 );
		$GLOBALS['ffl_bridge_test_term_parents']                    = array( 12 => 7 );
		$GLOBALS['ffl_bridge_test_term_map'][100]                   = array( 7 );

		$this->assertFalse( FFL_Bridge_Checkout::cart_requires_ffl() );
	}
}
