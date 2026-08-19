<?php
/**
 * Local (offline) Multibanco reference generator for legacy Entity/Sub-entity accounts.
 *
 * @package Ifthenpay\SureCart\Api
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Computes Multibanco payment references entirely offline, per ifthenpay's
 * "Multibanco Offline Algorithm" — used only for legacy Entity/Sub-entity
 * accounts (`IfmbAccountsClient::classify()`'s `legacy_mb` type), which have
 * no "MB Key" and so can't use `IfthenpayClient::create_multibanco_reference()`'s
 * `api.ifthenpay.com/multibanco/reference/init` call. Unlike that modern API,
 * nothing is sent to ifthenpay to generate an offline reference — it's pure
 * local computation, the reference never expires, and it stays payable for
 * the exact amount it was generated for (the amount is itself part of the
 * check-digit calculation).
 */
final class MultibancoOfflineReferenceGenerator {

	/**
	 * Per-digit weights for the weighted mod-97 check-digit calculation,
	 * applied left to right across the 20-digit entity+subentity+id+amount
	 * string.
	 *
	 * @var int[]
	 */
	private const WEIGHTS = array( 51, 73, 17, 89, 38, 62, 45, 53, 15, 50, 5, 49, 34, 81, 76, 27, 90, 9, 30, 3 );

	/**
	 * Generates an offline Multibanco entity + reference pair.
	 *
	 * @param string $entity    5-digit entity code assigned by ifthenpay.
	 * @param string $subentity 3-digit sub-entity code assigned by ifthenpay.
	 * @param string $id_source Order/customer identifier the reference's 4-digit id segment is derived from — only its digits are used, right-truncated (or left-zero-padded) to exactly 4.
	 * @param float  $amount    Amount the reference will be payable for.
	 *
	 * @return array{entity: string, reference: string}|\WP_Error
	 */
	public static function generate( string $entity, string $subentity, string $id_source, float $amount ) {
		if ( 1 !== preg_match( '/^\d{5}$/', $entity ) || 1 !== preg_match( '/^\d{3}$/', $subentity ) ) {
			return new \WP_Error( 'iftp_sc_mb_legacy_invalid_account', __( 'Invalid Multibanco entity/sub-entity.', 'ifthenpay-payments-for-surecart' ) );
		}

		$id = self::four_digit_id( $id_source );


		$amount_cents = (string) (int) round( $amount * 100 );
		if ( strlen( $amount_cents ) > 8 ) {
			return new \WP_Error( 'iftp_sc_mb_legacy_amount_too_large', __( 'Amount is too large for an offline Multibanco reference.', 'ifthenpay-payments-for-surecart' ) );
		}
		$amount_cents = str_pad( $amount_cents, 8, '0', STR_PAD_LEFT );

		$digits = $entity . $subentity . $id . $amount_cents;

		$sum = 0;
		foreach ( self::WEIGHTS as $position => $weight ) {
			$sum += $weight * (int) $digits[ $position ];
		}

		$check_digits = str_pad( (string) ( 98 - ( $sum % 97 ) ), 2, '0', STR_PAD_LEFT );

		return array(
			'entity'    => $entity,
			'reference' => $subentity . $id . $check_digits,
		);
	}

	/**
	 * Derives the reference's 4-digit id segment: the source string's digits
	 * only, right-truncated (or left-zero-padded) to exactly 4 — per the
	 * algorithm spec ("use only the 4 rightmost digits"/"pad with zeros to
	 * the left" if fewer). Falls back to a hash of the source when it has no
	 * digits at all (e.g. a purely alphabetic checkout-id fragment), so a
	 * reference can still always be generated.
	 *
	 * @param string $source Order/customer identifier.
	 *
	 * @return string Exactly 4 digits.
	 */
	private static function four_digit_id( string $source ): string {
		$digits = preg_replace( '/\D+/', '', $source ) ?? '';
		if ( '' === $digits ) {
			$digits = (string) crc32( $source );
		}
		return str_pad( substr( $digits, -4 ), 4, '0', STR_PAD_LEFT );
	}
}
