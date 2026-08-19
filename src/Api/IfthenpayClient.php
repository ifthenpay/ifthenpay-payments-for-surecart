<?php
/**
 * HTTP client for the ifthenpay API.
 *
 * @package Ifthenpay\SureCart\Api
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Api;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for the three ifthenpay endpoints this plugin drives directly:
 * Multibanco reference generation, MB WAY payment requests, and ifthenpay Gateway.
 *
 * Every method returns an array on success or a WP_Error on failure — never
 * throws — so callers can branch without try/catch.
 */
class IfthenpayClient extends AbstractIfthenpayClient {

	private const MULTIBANCO_ENDPOINT          = 'https://api.ifthenpay.com/multibanco/reference/init';
	private const MBWAY_ENDPOINT               = 'https://api.ifthenpay.com/spg/payment/mbway';
	private const MBWAY_STATUS_ENDPOINT        = 'https://api.ifthenpay.com/spg/payment/mbway/status';
	private const PBL_ENDPOINT                 = 'https://api.ifthenpay.com/gateway/pinpay/';
	private const CALLBACK_ACTIVATION_ENDPOINT = 'https://ifthenpay.com/api/endpoint/callback/activation/';

	/**
	 * Requests a Multibanco payment reference.
	 *
	 * @param string                                                             $mb_key      The merchant's MB Key.
	 * @param string                                                             $order_id    Our local payment ref, sent as `orderId`.
	 * @param float                                                              $amount      Amount to charge.
	 * @param string                                                             $description Description shown to the payer (max ~200 chars).
	 * @param int                                                                $expiry_days Days until the reference expires (0 = use ifthenpay default).
	 * @param array{url?: string, name?: string, email?: string, phone?: string} $client Optional client details (`url`, `name` -> `clientName`,
	 *                                                             `email` -> `clientEmail`, `phone` -> `clientPhone`). ifthenpay's API also
	 *                                                             accepts `clientCode`/`clientUsername`, which this plugin has no natural
	 *                                                             source for in a SureCart checkout and so never sends.
	 *
	 * @return array{Entity: string, Reference: string, RequestId: string, ExpiryDate?: string}|\WP_Error
	 */
	public function create_multibanco_reference( string $mb_key, string $order_id, float $amount, string $description, int $expiry_days = 0, array $client = array() ) {
		$body = array(
			'mbKey'       => $mb_key,
			'orderId'     => $order_id,
			'amount'      => IfthenpayHelper::format_amount( $amount ),
			'description' => substr( $description, 0, 200 ),
		);

		if ( $expiry_days > 0 ) {
			$body['expiryDays'] = $expiry_days;
		}

		if ( ! empty( $client['url'] ) ) {
			$body['url'] = $client['url'];
		}
		if ( ! empty( $client['name'] ) ) {
			$body['clientName'] = $client['name'];
		}
		if ( ! empty( $client['email'] ) ) {
			$body['clientEmail'] = $client['email'];
		}
		if ( ! empty( $client['phone'] ) ) {
			$body['clientPhone'] = $client['phone'];
		}

		$response = $this->post( self::MULTIBANCO_ENDPOINT, $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['Status'] ) || '0' !== (string) $response['Status'] ) {
			return new \WP_Error(
				'iftp_sc_multibanco_failed',
				$response['Message'] ?? __( 'ifthenpay could not generate a Multibanco reference.', 'ifthenpay-payments-for-surecart' )
			);
		}

