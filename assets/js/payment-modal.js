/**
 * PayPal-style payment modal for ifthenpay's three checkout methods.
 *
 * A capture-phase click listener on `document` identifies the Purchase
 * button via `composedPath()` (see `isPurchaseClick()`) without ever calling
 * `preventDefault()`, so SureCart's own finalize/confirm flow keeps running
 * unblocked underneath. That click only records intent (`pendingPurchase`);
 * the modal itself doesn't open until the wrapped `window.fetch()` sees
 * SureCart's own `.../checkouts/{id}/finalize` call resolve — the point
 * SureCart creates the real order and assigns its human-readable number
 * (synchronous for a manual gateway like ours, well before SureCart's own
 * `confirm()`/"Thank you" step) — so our ifthenpay reference can be built
 * from that real order number (see `extractOrderNumber()`, `launchPurchase()`).
 * Everything after that point (SureCart's own `confirm()` call and dialog)
 * is still left to run concurrently, same as before.
 *
 * Drives Multibanco/MB WAY/ifthenpay Gateway through the `PaymentModalController`
 * REST endpoints and the existing `/status` polling endpoint, so the
 * customer sees real payment progress instead of a premature "Thank you"
 * while nothing has actually been paid yet.
 *
 * Every terminal state (paid, cancelled, expired, failed) replaces the
 * modal's content with a permanent result panel rather than closing —
 * SureCart's own inline confirmation text stays hidden underneath until the
 * customer dismisses our panel, so they're never shown a "successful"
 * message for a payment that hasn't happened.
 *
 * Multibanco and MB WAY get their own panel modifier class
 * (`iftp-sc-modal-panel--multibanco`/`--mbway`, set by `setActiveMethod()`)
 * and their own body-content wrapper classes, instead of sharing one
 * generic layout — the two methods show genuinely different content
 * (a details list vs. a phone step/approval ring) and styling each
 * independently avoids the two fighting over the same selectors.
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof window.iftpScCheckout ) {
		return;
	}

	var config = window.iftpScCheckout;
	var i18n = config.i18n || {};
	var methodIcons = config.modalHeaderIcons || {};

	var STATUS_INITIAL_INTERVAL = 2000;
	var STATUS_BACKOFF_FACTOR = 1.15;
	var STATUS_MAX_ELAPSED = 20 * 60 * 1000;
	var MBWAY_WINDOW_MS = 4 * 60 * 1000;
	var MBWAY_POLL_INTERVAL = 10 * 1000;

	var MODAL_SPINNER_SIZE = 190;
	var MBWAY_SQUARE_MIN = 300;
	var MBWAY_SQUARE_MAX = 400;

	var GENERAL_MIN_HEIGHT = 278;

	var selectedMethodKey = null;
	var modalOpen = false;

	/* ---------------------------------------------------------------- *
	 * Live checkout id tracking
	 *
	 * SureCart's checkout is a client-rendered SPA: the checkout draft is
	 * created and updated entirely via REST calls made *after* this page
	 * has already loaded, so there is no `sc_checkout_id` in the URL (and
	 * therefore nothing for `wp_localize_script` to bake in) for a fresh
	 * visit — `config.checkoutId` is reliably empty. Instead, watch every
	 * outgoing `fetch()` call for SureCart's own
	 * `.../checkouts/{id}` requests and remember the id we see.
	 * ---------------------------------------------------------------- */

	var liveCheckoutId = '';
	var CHECKOUT_ID_RE = /checkouts(?:%2F|\/)([0-9a-fA-F-]{36})/;

	/**
	 * A Purchase click on an ifthenpay method is a two-step launch, not one:
	 * the click handler below only *records* which method was chosen, and
	 * the fetch wrapper is what actually opens the modal, once it sees
	 * SureCart's own `.../checkouts/{id}/finalize` call resolve. That's the
	 * exact call that creates the real SureCart order and assigns its
	 * human-readable number — for a manual gateway like ours, that happens
	 * synchronously inside `finalize()` itself, well before SureCart's own
	 * `confirm()`/"Thank you" step. Waiting the extra beat for it means our
	 * ifthenpay reference can be built from SureCart's real order number
	 * instead of an unrelated one — see `extractOrderNumber()`. Everything
	 * after that (SureCart's own `confirm()` call, its "Thank you" dialog)
	 * is still left to run concurrently and unblocked, same as before.
	 */
	var pendingPurchase = null;
	var FINALIZE_URL_RE = /checkouts(?:%2F|\/)[0-9a-fA-F-]{36}(?:%2F|\/)finalize/;

	/**
	 * Pulls a human-readable order number out of a `.../finalize` response
	 * body. The exact shape isn't part of any documented contract (this is
	 * SureCart's own remote API response, passed through largely as-is —
	 * see `RestServiceProvider::callback()`), so several plausible field
	 * paths are tried; if none match, the caller falls back to generating
	 * its reference the old way rather than failing the purchase.
	 *
	 * @param {*} data Parsed JSON body of the finalize response.
	 * @return {?string}
	 */
	function extractOrderNumber( data ) {
		if ( ! data || 'object' !== typeof data ) {
			return null;
		}
		var candidates = [
			data.order && data.order.number,
			data.number,
			data.checkout && data.checkout.order && data.checkout.order.number,
		];
		for ( var i = 0; i < candidates.length; i++ ) {
			if ( candidates[ i ] ) {
				return String( candidates[ i ] );
			}
		}
		return null;
	}

	if ( 'function' === typeof window.fetch ) {
		var nativeFetch = window.fetch;
		window.fetch = function ( input ) {
			try {
				var url = 'string' === typeof input ? input : ( input && input.url ) || '';
				var match = CHECKOUT_ID_RE.exec( url );
				if ( match ) {
					liveCheckoutId = match[ 1 ];
				}

				if ( pendingPurchase && FINALIZE_URL_RE.test( url ) ) {
					var purchase = pendingPurchase;

					pendingPurchase = null;

					return nativeFetch.apply( this, arguments ).then( function ( response ) {

						if ( response.ok ) {
							response.clone().json().then( function ( data ) {
								launchPurchase( purchase.methodKey, extractOrderNumber( data ) );
							} ).catch( function () {
								launchPurchase( purchase.methodKey, null );
							} );
						}
						return response;
					} );
				}
			} catch ( e ) {

			}
			return nativeFetch.apply( this, arguments );
		};
	}

	function currentCheckoutId() {
		return liveCheckoutId || config.checkoutId || '';
	}

	/* ---------------------------------------------------------------- *
	 * Selection tracking
	 * ---------------------------------------------------------------- */

	function methodKeyFromChoice( choice ) {
		if ( ! choice ) {
			return null;
		}
		var processorId = choice.getAttribute( 'processor-id' );
		return processorId ? ( config.idMethodMap[ processorId ] || null ) : null;
	}

	/**
	 * `document.querySelectorAll()`, but also searching inside every open
	 * shadow root nested anywhere in the document — SureCart renders
	 * `sc-payment-method-choice` inside `sc-toggles`' shadow DOM, so a plain
	 * light-DOM query never finds it (mirrors `checkout.js`'s own
	 * `deepQuerySelectorAll()`; kept as a separate copy since these two
	 * scripts have no shared module to put it in).
	 *
	 * @param {string} selector
	 * @return {Array<Element>}
	 */
	function deepQuerySelectorAll( selector ) {
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

		var results = Array.prototype.slice.call( document.querySelectorAll( selector ) );
		collectShadowRoots( document ).forEach( function ( shadowRoot ) {
			results = results.concat( Array.prototype.slice.call( shadowRoot.querySelectorAll( selector ) ) );
		} );
		return results;
	}

	document.addEventListener( 'scShow', function ( event ) {
		var path = typeof event.composedPath === 'function' ? event.composedPath() : [];
		for ( var i = 0; i < path.length; i++ ) {
			if ( path[ i ] && path[ i ].tagName === 'SC-PAYMENT-METHOD-CHOICE' ) {
				var key = methodKeyFromChoice( path[ i ] );
				if ( key ) {
					selectedMethodKey = key;
				}
				return;
			}
		}
	} );

	/**
	 * Whether a `sc-payment-method-choice` row is the currently selected one,
	 * read directly from its own rendered state rather than relying on it
	 * having ever fired `scShow`. Covers the case `scShow` tracking misses
	 * entirely: whichever choice SureCart auto-selects by default when the
	 * checkout first loads never dispatches that event — it's already open,
	 * nothing "changed" to show it. `sc-payment-method-choice` wraps every
	 * choice in `sc-toggle` (reflecting the real selection via its `.open`
	 * property) whenever more than one choice exists at all, and only falls
	 * back to a plain, unconditionally-shown `<div>` when it's the sole
	 * payment option on the whole form (see its Stencil source).
	 *
	 * @param {Element} choice
	 * @return {boolean}
	 */
	function isChoiceSelected( choice ) {
		var root = choice.shadowRoot;
		if ( ! root ) {
			return false;
		}
		var toggle = root.querySelector( 'sc-toggle' );
		if ( toggle ) {
			return !! toggle.open;
		}
		return !! root.querySelector( 'div' );
	}

	/**
	 * Resolves the selection from live DOM state whenever `scShow` tracking
	 * hasn't recorded an explicit choice yet — necessary for whichever
	 * option loaded pre-selected, which never fires that event at all.
	 *
	 * @return {?string}
	 */
	function resolveSelectedMethodKey() {
		if ( selectedMethodKey ) {
			return selectedMethodKey;
		}

		var rows = deepQuerySelectorAll( 'sc-payment-method-choice[is-manual]' );
		for ( var i = 0; i < rows.length; i++ ) {
			if ( isChoiceSelected( rows[ i ] ) ) {
				var key = methodKeyFromChoice( rows[ i ] );
				if ( key ) {
					return key;
				}
			}
		}

		return null;
	}

	/* ---------------------------------------------------------------- *
	 * REST helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Joins `config.restUrl` + `path` + a query param safely. On sites
	 * without pretty permalinks, `restUrl` is already itself a query string
	 * (`.../index.php?rest_route=/ifthenpay-surecart/v1`) — naively
	 * appending `?ref=...` after the path produces a *second* `?`, which
	 * isn't a new query delimiter, just a literal character glued onto the
	 * `rest_route` value, so WordPress sees an unrecognized route and 404s.
	 *
	 * @param {string} path
	 * @param {Object} [params]
	 * @return {string}
	 */
	function restUrlFor( path, params ) {
		var url = config.restUrl + path;
		var pairs = [];
		for ( var key in params ) {
			if ( Object.prototype.hasOwnProperty.call( params, key ) ) {
				pairs.push( encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] ) );
			}
		}
		if ( pairs.length ) {
			url += ( -1 === url.indexOf( '?' ) ? '?' : '&' ) + pairs.join( '&' );
		}
		return url;
	}

	function restPost( path, body ) {
		return fetch( restUrlFor( path ), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.restNonce,
			},
			body: JSON.stringify( body ),
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				return { ok: response.ok, data: data };
			} );
		} );
	}

	function restStatus( ref ) {
		return fetch( restUrlFor( '/status', { ref: ref, checkout_id: currentCheckoutId() } ) )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( body ) {
				return ( body && body.state ) || 'UNKNOWN';
			} )
			.catch( function () {
				return 'UNKNOWN';
			} );
	}

	function cancelPending( ref, state ) {
		if ( ! ref ) {
			return Promise.resolve();
		}

		return restPost( '/modal/cancel', { ref: ref, state: state || 'CANCELLED', checkout_id: currentCheckoutId() } );
	}

	/* ---------------------------------------------------------------- *
	 * Suppressing SureCart's own "Thank you" confirmation
	 *
	 * Whether it renders as an `sc-dialog` popup or as an inline swap inside
	 * `sc-checkout` itself (SureCart's own checkout state machine — see
	 * `store-b1758b00.js` — drives both from the same "confirmed" state, and
	 * exactly which one is used isn't worth depending on), out-ranking it
	 * with z-index alone proved unreliable — likely some ancestor along the
	 * way traps it into its own stacking context, which no z-index value on
	 * our side can escape. The reliable fix is to hide the entire checkout
	 * root the moment our own modal takes over: `display:none` on `sc-checkout`
	 * removes its whole subtree from rendering regardless of *how* SureCart
	 * chooses to show its confirmation, and the customer has no reason to
	 * see or interact with the underlying page again once Purchase is
	 * clicked and our modal is driving the flow. `sc-dialog` is hidden too,
	 * as defense in depth in case a dialog ever renders outside that root.
	 * Once hidden, this stays hidden for the rest of the page's life rather
	 * than being restored — there's no case where surfacing SureCart's own
	 * (possibly premature, possibly now-stale) message after the fact would
	 * be more correct than what our own modal already told the customer.
	 * ---------------------------------------------------------------- */

	var dialogSuppressionObserver = null;

	function suppressNativeDialogs() {
		Array.prototype.forEach.call( document.querySelectorAll( 'sc-dialog' ), function ( dialog ) {
			dialog.style.setProperty( 'display', 'none', 'important' );
		} );
		var checkout = document.querySelector( 'sc-checkout' );
		if ( checkout ) {
			checkout.style.setProperty( 'display', 'none', 'important' );
		}
	}

	function startSuppressingDialogs() {
		suppressNativeDialogs();
		if ( dialogSuppressionObserver ) {
			return;
		}
		dialogSuppressionObserver = new MutationObserver( suppressNativeDialogs );
		dialogSuppressionObserver.observe( document.documentElement, { childList: true, subtree: true } );
	}

	function stopSuppressingDialogs() {
		if ( dialogSuppressionObserver ) {
			dialogSuppressionObserver.disconnect();
			dialogSuppressionObserver = null;
		}
	}

	/* ---------------------------------------------------------------- *
	 * Modal chrome
	 * ---------------------------------------------------------------- */

	var overlay, panel, body, closeButton;
	var PANEL_METHOD_CLASSES = [ 'multibanco', 'mbway', 'pbl' ];

	function buildModal() {
		overlay = document.createElement( 'div' );
		overlay.className = 'iftp-sc-modal-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );

		panel = document.createElement( 'div' );
		panel.className = 'iftp-sc-modal-panel';

		closeButton = document.createElement( 'button' );
		closeButton.type = 'button';
		closeButton.className = 'iftp-sc-modal-close';
		closeButton.setAttribute( 'aria-label', i18n.close || 'Close' );
		closeButton.innerHTML = '&times;';

		body = document.createElement( 'div' );
		body.className = 'iftp-sc-modal-body';

		panel.appendChild( closeButton );
		panel.appendChild( body );
		overlay.appendChild( panel );
		document.body.appendChild( overlay );
	}

	/**
	 * Applies the current method's panel modifier class
	 * (`iftp-sc-modal-panel--mbway`/`--multibanco`/`--pbl`) so each method's
	 * CSS lives under its own selector instead of all three sharing one
	 * generic look.
	 *
	 * @param {string} methodKey `multibanco` | `mbway` | `pbl`.
	 */
	function setActiveMethod( methodKey ) {
		PANEL_METHOD_CLASSES.forEach( function ( key ) {
			panel.classList.toggle( 'iftp-sc-modal-panel--' + key, key === methodKey );
		} );
	}

	function showModal() {
		if ( ! overlay ) {
			buildModal();
		}
		overlay.classList.add( 'iftp-sc-modal-overlay--open' );
		modalOpen = true;

		closeButton.onclick = hideModal;
		startSuppressingDialogs();
	}

	function hideModal() {
		stopSuppressingDialogs();
		if ( overlay ) {
			overlay.classList.remove( 'iftp-sc-modal-overlay--open' );
		}
		modalOpen = false;
	}

	/**
	 * Swaps the modal body's content and animates the panel to fit it,
	 * instead of the old fixed-size box that either stranded a lot of empty
	 * space below short content (the loading spinner, the phone form) or
	 * had to scroll for taller content (Multibanco's details list). `compact`
	 * marks the brief "we don't know what we're showing yet" loading state,
	 * which renders as a small square shared by every method; every other
	 * state settles back to the standard panel width.
	 *
	 * `square` is MB WAY's own thing: none of its steps (sending the
	 * request, waiting on the ring) have much content, so instead of the
	 * standard 400px-wide layout — which left a lot of the box empty and
	 * made the header look like it was floating well below the top — each
	 * step gets its own square instead. Pass `true` to measure this step's
	 * own content (clamped between `MBWAY_SQUARE_MIN`/`MAX`) — used for the
	 * phone form and the waiting ring. Pass a number to skip measuring and
	 * use that fixed size instead — used for the brief "sending your
	 * request" spinner, whose content (a spinner + one short line) is
	 * trivial enough that measuring it could clamp up to the exact same
	 * size as the phone form, making the two look identical instead of the
	 * spinner reading as visibly tinier (see `MODAL_SPINNER_SIZE`). Either
	 * way, both width and height animate between one square size and the
	 * next. Non-square states always clear any square width back to the
	 * standard/compact one, so leaving MB WAY for a shared state (paid, an
	 * error) doesn't strand the panel at whatever narrow width its last
	 * square happened to be.
	 *
	 * Both starting dimensions are pinned to their current on-screen values
	 * first, then a double `requestAnimationFrame` gives the browser a
	 * chance to commit that as the transition's "from" value before the
	 * target is applied — skipping that would just snap straight to the new
	 * size instead of animating, since there'd be no committed frame to
	 * transition from.
	 *
	 * Measuring is done at the width *and* height this content will actually
	 * end up at, not whatever the panel is still pinned to from the
	 * *previous* state:
	 * - A narrower prior width (the 280px compact square, or MB WAY's own
	 *   square) wraps the new text into extra lines it won't really need at
	 *   its real width.
	 * - A *taller* prior height is just as wrong the other way: `scrollHeight`
	 *   can never report less than the panel's own currently-set height
	 *   (nothing to reveal by "overflowing" a box that's already tall enough),
	 *   so shorter content measured while still pinned to a taller previous
	 *   state's height gets floored at that taller number instead of its own
	 *   true, smaller size.
	 * Both are why, e.g., a terminal state reached from MB WAY's approval
	 * ring (a tall square) or Multibanco's details list came out taller than
	 * the same terminal state reached from Pay by Link's shorter waiting
	 * step, despite showing identical markup either way.
	 *
	 * `result` is for the three "outcome" panels (paid / terminal / error):
	 * unlike everything else here, these are a fixed size regardless of
	 * method or exactly which message is showing — Multibanco's own expiry
	 * text ("This reference has expired.") is one short line, MB WAY's is a
	 * full explanation running to three, and no natural-height measurement
	 * of either one reads as "the same result screen" the way a truly fixed
	 * size does. Handled entirely in CSS (`.iftp-sc-modal-panel--result`,
	 * `!important`) rather than computed here, so nothing — including a
	 * future change to this function — can accidentally reintroduce a
	 * measured size for these three.
	 *
	 * @param {string}         html
	 * @param {boolean}        [compact]
	 * @param {boolean|number} [square]
	 * @param {boolean}        [result]
	 */
	function setBody( html, compact, square, result ) {
		var startHeight = panel.getBoundingClientRect().height;
		var startWidth = panel.getBoundingClientRect().width;
		panel.style.height = startHeight + 'px';
		panel.style.width = startWidth + 'px';

		panel.classList.toggle( 'iftp-sc-modal-panel--compact', !! compact && 'number' !== typeof square );
		panel.classList.toggle( 'iftp-sc-modal-panel--result', !! result );
		body.innerHTML = html;

		var targetHeight, targetWidth;
		if ( result ) {

			targetHeight = 278;
			targetWidth = 400;
		} else if ( 'number' === typeof square ) {
			targetHeight = square;
			targetWidth = square;
		} else {

			var measureWidth = compact ? 280 : 400;
			panel.style.width = measureWidth + 'px';
			panel.style.height = 'auto';
			var naturalHeight = panel.getBoundingClientRect().height;
			panel.style.width = startWidth + 'px';
			panel.style.height = startHeight + 'px';

			if ( square ) {
				targetHeight = Math.min( MBWAY_SQUARE_MAX, Math.max( MBWAY_SQUARE_MIN, naturalHeight ) );
				targetWidth = targetHeight;
			} else if ( compact ) {

				targetHeight = naturalHeight;
				targetWidth = 280;
			} else {
				targetHeight = Math.max( GENERAL_MIN_HEIGHT, naturalHeight );
				targetWidth = null;
			}
		}

		window.requestAnimationFrame( function () {
			window.requestAnimationFrame( function () {
				panel.style.height = targetHeight + 'px';
				panel.style.width = targetWidth ? targetWidth + 'px' : '';
			} );
		} );
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = String( value == null ? '' : value );
		return div.innerHTML;
	}

	/**
	 * A compact "{method logo} | #0054" header for the top of the
	 * Multibanco/MB WAY panels, once SureCart's real order number is known
	 * (see `extractOrderNumber()`) — the method's own logo takes the place
	 * of a text abbreviation ("MB"/"MBWAY"), read at a glance the same way
	 * the payment-method choice list already does, separated from the order
	 * number by a plain vertical rule rather than the literal text `|`.
	 * Renders nothing if the order number couldn't be resolved, so a
	 * payment is never blocked on it.
	 *
	 * @param {string}  methodKey   `multibanco` | `mbway`.
	 * @param {?string} orderNumber SureCart's order number, if resolved.
	 * @return {string}
	 */
	function paymentHeaderHtml( methodKey, orderNumber ) {
		if ( ! orderNumber ) {
			return '';
		}
		var iconUrl = methodIcons[ methodKey ] || '';
		var icon = iconUrl ? '<img src="' + escapeHtml( iconUrl ) + '" alt="" class="iftp-sc-modal-header__icon" />' : '';
		return (
			'<div class="iftp-sc-modal-header">' +
				icon +
				'<span class="iftp-sc-modal-header__separator" aria-hidden="true"></span>' +
				'<span class="iftp-sc-modal-header__order">#' + escapeHtml( orderNumber ) + '</span>' +
			'</div>'
		);
	}

	/* ---------------------------------------------------------------- *
	 * Shared result / countdown rendering
	 * ---------------------------------------------------------------- */

	var activeTimers = [];

	function clearActiveTimers() {
		activeTimers.forEach( function ( id ) {
			window.clearTimeout( id );
			window.clearInterval( id );
		} );
		activeTimers = [];
	}

	function track( id ) {
		activeTimers.push( id );
		return id;
	}

	/**
	 * Builds the same icon-in-a-circle SureCart itself uses on its own
	 * order-confirmation dialog (`.confirm__icon` / `.confirm__icon-container`
	 * — a 55px circle, `sc-icon` centered inside, found in their own compiled
	 * component source) so ours reads as the same visual language, just
	 * reusing our own class names and `sc-icon`, which is already loaded on
	 * the checkout page regardless of what we do.
	 *
	 * @param {string} iconName
	 * @param {string} modifier 'success' | 'danger' — which color circle.
	 * @return {string}
	 */
	function statusIconHtml( iconName, modifier ) {
		return (
			'<div class="iftp-sc-modal-confirmation__icon">' +
				'<div class="iftp-sc-modal-confirmation__icon-circle iftp-sc-modal-confirmation__icon-circle--' + modifier + '">' +
					'<sc-icon name="' + iconName + '"></sc-icon>' +
				'</div>' +
			'</div>'
		);
	}

	/**
	 * Deliberately mirrors SureCart's own native order-confirmation dialog
	 * exactly (check icon + title + description + "Continue"), since the
	 * whole point here is that a paid order should look indistinguishable
	 * from SureCart's real "Thank you!" regardless of which ifthenpay method
	 * was used. There is no merchant-configurable override for that copy
	 * anywhere in SureCart's own settings (checked — it's fixed copy in
	 * their own checkout state machine), so this mirrors their literal
	 * default text too.
	 */
	function renderPaid() {
		clearActiveTimers();
		setBody(
			statusIconHtml( 'check', 'success' ) +
			'<div class="iftp-sc-modal-confirmation">' +
				'<h2 class="iftp-sc-modal-confirmation__title">' + escapeHtml( i18n.paidTitle || 'Thank you!' ) + '</h2>' +
				'<p class="iftp-sc-modal-confirmation__message">' + escapeHtml( i18n.paidMessage || 'Your payment was successful. A receipt is on its way to your inbox.' ) + '</p>' +
			'</div>' +
			'<button type="button" class="iftp-sc-modal-continue">' + escapeHtml( i18n.continueLabel || 'Continue' ) + '</button>',
			false,
			false,
			true
		);
		wireContinueButton();
	}

	function renderTerminal( message, title ) {
		clearActiveTimers();
		setBody(
			statusIconHtml( 'x', 'danger' ) +
			'<div class="iftp-sc-modal-confirmation">' +
				'<h2 class="iftp-sc-modal-confirmation__title">' + escapeHtml( title || i18n.notCompleted || 'Payment not completed' ) + '</h2>' +
				'<p class="iftp-sc-modal-confirmation__message">' + escapeHtml( message || i18n.cancelledNote || '' ) + '</p>' +
			'</div>' +
			continueButtonHtml(),
			false,
			false,
			true
		);
		wireContinueButton();
	}

	/**
	 * Picks the right description for a polled terminal state — FAILED and
	 * EXPIRED get their own wording rather than sharing the cancelled-by-the-
	 * customer message, which was misleading for those two.
	 *
	 * @param {string} state
	 * @return {string}
	 */
	function messageForState( state ) {
		if ( 'FAILED' === state ) {
			return i18n.paymentFailed || i18n.cancelledNote;
		}
		if ( 'EXPIRED' === state ) {
			return i18n.paymentExpiredGeneric || i18n.cancelledNote;
		}
		return i18n.cancelledNote;
	}

	function continueButtonHtml() {
		return '<button type="button" class="iftp-sc-modal-continue">' + escapeHtml( i18n.close || 'Close' ) + '</button>';
	}

	function wireContinueButton() {
		var btn = body.querySelector( '.iftp-sc-modal-continue' );
		if ( btn ) {
			btn.addEventListener( 'click', hideModal );
		}
	}

	function renderError( message ) {
		clearActiveTimers();
		setBody(
			statusIconHtml( 'alert-triangle', 'danger' ) +
			'<div class="iftp-sc-modal-confirmation">' +
				'<p class="iftp-sc-modal-confirmation__message">' + escapeHtml( message || i18n.genericError || '' ) + '</p>' +
			'</div>' +
			continueButtonHtml(),
			false,
			false,
			true
		);
		wireContinueButton();
	}

	function formatCountdown( ms ) {
		var totalSeconds = Math.max( 0, Math.floor( ms / 1000 ) );
		var hours = Math.floor( totalSeconds / 3600 );
		var minutes = Math.floor( ( totalSeconds % 3600 ) / 60 );
		var seconds = totalSeconds % 60;
		var pad = function ( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		};
		return hours > 0 ? ( hours + ':' + pad( minutes ) + ':' + pad( seconds ) ) : ( pad( minutes ) + ':' + pad( seconds ) );
	}


	var EXPIRY_CHECK_INTERVAL = 30 * 1000;

	/**
	 * Silently watches for `targetMs` (epoch milliseconds) passing and calls
	 * `onExpire` once — unlike `startRingCountdown()`, nothing is rendered
	 * here; Multibanco shows a fixed expiry date/time instead of a ticking
	 * clock (see `renderMultibancoDetails()`), so this only needs to trigger
	 * the same "reference expired" transition at the right moment.
	 *
	 * @param {number}   targetMs
	 * @param {Function} onExpire
	 */
	function watchExpiry( targetMs, onExpire ) {
		var expired = false;
		var tick = function () {
			if ( targetMs - Date.now() <= 0 && ! expired ) {
				expired = true;
				onExpire();
			}
		};
		tick();
		track( window.setInterval( tick, EXPIRY_CHECK_INTERVAL ) );
	}

	/**
	 * Like `startCountdown()`, but also drives a circular progress ring
	 * (`.iftp-sc-modal-ring`) that fills clockwise from empty to full as time
	 * elapses — mirrors the MB WAY app's own approval-countdown treatment
	 * (a ring around the timer, rather than a bare digital clock), used only
	 * for MB WAY's waiting-for-approval step.
	 *
	 * @param {number}   targetMs Epoch ms this countdown reaches zero at.
	 * @param {number}   totalMs  The countdown's full duration, for the ring's percentage.
	 * @param {Function} onExpire
	 */
	function startRingCountdown( targetMs, totalMs, onExpire ) {
		var expired = false;
		var tick = function () {
			var remaining = targetMs - Date.now();
			var valueEl = body.querySelector( '.iftp-sc-modal-countdown' );
			var ring = body.querySelector( '.iftp-sc-modal-ring' );
			if ( valueEl ) {
				valueEl.textContent = formatCountdown( remaining );
			}
			if ( ring ) {
				var elapsedPct = Math.min( 100, Math.max( 0, ( ( totalMs - remaining ) / totalMs ) * 100 ) );
				ring.style.setProperty( '--iftp-ring-progress', elapsedPct.toFixed( 2 ) );
			}
			if ( remaining <= 0 && ! expired ) {
				expired = true;
				onExpire();
			}
		};
		tick();
		track( window.setInterval( tick, 1000 ) );
	}

	/**
	 * Polls `/status` until PAID/FAILED/CANCELLED/EXPIRED or the ceiling is
	 * reached. Never treats "still pending" as an error — only reflects
	 * whatever the webhook already wrote (mirrors `polling.js`'s back-off).
	 *
	 * Immediately calls `onTerminal` on a resolved state, so the customer
	 * never has to manually close anything once payment is actually
	 * confirmed — the modal itself flips to the paid/failed panel.
	 *
	 * @param {string}   ref
	 * @param {Function} onTerminal   Called with the resolved state.
	 * @param {number}   [fixedInterval] If given, poll at exactly this cadence
	 *   instead of the default back-off (used for MB WAY's short, predictable
	 *   waiting window).
	 */
	function pollStatus( ref, onTerminal, fixedInterval ) {
		var interval = fixedInterval || STATUS_INITIAL_INTERVAL;
		var elapsed = 0;
		var stopped = false;

		function poll() {
			if ( stopped ) {
				return;
			}
			restStatus( ref ).then( function ( state ) {
				if ( stopped ) {
					return;
				}
				if ( 'PAID' === state || 'FAILED' === state || 'CANCELLED' === state || 'EXPIRED' === state ) {
					onTerminal( state );
					return;
				}
				elapsed += interval;
				if ( elapsed >= STATUS_MAX_ELAPSED ) {
					return;
				}
				if ( ! fixedInterval ) {
					interval = interval * STATUS_BACKOFF_FACTOR;
				}
				track( window.setTimeout( poll, interval ) );
			} );
		}

		track( window.setTimeout( poll, interval ) );

		return function stop() {
			stopped = true;
		};
	}

	/* ---------------------------------------------------------------- *
	 * Per-method flows
	 * ---------------------------------------------------------------- */

	function renderLoading( message, square ) {
		setBody( '<div class="iftp-sc-modal-loading"><sc-spinner></sc-spinner><p>' + escapeHtml( message || '' ) + '</p></div>', true, square );
	}

	function startMultibanco( orderNumber ) {

		renderLoading( i18n.processing || '', MODAL_SPINNER_SIZE );

		restPost( '/modal/start', { checkout_id: currentCheckoutId(), method: 'multibanco', order_number: orderNumber || '' } ).then( function ( result ) {
			if ( ! result.ok || ! result.data || ! result.data.ok ) {
				renderError( result.data && result.data.message );
				return;
			}

			var data = result.data;
			renderMultibancoDetails( data, orderNumber );

			var stopPolling = pollStatus( data.ref, function ( state ) {
				stopPolling();
				'PAID' === state ? renderPaid() : renderTerminal( messageForState( state ) );
			} );

			if ( data.expires_at ) {
				var targetMs = Date.parse( data.expires_at.replace( ' ', 'T' ) + 'Z' );
				watchExpiry( targetMs, function () {
					stopPolling();
					cancelPending( data.ref, 'EXPIRED' );
					renderTerminal( i18n.multibancoExpired );
				} );
			}


		} ).catch( function () {
			renderError( i18n.genericError );
		} );
	}

	/**
	 * Multibanco shows more than any other method (entity, reference,
	 * amount, the reference's expiry date/time, plus an explainer card), so
	 * this is the state that grows the most from the compact loading square — the panel
	 * animation in `setBody()` handles that growth smoothly rather than
	 * snapping straight to the taller layout. No longer repeats the "pay at
	 * an ATM/home banking app" copy the customer already saw on the payment
	 * method choice itself, one step before this — instead it explains, in
	 * plain terms, why it's safe to leave.
	 *
	 * @param {Object}  data        `/modal/start` response.
	 * @param {?string} orderNumber SureCart's order number, if resolved.
	 */
	function renderMultibancoDetails( data, orderNumber ) {
		setBody(
			paymentHeaderHtml( 'multibanco', orderNumber ) +
			'<div class="iftp-sc-modal-multibanco">' +
				'<h3 class="iftp-sc-modal-section-title">' + escapeHtml( i18n.multibancoDetailsTitle || '' ) + '</h3>' +
				'<dl class="iftp-sc-modal-list">' +
					'<div><dt>' + escapeHtml( i18n.entity || 'Entity' ) + '</dt><dd>' + escapeHtml( data.entity ) + '</dd></div>' +
					'<div><dt>' + escapeHtml( i18n.reference || 'Reference' ) + '</dt><dd>' + escapeHtml( data.reference ) + '</dd></div>' +
					'<div><dt>' + escapeHtml( i18n.amount || 'Amount' ) + '</dt><dd>' + escapeHtml( data.amount ) + ' &euro;</dd></div>' +
					( data.expires_at_label ? '<div><dt>' + escapeHtml( i18n.expires || 'Expires' ) + '</dt><dd>' + escapeHtml( data.expires_at_label ) + '</dd></div>' : '' ) +
				'</dl>' +
				'<div class="iftp-sc-modal-safe-close">' +
					'<sc-icon name="check-circle" class="iftp-sc-modal-safe-close__icon"></sc-icon>' +
					'<div>' +
						'<p class="iftp-sc-modal-safe-close__title">' + escapeHtml( i18n.multibancoSafeCloseTitle || '' ) + '</p>' +
						'<p class="iftp-sc-modal-safe-close__message">' + escapeHtml( i18n.multibancoSafeClose || '' ) + '</p>' +
					'</div>' +
				'</div>' +
			'</div>'
		);
	}

	function startMbWay( orderNumber ) {
		renderPhoneForm( undefined, orderNumber );
	}

	function renderPhoneForm( errorMessage, orderNumber ) {
		setBody(
			paymentHeaderHtml( 'mbway', orderNumber ) +
			'<div class="iftp-sc-modal-mbway-phone">' +
				'<sc-input type="tel" inputmode="tel" class="iftp-sc-modal-phone-input" ' +
					'label="' + escapeHtml( i18n.phoneLabel || 'MB WAY phone number' ) + '" show-label ' +
					'help="' + escapeHtml( i18n.phoneHelp || '' ) + '" placeholder="912 345 678" autocomplete="tel"></sc-input>' +
				( errorMessage ? '<sc-alert type="danger" open>' + escapeHtml( errorMessage ) + '</sc-alert>' : '' ) +
				'<button type="button" class="iftp-sc-modal-send">' + escapeHtml( i18n.send || 'Send' ) + '</button>' +
			'</div>',
			false,
			true
		);

		var sendBtn = body.querySelector( '.iftp-sc-modal-send' );
		var input = body.querySelector( '.iftp-sc-modal-phone-input' );

		sendBtn.addEventListener( 'click', function () {
			var phone = ( input && input.value ) || '';
			var digits = phone.replace( /\D+/g, '' );

			if ( ! /^9[1236]\d{7}$/.test( digits ) ) {
				renderPhoneForm( i18n.phoneInvalid, orderNumber );
				return;
			}

			renderLoading( i18n.processing || '', MODAL_SPINNER_SIZE );

			restPost( '/modal/start', { checkout_id: currentCheckoutId(), method: 'mbway', phone: phone, order_number: orderNumber || '' } ).then( function ( result ) {
				if ( ! result.ok || ! result.data || ! result.data.ok ) {
					renderPhoneForm( ( result.data && result.data.message ) || i18n.genericError, orderNumber );
					return;
				}

				var data = result.data;
				renderMbWayWaiting( orderNumber );

				var stopPolling = pollStatus( data.ref, function ( state ) {
					stopPolling();
					'PAID' === state ? renderPaid() : renderTerminal( messageForState( state ) );
				}, MBWAY_POLL_INTERVAL );

				startRingCountdown( Date.now() + MBWAY_WINDOW_MS, MBWAY_WINDOW_MS, function () {
					stopPolling();
					cancelPending( data.ref, 'EXPIRED' );
					renderTerminal( i18n.mbwayExpired );
				} );

				wireCloseAsCancel( data.ref, function () {
					renderMbWayWaiting( orderNumber );
				} );
			} ).catch( function () {
				renderPhoneForm( i18n.genericError, orderNumber );
			} );
		} );
	}

	/**
	 * The "waiting for the customer to approve on their phone" step — a
	 * circular countdown ring (see `startRingCountdown()`) takes the place
	 * of a bare spinner, mirroring the MB WAY app's own approval-countdown
	 * treatment: a gray ring that fills clockwise in orange as the window
	 * ticks down, with the remaining time centered inside it.
	 *
	 * @param {?string} orderNumber SureCart's order number, if resolved.
	 */
	function renderMbWayWaiting( orderNumber ) {
		setBody(
			paymentHeaderHtml( 'mbway', orderNumber ) +
			'<div class="iftp-sc-modal-mbway-waiting">' +
				'<div class="iftp-sc-modal-ring" style="--iftp-ring-progress:0">' +
					'<span class="iftp-sc-modal-ring__value iftp-sc-modal-countdown"></span>' +
				'</div>' +
				'<p class="iftp-sc-modal-mbway-waiting__title">' + escapeHtml( i18n.mbwayWaitingTitle || '' ) + '</p>' +
				'<p class="iftp-sc-modal-mbway-waiting__message">' + escapeHtml( i18n.mbwayWaiting || '' ) + '</p>' +
			'</div>',
			false,
			true
		);
	}

	function openPopup( url ) {
		var popup = null;
		try {
			popup = window.open( url, '_blank' );
			if ( popup ) {
				popup.opener = null;
			}
		} catch ( e ) {
			popup = null;
		}
		return popup;
	}

	/**
	 * Watches the ifthenpay Gateway popup for either closing on its own, or
	 * navigating back to our own `success_url`/`error_url`/`cancel_url`
	 * (same-origin once it does, so `popup.location.href` stops throwing —
	 * cross-origin reads on ifthenpay's hosted page always throw until then,
	 * which is how "still there" is detected). Reacting immediately here —
	 * instead of only ever finding out on the next scheduled `/status` poll
	 * tick — is what makes closing the popup actually feel like it did
	 * something, rather than leaving the spinner running until the next
	 * poll happens to land.
	 *
	 * @param {Window}   popup
	 * @param {Function} onOutcome Called with 'success' | 'cancel' | 'error' | 'closed'.
	 * @return {Function} Stops watching.
	 */
	function watchPopupOutcome( popup, onOutcome ) {
		if ( ! popup ) {
			return function () {};
		}

		var stopped = false;
		var intervalId = window.setInterval( function () {
			if ( stopped ) {
				return;
			}
			if ( popup.closed ) {
				window.clearInterval( intervalId );
				onOutcome( 'closed' );
				return;
			}
			var href;
			try {
				href = popup.location.href;
			} catch ( e ) {
				return;
			}
			var match = /[?&]iftp_sc_pbl=([a-z]+)/.exec( href );
			if ( match ) {
				window.clearInterval( intervalId );
				onOutcome( match[ 1 ] );
			}
		}, 700 );
		track( intervalId );

		return function () {
			stopped = true;
			window.clearInterval( intervalId );
		};
	}

	function startPbl( orderNumber ) {

		var popup = openPopup( '' );

		renderLoading( i18n.processing || '' );

		restPost( '/modal/start', { checkout_id: currentCheckoutId(), method: 'pbl', order_number: orderNumber || '' } ).then( function ( result ) {
			if ( ! result.ok || ! result.data || ! result.data.ok || ! result.data.redirect_url ) {
				if ( popup && ! popup.closed ) {
					popup.close();
				}
				renderError( result.data && result.data.message );
				return;
			}

			var data = result.data;
			var openedAutomatically = false;

			if ( popup && ! popup.closed ) {
				try {
					popup.location.href = data.redirect_url;
					openedAutomatically = true;
				} catch ( e ) {
					openedAutomatically = false;
				}
			}

			renderPblWaiting( data.redirect_url );


			if ( ! openedAutomatically ) {
				popup = openPopup( data.redirect_url );
			}

			var stopPolling = pollStatus( data.ref, function ( state ) {
				stopPolling();
				stopWatchingPopup();
				'PAID' === state ? renderPaid() : renderTerminal( messageForState( state ) );
			} );


			var stopWatchingPopup = watchPopupOutcome( popup, function ( outcome ) {
				if ( popup && ! popup.closed ) {
					popup.close();
				}

				if ( 'success' === outcome ) {

					restStatus( data.ref ).then( function ( state ) {
						if ( 'PAID' === state ) {
							stopPolling();
							stopWatchingPopup();
							renderPaid();
						}
					} );
					return;
				}

				if ( 'cancel' === outcome || 'error' === outcome ) {
					stopPolling();
					stopWatchingPopup();
					var state = 'cancel' === outcome ? 'CANCELLED' : 'FAILED';
					cancelPending( data.ref, state );
					renderTerminal( messageForState( state ) );
					return;
				}


				restStatus( data.ref ).then( function ( state ) {
					if ( 'PAID' === state || 'FAILED' === state || 'CANCELLED' === state || 'EXPIRED' === state ) {
						stopPolling();
						stopWatchingPopup();
						'PAID' === state ? renderPaid() : renderTerminal( messageForState( state ) );
					}
				} );
			} );

			wireCloseAsCancel( data.ref, function () {
				renderPblWaiting( data.redirect_url );
			} );
		} ).catch( function () {
			if ( popup && ! popup.closed ) {
				popup.close();
			}
			renderError( i18n.genericError );
		} );
	}

	function renderPblWaiting( redirectUrl ) {
		setBody(
			'<div class="iftp-sc-modal-loading"><sc-spinner></sc-spinner><p>' + escapeHtml( i18n.pblWaiting || '' ) + '</p></div>' +
			'<button type="button" class="iftp-sc-modal-send iftp-sc-modal-pbl-button">' + escapeHtml( i18n.pblOpenLink || 'Open payment page' ) + '</button>'
		);

		body.querySelector( '.iftp-sc-modal-pbl-button' ).addEventListener( 'click', function () {
			openPopup( redirectUrl );
		} );
	}

	/**
	 * Shows an "are you sure?" step before actually cancelling a payment
	 * that's already in flight (MB WAY push sent, ifthenpay Gateway session
	 * opened) — closing by accident there would leave the customer
	 * wondering why their approval/payment no longer seems to be tracked.
	 * "No" re-renders whatever was showing via `restoreFn` rather than
	 * touching any in-flight polling/countdown, which keep running
	 * regardless throughout.
	 *
	 * @param {string}   ref
	 * @param {Function} restoreFn Re-renders the state this replaced.
	 */
	function confirmCancel( ref, restoreFn ) {
		setBody(
			'<sc-alert type="warning" open>' + escapeHtml( i18n.cancelConfirm || 'Are you sure you want to cancel the payment?' ) + '</sc-alert>' +
			'<div class="iftp-sc-modal-confirm-row">' +
				'<button type="button" class="iftp-sc-modal-continue iftp-sc-modal-confirm-no">' + escapeHtml( i18n.no || 'No' ) + '</button>' +
				'<button type="button" class="iftp-sc-modal-send iftp-sc-modal-confirm-yes">' + escapeHtml( i18n.yes || 'Yes, cancel' ) + '</button>' +
			'</div>'
		);

		body.querySelector( '.iftp-sc-modal-confirm-no' ).addEventListener( 'click', restoreFn );
		body.querySelector( '.iftp-sc-modal-confirm-yes' ).addEventListener( 'click', function () {
			cancelPending( ref, 'CANCELLED' );
			renderTerminal( i18n.cancelledNote );
		} );
	}

	/**
	 * Wires the × button to confirm before cancelling an in-flight MB WAY/
	 * ifthenpay Gateway payment. Multibanco never calls this at all — see
	 * `startMultibanco()` — since closing there doesn't cancel anything.
	 *
	 * @param {string}   ref
	 * @param {Function} restoreFn Passed straight through to `confirmCancel()`.
	 */
	function wireCloseAsCancel( ref, restoreFn ) {
		closeButton.onclick = function () {
			confirmCancel( ref, restoreFn );
		};
	}

	/* ---------------------------------------------------------------- *
	 * Trigger: click on the Purchase button, capture phase, non-blocking
	 *
	 * A native `submit` event turned out not to be reliable here (SureCart's
	 * own `sc-button[submit]` documents that it injects and clicks a native
	 * submit button to trigger one, but in practice no `submit` event was
	 * ever observed reaching `document` even with a guaranteed-first
	 * capture-phase listener). Listening for the click itself, and
	 * identifying it via `composedPath()` the same way selection tracking
	 * already does for `scShow`, is more robust regardless of why.
	 * ---------------------------------------------------------------- */

	function isPurchaseClick( event ) {
		var path = typeof event.composedPath === 'function' ? event.composedPath() : [];
		for ( var i = 0; i < path.length; i++ ) {
			var el = path[ i ];
			if ( ! el || ! el.tagName ) {
				continue;
			}
			if ( 'SC-ORDER-SUBMIT' === el.tagName ) {
				return true;
			}
			if ( 'SC-BUTTON' === el.tagName && el.hasAttribute( 'submit' ) ) {
				return true;
			}
			if ( 'BUTTON' === el.tagName && 'submit' === el.getAttribute( 'type' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Actually opens the modal and kicks off the chosen method's flow, once
	 * the fetch wrapper above has seen SureCart's own finalize response
	 * (see `pendingPurchase`). `orderNumber` is `null` if it couldn't be
	 * read from that response — the per-method `start*()` functions and
	 * the backend both fall back to the old checkout-id-based reference in
	 * that case, so a payment is never blocked on it.
	 *
	 * @param {string}  methodKey   `multibanco` | `mbway` | `pbl`.
	 * @param {?string} orderNumber SureCart's real order number, if resolved.
	 */
	function launchPurchase( methodKey, orderNumber ) {
		showModal();
		setActiveMethod( methodKey );

		if ( 'multibanco' === methodKey ) {
			startMultibanco( orderNumber );
		} else if ( 'mbway' === methodKey ) {
			startMbWay( orderNumber );
		} else if ( 'pbl' === methodKey ) {
			startPbl( orderNumber );
		}
	}

	document.addEventListener(
		'click',
		function ( event ) {
			if ( modalOpen || config.hasRecurring || ! isPurchaseClick( event ) ) {
				return;
			}

			var methodKey = resolveSelectedMethodKey();
			if ( ! methodKey ) {
				return;
			}


			pendingPurchase = { methodKey: methodKey };
		},
		true
	);
} )();
