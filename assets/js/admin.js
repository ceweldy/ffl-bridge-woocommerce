/**
 * FFL Bridge settings connection test.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'ffl-bridge-test' );
		var result = document.getElementById( 'ffl-bridge-test-result' );
		var config = window.fflBridgeAdmin || {};

		if ( ! button || ! result ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var body = new URLSearchParams( {
				action: 'ffl_bridge_test_connection',
				nonce: config.nonce || '',
			} );

			button.disabled = true;
			result.className = '';
			result.textContent = ' ' + ( config.testing || 'Testing…' );

			fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					result.className = payload.success ? 'ffl-bridge-test-success' : 'ffl-bridge-test-error';
					result.textContent = ' ' + ( payload.data && payload.data.message ? payload.data.message : config.failed );
				} )
				.catch( function () {
					result.className = 'ffl-bridge-test-error';
					result.textContent = ' ' + ( config.failed || 'Connection test failed.' );
				} )
				.finally( function () {
					button.disabled = false;
				} );
		} );
	} );
}() );
