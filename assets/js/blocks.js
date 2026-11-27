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

	// Ship to the chosen dealer: the server writes the dealer address onto the
	// customer and puts it back after every address update. After a selection
	// changes, ask the Store API to run that sync so the block shows it.
	var data = wp.data;
	function setShipToDealer( active ) {
		document.body.classList.toggle( 'ffl-bridge-ship-to-dealer', !! active );
		if ( active && data && data.select && data.dispatch ) {
			// Billing stays the shopper's: stop the block copying the dealer
			// address into billing.
			var checkout = data.select( 'wc/store/checkout' );
			var actions = data.dispatch( 'wc/store/checkout' );
			if ( checkout && actions && checkout.getUseShippingAsBilling && checkout.getUseShippingAsBilling() && actions.__internalSetUseShippingAsBilling ) {
				actions.__internalSetUseShippingAsBilling( false );
			}
		}
	}

	if ( config.shipToDealer ) {
		setShipToDealer( !! ( config.selected && config.shipTo ) );
		document.addEventListener( 'ffl-bridge-selection-changed', function ( event ) {
			setShipToDealer( !! ( event.detail && event.detail.shipTo ) );
			if ( typeof wc.blocksCheckout.extensionCartUpdate === 'function' ) {
				var update = wc.blocksCheckout.extensionCartUpdate( { namespace: 'ffl-bridge', data: {} } );
				if ( update && typeof update.catch === 'function' ) {
					// The order address is set on the server either way.
					update.catch( function () {} );
				}
			}
		} );
	}

	// In-store pickup: tell the picker when a local pickup rate is selected.
	if ( config.storeDealer && data && data.subscribe && data.select ) {
		var lastPickup = null;
		data.subscribe( function () {
			var cart = data.select( 'wc/store/cart' );
			var packages = cart && cart.getShippingRates ? cart.getShippingRates() : [];
			var active = ( packages || [] ).some( function ( pack ) {
				return ( pack.shipping_rates || [] ).some( function ( rate ) {
					return rate.selected && /^(local_pickup|pickup_location)/.test( rate.method_id || '' );
				} );
			} );
			if ( active !== lastPickup ) {
				lastPickup = active;
				document.dispatchEvent( new CustomEvent( 'ffl-bridge-pickup-changed', { detail: { active: active } } ) );
			}
		} );
	}

	wp.plugins.registerPlugin( 'ffl-bridge-checkout', {
		render: DealerSelector,
		scope: 'woocommerce-checkout',
	} );
}( window.wp, window.wc ) );
