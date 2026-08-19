<?php
/**
 * Checkout-page asset enqueuing and localization.
 *
 * @package Ifthenpay\SureCart\Checkout
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Checkout;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Api\IfthenpayHelper;
use Ifthenpay\SureCart\Setup\ManualPaymentMethodProvisioner;
use SureCart\Models\Checkout;

/**
 * Enqueues the checkout-page JS/CSS: `checkout.js` (hides our manual-payment
 * rows on recurring/subscription checkouts, since ifthenpay can't auto-renew)
 * and `payment-modal.js` (the PayPal-style modal that opens the instant
 * Purchase is clicked), and localizes the data both need (which manual-method
 * IDs map to which ifthenpay method, whether this checkout has a recurring
 * line item, and the REST endpoints to call).
 */
class CheckoutAssets {

	/**
	 * Registers the enqueue hook.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueues checkout assets when the current page has a SureCart checkout.
	 *
	 * @return void
	 */
	public function maybe_enqueue(): void {
		if ( ! $this->is_checkout_page() ) {
			return;
		}

		IfthenpayHelper::enqueue_checkout_style();

		wp_enqueue_style(
			'iftp-sc-payment-modal',
			IFTP_SC_CSS_URL . '/payment-modal.css',
			array(),
			ifthenpay_sc_asset_version( 'css/payment-modal.css' )
		);

		wp_enqueue_script(
			'iftp-sc-checkout',
			IFTP_SC_JS_URL . '/checkout.js',
			array(),
			ifthenpay_sc_asset_version( 'js/checkout.js' ),
			true
		);

		wp_enqueue_script(
			'iftp-sc-payment-modal',
			IFTP_SC_JS_URL . '/payment-modal.js',
			array(),
			ifthenpay_sc_asset_version( 'js/payment-modal.js' ),
			true
		);

		$data = $this->localized_data();
		wp_localize_script( 'iftp-sc-checkout', 'iftpScCheckout', $data );
		wp_localize_script( 'iftp-sc-payment-modal', 'iftpScCheckout', $data );
	}

