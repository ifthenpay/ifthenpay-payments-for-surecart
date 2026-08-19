<?php
/**
 * Shared ifthenpay payment-initiation logic.
 *
 * @package Ifthenpay\SureCart\Api
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Api;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Repository\DTO\PendingPayment;
use Ifthenpay\SureCart\Repository\PendingPaymentRepository;
use SureCart\Models\Checkout;

/**
 * Calls the ifthenpay API for a checkout's selected method (Multibanco,
 * MB WAY, or ifthenpay Gateway) and records the resulting pending payment.
 *
 * Used by two callers: `Frontend\ConfirmationInstructions` (the original,
 * confirmation-page-triggered flow — kept as a fallback for stores that do
 * have a dedicated order-confirmation page) and `Checkout\PaymentModalController`
 * (the new checkout-time modal, triggered the instant Purchase is clicked).
 * Both share this class so the Multibanco/MB WAY/ifthenpay Gateway branches only
 * exist once.
 */
class PaymentInitiator {

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
	 * Constructor.
	 *
	 * @param PendingPaymentRepository|null $repository Injected for testability.
	 * @param IfthenpayClient|null          $client     Injected for testability.
	 */
	public function __construct( ?PendingPaymentRepository $repository = null, ?IfthenpayClient $client = null ) {
		$this->repository = $repository ?? new PendingPaymentRepository();
		$this->client     = $client ?? new IfthenpayClient();
	}

	/**
	 * Calls ifthenpay for the given method and records the pending payment.
	 *
	 * @param Checkout             $checkout    The finalized (or finalizing) SureCart checkout.
	 * @param string               $checkout_id The checkout's id.
	 * @param string               $method_key  One of `multibanco`, `mbway`, `pbl`.
	 * @param array<string, mixed> $extra       Extra per-method input — `phone` for `mbway`,
	 *                                           plus `order_number` (SureCart's real order
	 *                                           number, if the caller already resolved it
	 *                                           from SureCart's own `finalize()` response).
	 *
	 * @return array{pending: PendingPayment, redirect_url: ?string}|\WP_Error
	 */
	public function initiate( Checkout $checkout, string $checkout_id, string $method_key, array $extra = array() ) {

		$currency     = (string) ( $checkout->currency ?? \SureCart::account()->currency ?? '' );
		$amount       = (float) \SureCart\Support\Currency::maybeConvertAmount( (float) ( $checkout->amount_due ?? $checkout->amount ?? 0 ), $currency );
		$ref          = IfthenpayHelper::generate_ref( $checkout_id, (string) ( $extra['order_number'] ?? '' ) );
		$description  = sprintf( /* translators: %s: site name */ __( 'Order at %s', 'ifthenpay-payments-for-surecart' ), get_bloginfo( 'name' ) );
		$client_name  = (string) ( $checkout->customer->name ?? '' );
		$client_email = (string) ( $checkout->customer->email ?? '' );

		if ( 'multibanco' === $method_key ) {
			return $this->initiate_multibanco( $checkout_id, $ref, $amount, $description, $client_name, $client_email );
		}

		if ( 'mbway' === $method_key ) {
			return $this->initiate_mb_way( $checkout_id, $ref, $amount, $description, $client_email, (string) ( $extra['phone'] ?? '' ) );
		}

		if ( 'pbl' === $method_key ) {
			return $this->initiate_pbl( $checkout_id, $ref, $amount, $description );
		}

		return new \WP_Error( 'iftp_sc_unknown_method', __( 'Unknown ifthenpay payment method.', 'ifthenpay-payments-for-surecart' ) );
	}

	/**
	 * Generates a Multibanco reference — via ifthenpay's API for a modern MB
	 * Key account, or entirely offline for a legacy Entity/Sub-entity account
	 * (see `MultibancoOfflineReferenceGenerator`). `SettingsPage::handle_save_multibanco()`
	 * guarantees the two are mutually exclusive, so the MB Key option alone
	 * is enough to decide which branch applies.
	 *
	 * @param string $checkout_id  The checkout's id.
	 * @param string $ref          Locally-generated payment reference.
	 * @param float  $amount       Amount due.
	 * @param string $description  Order description.
	 * @param string $client_name  Customer name, if known.
	 * @param string $client_email Customer email, if known.
	 *
	 * @return array{pending: PendingPayment, redirect_url: ?string}|\WP_Error
	 */
	private function initiate_multibanco( string $checkout_id, string $ref, float $amount, string $description, string $client_name, string $client_email ) {
		$mb_key = (string) get_option( 'iftp_sc_mb_key', '' );
		if ( '' !== $mb_key ) {
			return $this->initiate_modern_multibanco( $mb_key, $checkout_id, $ref, $amount, $description, $client_name, $client_email );
		}

		$entity    = (string) get_option( 'iftp_sc_mb_legacy_entity', '' );
		$subentity = (string) get_option( 'iftp_sc_mb_legacy_subentity', '' );
		if ( '' !== $entity && '' !== $subentity ) {
			return $this->initiate_legacy_multibanco( $entity, $subentity, $checkout_id, $ref, $amount );
		}

		return new \WP_Error( 'iftp_sc_mb_not_configured', __( 'Multibanco is not configured.', 'ifthenpay-payments-for-surecart' ) );
	}

