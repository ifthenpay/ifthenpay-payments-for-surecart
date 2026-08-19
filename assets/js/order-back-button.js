/**
 * On SureCart's order edit screen, when reached from ifthenpay's own Entries
 * tab, repoints the screen's own links back to the orders list — a Stencil
 * `<sc-button href="admin.php?page=sc-orders">` (the circular arrow-left
 * button) and an `<sc-breadcrumb href="admin.php?page=sc-orders">` ("Orders"
 * crumb), both of which render a real `<a>` internally — at that tab instead
 * of SureCart's order list.
 *
 * @package Ifthenpay\SureCart
 */

( function () {
	'use strict';

	if ( 'undefined' === typeof iftpScOrderBack || ! iftpScOrderBack.entriesUrl ) {
		return;
	}

	var BACK_HREF = 'admin.php?page=sc-orders';
	var TARGET_HREF = iftpScOrderBack.entriesUrl;
	var SELECTOR = 'sc-button[href="' + BACK_HREF + '"], sc-breadcrumb[href="' + BACK_HREF + '"]';

	function patch() {
		document.querySelectorAll( SELECTOR ).forEach( function ( el ) {
			el.setAttribute( 'href', TARGET_HREF );
		} );
	}

	patch();


	new MutationObserver( patch ).observe( document.body, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [ 'href' ],
	} );
} )();
