/**
 * FFL Bridge Checkout Block SlotFill.
 */
( function ( wp, wc ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wc || ! wc.blocksCheckout || ! wc.wcSettings || ! window.FFLBridgeCheckout ) {
		return;
	}

	var createElement = wp.element.createElement;
	var OrderMeta = wc.blocksCheckout.ExperimentalOrderMeta;
	var config = wc.wcSettings.getSetting( 'ffl-bridge_data', {} );

	if ( ! OrderMeta || ! config.enabled ) {
		return;
	}

	function DealerSelector() {
		function mountSelector( root ) {
			if ( root ) {
				window.FFLBridgeCheckout.mount( root, config );
			}
		}

		return createElement(
			OrderMeta,
			null,
			createElement(
				'div',
				{ className: 'ffl-bridge-section' },
				createElement( 'h3', null, config.required ? config.messages.heading + ' *' : config.messages.heading ),
				createElement( 'p', null, config.messages.description ),
				createElement( 'div', { className: 'ffl-bridge-selector', ref: mountSelector } )
			)
		);
	}

	wp.plugins.registerPlugin( 'ffl-bridge-checkout', {
		render: DealerSelector,
		scope: 'woocommerce-checkout',
	} );
}( window.wp, window.wc ) );
