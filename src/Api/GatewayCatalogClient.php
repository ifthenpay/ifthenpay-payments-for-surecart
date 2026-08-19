<?php
/**
 * ifthenpay Gateway method catalog client for the ifthenpay API.
 *
 * @package Ifthenpay\SureCart\Api
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the ifthenpay Gateway Keys available on a Backoffice Key and the
 * global method catalog — the two ingredients `SettingsPage` combines into
 * the Gateway Key dropdown and its methods table. Provisioned accounts per
 * Gateway Key come from a separate call, `IfmbAccountsClient`.
 */
class GatewayCatalogClient extends AbstractIfthenpayClient {

	private const API_BASE = 'https://api.ifthenpay.com';

	/**
	 * Option holding the cached `Entity => {label, icon_url}` lookup built by
	 * `refresh_available_methods_cache()`, so front-end rendering (order
	 * payment details) never has to call the ifthenpay API itself.
	 */
	public const METHODS_CACHE_OPTION = 'iftp_sc_gateway_methods_catalog';

	/**
	 * Fetches the full catalog of ifthenpay payment methods.
	 *
	 * @return array<int, array{Entity: string, Method: string, IsVisible: bool, Position: int, SmallImageUrl?: string}>|\WP_Error
	 */
	public function get_available_methods() {
		return $this->get( self::API_BASE . '/gateway/methods/available' );
	}

	/**
	 * Fetches the method catalog and caches a `{label, icon_url}` lookup keyed
	 * by uppercase entity code (e.g. `MBWAY`, `CCARD`) in `METHODS_CACHE_OPTION` —
	 * called once when a Backoffice Key is connected
	 * (`SettingsPage::handle_connect_backoffice()`) so the real ifthenpay
	 * brand icons are available for order-payment-details rendering without
	 * an API call on every page view.
	 *
	 * @return array<string, array{label: string, icon_url: string}>|\WP_Error
	 */
	public function refresh_available_methods_cache() {
		$methods = $this->get_available_methods();
		if ( is_wp_error( $methods ) ) {
			return $methods;
		}

		$catalog = array();
		foreach ( (array) $methods as $method ) {
			$entity = strtoupper( (string) ( $method['Entity'] ?? '' ) );
			if ( '' === $entity ) {
				continue;
			}
			$catalog[ $entity ] = array(
				'label'    => (string) ( $method['Method'] ?? $entity ),
				'icon_url' => (string) ( $method['SmallImageUrl'] ?? '' ),
			);
		}

		update_option( self::METHODS_CACHE_OPTION, $catalog, false );

		return $catalog;
	}

	/**
	 * Fetches the raw gateway rows for a Backoffice Key.
	 *
	 * @param string $backoffice_key The ifthenpay Backoffice Key.
	 * @param string $type           Gateway type tag. `Dinâmicas` is ifthenpay's own
	 *                               category for ifthenpay Gateway (dynamic) gateway keys —
	 *                               not a per-CMS-platform tag.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error Raw gateway rows for this Backoffice Key.
	 */
	public function get_gateway_keys( string $backoffice_key, string $type = 'Dinâmicas' ) {
		return $this->get(
			add_query_arg(
				array(

					'boKey' => $backoffice_key,
					'Type'  => $type,
				),
				self::API_BASE . '/gateway/get'
			)
		);
	}

}
