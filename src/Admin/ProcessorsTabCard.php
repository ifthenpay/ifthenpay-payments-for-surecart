<?php
/**
 * Injects an ifthenpay row into SureCart's Processors tab.
 *
 * @package Ifthenpay\SureCart
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Injects an "ifthenpay" row into SureCart's Processors tab, matching the
 * native Stripe/PayPal/Razorpay markup. Best-effort DOM injection done
 * client-side in processors-card.js, since SureCart's settings screen is a
 * client-rendered SPA with no server-side hook for a third-party row.
 */
class ProcessorsTabCard {

	/**
	 * Registers the enqueue hook.
	 */
	public function boot(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueues the row-injection script only on SureCart's own settings page.
	 */
	public function maybe_enqueue(): void {
		if ( ! isset( $_GET['page'] ) || 'sc-settings' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-context check, no state changes.
			return;
		}

		wp_enqueue_script(
			'iftp-sc-processors-card',
			IFTP_SC_JS_URL . '/processors-card.js',
			array(),
			ifthenpay_sc_asset_version( 'js/processors-card.js' ),
			true
		);

		wp_localize_script(
			'iftp-sc-processors-card',
			'iftpScProcessorsCard',
			array(
				'settingsUrl'            => admin_url( 'admin.php?page=iftp-sc-settings' ),
				'logoUrl'                => IFTP_SC_IMAGES_URL . '/logo-color.svg',
				'description'            => __( 'Connect with ifthenpay to add Multibanco, MB WAY, and ifthenpay Gateway buttons to your checkout.', 'ifthenpay-payments-for-surecart' ),
				'isConnected'            => $this->is_connected(),
				'enabledText'            => __( 'Live Payments Enabled', 'ifthenpay-payments-for-surecart' ),
				'disabledText'           => __( 'Live Payments Disabled', 'ifthenpay-payments-for-surecart' ),

				'processorsSectionTitle' => __( 'Available Processors', 'ifthenpay-payments-for-surecart' ),
			)
		);
	}

	/**
	 * At least one of ifthenpay Gateway, MB WAY, or Multibanco (modern MB Key or
	 * legacy Entity/Sub-entity) has an account configured.
	 *
	 * @return bool
	 */
	private function is_connected(): bool {
		return '' !== (string) get_option( 'iftp_sc_gateway_key', '' )
			|| '' !== (string) get_option( 'iftp_sc_mb_key', '' )
			|| '' !== (string) get_option( 'iftp_sc_mb_legacy_entity', '' )
			|| '' !== (string) get_option( 'iftp_sc_mbway_key', '' );
	}
}
