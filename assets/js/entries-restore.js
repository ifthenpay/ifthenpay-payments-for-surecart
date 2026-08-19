/**
 * Restores the Entries tab's `state`/`method` filters from localStorage into
 * the URL before the page body renders. Loaded in <head> (no defer/footer,
 * see `SettingsPage::maybe_enqueue_assets()`) so the redirect, if needed,
 * happens before the user sees the (wrongly-filtered) table. `search` is
 * deliberately not persisted here — see `SettingsPage::entries_search()`.
 *
 * @package Ifthenpay\SureCart
 */

( function () {
	'use strict';

	var FIELDS = [
		{ param: 'state', key: 'iftp_sc_entries_state' },
		{ param: 'method', key: 'iftp_sc_entries_method' },
	];

	try {
		var url = new URL( window.location.href );
		var changed = false;

		FIELDS.forEach( function ( field ) {
			if ( ! url.searchParams.has( field.param ) ) {
				var saved = localStorage.getItem( field.key );
				if ( saved ) {
					url.searchParams.set( field.param, saved );
					changed = true;
				}
				return;
			}

			var current = url.searchParams.get( field.param );
			if ( current ) {
				localStorage.setItem( field.key, current );
			} else {
				localStorage.removeItem( field.key );
			}
		} );

		if ( changed ) {
			url.searchParams.delete( 'paged' );
			window.location.replace( url.toString() );
		}
	} catch ( e ) {}


	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest( '.iftp-sc-entries-clear' ) ) {
			return;
		}
		try {
			FIELDS.forEach( function ( field ) {
				localStorage.removeItem( field.key );
			} );
		} catch ( e ) {}
	} );
}() );
