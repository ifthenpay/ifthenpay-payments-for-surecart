<?php
/**
 * Shared "mark paid + bridge to SureCart" logic for Multibanco/MB WAY/PBL.
 *
 * @package Ifthenpay\SureCart\Webhook
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Webhook;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Repository\DTO\PendingPayment;
use Ifthenpay\SureCart\Repository\PendingPaymentRepository;
use Ifthenpay\SureCart\Setup\ManualPaymentMethodProvisioner;
use SureCart\Models\Checkout;
use SureCart\WordPress\Users\CustomerLinkService;
use WP_Error;

/**
 * Used by both `CallbackController` (ifthenpay's own notify-URL/webhook)
 * and `StatusController` (MB WAY's active status check, so confirmation
 * doesn't depend solely on the webhook ever arriving) — both can reach
 * `confirm_paid()` for the same payment at nearly the same time, so it's
 * concurrency-safe via `PendingPaymentRepository::claim_paid()`'s atomic
 * `UPDATE ... WHERE state != 'PAID'`, not a plain state check.
 */
class PaymentConfirmationService {

	/**
	 * Persists/looks up pending payments.
	 *
	 * @var PendingPaymentRepository
	 */
	private PendingPaymentRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param PendingPaymentRepository|null $repository Injected for testability.
	 */
	public function __construct( ?PendingPaymentRepository $repository = null ) {
		$this->repository = $repository ?? new PendingPaymentRepository();
	}

	/**
	 * Marks the local row as paid and bridges to SureCart via `manuallyPay()`.
	 * Idempotent — a second call for an already-PAID row is a no-op.
	 *
	 * @param PendingPayment       $pending Pending payment row being confirmed.
	 * @param array<string, mixed> $extra   Extra columns to persist alongside the state change.
	 *
	 * @return true|WP_Error
	 */
	public function confirm_paid( PendingPayment $pending, array $extra = array() ) {

		if ( ! $this->repository->claim_paid( $pending->ref ) ) {
			return true;
		}

		$extra['state'] = 'PAID';
		$this->repository->update_state( $pending->ref, 'PAID', $extra );

		$checkout = Checkout::find( $pending->checkout_id );
		if ( is_wp_error( $checkout ) || ! $checkout ) {
			return new WP_Error( 'checkout_not_found', 'Checkout not found for pending payment.' );
		}


		$method_ids = ManualPaymentMethodProvisioner::get_method_ids();
		if ( isset( $method_ids[ $pending->method ] ) ) {
			$updated = $checkout->update(
				array(
					'manual_payment'           => true,
					'manual_payment_method_id' => $method_ids[ $pending->method ],
				)
			);
			if ( is_wp_error( $updated ) ) {

				error_log( sprintf( '[ifthenpay-surecart] Failed to set manual_payment_method_id for checkout %s: %s', $pending->checkout_id, $updated->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate diagnostic for a silent failure with no other visibility.
				return $updated;
			}
			$checkout = $updated;
		}


		$checkout = $checkout->with( array( 'purchases' ) );

		$result = $checkout->manuallyPay();
		if ( is_wp_error( $result ) ) {
			error_log( sprintf( '[ifthenpay-surecart] manuallyPay() failed for checkout %s: %s', $pending->checkout_id, $result->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate diagnostic for a silent failure with no other visibility.
			return $result;
		}

		$this->fire_purchase_created( $result );
		$this->link_customer( $result );
		$this->store_order_id( $pending );

		return true;
	}

	/**
	 * Broadcasts `surecart/purchase_created` for each purchase granted by
	 * `manuallyPay()` — the same step `CheckoutsController::manuallyPay()`
	 * performs after its REST call. Every SureCart Integration (membership/
	 * course access grants, AffiliateWP, Thrive Automator, etc.) hooks its
	 * fulfillment logic on this action; calling `manuallyPay()` directly on
	 * the model (see class docblock) bypasses that REST controller entirely,
	 * so without this an ifthenpay-paid order shows "Paid" while every
	 * Integration silently never runs.
	 *
	 * @param Checkout $checkout The manually-paid checkout, with `purchases` expanded.
	 *
	 * @return void
	 */
	private function fire_purchase_created( Checkout $checkout ): void {
		if ( empty( $checkout->purchases->data ) ) {
			return;
		}

		foreach ( $checkout->purchases->data as $purchase ) {
			if ( ! empty( $purchase->revoked ) ) {
				continue;
			}

			try {
				do_action( 'surecart/purchase_created', $purchase ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately SureCart's own native hook name, not ours: replicates the exact action its REST controllers fire so existing SureCart Integrations (AffiliateWP, Thrive Automator, IntegrationService) that already listen on `surecart/purchase_created` keep firing for ifthenpay-paid orders; a prefixed hook name here would be invisible to them.
			} catch ( \Throwable $e ) {

				error_log( sprintf( '[ifthenpay-surecart] surecart/purchase_created listener threw for checkout %s: %s', $checkout->id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate diagnostic, an integration failure must not appear as a payment-confirmation failure.
			}
		}
	}

	/**
	 * Links the checkout's SureCart customer to a WordPress user, mirroring
	 * SureCart's own REST checkout-confirmation step — otherwise skipped
	 * here since manual payments call `manuallyPay()` directly on the model.
	 *
	 * @param Checkout $checkout The manually-paid checkout.
	 *
	 * @return void
	 */
	private function link_customer( Checkout $checkout ): void {

		$expanded = Checkout::with( array( 'customer' ) )->find( $checkout->id );
		if ( is_wp_error( $expanded ) || ! $expanded ) {
			$expanded = $checkout;
		}

		$password_hash = get_transient( 'sc_checkout_password_hash_' . $expanded->id );
		delete_transient( 'sc_checkout_password_hash_' . $expanded->id );

		( new CustomerLinkService( $expanded, $password_hash ) )->link();
	}

	/**
	 * Resolves the order created by `manuallyPay()` and stores its id, so the
	 * Entries tab can link straight to `admin.php?page=sc-orders&action=edit`
	 * without an API call per row. A checkout id is not an order id in
	 * SureCart, so this needs its own expanded lookup — kept separate from
	 * the `$checkout` object used above, which is a live write target and
	 * shouldn't carry an `order` expand into its own PATCH request bodies.
	 * Best-effort: a lookup failure here doesn't affect the payment, which is
	 * already confirmed at this point — the Entries row just won't get a link.
	 *
	 * @param PendingPayment $pending Pending payment row being confirmed.
	 *
	 * @return void
	 */
	private function store_order_id( PendingPayment $pending ): void {
		$expanded = Checkout::with( array( 'order' ) )->find( $pending->checkout_id );
		if ( is_wp_error( $expanded ) || ! $expanded || empty( $expanded->order->id ) ) {
			return;
		}

		$this->repository->update_state( $pending->ref, 'PAID', array( 'order_id' => (string) $expanded->order->id ) );
	}
}