	/**
	 * Builds the data localized to the checkout JS.
	 *
	 * @return array<string, mixed>
	 */
	private function localized_data(): array {
		$checkout_id = IfthenpayHelper::current_checkout_id();

		return array(
			'restUrl'      => esc_url_raw( rest_url( 'ifthenpay-surecart/v1' ) ),
			'restNonce'    => wp_create_nonce( 'wp_rest' ),
			'checkoutId'   => $checkout_id,
			'idMethodMap'  => ManualPaymentMethodProvisioner::get_id_method_map(),
			'hasRecurring' => $checkout_id ? $this->checkout_has_recurring( $checkout_id ) : false,
			'logoUrl'      => IFTP_SC_IMAGES_URL . '/logo-color.svg',
			'methodIcons'  => array(
				'multibanco' => IFTP_SC_IMAGES_URL . '/multibanco_icon.svg',
				'mbway'      => IFTP_SC_IMAGES_URL . '/mbway_icon.svg',
			),

			'modalHeaderIcons' => array(
				'multibanco' => IFTP_SC_IMAGES_URL . '/multibanco_only_icon.svg',
				'mbway'      => IFTP_SC_IMAGES_URL . '/mbway_only_icon.svg',
			),
			'methodLabels' => array(
				'multibanco' => __( 'Multibanco', 'ifthenpay-payments-for-surecart' ),
				'mbway'      => __( 'MbWay', 'ifthenpay-payments-for-surecart' ),
				'pbl'        => __( 'Gateway', 'ifthenpay-payments-for-surecart' ),
			),
			'pblEnabledMethods' => $this->enabled_pbl_methods(),
			'i18n'         => array(
				'pblEnabledMethodsLabel' => __( 'ifthenpay Gateway Payment methods:', 'ifthenpay-payments-for-surecart' ),
				'phoneLabel'            => __( 'MB WAY phone number', 'ifthenpay-payments-for-surecart' ),
				'phoneHelp'             => __( "We'll send a payment request to this number.", 'ifthenpay-payments-for-surecart' ),
				'phoneInvalid'          => __( 'Enter a valid Portuguese mobile number (9 digits, starting with 91, 92, 93 or 96).', 'ifthenpay-payments-for-surecart' ),
				'send'                  => __( 'Send', 'ifthenpay-payments-for-surecart' ),
				'processing'            => __( 'Sending payment request…', 'ifthenpay-payments-for-surecart' ),
				'genericError'          => __( 'Something went wrong starting this payment. Please try again.', 'ifthenpay-payments-for-surecart' ),
				'close'                 => __( 'Close', 'ifthenpay-payments-for-surecart' ),
				'mbwayWaitingTitle'        => __( 'Approve on your phone', 'ifthenpay-payments-for-surecart' ),
				'mbwayWaiting'             => __( "We've sent a payment request to your MB WAY app. Open it and approve the payment to finish.", 'ifthenpay-payments-for-surecart' ),
				'mbwayExpired'             => __( "We didn't receive your approval in time. If you already approved it, your order will still update automatically once we hear back.", 'ifthenpay-payments-for-surecart' ),
				'multibancoDetailsTitle'   => __( 'Your payment details', 'ifthenpay-payments-for-surecart' ),
				'multibancoExpired'        => __( 'This reference has expired.', 'ifthenpay-payments-for-surecart' ),
				'multibancoSafeCloseTitle' => __( 'Safe to close this window', 'ifthenpay-payments-for-surecart' ),
				'multibancoSafeClose'      => __( "Pay whenever suits you at an ATM or in your home banking app. We'll confirm your order automatically as soon as the payment arrives.", 'ifthenpay-payments-for-surecart' ),
				'pblWaiting'            => __( 'Complete your payment in the window that just opened.', 'ifthenpay-payments-for-surecart' ),
				'pblOpenLink'           => __( "Didn't see a new window? Open payment page", 'ifthenpay-payments-for-surecart' ),
				'cancelledNote'         => __( "Your payment wasn't completed. If you already paid, your order will still update automatically once we hear back.", 'ifthenpay-payments-for-surecart' ),
				'cancelConfirm'         => __( 'Are you sure you want to cancel the payment?', 'ifthenpay-payments-for-surecart' ),
				'yes'                   => __( 'Yes, cancel', 'ifthenpay-payments-for-surecart' ),
				'no'                    => __( 'No', 'ifthenpay-payments-for-surecart' ),

				'paidTitle'             => __( 'Thank you!', 'ifthenpay-payments-for-surecart' ),
				'paidMessage'           => __( 'Your payment was successful. A receipt is on its way to your inbox.', 'ifthenpay-payments-for-surecart' ),
				'continueLabel'         => __( 'Continue', 'ifthenpay-payments-for-surecart' ),
				'notCompleted'          => __( 'Payment not completed', 'ifthenpay-payments-for-surecart' ),
				'paymentFailed'         => __( 'ifthenpay reported this payment as failed. You can try again from checkout.', 'ifthenpay-payments-for-surecart' ),
				'paymentExpiredGeneric' => __( 'This payment expired before it was confirmed.', 'ifthenpay-payments-for-surecart' ),
				'entity'                => __( 'Entity', 'ifthenpay-payments-for-surecart' ),
				'reference'             => __( 'Reference', 'ifthenpay-payments-for-surecart' ),
				'amount'                => __( 'Amount', 'ifthenpay-payments-for-surecart' ),
				'expires'               => __( 'Expires', 'ifthenpay-payments-for-surecart' ),
			),
		);
	}

	/**
	 * The ifthenpay Gateway sub-methods (Card, Apple Pay, Google Pay, Cofidis,
	 * Pix, …) the merchant has actually switched on in the ifthenpay Gateway tab —
	 * read straight off the `iftp_sc_pbl_methods` option saved by
	 * `SettingsPage::handle_save_pbl_config()`, which already carries each
	 * method's real ifthenpay brand icon in its `logo` field (the API's own
	 * `SmallImageUrl`, resolved once in `resolve_pbl_methods_table()`) — so
	 * this needs no ifthenpay API call of its own on a customer-facing page.
	 *
	 * @return array<int, array{label: string, icon: string}>
	 */
	private function enabled_pbl_methods(): array {
		$methods = get_option( 'iftp_sc_pbl_methods', array() );
		$enabled = array();

		foreach ( (array) $methods as $entity => $method ) {
			if ( empty( $method['enabled'] ) || empty( $method['logo'] ) ) {
				continue;
			}

			$enabled[] = array(
				'label' => (string) ( $method['label'] ?? $entity ),
				'icon'  => (string) $method['logo'],
			);
		}

		return $enabled;
	}

	/**
	 * Whether the current page renders a SureCart checkout.
	 *
	 * @return bool
	 */
	private function is_checkout_page(): bool {
		if ( is_admin() ) {
			return false;
		}

		return has_block( 'surecart/checkout-form' ) || has_block( 'surecart/payment' );
	}

	/**
	 * Whether a checkout contains a recurring (subscription) line item.
	 *
	 * @param string $checkout_id The checkout id to check.
	 *
	 * @return bool
	 */
	private function checkout_has_recurring( string $checkout_id ): bool {
		$checkout = Checkout::find( $checkout_id );

		if ( is_wp_error( $checkout ) || ! $checkout ) {
			return false;
		}

		return (bool) $checkout->has_recurring;
	}
}
