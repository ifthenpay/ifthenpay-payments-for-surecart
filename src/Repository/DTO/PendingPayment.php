<?php
/**
 * Pending payment value object.
 *
 * @package Ifthenpay\SureCart\Repository\DTO
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Repository\DTO;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for a single row in the {prefix}ifthenpay_sc_payments table.
 */
class PendingPayment {

	/**
	 * Ifthenpay payment reference (`orderId`).
	 *
	 * @var string
	 */
	public string $ref = '';

	/**
	 * SureCart checkout id this payment belongs to.
	 *
	 * @var string
	 */
	public string $checkout_id = '';

	/**
	 * SureCart order id this payment resolved to once paid — resolved once
	 * at confirmation time (see `PaymentConfirmationService::confirm_paid()`)
	 * and stored here rather than derived on every Entries tab render, since
	 * a checkout id doesn't equal its resulting order id in SureCart. Blank
	 * for rows that never reached `PAID`.
	 *
	 * @var string
	 */
	public string $order_id = '';

	/**
	 * One of `multibanco`, `mbway`, `pbl`.
	 *
	 * @var string
	 */
	public string $method = '';

	/**
	 * Amount charged, as a 2-decimal string.
	 *
	 * @var string
	 */
	public string $amount = '0.00';

	/**
	 * Multibanco entity (Multibanco only).
	 *
	 * @var string
	 */
	public string $entity = '';

	/**
	 * Multibanco reference (Multibanco only).
	 *
	 * @var string
	 */
	public string $reference = '';

	/**
	 * MB WAY phone number (MB WAY only).
	 *
	 * @var string
	 */
	public string $phone = '';

	/**
	 * Ifthenpay's own RequestId.
	 *
	 * @var string
	 */
	public string $request_id = '';

	/**
	 * The actual sub-method used to pay (e.g. `CCARD`, `APPLE`, `MBWAY`) —
	 * only ever populated for `pbl` rows, once the webhook echoes it back via
	 * `mtd` (see `Webhook\CallbackController::handle_pbl()`). Multibanco and
	 * MB WAY already know their method from `$this->method`, so this stays
	 * blank for those rows.
	 *
	 * @var string
	 */
	public string $mtd = '';

	/**
	 * The one-time ifthenpay Gateway payment link the customer can still open to
	 * pay (`pbl` only) — persisted so a PENDING row can still offer it after
	 * the checkout-time modal that originally opened it is long gone. See
	 * `PaymentInitiator::initiate_pbl()`.
	 *
	 * @var string
	 */
	public string $redirect_url = '';

	/**
	 * Multibanco reference expiry (MySQL datetime), if any.
	 *
	 * @var string|null
	 */
	public ?string $expires_at = null;

	/**
	 * One of `PENDING`, `PAID`, `FAILED`, `CANCELLED`.
	 *
	 * @var string
	 */
	public string $state = 'PENDING';

	/**
	 * Builds a DTO from a raw $wpdb row (object or array).
	 *
	 * @param array<string, mixed>|object $row Raw database row.
	 *
	 * @return self
	 */
	public static function from_row( $row ): self {
		$row = (array) $row;

		$dto               = new self();
		$dto->ref          = (string) ( $row['ref'] ?? '' );
		$dto->checkout_id  = (string) ( $row['checkout_id'] ?? '' );
		$dto->order_id     = (string) ( $row['order_id'] ?? '' );
		$dto->method       = (string) ( $row['method'] ?? '' );
		$dto->amount       = (string) ( $row['amount'] ?? '0.00' );
		$dto->entity       = (string) ( $row['entity'] ?? '' );
		$dto->reference    = (string) ( $row['reference'] ?? '' );
		$dto->phone        = (string) ( $row['phone'] ?? '' );
		$dto->request_id   = (string) ( $row['request_id'] ?? '' );
		$dto->mtd          = (string) ( $row['mtd'] ?? '' );
		$dto->redirect_url = (string) ( $row['redirect_url'] ?? '' );
		$dto->expires_at   = $row['expires_at'] ?? null;
		$dto->state        = (string) ( $row['state'] ?? 'PENDING' );

		return $dto;
	}
}
