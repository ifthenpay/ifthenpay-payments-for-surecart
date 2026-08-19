<?php
/**
 * Uninstall routine — removes plugin options and the pending-payments table.
 *
 * @package Ifthenpay\SureCart
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$iftp_sc_options = array(
	'iftp_sc_activated',
	'iftp_sc_backoffice_key',
	'iftp_sc_gateway_key',
	'iftp_sc_mb_key',
	'iftp_sc_mbway_key',
	'iftp_sc_pbl_methods',
	'iftp_sc_pbl_default_method',
	'iftp_sc_pbl_description',
	'iftp_sc_pbl_expire_days',
	'iftp_sc_mb_expire_days',
	'iftp_sc_secret_key',
	'iftp_sc_manual_methods',
	'iftp_sc_provisioning_deferred',
);

foreach ( $iftp_sc_options as $iftp_sc_option ) {
	delete_option( $iftp_sc_option );
}

$iftp_sc_timestamp = wp_next_scheduled( 'iftp_sc_expire_pending_pbl' );
if ( false !== $iftp_sc_timestamp ) {
	wp_unschedule_event( $iftp_sc_timestamp, 'iftp_sc_expire_pending_pbl' );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall-time table drop of a plugin-owned table; no caching layer applies.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ifthenpay_sc_payments' );
