/**
 * Polls the local `/status` endpoint on the order-confirmation page while an
 * MB WAY or ifthenpay Gateway payment is still awaiting the ifthenpay webhook.
 * Never calls ifthenpay itself — only reflects what the webhook already
 * wrote (mirrors `multibanco-ifthen-software-gateway-for-woocommerce`'s
 * `assets/mbway.js` back-off pattern).
 */
( function () {
	'use strict';

	var dataEl = document.getElementById( 'iftp-sc-status-data' );
	if ( ! dataEl ) {
		return;
	}

	var data;
	try {
		data = JSON.parse( dataEl.textContent );
	} catch ( e ) {
		return;
	}

	var container = document.querySelector( '[data-iftp-sc-ref="' + data.ref + '"]' );
	if ( ! container ) {
		return;
	}

	var INITIAL_INTERVAL = 4000;
	var BACKOFF_FACTOR = 1.2;
	var MAX_ELAPSED = 15 * 60 * 1000;

	var interval = INITIAL_INTERVAL;
	var elapsed = 0;

	function setState( state ) {
		container.className = container.className.replace( /iftp-sc-mbway-status--\S+/, 'iftp-sc-mbway-status--' + state.toLowerCase() );
	}

	function poll() {

		var separator = -1 === data.restUrl.indexOf( '?' ) ? '?' : '&';
		fetch( data.restUrl + '/status' + separator + 'ref=' + encodeURIComponent( data.ref ) + '&checkout_id=' + encodeURIComponent( data.checkoutId ) )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( body ) {
				var state = body && body.state ? body.state : 'PENDING';

				if ( 'PAID' === state ) {
					setState( 'paid' );
					window.location.reload();
					return;
				}

				if ( 'FAILED' === state || 'CANCELLED' === state ) {
					setState( state );
					return;
				}

				scheduleNext();
			} )
			.catch( scheduleNext );
	}

	function scheduleNext() {
		elapsed += interval;
		if ( elapsed >= MAX_ELAPSED ) {
			setState( 'expired' );
			return;
		}

		interval = interval * BACKOFF_FACTOR;
		window.setTimeout( poll, interval );
	}

	window.setTimeout( poll, interval );
} )();
