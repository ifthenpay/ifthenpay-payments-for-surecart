<?php
/**
 * Order-confirmation page payment orchestration.
 *
 * @package Ifthenpay\SureCart\Frontend
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Frontend;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Api\IfthenpayHelper;
use Ifthenpay\SureCart\Api\PaymentInitiator;
use Ifthenpay\SureCart\Repository\DTO\PendingPayment;
use Ifthenpay\SureCart\Repository\PendingPaymentRepository;
use Ifthenpay\SureCart\Setup\ManualPaymentMethodProvisioner;
use SureCart\Models\Checkout;

/**
 * Drives payment initiation on SureCart's order-confirmation page, for
 * stores with one configured. Fallback path only: the checkout-time modal
 * (`Checkout\PaymentModalController`) is the primary trigger; a dedupe
 * check here prevents re-initiating a payment the modal already started.
 */
class ConfirmationInstructions {

	/**
	 * Persists/looks up pending payments.
	 *
	 * @var PendingPaymentRepository
	 */
	private PendingPaymentRepository $repository;

	/**
	 * Shared ifthenpay payment-initiation logic.
	 *
	 * @var PaymentInitiator
	 */
	private PaymentInitiator $initiator;

	/**
	 * Constructor.
	 *
	 * @param PendingPaymentRepository|null $repository Injected for testability.
	 * @param PaymentInitiator|null         $initiator  Injected for testability.
	 */
	public function __construct( ?PendingPaymentRepository $repository = null, ?PaymentInitiator $initiator = null ) {
		$this->repository = $repository ?? new PendingPaymentRepository();
		$this->initiator  = $initiator ?? new PaymentInitiator( $this->repository );
	}

	/**
	 * Registers all hooks this collaborator owns.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'template_redirect', array( $this, 'maybe_initiate_payment' ), 5 );
		add_filter( 'the_content', array( $this, 'append_instructions' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_polling' ) );
	}

	/**
	 * Enqueues the status-polling script on the confirmation page.
	 *
	 * @return void
	 */
	public function maybe_enqueue_polling(): void {
		if ( is_admin() || ! $this->is_confirmation_page() ) {
			return;
		}

		IfthenpayHelper::enqueue_checkout_style();
		IfthenpayHelper::enqueue_polling_script();
	}

	/**
	 * Runs once per confirmation-page view. Kicks off the ifthenpay request
	 * the first time a checkout using one of our methods lands here, and
	 * handles the ifthenpay Gateway interstitial redirect.
	 */
	public function maybe_initiate_payment(): void {
		if ( is_admin() || ! $this->is_confirmation_page() ) {
			return;
		}

		$checkout_id = IfthenpayHelper::current_checkout_id();
		if ( '' === $checkout_id ) {
			return;
		}

		$checkout = Checkout::with( array( 'customer' ) )->find( $checkout_id );
		if ( is_wp_error( $checkout ) || ! $checkout || 'paid' === $checkout->status ) {
			return;
		}

		$method_key = $this->resolve_method_key( $checkout );
		if ( null === $method_key ) {
			return;
		}


		if ( $checkout->has_recurring ) {
			return;
		}

		$pending = $this->repository->find_latest_by_checkout_id( $checkout_id );

		if ( $pending ) {
			if ( 'pbl' === $method_key ) {
				$this->handle_pbl_return( $pending );
			}
			return;
		}

		$this->initiate_payment( $checkout, $checkout_id, $method_key );
	}

	/**
	 * Calls the ifthenpay API for the selected method and stores the result,
	 * via the same shared `PaymentInitiator` the checkout-time modal uses.
	 * No phone number is available here for `mbway` (only collected in the
	 * modal), so an MB WAY checkout reaching this path is silently skipped.
	 *
	 * @param Checkout $checkout    The finalized SureCart checkout.
	 * @param string   $checkout_id The checkout's id.
	 * @param string   $method_key  One of `multibanco`, `mbway`, `pbl`.
	 *
	 * @return void
	 */
	private function initiate_payment( Checkout $checkout, string $checkout_id, string $method_key ): void {
		$result = $this->initiator->initiate( $checkout, $checkout_id, $method_key );

		if ( is_wp_error( $result ) ) {
			return;
		}

		if ( 'pbl' === $method_key && ! empty( $result['redirect_url'] ) ) {
			wp_safe_redirect( $result['redirect_url'] );
			exit;
		}
	}

	/**
	 * The browser return from ifthenpay's hosted PBL page is a UX-only
	 * signal — it never marks the payment paid by itself (the webhook does
	 * that). It only avoids showing a blank/stuck "waiting" state.
	 *
	 * @param PendingPayment $pending The pending payment for this checkout.
	 *
	 * @return void
	 */
	private function handle_pbl_return( PendingPayment $pending ): void {
		$status = isset( $_GET['iftp_sc_pbl'] ) ? sanitize_text_field( wp_unslash( $_GET['iftp_sc_pbl'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- browser return from ifthenpay's hosted PBL page, which cannot carry a WP nonce; can only cancel/fail this checkout's own still-pending row (never an already-PAID one), and the webhook remains authoritative.

		if ( 'cancel' === $status || 'error' === $status ) {
			if ( 'PAID' !== $pending->state ) {
				$this->repository->update_state( $pending->ref, 'cancel' === $status ? 'CANCELLED' : 'FAILED' );
			}
		}
	}

	/**
	 * Appends the payment-instructions module after SureCart's own
	 * order-confirmation blocks (see design spec §2).
	 *
	 * @param string $content Rendered page content.
	 */
	public function append_instructions( string $content ): string {
		if ( is_admin() || ! has_block( 'surecart/order-confirmation', $content ) ) {
			return $content;
		}

		$checkout_id = IfthenpayHelper::current_checkout_id();
		if ( '' === $checkout_id ) {
			return $content;
		}

		$pending = $this->repository->find_latest_by_checkout_id( $checkout_id );
		if ( ! $pending ) {
			return $content;
		}

		ob_start();
		require IFTP_SC_PATH . '/src/Frontend/views/instructions.php';
		$instructions = ob_get_clean();

		return $content . $instructions;
	}

	/**
	 * Resolves a checkout's SureCart manual-payment-method id to our own
	 * `multibanco`/`mbway`/`pbl` key, or null if it used a different method.
	 *
	 * @param Checkout $checkout The checkout to inspect.
	 *
	 * @return string|null
	 */
	private function resolve_method_key( Checkout $checkout ): ?string {
		$manual_payment_method = $checkout->manual_payment_method ?? null;
		if ( empty( $manual_payment_method ) ) {
			return null;
		}

		$id = is_string( $manual_payment_method ) ? $manual_payment_method : ( $manual_payment_method->id ?? '' );
		if ( '' === $id ) {
			return null;
		}

		$map = ManualPaymentMethodProvisioner::get_id_method_map();
		return $map[ $id ] ?? null;
	}

	/**
	 * Whether the current request is rendering SureCart's order-confirmation
	 * page/block.
	 *
	 * @return bool
	 */
	private function is_confirmation_page(): bool {
		global $post;
		return $post instanceof \WP_Post && has_block( 'surecart/order-confirmation', $post->post_content );
	}

}