	/**
	 * Generates a Multibanco reference via ifthenpay's modern MB Key API.
	 *
	 * @param string $mb_key       The merchant's MB Key.
	 * @param string $checkout_id  The checkout's id.
	 * @param string $ref          Locally-generated payment reference.
	 * @param float  $amount       Amount due.
	 * @param string $description  Order description.
	 * @param string $client_name  Customer name, if known.
	 * @param string $client_email Customer email, if known.
	 *
	 * @return array{pending: PendingPayment, redirect_url: ?string}|\WP_Error
	 */
	private function initiate_modern_multibanco( string $mb_key, string $checkout_id, string $ref, float $amount, string $description, string $client_name, string $client_email ) {
		$result = $this->client->create_multibanco_reference(
			$mb_key,
			$ref,
			$amount,
			$description,
			max( 1, (int) get_option( 'iftp_sc_mb_expire_days', 3 ) ),
			array(
				'url'   => home_url(),
				'name'  => $client_name,
				'email' => $client_email,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = array(
			'ref'         => $ref,
			'checkout_id' => $checkout_id,
			'method'      => 'multibanco',
			'amount'      => IfthenpayHelper::format_amount( $amount ),
			'entity'      => $result['Entity'] ?? '',
			'reference'   => $result['Reference'] ?? '',
			'request_id'  => $result['RequestId'] ?? '',
			'expires_at'  => $this->parse_expiry( $result['ExpiryDate'] ?? '' ),
		);

		$this->log_if_insert_failed( $this->repository->insert( $data ), $ref );

		return array(
			'pending'      => PendingPayment::from_row( $data ),
			'redirect_url' => null,
		);
	}

	/**
	 * Generates a Multibanco reference entirely offline for a legacy
	 * Entity/Sub-entity account, per ifthenpay's Multibanco Offline
	 * Algorithm — no request is sent to ifthenpay to generate it.
	 *
	 * @param string $entity      5-digit entity code.
	 * @param string $subentity   3-digit sub-entity code.
	 * @param string $checkout_id The checkout's id.
	 * @param string $ref         Locally-generated payment reference, also used as the reference's 4-digit id segment source.
	 * @param float  $amount      Amount due.
	 *
	 * @return array{pending: PendingPayment, redirect_url: ?string}|\WP_Error
	 */
	private function initiate_legacy_multibanco( string $entity, string $subentity, string $checkout_id, string $ref, float $amount ) {
		$result = MultibancoOfflineReferenceGenerator::generate( $entity, $subentity, $ref, $amount );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = array(
			'ref'         => $ref,
			'checkout_id' => $checkout_id,
			'method'      => 'multibanco',
			'amount'      => IfthenpayHelper::format_amount( $amount ),
			'entity'      => $result['entity'],
			'reference'   => $result['reference'],

			'request_id'  => '',
			'expires_at'  => null,
		);

		$this->log_if_insert_failed( $this->repository->insert( $data ), $ref );

		return array(
			'pending'      => PendingPayment::from_row( $data ),
			'redirect_url' => null,
		);
	}

	/**
	 * Sends an MB WAY payment request.
	 *
	 * @param string $checkout_id  The checkout's id.
	 * @param string $ref          Locally-generated payment reference.
	 * @param float  $amount       Amount due.
	 * @param string $description  Order description.
	 * @param string $client_email Customer email, if known.
	 * @param string $phone        Customer's MB WAY phone number, as entered.
	 *
	 * @return array{pending: PendingPayment, redirect_url: ?string}|\WP_Error
	 */
	private function initiate_mb_way( string $checkout_id, string $ref, float $amount, string $description, string $client_email, string $phone ) {
		if ( ! IfthenpayHelper::is_valid_mb_way_phone( $phone ) ) {
			return new \WP_Error( 'iftp_sc_invalid_phone', __( 'Enter a valid Portuguese mobile number.', 'ifthenpay-payments-for-surecart' ) );
		}

		$result = $this->client->create_mb_way_payment(
			(string) get_option( 'iftp_sc_mbway_key', '' ),
			$ref,
			$amount,
			IfthenpayHelper::normalize_mb_way_phone( $phone ),
			$description,
			$client_email
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = array(
			'ref'         => $ref,
			'checkout_id' => $checkout_id,
			'method'      => 'mbway',
			'amount'      => IfthenpayHelper::format_amount( $amount ),
			'phone'       => $phone,
			'request_id'  => $result['RequestId'] ?? '',
		);

		$this->log_if_insert_failed( $this->repository->insert( $data ), $ref );

		return array(
			'pending'      => PendingPayment::from_row( $data ),
			'redirect_url' => null,
		);
	}

	/**
	 * Creates a ifthenpay Gateway session.
	 *
	 * @param string $checkout_id The checkout's id.
	 * @param string $ref         Locally-generated payment reference.
	 * @param float  $amount      Amount due.
	 * @param string $description Order description.
	 *
	 * @return array{pending: PendingPayment, redirect_url: ?string}|\WP_Error
	 */
	private function initiate_pbl( string $checkout_id, string $ref, float $amount, string $description ) {
		$pbl_description = (string) get_option( 'iftp_sc_pbl_description', '' );

		$result = $this->client->create_pay_by_link(
			(string) get_option( 'iftp_sc_gateway_key', '' ),
			$ref,
			$amount,
			'' !== $pbl_description ? $pbl_description : $description,
			$this->build_accounts_string(),
			add_query_arg(
				array(
					'iftp_sc_pbl' => 'success',
					'iftp_sc_txn' => '[TRANSACTIONID]',
				),
				home_url( '/' )
			),
			add_query_arg(
				array(
					'iftp_sc_pbl' => 'error',
					'iftp_sc_txn' => '[TRANSACTIONID]',
				),
				home_url( '/' )
			),
			add_query_arg(
				array(
					'iftp_sc_pbl' => 'cancel',
					'iftp_sc_txn' => '[TRANSACTIONID]',
				),
				home_url( '/' )
			),
			$this->current_lang(),
			$this->default_method_position()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$redirect_url = $result['redirect_url'] ?? $result['RedirectUrl'] ?? '';

		$data = array(
			'ref'          => $ref,
			'checkout_id'  => $checkout_id,
			'method'       => 'pbl',
			'amount'       => IfthenpayHelper::format_amount( $amount ),
			'redirect_url' => $redirect_url,
		);

		$this->log_if_insert_failed( $this->repository->insert( $data ), $ref );

		return array(
			'pending'      => PendingPayment::from_row( $data ),
			'redirect_url' => '' !== $redirect_url ? $redirect_url : null,
		);
	}

	/**
	 * Logs a persistence failure after ifthenpay has already accepted the
	 * payment request — the reference is real and payable either way, but
	 * without a local row the eventual webhook has nothing to match it
	 * against (`CallbackController` returns `not_found`), so this is the
	 * only place such a failure would otherwise be visible.
	 *
	 * @param bool   $succeeded Return value of `PendingPaymentRepository::insert()`.
	 * @param string $ref       The ifthenpay reference that failed to persist.
	 *
	 * @return void
	 */
	private function log_if_insert_failed( bool $succeeded, string $ref ): void {
		if ( $succeeded ) {
			return;
		}

		error_log( sprintf( '[ifthenpay-surecart] Failed to persist pending payment for ref %s — a webhook for this reference will not be matched.', $ref ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate diagnostic for a silent failure with no other visibility.
	}

	/**
	 * Maps the site locale to one of ifthenpay's supported PBL languages.
	 *
	 * @return string
	 */
	private function current_lang(): string {
		$locale = substr( get_locale(), 0, 2 );
		return in_array( $locale, array( 'pt', 'es', 'fr' ), true ) ? $locale : 'en';
	}

	/**
	 * Resolves the merchant's chosen default ifthenpay Gateway method (if any and
	 * still enabled) to its catalog `Position`, for the PBL `selected_method`
	 * param.
	 *
	 * @return int
	 */
	private function default_method_position(): int {
		$default = (string) get_option( 'iftp_sc_pbl_default_method', '' );
		if ( '' === $default ) {
			return 0;
		}

		$methods = get_option( 'iftp_sc_pbl_methods', array() );
		$methods = is_array( $methods ) ? $methods : array();

		if ( empty( $methods[ $default ]['enabled'] ) ) {
			return 0;
		}

		return (int) ( $methods[ $default ]['position'] ?? 0 );
	}

	/**
	 * Builds the `"ENTITY|ACCOUNT;ENTITY2|ACCOUNT2"` accounts string from the
	 * methods table the merchant enabled in Admin\SettingsPage — Multibanco
	 * and MB WAY are never included here, since those two are always excluded
	 * from `iftp_sc_pbl_methods` (see `Api\GatewayCatalogClient`).
	 *
	 * @return string
	 */
	private function build_accounts_string(): string {
		$methods = get_option( 'iftp_sc_pbl_methods', array() );
		$methods = is_array( $methods ) ? $methods : array();

		$parts = array();
		foreach ( $methods as $entity => $method ) {
			if ( empty( $method['enabled'] ) || empty( $method['account'] ) ) {
				continue;
			}
			$raw     = (string) $method['account'];
			$account = false !== strrpos( $raw, '|' ) ? trim( substr( $raw, strrpos( $raw, '|' ) + 1 ) ) : trim( $raw );
			$parts[] = $entity . '|' . $account;
		}

		return implode( ';', $parts );
	}

	/**
	 * Converts ifthenpay's `dd-mm-yyyy` expiry date to a MySQL datetime.
	 *
	 * @param string $expiry_date Date string from the ifthenpay API.
	 *
	 * @return string|null
	 */
	private function parse_expiry( string $expiry_date ): ?string {
		if ( '' === $expiry_date ) {
			return null;
		}

		$parts = explode( '-', $expiry_date );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		return sprintf( '%04d-%02d-%02d 23:59:59', (int) $parts[2], (int) $parts[1], (int) $parts[0] );
	}
}
