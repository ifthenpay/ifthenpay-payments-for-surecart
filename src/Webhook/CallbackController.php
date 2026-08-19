<?php
/**
 * Webhook / notify-URL callback controller for ifthenpay.
 *
 * @package Ifthenpay\SureCart\Webhook
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Webhook;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Api\IfthenpayHelper;
use Ifthenpay\SureCart\Repository\DTO\PendingPayment;
use Ifthenpay\SureCart\Repository\PendingPaymentRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Receives ifthenpay's server-to-server payment confirmations and bridges
 * them to SureCart via `PaymentConfirmationService::confirm_paid()`.
 *
 * Two distinct callback shapes are handled, because Multibanco/MB WAY
 * (direct API) and ifthenpay Gateway use different ifthenpay notification systems —
 * but both are registered (via `IfthenpayClient::activate_callback()`) with
 * the *same* random per-site secret, so both are validated identically with
 * `IfthenpayHelper::is_valid_secret_key()`:
 *
 * - Multibanco / MB WAY: notify-URL params (`order_id`, `apk`, `amount`,
 *   `request_id`, plus `entity`/`reference` for Multibanco only) — see
 *   `SettingsPage::multibanco_notify_url()`/`mbway_notify_url()` for the
 *   placeholder tokens these come from. ifthenpay's modern API only ever
 *   calls this back on an actual successful payment for these two methods —
 *   there's no separate status/cancelled signal here (unlike Direct Debit),
 *   so a validated delivery is treated as PAID outright.
 * - ifthenpay Gateway: standard webhook params (`ref`, `apk`, `val`, `mtd`, `req`).
 *
 * Because all three methods validate `apk` against the same shared per-site
 * secret, a valid `apk` on any one endpoint also passes on the other two —
 * `is_valid_secret_key()` alone does NOT prove which method a delivery
 * belongs to. Every handler below must therefore also confirm
 * `$pending->method` matches the endpoint it arrived on before treating the
 * lookup result as authoritative.
 */
class CallbackController {

	/**
	 * Persists/looks up pending payments.
	 *
	 * @var PendingPaymentRepository
	 */
	private PendingPaymentRepository $repository;

	/**
	 * Shared "mark paid + bridge to SureCart" logic, also used by
	 * `StatusController`'s active MB WAY status check.
	 *
	 * @var PaymentConfirmationService
	 */
	private PaymentConfirmationService $confirmation;

	/**
	 * Constructor.
	 *
	 * @param PendingPaymentRepository|null   $repository   Injected for testability.
	 * @param PaymentConfirmationService|null $confirmation Injected for testability.
	 */
	public function __construct( ?PendingPaymentRepository $repository = null, ?PaymentConfirmationService $confirmation = null ) {
		$this->repository   = $repository ?? new PendingPaymentRepository();
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
	 * Registers the three notify-URL/webhook REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'ifthenpay-surecart/v1',
			'/callback/multibanco',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_multibanco' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'ifthenpay-surecart/v1',
			'/callback/mbway',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_mb_way' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'ifthenpay-surecart/v1',
			'/callback/pbl',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_pbl' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles the Multibanco notify-URL callback.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_multibanco( WP_REST_Request $request ) {
		return $this->handle_direct_notify( $request, 'iftp_sc_mb_key', 'multibanco' );
	}

