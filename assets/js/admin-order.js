/**
 * FFL Bridge "Mark transfer confirmed" action on the order screen.
 *
 * The order screen is already a form, so the controls post separately.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var config = window.fflBridgeAdminOrder || {};

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
