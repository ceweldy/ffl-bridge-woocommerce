/**
 * Same-origin FFL dealer selector for classic and block checkout.
 */
( function () {
	'use strict';

	function element( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( typeof text === 'string' ) {
			node.textContent = text;
		}
		return node;
	}

	function message( config, key, fallback ) {
		return config.messages && config.messages[ key ] ? config.messages[ key ] : fallback;
	}

	function addressText( dealer ) {
		return [ dealer.address, dealer.city, dealer.state + ' ' + dealer.zip ]
			.filter( Boolean )
			.join( ', ' );
	}

	function request( config, action, fields ) {
		var body = new URLSearchParams( Object.assign( {
			action: action,
			nonce: config.nonce || '',
			requestToken: config.requestToken || '',
		}, fields || {} ) );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					throw new Error( message( config, 'genericError', 'The dealer request could not be completed.' ) );
				} );
			} )
			.then( function ( payload ) {
				if ( ! payload.success ) {
					throw new Error( payload.data && payload.data.message ? payload.data.message : message( config, 'genericError', 'The dealer request could not be completed.' ) );
				}
				return payload.data || {};
			} );
	}

	function appendDealerDetails( container, dealer, includeDistance, config ) {
		container.appendChild( element( 'strong', 'ffl-bridge-dealer-name', dealer.name || '' ) );
		container.appendChild( element( 'span', 'ffl-bridge-dealer-address', addressText( dealer ) ) );
		if ( dealer.phone ) {
			container.appendChild( element( 'span', 'ffl-bridge-dealer-phone', dealer.phone ) );
		}
		if ( includeDistance && typeof dealer.distance === 'number' ) {
			container.appendChild( element( 'span', 'ffl-bridge-dealer-distance', dealer.distance + ' ' + message( config, 'miles', 'miles away' ) ) );
		}
	}

	function appendBadges( container, dealer, config ) {
		var badges = element( 'span', 'ffl-bridge-badges' );
		if ( dealer.storePreferred ) {
			badges.appendChild( element( 'span', 'ffl-bridge-badge is-preferred', message( config, 'preferred', 'Store preferred dealer' ) ) );
		}
		if ( dealer.network === 'unconfirmed' || dealer.followUp ) {
			badges.appendChild( dealer.transferStatus === 'confirmed' || dealer.transferStatus === 'merchant_confirmed'
				? element( 'span', 'ffl-bridge-badge is-unconfirmed', message( config, 'licenseBadge', 'License not verified' ) )
				: element( 'span', 'ffl-bridge-badge is-unconfirmed', message( config, 'unconfirmed', 'Transfer not confirmed' ) ) );
		} else if ( dealer.network === 'verified' ) {
			badges.appendChild( element( 'span', 'ffl-bridge-badge is-verified', message( config, 'verifiedBadge', 'Confirmed transfer dealer' ) ) );
		} else if ( dealer.network === 'directory' ) {
			badges.appendChild( element( 'span', 'ffl-bridge-badge is-directory', message( config, 'directoryOnly', 'Directory listing only' ) ) );
		}
		if ( badges.childNodes.length ) {
			container.appendChild( badges );
		}
	}

	function unconfirmedNote( dealer, config ) {
		return dealer.transferStatus === 'confirmed' || dealer.transferStatus === 'merchant_confirmed'
			? message( config, 'licenseNote', 'This dealer has confirmed transfers with FFL Bridge, but its license copy is not verified yet.' )
			: message( config, 'contactDealer', 'This dealer has not confirmed with FFL Bridge that it accepts transfers. You can still choose it.' );
	}

	function mount( root, suppliedConfig ) {
		if ( ! root || root.dataset.fflBridgeMounted === 'true' ) {
			return;
		}

		var config = Object.assign( {}, suppliedConfig || {} );
		var selected = config.selected || null;
		root.dataset.fflBridgeMounted = 'true';
		root.classList.add( 'ffl-bridge-theme-' + ( config.theme === 'dark' ? 'dark' : 'light' ) );

		function render() {
			root.replaceChildren();

			var status = element( 'div', 'ffl-bridge-status' );
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );

			function setStatus( text, kind ) {
				status.textContent = text || '';
				status.className = 'ffl-bridge-status' + ( kind ? ' is-' + kind : '' );
			}

			if ( selected ) {
				var selectedBox = element( 'div', 'ffl-bridge-selected' );
				selectedBox.appendChild( element( 'h4', '', message( config, 'selectedTitle', 'Selected transfer dealer' ) ) );
				appendBadges( selectedBox, selected, config );
				appendDealerDetails( selectedBox, selected, false, config );
				if ( selected.followUp ) {
					selectedBox.classList.add( 'is-unconfirmed' );
					selectedBox.appendChild( element( 'p', 'ffl-bridge-unconfirmed-note', unconfirmedNote( selected, config ) ) );
					selectedBox.appendChild( element( 'p', 'ffl-bridge-followup', message( config, 'followUp', 'Next step: contact your selected FFL dealer and confirm they will accept this transfer. Then ask them to send a copy of their current FFL to the store.' ) ) );
				}
				selectedBox.appendChild( element( 'p', 'ffl-bridge-confirm-notice', message( config, 'confirmNotice', 'Contact the dealer before the order ships.' ) ) );

				var clearButton = element( 'button', 'button ffl-bridge-clear', message( config, 'clear', 'Choose a different dealer' ) );
				clearButton.type = 'button';
				clearButton.addEventListener( 'click', function () {
					clearButton.disabled = true;
					setStatus( message( config, 'selecting', 'Verifying…' ), '' );
					request( config, 'ffl_bridge_clear' )
						.then( function () {
							selected = null;
							config.selected = null;
							root.dispatchEvent( new CustomEvent( 'ffl-bridge-selection-changed', { bubbles: true, detail: { dealer: null } } ) );
							render();
						} )
						.catch( function ( error ) {
							clearButton.disabled = false;
							setStatus( error.message, 'error' );
						} );
				} );
				selectedBox.appendChild( clearButton );
				root.appendChild( selectedBox );
				root.appendChild( status );
				return;
			}

			if ( ! config.configured ) {
				setStatus( message( config, 'notConfigured', 'Dealer selection is unavailable. Contact the store.' ), 'error' );
				root.appendChild( status );
				return;
			}

			var form = element( 'form', 'ffl-bridge-search-form' );
			form.noValidate = false;

			var zipGroup = element( 'div', 'ffl-bridge-field' );
			var zipId = 'ffl-bridge-zip-' + Math.random().toString( 36 ).slice( 2 );
			var zipLabel = element( 'label', '', message( config, 'zipLabel', 'ZIP code' ) );
			zipLabel.htmlFor = zipId;
			var zip = element( 'input', 'input-text' );
			zip.id = zipId;
			zip.name = 'ffl_bridge_zip';
			zip.type = 'text';
			zip.inputMode = 'numeric';
			zip.autocomplete = 'postal-code';
			zip.pattern = '[0-9]{5}';
			zip.maxLength = 5;
			zip.required = true;
			zip.value = config.initialZip || '';
			zipGroup.append( zipLabel, zip );

			var radiusGroup = element( 'div', 'ffl-bridge-field' );
			var radiusId = 'ffl-bridge-radius-' + Math.random().toString( 36 ).slice( 2 );
			var radiusLabel = element( 'label', '', message( config, 'radiusLabel', 'Radius' ) );
			radiusLabel.htmlFor = radiusId;
			var radius = element( 'select', 'select' );
			radius.id = radiusId;
			[ 10, 25, 50, 100 ].forEach( function ( miles ) {
				var option = element( 'option', '', miles + ' mi' );
				option.value = miles;
				option.selected = miles === 25;
				radius.appendChild( option );
			} );
			radiusGroup.append( radiusLabel, radius );

			var searchButton = element( 'button', 'button alt ffl-bridge-search', message( config, 'search', 'Search dealers' ) );
			searchButton.type = 'submit';
			form.append( zipGroup, radiusGroup, searchButton );

			var results = element( 'div', 'ffl-bridge-results' );
			results.setAttribute( 'aria-live', 'polite' );

			function renderResults( dealers, notice ) {
				results.replaceChildren();
				if ( notice ) {
					var noticeBox = element( 'p', 'ffl-bridge-notice', notice );
					noticeBox.setAttribute( 'role', 'status' );
					results.appendChild( noticeBox );
				}

				if ( ! dealers.length ) {
					if ( ! notice ) {
						results.appendChild( element( 'p', 'ffl-bridge-empty', message( config, 'noResults', 'No eligible dealers were found in that area.' ) ) );
					}
					return;
				}

				dealers.forEach( function ( dealer ) {
					var card = element( 'article', 'ffl-bridge-result' );
					appendBadges( card, dealer, config );
					appendDealerDetails( card, dealer, true, config );

					if ( ! dealer.selectionToken ) {
						card.classList.add( 'is-directory-only' );
						card.appendChild( element( 'p', 'ffl-bridge-directory-note', message( config, 'directoryNote', 'This dealer is not yet verified for checkout.' ) ) );
						results.appendChild( card );
						return;
					}

					if ( dealer.network === 'unconfirmed' ) {
						card.classList.add( 'is-unconfirmed' );
						card.appendChild( element( 'p', 'ffl-bridge-unconfirmed-note', unconfirmedNote( dealer, config ) ) );
					}

					var selectButton = element( 'button', 'button ffl-bridge-select', message( config, 'select', 'Select dealer' ) );
					selectButton.type = 'button';
					selectButton.addEventListener( 'click', function () {
						selectButton.disabled = true;
						selectButton.textContent = message( config, 'selecting', 'Verifying…' );
						setStatus( '', '' );
						request( config, 'ffl_bridge_select', { selection: dealer.selectionToken } )
							.then( function ( data ) {
								selected = data.dealer;
								config.selected = selected;
								root.dispatchEvent( new CustomEvent( 'ffl-bridge-selection-changed', { bubbles: true, detail: { dealer: selected } } ) );
								render();
							} )
							.catch( function ( error ) {
								selectButton.disabled = false;
								selectButton.textContent = message( config, 'select', 'Select dealer' );
								setStatus( error.message, 'error' );
							} );
					} );
					card.appendChild( selectButton );
					results.appendChild( card );
				} );
			}

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				if ( ! form.reportValidity() ) {
					return;
				}

				searchButton.disabled = true;
				searchButton.textContent = message( config, 'searching', 'Searching…' );
				results.replaceChildren();
				setStatus( message( config, 'searching', 'Searching…' ), '' );
				request( config, 'ffl_bridge_search', { zip: zip.value, radius: radius.value } )
					.then( function ( data ) {
						setStatus( '', '' );
						renderResults( Array.isArray( data.dealers ) ? data.dealers : [], typeof data.notice === 'string' ? data.notice : '' );
					} )
					.catch( function ( error ) {
						setStatus( error.message, 'error' );
					} )
					.finally( function () {
						searchButton.disabled = false;
						searchButton.textContent = message( config, 'search', 'Search dealers' );
					} );
			} );

			root.append( form, status, results );
		}

		render();
	}

	function mountClassicSelectors() {
		var config = window.fflBridgeData || {};
		document.querySelectorAll( '[data-ffl-bridge-selector]' ).forEach( function ( root ) {
			mount( root, config );
		} );
	}

	window.FFLBridgeCheckout = { mount: mount };
	document.addEventListener( 'DOMContentLoaded', mountClassicSelectors );
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', mountClassicSelectors );
	}
}() );
