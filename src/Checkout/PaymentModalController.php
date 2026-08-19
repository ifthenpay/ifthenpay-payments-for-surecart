<?php
/**
 * REST endpoints backing the checkout-time payment modal.
 *
 * @package Ifthenpay\SureCart\Checkout
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Checkout;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Api\PaymentInitiator;
use Ifthenpay\SureCart\Repository\PendingPaymentRepository;
use SureCart\Models\Checkout;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Drives the checkout-time payment modal (`assets/js/payment-modal.js`):
 * `start` initiates payment the instant Purchase is clicked; `cancel`
 * (modal closed or countdown expired) only writes local bookkeeping state,
 * so a late webhook can still confirm the order regardless.
 */
class PaymentModalController {

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
	 * Registers the REST hook.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the `start`/`cancel` REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'ifthenpay-surecart/v1',
			'/modal/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_start' ),
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => array(
					'checkout_id' => array(
						'required' => true,
						'type'     => 'string',
					),
					'method'      => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'multibanco', 'mbway', 'pbl' ),
					),
					'phone'       => array(
						'required' => false,
						'type'     => 'string',
					),
					'order_number' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'ifthenpay-surecart/v1',
			'/modal/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_cancel' ),
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => array(
					'ref'         => array(
						'required' => true,
						'type'     => 'string',
					),
					'checkout_id' => array(
						'required' => true,
						'type'     => 'string',
					),
					'state'       => array(
						'required' => false,
						'type'     => 'string',
						'enum'     => array( 'CANCELLED', 'EXPIRED' ),
						'default'  => 'CANCELLED',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: requires a valid `wp_rest` nonce (CSRF — blocks a
	 * third-party site from silently driving this endpoint via a visitor's
	 * browser) *and* that the current visitor is allowed to act on the given
	 * `checkout_id`, via SureCart's own `edit_sc_checkout` meta-capability —
	 * the same authorization SureCart's own checkout REST routes rely on. It
	 * resolves `true` for anyone (including guests) while the checkout is
	 * still `draft`/`finalized`, and stops being true once the checkout
	 * moves past that (e.g. already paid), so a guessed/enumerated
	 * `checkout_id` can't be used to start or cancel a payment on a
	 * checkout that isn't actively being paid for.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 *
	 * @return bool
	 */
	public function check_nonce( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return false;
		}

		$checkout_id = sanitize_text_field( (string) $request->get_param( 'checkout_id' ) );
		return '' !== $checkout_id && current_user_can( 'edit_sc_checkout', $checkout_id );
	}

	/**
	 * Calls ifthenpay for the checkout's selected method and returns the
	 * data the modal needs to render (reference/entity/amount/expiry for
	 * Multibanco, or a redirect_url to open in a popup for ifthenpay Gateway).
	 *
	 * Mirrors the same guards `ConfirmationInstructions::maybe_initiate_payment()`
	 * applies to its own (fallback) trigger: refuses to re-initiate for an
	 * already-paid checkout, a recurring checkout (ifthenpay cannot auto-renew
	 * subscriptions), or a checkout that already has a pending payment on
	 * file — the last of which also makes this endpoint safely idempotent
	 * against a double-submitted Purchase click or a re-opened modal.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_start( WP_REST_Request $request ): WP_REST_Response {
		$checkout_id  = sanitize_text_field( (string) $request->get_param( 'checkout_id' ) );
		$method       = sanitize_text_field( (string) $request->get_param( 'method' ) );
		$phone        = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$order_number = sanitize_text_field( (string) $request->get_param( 'order_number' ) );

		$checkout = Checkout::with( array( 'customer' ) )->find( $checkout_id );
		if ( is_wp_error( $checkout ) || ! $checkout ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( "We couldn't find your order.", 'ifthenpay-payments-for-surecart' ),
				),
				404
			);
		}

		if ( 'paid' === $checkout->status ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'This order has already been paid.', 'ifthenpay-payments-for-surecart' ),
				),
				400
			);
		}

		if ( $checkout->has_recurring ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'ifthenpay cannot be used for subscription orders.', 'ifthenpay-payments-for-surecart' ),
				),
				400
			);
		}


		$existing = 'pbl' === $method ? null : $this->repository->find_latest_by_checkout_id( $checkout_id );
		if ( $existing ) {
			return $this->response_for_pending( $existing, null );
		}

		$result = $this->initiator->initiate(
			$checkout,
			$checkout_id,
			$method,
			array(
				'phone'        => $phone,
				'order_number' => $order_number,
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		return $this->response_for_pending( $result['pending'], $result['redirect_url'] );
	}

	/**
	 * Builds the modal's `start` response from a pending payment row.
	 *
	 * @param \Ifthenpay\SureCart\Repository\DTO\PendingPayment $pending      The pending payment.
	 * @param string|null                                       $redirect_url ifthenpay Gateway redirect URL, if any.
	 *
	 * @return WP_REST_Response
	 */
	private function response_for_pending( $pending, ?string $redirect_url ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'          => true,
				'ref'         => $pending->ref,
				'method'      => $pending->method,
				'amount'      => $pending->amount,
				'entity'      => $pending->entity,
				'reference'   => $pending->reference,
				'expires_at'  => $pending->expires_at,

				'expires_at_label' => $pending->expires_at ? mysql2date( 'j M Y, H:i', $pending->expires_at ) : null,
				'redirect_url'     => $redirect_url,
			),
			200
		);
	}

	/**
	 * Marks a pending payment as cancelled/expired. Purely local bookkeeping
	 * — never contacts ifthenpay, and never overrides an already-`PAID` row.
	 *
	 * `ref` alone is deterministic from SureCart's own sequential order
	 * number (see `IfthenpayHelper::generate_ref()`), unlike a SureCart
	 * checkout id, so it's guessable/enumerable — requiring the matching
	 * `checkout_id` too (which the modal already has, the same value it
	 * sends on every `/modal/start` call) stops a guessed `ref` alone from
	 * cancelling a payment on someone else's checkout.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_cancel( WP_REST_Request $request ): WP_REST_Response {
		$ref         = sanitize_text_field( (string) $request->get_param( 'ref' ) );
		$state       = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$checkout_id = sanitize_text_field( (string) $request->get_param( 'checkout_id' ) );

		$pending = $this->repository->find_by_ref( $ref );
		if ( ! $pending || ! hash_equals( $pending->checkout_id, $checkout_id ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 404 );
		}

		if ( 'PAID' === $pending->state ) {
			return new WP_REST_Response(
				array(
					'ok'    => true,
					'state' => 'PAID',
				),
				200
			);
		}

		$this->repository->update_state( $ref, $state );

		return new WP_REST_Response(
			array(
				'ok'    => true,
				'state' => $state,
			),
			200
		);
	}
}
