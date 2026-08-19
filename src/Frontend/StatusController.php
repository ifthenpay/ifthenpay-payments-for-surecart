<?php
/**
 * REST endpoint the confirmation page polls for payment status.
 *
 * @package Ifthenpay\SureCart\Frontend
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Frontend;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Api\IfthenpayClient;
use Ifthenpay\SureCart\Repository\DTO\PendingPayment;
use Ifthenpay\SureCart\Repository\PendingPaymentRepository;
use Ifthenpay\SureCart\Webhook\PaymentConfirmationService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Polled by the payment modal's JS while waiting for MB WAY / ifthenpay
 * Gateway confirmation. Gateway relies solely on the webhook; MB WAY also
 * actively re-checks ifthenpay's status API each poll (see `maybe_confirm_mbway()`).
 */
class StatusController {

	/**
	 * Persists/looks up pending payments.
	 *
	 * @var PendingPaymentRepository
	 */
	private PendingPaymentRepository $repository;

	/**
	 * HTTP client for the ifthenpay API.
	 *
	 * @var IfthenpayClient
	 */
	private IfthenpayClient $client;

	/**
	 * Shared "mark paid + bridge to SureCart" logic, also used by
	 * `CallbackController`'s webhook.
	 *
	 * @var PaymentConfirmationService
	 */
	private PaymentConfirmationService $confirmation;

	/**
	 * Terminal states that never need re-checking against ifthenpay.
	 */
	private const TERMINAL_STATES = array( 'PAID', 'CANCELLED', 'EXPIRED', 'FAILED' );

	/**
	 * Constructor.
	 *
	 * @param PendingPaymentRepository|null   $repository   Injected for testability.
	 * @param IfthenpayClient|null            $client       Injected for testability.
	 * @param PaymentConfirmationService|null $confirmation Injected for testability.
	 */
	public function __construct( ?PendingPaymentRepository $repository = null, ?IfthenpayClient $client = null, ?PaymentConfirmationService $confirmation = null ) {
		$this->repository   = $repository ?? new PendingPaymentRepository();
		$this->client       = $client ?? new IfthenpayClient();
		$this->confirmation = $confirmation ?? new PaymentConfirmationService( $this->repository );
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
	 * Registers the status REST route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'ifthenpay-surecart/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'ref'         => array(
						'required' => true,
						'type'     => 'string',
					),
					'checkout_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Returns the current state of a pending payment by its reference.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$ref         = sanitize_text_field( (string) $request->get_param( 'ref' ) );
		$checkout_id = sanitize_text_field( (string) $request->get_param( 'checkout_id' ) );
		$pending     = '' !== $ref ? $this->repository->find_by_ref( $ref ) : null;


		if ( ! $pending || ! hash_equals( $pending->checkout_id, $checkout_id ) ) {
			return new WP_REST_Response( array( 'state' => 'UNKNOWN' ), 404 );
		}

		if ( 'mbway' === $pending->method && ! in_array( $pending->state, self::TERMINAL_STATES, true ) ) {
			$pending = $this->maybe_confirm_mbway( $pending );
		}

		return new WP_REST_Response( array( 'state' => $pending->state ), 200 );
	}

	/**
	 * Actively asks ifthenpay whether this MB WAY request has been paid and,
	 * if so, confirms it via `PaymentConfirmationService` — a supplementary
	 * check alongside the webhook; either can confirm first, the other is a
	 * safe no-op after.
	 *
	 * @param PendingPayment $pending Pending MB WAY payment, not yet in a terminal state.
	 *
	 * @return PendingPayment
	 */
	private function maybe_confirm_mbway( PendingPayment $pending ): PendingPayment {
		$response = $this->client->check_mb_way_status(
			(string) get_option( 'iftp_sc_mbway_key', '' ),
			$pending->ref,
			(float) $pending->amount,
			$pending->phone
		);

		if ( is_wp_error( $response ) || ! $this->mbway_status_confirms_paid( $response ) ) {
			return $pending;
		}

		$result = $this->confirmation->confirm_paid( $pending );
		if ( is_wp_error( $result ) ) {
			return $pending;
		}

		return $this->repository->find_by_ref( $pending->ref ) ?? $pending;
	}

	/**
	 * Whether an MB WAY status-check response indicates the payment is
	 * confirmed. Checks both `Status`/`status` == `000` and `Estado`/`estado`
	 * == `PAGO`, since the response shape isn't independently verified.
	 *
	 * @param array<string, mixed> $response Decoded response body.
	 *
	 * @return bool
	 */
	private function mbway_status_confirms_paid( array $response ): bool {
		$status = (string) ( $response['Status'] ?? $response['status'] ?? '' );
		if ( '000' === $status ) {
			return true;
		}

		$estado = strtoupper( (string) ( $response['Estado'] ?? $response['estado'] ?? '' ) );
		return 'PAGO' === $estado || 'PAID' === $estado;
	}
}
