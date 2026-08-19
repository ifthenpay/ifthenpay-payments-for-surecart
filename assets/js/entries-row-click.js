/**
 * Makes each clickable Entries tab row (`.iftp-sc-methods-table__row--clickable`,
 * see `render_entries_tab()`) navigate to its SureCart order edit screen on
 * click, not just the Payment ID link inside it.
 *
 * @package Ifthenpay\SureCart
 */

( function () {
	'use strict';

	var ROW_SELECTOR = '.iftp-sc-methods-table__row--clickable';

	document.addEventListener( 'click', function ( event ) {
		var row = event.target.closest( ROW_SELECTOR );

		if ( ! row || event.target.closest( 'a' ) ) {
			return;
		}

		var href = row.getAttribute( 'data-href' );

		if ( href ) {
			window.location.assign( href );
		}
	} );
} )();
