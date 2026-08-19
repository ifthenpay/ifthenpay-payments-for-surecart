<?php
/**
 * Plugin Name:         ifthenpay | Payments for SureCart
 * Plugin URI:          https://github.com/ifthenpay/ifthenpay-payments-for-surecart
 * Description:         SureCart integration for payments with the ifthenpay gateway: Multibanco reference, MB WAY and ifthenpay Gateway (cards, Apple Pay, Google Pay).
 * Version:             1.0.0
 * Requires at least:   6.5
 * Tested up to:        7.0
 * Requires PHP:        7.4
 * Author:              ifthenpay
 * Author URI:          https://ifthenpay.com/
 * License:             GPL v3
 * License URI:         https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:         ifthenpay-payments-for-surecart
 * Domain Path:         /languages
 *
 * @package Ifthenpay\SureCart
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You shall not pass!' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

/** Single source of truth. */
define( 'IFTP_SC_VERSION', '1.0.0' );
define( 'IFTP_SC_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'IFTP_SC_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'IFTP_SC_ASSETS_URL', IFTP_SC_URL . '/assets' );
define( 'IFTP_SC_CSS_URL', IFTP_SC_ASSETS_URL . '/css' );
define( 'IFTP_SC_JS_URL', IFTP_SC_ASSETS_URL . '/js' );
define( 'IFTP_SC_IMAGES_URL', IFTP_SC_ASSETS_URL . '/images' );

/**
 * Cache-busting version for one enqueued asset — the file's own on-disk
 * mtime instead of the static `IFTP_SC_VERSION`, so editing a CSS/JS file
 * during development changes its query string immediately. Enqueuing with a
 * version that never changes between releases means the browser (and any
 * page/object cache in front of it) keeps serving the previous cached copy
 * under that same URL until something else forces a hard refresh.
 *
 * @param string $relative_path Path relative to the plugin's `assets/` dir, e.g. `css/admin.css`.
 *
 * @return string
 */
function ifthenpay_sc_asset_version( string $relative_path ): string {
	$file  = IFTP_SC_PATH . '/assets/' . $relative_path;
	$mtime = file_exists( $file ) ? filemtime( $file ) : false;

	return false !== $mtime ? (string) $mtime : IFTP_SC_VERSION;
}

/** PSR-4 autoload (Composer). */
$iftp_sc_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $iftp_sc_autoload ) ) {
	require_once $iftp_sc_autoload;
}

/**
 * Only run once SureCart is active. Wiring runs on 'plugins_loaded' so every
 * plugin (including SureCart) has had a chance to register itself first.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! is_plugin_active( 'surecart/surecart.php' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-warning"><p>' .
						esc_html__( 'ifthenpay | Payments for SureCart requires the SureCart plugin to be installed and active.', 'ifthenpay-payments-for-surecart' ) .
						'</p></div>';
				}
			);
			return;
		}

		( new Ifthenpay\SureCart\Plugin() )->boot();
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
			require_once __DIR__ . '/vendor/autoload.php';
		}
		( new Ifthenpay\SureCart\Repository\PendingPaymentRepository() )->install_table();
		update_option( 'iftp_sc_activated', true );
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
			require_once __DIR__ . '/vendor/autoload.php';
		}
		Ifthenpay\SureCart\Setup\ExpiredPaymentsCron::unschedule();
	}
);
