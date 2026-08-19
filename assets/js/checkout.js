/**
 * Two DOM adjustments to ifthenpay's manual-payment-method rows that
 * SureCart's own `sc-payment-method-choice`/`ManualPaymentMethods` component
 * has no hook for (it always renders the row's summary as plain text from
 * the manual payment method's own `name` field):
 *
 * - Hides all three rows on recurring (subscription) checkouts, since
 *   ifthenpay cannot auto-renew a subscription — see the plugin's
 *   `readme.txt` "Subscriptions" section for why this is a deliberate
 *   limitation, not a bug.
 * - Replaces the plain-text summary (e.g. "ifthenpay Gateway", and
 *   whatever a merchant might rename it to in SureCart's own dashboard —
 *   this never depends on that name) with our own icon + short label,
 *   matching the same `sc-payment-toggle-summary` look SureCart itself uses
 *   for Stripe/PayPal rows.
 *
 * SureCart nests `sc-payment-method-choice` inside `sc-toggles`' own open
 * shadow root (confirmed via devtools — it's not a sibling of `sc-payment`
 * in the light DOM), so a plain `document.querySelectorAll()` never finds
 * it: shadow boundaries block both `querySelectorAll` and `MutationObserver`
 * from the outside. `deepQuerySelectorAll()`/`observeAllShadowRoots()` below
 * walk into every open shadow root recursively instead of assuming
 * everything lives in the light DOM.
 *
 * SureCart's checkout is a client-rendered web-component tree that can
 * re-render on cart/customer changes, so both are driven by a
 * MutationObserver rather than a single DOMContentLoaded pass.
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof window.iftpScCheckout ) {
		return;
	}

	var config = window.iftpScCheckout;
	var SUMMARY_MARK = 'data-iftp-sc-summary';
	var PBL_METHODS_MARK = 'data-iftp-sc-pbl-methods';

	var ICON_INLINE_STYLE = 'height:25px;';

	var GATEWAY_ICON_INLINE_STYLE = 'height:20px;';

	function methodKeyForRow( row ) {
		var processorId = row.getAttribute( 'processor-id' );
		return processorId ? config.idMethodMap[ processorId ] : null;
	}

	/**
	 * Recursively collects every open shadow root nested anywhere under `root`.
	 *
	 * @param {Document|ShadowRoot} root
	 * @return {Array<ShadowRoot>}
	 */
	function collectShadowRoots( root ) {
		var found = [];
		var all = root.querySelectorAll( '*' );
		for ( var i = 0; i < all.length; i++ ) {
			var shadow = all[ i ].shadowRoot;
			if ( shadow ) {
				found.push( shadow );
				found = found.concat( collectShadowRoots( shadow ) );
			}
		}
		return found;
	}

	/**
	 * `document.querySelectorAll()`, but also searching inside every open
	 * shadow root nested anywhere in the document — needed because SureCart
	 * renders `sc-payment-method-choice` inside `sc-toggles`' shadow DOM.
	 *
	 * @param {string} selector
	 * @return {Array<Element>}
	 */
	function deepQuerySelectorAll( selector ) {
		var results = Array.prototype.slice.call( document.querySelectorAll( selector ) );
		collectShadowRoots( document ).forEach( function ( shadowRoot ) {
			results = results.concat( Array.prototype.slice.call( shadowRoot.querySelectorAll( selector ) ) );
		} );
		return results;
	}

	function findManualMethodRows() {
		return deepQuerySelectorAll( 'sc-payment-method-choice[is-manual]' );
	}

	function hideRow( row ) {
		row.style.display = 'none';
		row.setAttribute( 'aria-hidden', 'true' );
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = String( value == null ? '' : value );
		return div.innerHTML;
	}

	function applyCustomSummary( row, methodKey ) {
		if ( row.hasAttribute( SUMMARY_MARK ) ) {
			return;
		}

		var summary = row.querySelector( '[slot="summary"]' );
		if ( ! summary ) {
			return;
		}

		var label = ( config.methodLabels && config.methodLabels[ methodKey ] ) || methodKey;

		var hasOwnIcon = Boolean( config.methodIcons && config.methodIcons[ methodKey ] );
		var iconUrl = hasOwnIcon ? config.methodIcons[ methodKey ] : config.logoUrl;
		var iconStyle = hasOwnIcon ? ICON_INLINE_STYLE : GATEWAY_ICON_INLINE_STYLE;


		summary.innerHTML =
			'<span class="iftp-sc-toggle-summary" style="display:flex;align-items:center;gap:0.4em;">' +
			'<img src="' + escapeHtml( iconUrl ) + '" alt="' + escapeHtml( label ) + '" class="iftp-sc-toggle-summary__icon" style="' + iconStyle + '">' +
			'</span>';

		row.setAttribute( SUMMARY_MARK, '1' );
	}

	/**
	 * Appends an "ifthenpay Gateway Payment methods: [icons]" line to the ifthenpay Gateway
	 * row's own description card, listing whichever sub-methods (Card, Apple
	 * Pay, Google Pay, …) the merchant has actually enabled on the Pay by
	 * Link tab (`config.pblEnabledMethods`, built server-side in
	 * `CheckoutAssets::enabled_pbl_methods()`). `<sc-card>` is a light-DOM
	 * child of the row (not nested inside `sc-toggle`'s shadow root — see the
	 * summary/description split confirmed via devtools), so a plain
	 * `row.querySelector()` reaches it without needing `deepQuerySelectorAll()`.
	 *
	 * @param {Element} row
	 */
	function applyPblEnabledMethods( row ) {
		if ( row.hasAttribute( PBL_METHODS_MARK ) ) {
			return;
		}

		var methods = config.pblEnabledMethods || [];
		if ( ! methods.length ) {
			return;
		}

		var card = row.querySelector( 'sc-card' );
		if ( ! card ) {
			return;
		}

		var icons = methods.map( function ( method ) {
			return '<img src="' + escapeHtml( method.icon ) + '" alt="' + escapeHtml( method.label ) + '" title="' + escapeHtml( method.label ) + '" class="iftp-sc-pbl-methods__icon" style="' + ICON_INLINE_STYLE + '">';
		} ).join( '' );

		var label = ( config.i18n && config.i18n.pblEnabledMethodsLabel ) || 'ifthenpay Gateway Payment methods:';

		var wrapper = document.createElement( 'div' );
		wrapper.className = 'iftp-sc-pbl-methods';
		wrapper.innerHTML =
			'<span class="iftp-sc-pbl-methods__label">' + escapeHtml( label ) + '</span>' +
			'<span class="iftp-sc-pbl-methods__icons" style="display:flex;flex-wrap:wrap;align-items:center;gap:5px;">' + icons + '</span>';

		card.appendChild( wrapper );
		row.setAttribute( PBL_METHODS_MARK, '1' );
	}

	function processRows() {
		findManualMethodRows().forEach( function ( row ) {
			var methodKey = methodKeyForRow( row );
			if ( ! methodKey ) {
				return;
			}

			if ( config.hasRecurring ) {
				hideRow( row );
				return;
			}

			applyCustomSummary( row, methodKey );

			if ( 'pbl' === methodKey ) {
				applyPblEnabledMethods( row );
			}
		} );
	}


	var observedRoots = new WeakSet();

	function observeAllShadowRoots( onMutate ) {
		var roots = [ document ].concat( collectShadowRoots( document ) );
		roots.forEach( function ( root ) {
			if ( observedRoots.has( root ) ) {
				return;
			}
			observedRoots.add( root );
			new MutationObserver( onMutate ).observe( root === document ? document.body : root, { childList: true, subtree: true } );
		} );
	}


	var HYDRATION_POLL_INTERVAL = 250;
	var HYDRATION_POLL_ATTEMPTS = 40;

	function start() {
		function handleMutation() {
			processRows();
			observeAllShadowRoots( handleMutation );
		}

		processRows();
		observeAllShadowRoots( handleMutation );

		var attempts = 0;
		var pollId = window.setInterval( function () {
			handleMutation();
			attempts++;
			if ( attempts >= HYDRATION_POLL_ATTEMPTS ) {
				window.clearInterval( pollId );
			}
		}, HYDRATION_POLL_INTERVAL );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
