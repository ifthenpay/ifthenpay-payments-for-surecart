/**
 * Injects an ifthenpay row into SureCart's Processors tab
 * (admin.php?page=sc-settings&tab=processors), matching the native
 * Stripe/PayPal/Razorpay rows exactly: an <sc-stacked-list-row> inside the
 * same <sc-stacked-list> those rows live in.
 *
 * SureCart's settings screen is one client-rendered SPA covering every
 * settings tab (General, Processors, Emails, ...) — PHP never knows which
 * tab is active (SureCart's own routing renders the same shell for every
 * GET and lets the tab switch client-side without a reload), so `tab` is
 * read from `location.search` here, re-checked on every DOM mutation the
 * observer sees.
 *
 * The Processors tab itself renders two sibling sections sharing the same
 * <sc-stacked-list> markup: "Available Processors" (Stripe/PayPal/Razorpay
 * style — where ifthenpay belongs) and "Manual Payment Methods" (where our
 * own auto-provisioned ifthenpay Gateway/MB WAY/Multibanco entries already show
 * up as manual methods). Picking "any stacked list with rows" can land in
 * either one, so this anchors on the "Available Processors" heading text
 * (SureCart's own translation of it, passed down from PHP) and only looks
 * for a list inside that heading's own section.
 *
 * There's no PHP hook to register a third-party row into the SPA, so this
 * is a best-effort DOM injection. If SureCart changes its markup or
 * translation strings, this simply injects nothing rather than guessing —
 * never breaks the page.
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof window.iftpScProcessorsCard ) {
		return;
	}

	var config = window.iftpScProcessorsCard;
	var MARK = 'data-iftp-sc-processor-row';

	function isProcessorsTab() {
		try {
			return 'processors' === new URLSearchParams( window.location.search ).get( 'tab' );
		} catch ( e ) {
			return false;
		}
	}

	function findAvailableProcessorsList() {
		var root = document.getElementById( 'sc-settings-app' );
		if ( ! root || ! config.processorsSectionTitle ) {
			return null;
		}

		var heading = null;
		var candidates = root.querySelectorAll( '*' );
		for ( var i = 0; i < candidates.length; i++ ) {
			var el = candidates[ i ];
			if ( ! el.children.length && el.textContent.trim() === config.processorsSectionTitle ) {
				heading = el;
				break;
			}
		}
		if ( ! heading ) {
			return null;
		}


		var section = heading.parentElement;
		for ( var depth = 0; section && depth < 8; depth++ ) {
			var list = section.querySelector( 'sc-stacked-list' );
			if ( list ) {
				return list;
			}
			section = section.parentElement;
		}
		return null;
	}

	function buildRow() {
		var pillType = config.isConnected ? 'success' : 'warning';
		var pillText = config.isConnected ? config.enabledText : config.disabledText;

		var row = document.createElement( 'sc-stacked-list-row' );
		row.setAttribute( 'href', config.settingsUrl );
		row.setAttribute( MARK, '1' );
		row.innerHTML =
			'<sc-icon name="chevron-right" slot="suffix"></sc-icon>' +
			'<sc-flex flex-direction="column">' +
			'<sc-flex gap="1em" justify-content="flex-start" align-items="center">' +
			'<img src="' + config.logoUrl + '" alt="" height="32" width="auto" />' +
			'</sc-flex>' +
			'<sc-text>' + escapeHtml( config.description ) + '</sc-text>' +
			'<div><sc-tag type="' + pillType + '" size="medium">' + escapeHtml( pillText ) + '</sc-tag></div>' +
			'</sc-flex>';

		return row;
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = String( value );
		return div.innerHTML;
	}

	function tryInject() {
		if ( ! isProcessorsTab() ) {
			return;
		}

		var list = findAvailableProcessorsList();
		if ( ! list || list.querySelector( '[' + MARK + ']' ) ) {
			return;
		}

		list.appendChild( buildRow() );
	}

	var observer = new MutationObserver( tryInject );
	observer.observe( document.body, { childList: true, subtree: true } );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', tryInject );
	} else {
		tryInject();
	}
} )();
