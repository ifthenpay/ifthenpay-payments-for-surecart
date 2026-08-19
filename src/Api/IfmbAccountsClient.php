<?php
/**
 * Client for ifthenpay's Backoffice account list (Multibanco/MB WAY).
 *
 * @package Ifthenpay\SureCart\Api
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the merchant's named Multibanco/MB WAY accounts from ifthenpay's
 * Backoffice (`GetAccounts`), so the settings screen can offer a dropdown of
 * real, named accounts instead of a free-text key field. A separate client
 * from `GatewayCatalogClient`/`IfthenpayClient` because this hits a different
 * host (`ifthenpay.com/IfmbWS/...`, not `api.ifthenpay.com`) with a different
 * response shape.
 */
class IfmbAccountsClient extends AbstractIfthenpayClient {

	private const ENDPOINT = 'https://ifthenpay.com/IfmbWS/ifthenpaymobile.asmx/GetAccounts';

	private const ENDPOINT_BY_GATEWAY_KEY = 'https://ifthenpay.com/IfmbWS/ifthenpaymobile.asmx/GetAccountsByGatewayKey';

	/**
	 * Fetches the accounts provisioned specifically on one ifthenpay Gateway
	 * Gateway Key, grouped by method entity — this is the ifthenpay Gateway tab's
	 * per-method account dropdown source, as opposed to
	 * `get_classified_accounts()` (whole-Backoffice-Key Multibanco/MB WAY
	 * accounts for their own standalone tabs).
	 *
	 * Rows come back shaped like `{Alias, Conta, Entidade, SubEntidade}`. A
	 * numeric `Entidade` (a raw Multibanco entity number, e.g. `"10143"`) is
	 * the same thing as `Entidade: "MB"` — both are Multibanco, just reported
	 * differently — so both get folded into the same `MB` bucket.
	 *
	 * @param string $backoffice_key The ifthenpay Backoffice Key.
	 * @param string $gateway_key    The ifthenpay Gateway Key.
	 *
	 * @return array<string, array<string,string>>|\WP_Error Entity => [Alias => Conta].
	 */
	public function get_accounts_by_gateway_key( string $backoffice_key, string $gateway_key ) {
		$rows = $this->get(
			self::ENDPOINT_BY_GATEWAY_KEY
			. '?backofficekey=' . rawurlencode( $backoffice_key )
			. '&gatewayKey=' . rawurlencode( $gateway_key )
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$alias    = (string) ( $row['Alias'] ?? '' );
			$conta    = (string) ( $row['Conta'] ?? '' );
			$entidade = (string) ( $row['Entidade'] ?? '' );
			if ( '' === $alias || '' === $conta || '' === $entidade ) {
				continue;
			}

			$entity                     = is_numeric( $entidade ) ? 'MB' : strtoupper( $entidade );
			$grouped[ $entity ][ $alias ] = $conta;
		}

		return $grouped;
	}

	/**
	 * Fetches and classifies every account visible on a Backoffice Key.
	 *
	 * @param string $backoffice_key The ifthenpay Backoffice Key.
	 *
	 * @return array{modern_mb: array<string,string>, legacy_mb: array<string,string>, mbway: array<string,string>}|\WP_Error
	 *         Each bucket maps the raw `Conta` string (dropdown value) to its `Alias` (dropdown label).
	 */
	public function get_classified_accounts( string $backoffice_key ) {
		$rows = $this->get( self::ENDPOINT . '?chavebackoffice=' . rawurlencode( $backoffice_key ) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$buckets = array(
			'modern_mb' => array(),
			'legacy_mb' => array(),
			'mbway'     => array(),
		);

		foreach ( $rows as $row ) {
			$conta = (string) ( $row['Conta'] ?? '' );
			$alias = (string) ( $row['Alias'] ?? $conta );

			$classified = self::classify( $conta );
			if ( null === $classified ) {
				continue;
			}

			$buckets[ $classified['type'] ][ $conta ] = $alias;
		}

		return $buckets;
	}

	/**
	 * Classifies a raw `Conta` string from `GetAccounts` — pure parsing, no
	 * HTTP — also used by the settings screen's submit handlers to re-derive
	 * the account type/key from the raw value posted back, so there is only
	 * one parsing rule instead of two that can drift apart.
	 *
	 * @return array{type: 'modern_mb', key: string}|array{type: 'legacy_mb', entity: string, subentity: string}|array{type: 'mbway', key: string}|null
	 *         `null` for account types this plugin doesn't support (e.g. Cofidis).
	 */
	public static function classify( string $conta ): ?array {
		$parts = explode( '|', $conta, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$left  = trim( $parts[0] );
		$right = trim( $parts[1] );
		if ( '' === $left || '' === $right ) {
			return null;
		}

		if ( 0 === strcasecmp( $left, 'MB' ) ) {
			return array(
				'type' => 'modern_mb',
				'key'  => $right,
			);
		}

		if ( 0 === strcasecmp( $left, 'MBWAY' ) ) {
			return array(
				'type' => 'mbway',
				'key'  => $right,
			);
		}

		if ( 1 === preg_match( '/^\d{5}$/', $left ) ) {
			return array(
				'type'      => 'legacy_mb',
				'entity'    => $left,
				'subentity' => $right,
			);
		}

		return null;
	}

}
