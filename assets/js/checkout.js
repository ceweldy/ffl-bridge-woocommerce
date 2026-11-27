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
		if ( dealer.lastUsed ) {
			badges.appendChild( element( 'span', 'ffl-bridge-badge is-last-used', message( config, 'lastUsed', 'Your last dealer' ) ) );
		}
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

	// Classic checkout shipping fields that the dealer address replaces. The
	// server sets the order's shipping address itself; locking the fields only
	// shows the shopper what will be used.
	var SHIP_FIELDS = [ 'country', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode' ];

	function triggerChange( node ) {
		if ( window.jQuery ) {
			window.jQuery( node ).trigger( 'change' );
		} else {
			node.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	function updateCheckout() {
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
	}

	function keepChecked( event ) {
		if ( ! event.target.checked ) {
			event.target.checked = true;
			triggerChange( event.target );
		}
	}

	// The shopper's own field values from before the first lock, put back when
	// the lock ends (a cleared dealer or a switch to local pickup).
	var savedShipping = null;

	function lockShipping( shipTo, note ) {
		if ( ! savedShipping ) {
			savedShipping = {};
			SHIP_FIELDS.forEach( function ( name ) {
				var current = document.getElementById( 'shipping_' + name );
				if ( current ) {
					savedShipping[ name ] = current.value;
				}
			} );
		}

		var toggle = document.getElementById( 'ship-to-different-address-checkbox' );
		if ( toggle ) {
			if ( ! toggle.checked ) {
				toggle.checked = true;
				triggerChange( toggle );
			}
			toggle.addEventListener( 'change', keepChecked );
		}

		SHIP_FIELDS.forEach( function ( name ) {
			// Looked up one at a time: a country change can replace the state field.
			var node = document.getElementById( 'shipping_' + name );
			if ( ! node || typeof shipTo[ name ] !== 'string' ) {
				return;
			}
			if ( node.value !== shipTo[ name ] ) {
				node.value = shipTo[ name ];
				triggerChange( node );
			}
			if ( node.tagName === 'SELECT' ) {
				node.classList.add( 'ffl-bridge-locked' );
				node.setAttribute( 'aria-readonly', 'true' );
				node.setAttribute( 'tabindex', '-1' );
			} else {
				node.readOnly = true;
			}
		} );

		var fields = document.querySelector( '.woocommerce-shipping-fields' );
		if ( fields && note && ! fields.querySelector( '.ffl-bridge-ship-note' ) ) {
			fields.insertBefore( element( 'p', 'ffl-bridge-ship-note', note ), fields.firstChild );
		}
		updateCheckout();
	}

	function unlockShipping( restore ) {
		restore = restore || savedShipping;
		savedShipping = null;
		var toggle = document.getElementById( 'ship-to-different-address-checkbox' );
		if ( toggle ) {
			toggle.removeEventListener( 'change', keepChecked );
		}

		SHIP_FIELDS.forEach( function ( name ) {
			var node = document.getElementById( 'shipping_' + name );
			if ( ! node ) {
				return;
			}
			node.readOnly = false;
			node.classList.remove( 'ffl-bridge-locked' );
			node.removeAttribute( 'aria-readonly' );
			node.removeAttribute( 'tabindex' );
			if ( restore && typeof restore[ name ] === 'string' && node.value !== restore[ name ] ) {
				node.value = restore[ name ];
				triggerChange( node );
			}
		} );

		document.querySelectorAll( '.ffl-bridge-ship-note' ).forEach( function ( note ) {
			note.remove();
		} );
		updateCheckout();
	}

	function mount( root, suppliedConfig ) {
		if ( ! root || root.dataset.fflBridgeMounted === 'true' ) {
			return;
		}

		var config = Object.assign( {}, suppliedConfig || {} );
		var selected = config.selected || null;
		var pickup = !! ( config.storeDealer && config.pickupActive );
		var shipNote = message( config, 'shipToDealerNote', 'This order ships to your selected transfer dealer.' );
		root.dataset.fflBridgeMounted = 'true';
		if ( config.shipToDealer && selected && config.shipTo ) {
			lockShipping( config.shipTo, shipNote );
		}
		root.classList.add( 'ffl-bridge-theme-' + ( config.theme === 'dark' ? 'dark' : 'light' ) );

		// In-store pickup: the store is the dealer while a pickup method is chosen.
		document.addEventListener( 'ffl-bridge-pickup-changed', function ( event ) {
			var active = !! ( config.storeDealer && event.detail && event.detail.active );
			if ( active !== pickup ) {
				pickup = active;
				// Pickup has no shipping address: give the fields back while it is
				// chosen, and lock them to the dealer again after.
				if ( config.shipToDealer && selected && config.shipTo ) {
					if ( pickup ) {
						unlockShipping( null );
					} else {
						lockShipping( config.shipTo, shipNote );
					}
				}
				render();
			}
		} );

		function render() {
			root.replaceChildren();

			if ( pickup ) {
				var pickupBox = element( 'div', 'ffl-bridge-selected ffl-bridge-pickup' );
				pickupBox.appendChild( element( 'h4', '', message( config, 'pickupTitle', 'Transfer dealer for in-store pickup' ) ) );
				appendDealerDetails( pickupBox, config.storeDealer, false, config );
				pickupBox.appendChild( element( 'p', 'ffl-bridge-pickup-note', message( config, 'pickupNote', 'You chose in-store pickup, so the store is your transfer dealer.' ) ) );
				root.appendChild( pickupBox );
				return;
			}

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
					selectedBox.appendChild( element( 'p', 'ffl-bridge-followup', message( config, 'followUp', 'Next step: contact your selected FFL dealer and confirm they will accept this transfer. Then ask them to send a copy of their current FFL to the store.' ) ) );
				}
				if ( config.shipToDealer && config.shipTo ) {
					selectedBox.appendChild( element( 'p', 'ffl-bridge-ship-note', shipNote ) );
				}
				selectedBox.appendChild( element( 'p', 'ffl-bridge-confirm-notice', message( config, 'confirmNotice', 'Contact the dealer before the order ships.' ) ) );

				var clearButton = element( 'button', 'button ffl-bridge-clear', message( config, 'clear', 'Choose a different dealer' ) );
				clearButton.type = 'button';
				clearButton.addEventListener( 'click', function () {
					clearButton.disabled = true;
					setStatus( message( config, 'selecting', 'Verifying…' ), '' );
					request( config, 'ffl_bridge_clear' )
						.then( function ( data ) {
							selected = null;
							config.selected = null;
							if ( config.shipTo ) {
								config.shipTo = null;
								unlockShipping( data.restore || null );
							}
							root.dispatchEvent( new CustomEvent( 'ffl-bridge-selection-changed', { bubbles: true, detail: { dealer: null, shipTo: null } } ) );
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

			var name = null;
			var nameGroup = null;
			if ( config.nameFilter ) {
				nameGroup = element( 'div', 'ffl-bridge-field ffl-bridge-field-name' );
				var nameId = 'ffl-bridge-name-' + Math.random().toString( 36 ).slice( 2 );
				var nameLabel = element( 'label', '', message( config, 'nameLabel', 'Dealer name (optional)' ) );
				nameLabel.htmlFor = nameId;
				name = element( 'input', 'input-text' );
				name.id = nameId;
				name.name = 'ffl_bridge_name';
				name.type = 'search';
				name.maxLength = 60;
				name.autocomplete = 'off';
				nameGroup.append( nameLabel, name );
			}

			var searchButton = element( 'button', 'button alt ffl-bridge-search', message( config, 'search', 'Search dealers' ) );
			searchButton.type = 'submit';
			form.append( zipGroup, radiusGroup );
			if ( nameGroup ) {
				form.append( nameGroup );
			}
			form.append( searchButton );

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
								config.shipTo = config.shipToDealer && data.shipTo ? data.shipTo : null;
								if ( config.shipTo ) {
									lockShipping( config.shipTo, shipNote );
								}
								root.dispatchEvent( new CustomEvent( 'ffl-bridge-selection-changed', { bubbles: true, detail: { dealer: selected, shipTo: config.shipTo } } ) );
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
				var fields = { zip: zip.value, radius: radius.value };
				if ( name && name.value.trim() ) {
					fields.name = name.value.trim();
				}
				request( config, 'ffl_bridge_search', fields )
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

	// Classic checkout refreshes .ffl-bridge-pickup-state after every order
	// review update, including a shipping method change.
	var lastPickup = null;
	function syncPickup() {
		var state = document.querySelector( '.ffl-bridge-pickup-state' );
		var active = !! state && state.getAttribute( 'data-active' ) === '1';
		if ( active !== lastPickup ) {
			lastPickup = active;
			document.dispatchEvent( new CustomEvent( 'ffl-bridge-pickup-changed', { detail: { active: active } } ) );
		}
	}

	window.FFLBridgeCheckout = { mount: mount, syncPickup: syncPickup };
	document.addEventListener( 'DOMContentLoaded', mountClassicSelectors );
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', function () {
			mountClassicSelectors();
			syncPickup();
		} );
	}
}() );