		return $response;
	}

	/**
	 * Sends an MB WAY payment request to the customer's phone.
	 *
	 * @param string $mbway_key   The merchant's MB WAY Key.
	 * @param string $order_id    Our local payment ref, sent as `orderId`.
	 * @param float  $amount      Amount to charge.
	 * @param string $phone       Normalized `351#9XXXXXXXX` phone number.
	 * @param string $description Description shown in the MB WAY app (max 70 chars).
	 * @param string $email       Customer email, if known. Optional.
	 *
	 * @return array{RequestId: string, Amount: string}|\WP_Error
	 */
	public function create_mb_way_payment( string $mbway_key, string $order_id, float $amount, string $phone, string $description, string $email = '' ) {
		$body = array(
			'mbWayKey'     => $mbway_key,
			'orderId'      => $order_id,
			'amount'       => IfthenpayHelper::format_amount( $amount ),
			'mobileNumber' => $phone,
			'email'        => $email,
			'description'  => substr( $description, 0, 70 ),
		);

		$response = $this->post( self::MBWAY_ENDPOINT, $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = isset( $response['Status'] ) ? (string) $response['Status'] : '';

		if ( '999' === $status ) {
			return new \WP_Error(
				'iftp_sc_mbway_not_subscriber',
				__( 'This phone number is not registered with MB WAY.', 'ifthenpay-payments-for-surecart' )
			);
		}

		if ( '000' !== $status ) {
			return new \WP_Error(
				'iftp_sc_mbway_failed',
				$response['Message'] ?? __( 'ifthenpay could not send the MB WAY payment request.', 'ifthenpay-payments-for-surecart' )
			);
		}

		return $response;
	}

	/**
	 * Actively checks an MB WAY payment request's current status, used by
	 * `StatusController` so confirmation doesn't rely solely on the
	 * notify-URL callback. Request shape carried over from this
	 * integration's other ifthenpay plugins, not independently re-verified.
	 *
	 * @param string $mbway_key The merchant's MB WAY Key.
	 * @param string $ref       Our local payment ref, sent as `referencia`.
	 * @param float  $amount    Amount charged.
	 * @param string $phone     Phone number as stored (any formatting), sent as `nrtlm`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function check_mb_way_status( string $mbway_key, string $ref, float $amount, string $phone ) {
		$body = array(
			'MbWayKey'   => $mbway_key,
			'canal'      => '03',
			'referencia' => $ref,
			'valor'      => IfthenpayHelper::format_amount( $amount ),
			'nrtlm'      => preg_replace( '/\D+/', '', $phone ) ?? '',
			'email'      => '',
			'descricao'  => '',
		);

		return $this->post( self::MBWAY_STATUS_ENDPOINT, $body );
	}

	/**
	 * Creates a ifthenpay Gateway session (cards, Apple Pay, Google Pay).
	 *
	 * @param string $gateway_key     Gateway Key for this integration.
	 * @param string $order_id       Our local payment ref, sent as `id`.
	 * @param float  $amount         Amount to charge.
	 * @param string $description    Description shown on the hosted payment page.
	 * @param string $accounts       `"ENTITY|ACCOUNT;ENTITY2|ACCOUNT2"` accounts string.
	 * @param string $success_url    Must contain the literal `[TRANSACTIONID]` placeholder.
	 * @param string $error_url      Must contain the literal `[TRANSACTIONID]` placeholder.
	 * @param string $cancel_url     Must contain the literal `[TRANSACTIONID]` placeholder.
	 * @param string $lang           `pt`, `es`, `fr`, or `en`.
	 * @param int    $selected_method Catalog `Position` of the method to pre-select, 0 to omit.
	 *
	 * @return array{redirect_url?: string, RedirectUrl?: string}|\WP_Error
	 */
	public function create_pay_by_link(
		string $gateway_key,
		string $order_id,
		float $amount,
		string $description,
		string $accounts,
		string $success_url,
		string $error_url,
		string $cancel_url,
		string $lang = 'en',
		int $selected_method = 0
	) {
		$body = array(
			'id'          => $order_id,
			'amount'      => IfthenpayHelper::format_amount( $amount ),
			'description' => $description,
			'accounts'    => $accounts,
			'success_url' => $success_url,
			'error_url'   => $error_url,
			'cancel_url'  => $cancel_url,
			'otp'         => 'true',
			'lang'        => $lang,
		);

		if ( $selected_method > 0 ) {
			$body['selected_method'] = $selected_method;
		}

		$response = $this->post( self::PBL_ENDPOINT . $gateway_key, $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$redirect_url = $response['redirect_url'] ?? $response['RedirectUrl'] ?? '';
		if ( '' === $redirect_url ) {
			return new \WP_Error(
				'iftp_sc_pbl_failed',
				$response['Message'] ?? __( 'ifthenpay could not create a ifthenpay Gateway session.', 'ifthenpay-payments-for-surecart' )
			);
		}

		return $response;
	}

	/**
	 * Registers a notify-URL/webhook callback with ifthenpay for a given key
	 * (Gateway Key, MB Key, or MB WAY Key — the endpoint is generic), so
	 * confirmations are pushed to `$notify_url_template` automatically instead
	 * of requiring a manual support request. Mirrors
	 * `ifthenpay-payments-for-contactform7`'s `IfthenpayClient::activate_callback()`.
	 *
	 * @param string $key                  The key to register the callback against.
	 * @param string $notify_url_template  Notify URL, including ifthenpay's literal `[PLACEHOLDER]`s.
	 * @param string $secret               The shared per-site secret to register as `apKey`.
	 *
	 * @return bool
	 */
	public function activate_callback( string $key, string $notify_url_template, string $secret ): bool {
		$response = $this->post(
			self::CALLBACK_ACTIVATION_ENDPOINT . '?cms=surecart',
			array(
				'apKey' => $secret,
				'chave' => $key,
				'urlCb' => $notify_url_template,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 'OK' === (string) ( $response['data'] ?? '' );
	}

}
