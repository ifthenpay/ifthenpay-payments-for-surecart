<?php
/**
 * Small ifthenpay formatting/validation helpers.
 *
 * @package Ifthenpay\SureCart\Api
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Api;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Repository\DTO\PendingPayment;

/**
 * Small, dependency-free helpers shared by the API client, checkout
 * initiator and webhook controller.
 */
class IfthenpayHelper {

	/**
	 * SureCart tracks the active checkout across the session as the
	 * `sc_checkout_id` query var/GET param — shared by `CheckoutAssets` and
	 * `ConfirmationInstructions`, which both need to resolve it the same way.
	 *
	 * @return string
	 */
	public static function current_checkout_id(): string {
		$id = get_query_var( 'sc_checkout_id' );
		if ( ! $id && isset( $_GET['sc_checkout_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page context lookup, no state changes.
			$id = sanitize_text_field( wp_unslash( $_GET['sc_checkout_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page context lookup, no state changes.
		}

		return $id ? (string) $id : '';
	}

	/**
	 * Enqueues `checkout.css` — shared by `CheckoutAssets`,
	 * `ConfirmationInstructions` and `OrderPaymentDetailsBlock`, each of which
	 * calls this from behind its own, mutually-exclusive page-context guard.
	 *
	 * @return void
	 */
	public static function enqueue_checkout_style(): void {
		wp_enqueue_style( 'iftp-sc-checkout', IFTP_SC_CSS_URL . '/checkout.css', array(), ifthenpay_sc_asset_version( 'css/checkout.css' ) );
	}

	/**
	 * Enqueues `polling.js` — shared by `ConfirmationInstructions` and
	 * `OrderPaymentDetailsBlock`. It only acts if it finds a
	 * `#iftp-sc-status-data` element, so enqueuing it is a safe no-op on a
	 * page with nothing for it to poll.
	 *
	 * @return void
	 */
	public static function enqueue_polling_script(): void {
		wp_enqueue_script( 'iftp-sc-polling', IFTP_SC_JS_URL . '/polling.js', array(), ifthenpay_sc_asset_version( 'js/polling.js' ), true );
	}

	/**
	 * Ifthenpay always expects amounts as a 2-decimal, period-separated,
	 * non-thousand-separated string.
	 *
	 * @param float $amount Amount to format.
	 *
	 * @return string
	 */
	public static function format_amount( float $amount ): string {
		return number_format( $amount, 2, '.', '' );
	}

	/**
	 * Builds the payment reference used both as the `orderId` sent to
	 * ifthenpay and as the lookup key stored locally. Reuses SureCart's own
	 * order number verbatim when known, so ifthenpay's dashboard matches the
	 * store order; falls back to a checkout-id fragment otherwise.
	 *
	 * @param string $checkout_id  SureCart checkout id.
	 * @param string $order_number SureCart's order number, if already known.
	 *
	 * @return string
	 */
	public static function generate_ref( string $checkout_id, string $order_number = '' ): string {
		$clean_order_number = preg_replace( '/[^A-Za-z0-9\-_]/', '', $order_number ) ?? '';
		if ( '' !== $clean_order_number ) {
			return $clean_order_number;
		}
		return strtoupper( substr( str_replace( array( '-', '_' ), '', $checkout_id ), -8 ) );
	}

	/**
	 * Validates a notify-URL/webhook callback's anti-phishing secret.
	 *
	 * ifthenpay accepts two different values here, and a real callback may
	 * arrive with either depending on account/setup — both are checked:
	 *
	 * - The per-site random secret this plugin registers via
	 *   `IfthenpayClient::activate_callback()` (`chave` for the direct APIs,
	 *   `apk` for ifthenpay Gateway), matching `ifthenpay-payments-for-contactform7`.
	 * - ifthenpay's universal, registration-free anti-phishing key: the
	 *   relevant key itself (MB Key / MB WAY Key / Gateway Key / legacy MB
	 *   Entity), base64 encoded — always valid regardless of whether the
	 *   custom secret above was successfully registered.
	 *
	 * @param string $received Secret received on the callback.
	 * @param string $stored   Per-site secret configured for this site.
	 * @param string $raw_key  The MB Key / MB WAY Key / Gateway Key / legacy MB Entity this
	 *                         callback belongs to, for the base64(key) fallback. Optional —
	 *                         omit to only check the registered per-site secret.
	 *
	 * @return bool
	 */
	public static function is_valid_secret_key( string $received, string $stored, string $raw_key = '' ): bool {
		if ( '' === $received ) {
			return false;
		}

		if ( '' !== $stored && hash_equals( $stored, $received ) ) {
			return true;
		}

		if ( '' !== $raw_key && hash_equals( base64_encode( $raw_key ), $received ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalizes a Portuguese mobile number into the `351#9XXXXXXXX` shape
	 * MB WAY's API expects.
	 *
	 * @param string $phone Raw phone number as entered by the customer.
	 *
	 * @return string
	 */
	public static function normalize_mb_way_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';
		$digits = ltrim( $digits, '0' );


		if ( 0 === strpos( $digits, '351' ) && strlen( $digits ) > 9 ) {
			$digits = substr( $digits, 3 );
		}

		return '351#' . $digits;
	}

	/**
	 * `true` if the given string looks like a valid Portuguese mobile number
	 * (9 digits, starting 91/92/93/96).
	 *
	 * @param string $phone Phone number to validate.
	 *
	 * @return bool
	 */
	public static function is_valid_mb_way_phone( string $phone ): bool {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';
		return (bool) preg_match( '/^9[1236]\d{7}$/', $digits );
	}

	/**
	 * Resolves the label + icon to show for a pending payment's method,
	 * preferring the real ifthenpay brand icon cached by
	 * `GatewayCatalogClient::refresh_available_methods_cache()` (populated
	 * once a Backoffice Key is connected) and falling back to a hardcoded
	 * label with no icon when that cache is empty — e.g. a store using only
	 * a standalone Multibanco/MB WAY key, with no ifthenpay Gateway/Backoffice Key
	 * ever connected.
	 *
	 * @param PendingPayment $pending Pending payment row to resolve.
	 *
	 * @return array{label: string, icon_url: string}
	 */
	public static function resolve_payment_method( PendingPayment $pending ): array {
		$key = self::catalog_key( $pending );

		if ( '' !== $key ) {
			$catalog = get_option( GatewayCatalogClient::METHODS_CACHE_OPTION, array() );
			$catalog = is_array( $catalog ) ? $catalog : array();

			if ( isset( $catalog[ $key ]['icon_url'] ) ) {
				return array(
					'label'    => (string) ( $catalog[ $key ]['label'] ?? self::fallback_label( $key, $pending ) ),
					'icon_url' => (string) $catalog[ $key ]['icon_url'],
				);
			}
		}

		return array(
			'label'    => self::fallback_label( $key, $pending ),
			'icon_url' => '',
		);
	}

	/**
	 * The ifthenpay entity code identifying a pending payment's method, for
	 * looking it up in the cached methods catalog — the sub-method actually
	 * used (`mtd`) if known, else the entity code for our own two direct
	 * methods, else '' for a `pbl` row whose `mtd` isn't known yet.
	 *
	 * @param PendingPayment $pending Pending payment row to resolve.
	 *
	 * @return string
	 */
	private static function catalog_key( PendingPayment $pending ): string {
		if ( '' !== $pending->mtd ) {
			return strtoupper( $pending->mtd );
		}
		if ( 'multibanco' === $pending->method ) {
			return 'MB';
		}
		if ( 'mbway' === $pending->method ) {
			return 'MBWAY';
		}
		return '';
	}

	/**
	 * Hardcoded label fallback for when the methods catalog cache has
	 * nothing for this entity code (not yet fetched, or a code the cache
	 * doesn't happen to carry).
	 *
	 * @param string         $key     Catalog key resolved by `catalog_key()`.
	 * @param PendingPayment $pending Pending payment row to resolve.
	 *
	 * @return string
	 */
	private static function fallback_label( string $key, PendingPayment $pending ): string {
		$labels = array(
			'MB'      => __( 'Multibanco', 'ifthenpay-payments-for-surecart' ),
			'MBWAY'   => __( 'MB WAY', 'ifthenpay-payments-for-surecart' ),
			'CCARD'   => __( 'Card', 'ifthenpay-payments-for-surecart' ),
			'APPLE'   => __( 'Apple Pay', 'ifthenpay-payments-for-surecart' ),
			'GOOGLE'  => __( 'Google Pay', 'ifthenpay-payments-for-surecart' ),
			'PIX'     => __( 'Pix', 'ifthenpay-payments-for-surecart' ),
		);

		if ( isset( $labels[ $key ] ) ) {
			return $labels[ $key ];
		}

		if ( 'pbl' === $pending->method ) {
			return __( 'ifthenpay Gateway', 'ifthenpay-payments-for-surecart' );
		}

		return __( 'ifthenpay', 'ifthenpay-payments-for-surecart' );
	}
}
