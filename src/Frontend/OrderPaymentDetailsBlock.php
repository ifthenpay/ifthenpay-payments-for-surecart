<?php
/**
 * "Payment Details" block: shows the ifthenpay payment for the order the
 * customer is currently viewing on their single-order page.
 *
 * @package Ifthenpay\SureCart\Frontend
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Frontend;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Api\IfthenpayHelper;
use Ifthenpay\SureCart\Repository\DTO\PendingPayment;
use Ifthenpay\SureCart\Repository\PendingPaymentRepository;
use SureCart\Models\Order;

/**
 * Reads `$_GET['id']` directly since SureCart's single-order route never
 * resolves the `Order` server-side. `Order::find()` bypasses SureCart's own
 * REST permission check, so this class re-checks order ownership itself
 * before showing ifthenpay-specific details — otherwise editing the `id` in
 * the URL would leak another customer's payment details.
 */
class OrderPaymentDetailsBlock {

	/**
	 * Persists/looks up pending payments.
	 *
	 * @var PendingPaymentRepository
	 */
	private PendingPaymentRepository $entries;

	/**
	 * Constructor.
	 *
	 * @param PendingPaymentRepository|null $entries Injected for testability.
	 */
	public function __construct( ?PendingPaymentRepository $entries = null ) {
		$this->entries = $entries ?? new PendingPaymentRepository();
	}

	/**
	 * Registers all hooks this collaborator owns.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Registers the block and its (build-step-free) editor script.
	 *
	 * @return void
	 */
	public function register(): void {
		wp_register_script(
			'iftp-sc-order-payment-details-editor',
			IFTP_SC_URL . '/blocks/ifthenpay-order-payment-details/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			IFTP_SC_VERSION,
			true
		);

		register_block_type(
			IFTP_SC_PATH . '/blocks/ifthenpay-order-payment-details',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Enqueues the same styles/polling script `ConfirmationInstructions` uses
	 * on the order-confirmation page — `polling.js` only acts if it finds a
	 * `#iftp-sc-status-data` element, so enqueuing it unconditionally here is
	 * a safe no-op when this order has no ifthenpay payment to show.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets(): void {
		if ( is_admin() || ! $this->is_order_show_page() ) {
			return;
		}

		IfthenpayHelper::enqueue_checkout_style();
		IfthenpayHelper::enqueue_polling_script();
	}

	/**
	 * Renders the block on the front end.
	 *
	 * @return string
	 */
	public function render(): string {
		if ( ! is_user_logged_in() || ! $this->is_order_show_page() ) {
			return '';
		}

		$order_id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page context lookup, no state changes.
		if ( '' === $order_id ) {
			return '';
		}


		$order = Order::with( array( 'checkout' ) )->find( $order_id );
		if ( is_wp_error( $order ) || empty( $order->checkout ) || ! $order->checkout->belongsToUser( get_current_user_id() ) ) {
			return '';
		}


		$pending = $this->entries->find_latest_by_checkout_id( (string) $order->checkout->id );
		if ( ! $pending instanceof PendingPayment ) {
			return '';
		}

		\SureCart::assets()->enqueueComponents();

		ob_start();
		require IFTP_SC_PATH . '/src/Frontend/views/instructions.php';
		return (string) ob_get_clean();
	}

	/**
	 * Whether the current request is SureCart's customer-facing single-order
	 * page (`?model=order&action=show`).
	 *
	 * @return bool
	 */
	private function is_order_show_page(): bool {
		$model  = isset( $_GET['model'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['model'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page context lookup, no state changes.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page context lookup, no state changes.

		return 'order' === $model && 'show' === $action;
	}
}
