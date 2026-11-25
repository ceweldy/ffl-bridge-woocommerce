/**
 * FFL Bridge "Mark transfer confirmed" action on the order screen.
 *
 * The order screen is already a form, so the controls post separately.
 */
( function () {
	'use strict';

	function post( config, fields ) {
		var body = new FormData();
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );
		return fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	// Change the dealer on an order, or add one to a manual order. The browser
	// sends only the dealer ID; the server fetches the dealer record itself.
	function setUpDealerChange( box, config ) {
		var toggle = box.querySelector( '[data-ffl-bridge-change-toggle]' );
		var form = box.querySelector( '[data-ffl-bridge-change-form]' );
		var search = box.querySelector( '[data-ffl-bridge-change-search]' );
		var results = box.querySelector( '[data-ffl-bridge-change-results]' );
		var status = box.querySelector( '[data-ffl-bridge-change-status]' );
		if ( ! toggle || ! form || ! search || ! results || ! status ) {
			return;
		}

		var base = {
			order_id: box.getAttribute( 'data-order-id' ) || '',
			nonce: box.getAttribute( 'data-nonce' ) || '',
		};

		toggle.addEventListener( 'click', function () {
			form.hidden = ! form.hidden;
			toggle.setAttribute( 'aria-expanded', form.hidden ? 'false' : 'true' );
		} );

		search.addEventListener( 'click', function () {
			search.disabled = true;
			results.replaceChildren();
			status.textContent = config.searching || 'Searching…';
			post( config, Object.assign( { action: 'ffl_bridge_admin_dealer_search' }, base, {
				zip: box.querySelector( '[data-ffl-bridge-change-zip]' ).value,
				radius: box.querySelector( '[data-ffl-bridge-change-radius]' ).value,
				name: box.querySelector( '[data-ffl-bridge-change-name]' ).value,
			} ) )
				.then( function ( payload ) {
					var data = payload && payload.data ? payload.data : {};
					status.textContent = payload && payload.success ? ( data.message || '' ) : ( data.message || config.searchFail );
					( payload && payload.success && Array.isArray( data.dealers ) ? data.dealers : [] ).forEach( function ( dealer ) {
						var row = document.createElement( 'p' );
						var name = document.createElement( 'strong' );
						name.textContent = dealer.name;
						row.appendChild( name );
						row.appendChild( document.createTextNode( ' ' + dealer.address + ' · ' + ( config.licenseText || 'License:' ) + ' ' + dealer.license + ' · ' + dealer.status + ' ' ) );
						var use = document.createElement( 'button' );
						use.type = 'button';
						use.className = 'button button-small';
						use.textContent = config.useDealer || 'Use this dealer';
						use.addEventListener( 'click', function () {
							use.disabled = true;
							status.textContent = config.saving || 'Saving dealer…';
							post( config, Object.assign( { action: 'ffl_bridge_admin_set_dealer', dealer_id: dealer.id }, base ) )
								.then( function ( saved ) {
									status.textContent = saved && saved.data && saved.data.message ? saved.data.message : config.saveFail;
									if ( saved && saved.success ) {
										window.location.reload();
									} else {
										use.disabled = false;
									}
								} )
								.catch( function () {
									status.textContent = config.saveFail || 'The dealer could not be saved.';
									use.disabled = false;
								} );
						} );
						row.appendChild( use );
						results.appendChild( row );
					} );
				} )
				.catch( function () {
					status.textContent = config.searchFail || 'The dealer search could not be completed.';
				} )
				.finally( function () {
					search.disabled = false;
				} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var config = window.fflBridgeAdminOrder || {};

		document.querySelectorAll( '[data-ffl-bridge-change-dealer]' ).forEach( function ( box ) {
			setUpDealerChange( box, config );
		} );

		document.querySelectorAll( '[data-ffl-bridge-confirm]' ).forEach( function ( box ) {
			var button = box.querySelector( '[data-ffl-bridge-confirm-button]' );
			var status = box.querySelector( '[data-ffl-bridge-confirm-status]' );
			var file = box.querySelector( '[data-ffl-bridge-file]' );
			var note = box.querySelector( '[data-ffl-bridge-note]' );

			if ( ! button || ! status ) {
				return;
			}

			button.addEventListener( 'click', function () {
				var body = new FormData();
				body.append( 'action', 'ffl_bridge_confirm_transfer' );
				body.append( 'order_id', box.getAttribute( 'data-order-id' ) || '' );
				body.append( 'nonce', box.getAttribute( 'data-nonce' ) || '' );
				body.append( 'note', note ? note.value : '' );
				if ( file && file.files && file.files[ 0 ] ) {
					body.append( 'license_file', file.files[ 0 ] );
				}

				button.disabled = true;
				status.textContent = ' ' + ( config.working || 'Saving confirmation…' );

				fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( payload ) {
						var message = payload && payload.data && payload.data.message ? payload.data.message : config.failed;
						status.textContent = ' ' + message;
						if ( payload && payload.success ) {
							window.setTimeout( function () {
								window.location.reload();
							}, 1500 );
						} else {
							button.disabled = false;
						}
					} )
					.catch( function () {
						status.textContent = ' ' + ( config.failed || 'The confirmation could not be saved.' );
						button.disabled = false;
					} );
			} );
		} );
	} );
}() );