	/**
	 * Handles the MB WAY notify-URL callback.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_mb_way( WP_REST_Request $request ) {
		return $this->handle_direct_notify( $request, 'iftp_sc_mbway_key', 'mbway' );
	}

	/**
	 * Shared handler for the Multibanco and MB WAY notify-URL callbacks —
	 * both carry the exact same params (`order_id`, `apk`, `amount`, plus
	 * `entity`/`reference` for Multibanco, which aren't needed for lookup
	 * since `order_id` alone already identifies the pending payment), and
	 * only differ in which raw key validates the anti-phishing secret.
	 *
	 * @param WP_REST_Request $request         Incoming REST request.
	 * @param string          $key_option      `get_option()` key holding this method's raw
	 *                                          ifthenpay key (`iftp_sc_mb_key`/`iftp_sc_mbway_key`).
	 * @param string          $expected_method `PendingPayment::$method` this endpoint is
	 *                                          allowed to confirm (`multibanco`/`mbway`).
	 *
	 * @return WP_REST_Response
	 */
	private function handle_direct_notify( WP_REST_Request $request, string $key_option, string $expected_method ): WP_REST_Response {
		$apk      = sanitize_text_field( (string) $request->get_param( 'apk' ) );
		$order_id = sanitize_text_field( (string) $request->get_param( 'order_id' ) );
		$amount   = sanitize_text_field( (string) $request->get_param( 'amount' ) );


		$raw_key = (string) get_option( $key_option, '' );
		if ( '' === $raw_key && 'iftp_sc_mb_key' === $key_option ) {
			$raw_key = (string) get_option( 'iftp_sc_mb_legacy_entity', '' );
		}

		if ( ! IfthenpayHelper::is_valid_secret_key( $apk, (string) get_option( 'iftp_sc_secret_key', '' ), $raw_key ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_key' ), 403 );
		}

		$pending = $this->repository->find_by_ref( $order_id );


		if ( ! $pending || $expected_method !== $pending->method ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}


		if ( 'PAID' === $pending->state ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'already' => true,
				),
				200
			);
		}

		if ( ! $this->amount_matches( $pending->amount, $amount ) ) {
			return new WP_REST_Response( array( 'error' => 'amount_mismatch' ), 400 );
		}

		return $this->finalize( $pending, array( 'state' => 'PAID' ) );
	}

	/**
	 * Handles the ifthenpay Gateway webhook (both the state-change and
	 * cancel/error notifications).
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_pbl( WP_REST_Request $request ) {
		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$ref    = sanitize_text_field( (string) $request->get_param( 'ref' ) );
		$apk    = sanitize_text_field( (string) $request->get_param( 'apk' ) );


		if ( ! IfthenpayHelper::is_valid_secret_key( $apk, (string) get_option( 'iftp_sc_secret_key', '' ), (string) get_option( 'iftp_sc_gateway_key', '' ) ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_key' ), 403 );
		}

		$pending = $this->repository->find_by_ref( $ref );


		if ( ! $pending || 'pbl' !== $pending->method ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}


		if ( 'PAID' === $pending->state ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'already' => true,
				),
				200
			);
		}

		if ( '' !== $status ) {
			$this->repository->update_state( $pending->ref, 'cancelled' === $status ? 'CANCELLED' : 'FAILED' );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$val = sanitize_text_field( (string) $request->get_param( 'val' ) );
		$req = sanitize_text_field( (string) $request->get_param( 'req' ) );
		$mtd = strtoupper( sanitize_text_field( (string) $request->get_param( 'mtd' ) ) );

		if ( ! $this->amount_matches( $pending->amount, $val ) ) {
			return new WP_REST_Response( array( 'error' => 'amount_mismatch' ), 400 );
		}

		return $this->finalize(
			$pending,
			array(
				'state'      => 'PAID',
				'request_id' => $req,

				'mtd'        => $mtd,
				'phone'      => $pending->phone,
			)
		);
	}

	/**
	 * Compares an expected amount to a received one, tolerant of float rounding.
	 *
	 * @param string $expected Amount stored locally.
	 * @param string $received Amount received from ifthenpay.
	 *
	 * @return bool
	 */
	private function amount_matches( string $expected, string $received ): bool {
		return abs( (float) $expected - (float) $received ) < 0.01;
	}

	/**
	 * Marks the local row as paid and bridges to SureCart via
	 * `PaymentConfirmationService::confirm_paid()`.
	 *
	 * @param PendingPayment       $pending Pending payment row being finalized.
	 * @param array<string, mixed> $extra   Extra columns to persist alongside the state change.
	 *
	 * @return WP_REST_Response
	 */
	private function finalize( PendingPayment $pending, array $extra ): WP_REST_Response {
		if ( 'PAID' === $pending->state ) {

			return new WP_REST_Response(
				array(
					'ok'      => true,
					'already' => true,
				),
				200
			);
		}

		$result = $this->confirmation->confirm_paid( $pending, $extra );
		if ( is_wp_error( $result ) ) {
			$status = 'checkout_not_found' === $result->get_error_code() ? 404 : 502;
			return new WP_REST_Response( array( 'error' => $result->get_error_code() ), $status );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
