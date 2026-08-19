<?php
/**
 * Shared HTTP request/response handling for the ifthenpay API clients.
 *
 * @package Ifthenpay\SureCart\Api
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Api;

defined( 'ABSPATH' ) || exit;

/**
 * `IfthenpayClient`, `IfmbAccountsClient`, and `GatewayCatalogClient` each hit
 * a different ifthenpay host with a different response shape (see their own
 * docblocks for why they stay separate classes), but all three need the same
 * GET/POST + JSON-decode + transport-error handling — kept here once instead
 * of duplicated three times.
 */
abstract class AbstractIfthenpayClient {

	/**
	 * GET request + JSON decode + transport-error handling.
	 *
	 * @param string $url Request URL.
	 *
	 * @return array<int|string, mixed>|\WP_Error
	 */
	protected function get( string $url ) {
		return $this->decode( wp_remote_get( $url, array( 'timeout' => 20 ) ) );
	}

	/**
	 * POST request + JSON decode + transport-error handling.
	 *
	 * @param string               $url  Endpoint URL.
	 * @param array<string, mixed> $body Request body, JSON-encoded.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function post( string $url, array $body ) {
		return $this->decode(
			wp_remote_post(
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'    => wp_json_encode( $body ),
					'timeout' => 20,
				)
			)
		);
	}

	/**
	 * Shared HTTP-status + JSON-decode handling for both verbs above.
	 *
	 * @param array<string, mixed>|\WP_Error $response Result of `wp_remote_get()`/`wp_remote_post()`.
	 *
	 * @return array<int|string, mixed>|\WP_Error
	 */
	private function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'iftp_sc_http_error', sprintf( 'ifthenpay API returned HTTP %d.', $code ) );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'iftp_sc_bad_response', __( 'ifthenpay returned an unexpected response.', 'ifthenpay-payments-for-surecart' ) );
		}

		return $decoded;
	}
}
