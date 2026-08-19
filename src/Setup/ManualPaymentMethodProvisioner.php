<?php
/**
 * Provisions ifthenpay's Manual Payment Methods in SureCart.
 *
 * @package Ifthenpay\SureCart\Setup
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Setup;

defined( 'ABSPATH' ) || exit;

use SureCart\Models\ManualPaymentMethod;

/**
 * Creates the three ifthenpay Manual Payment Methods (Multibanco, MB WAY,
 * ifthenpay Gateway) in SureCart on activation, so the merchant doesn't have to
 * build them by hand in the SureCart dashboard.
 *
 * SureCart's `Model::create()` is reached through its static facade
 * (`Model::__callStatic()` -> `new static() -> create()`), and requires
 * SureCart's own site-to-account API token (`sc_api_token`) to already be
 * configured. If it isn't, provisioning is deferred and retried on every
 * `admin_init` until it first succeeds; after that, this plugin's own
 * settings page re-verifies (and recreates if needed) that all three still
 * exist and are non-archived on SureCart's side — see `maybe_provision()`.
 */
class ManualPaymentMethodProvisioner {

	public const OPTION_METHODS  = 'iftp_sc_manual_methods';
	public const OPTION_DEFERRED = 'iftp_sc_provisioning_deferred';

	/**
	 * Raw method list from SureCart, memoized for the current request only.
	 * `live_method_ids()` and `get_all_statuses()` both need it, and on a
	 * single settings-page load they run on two separate instances —
	 * `Plugin::boot()`'s own instance (via `maybe_provision()` on
	 * `admin_init`) and `SettingsPage`'s independently-constructed one —
	 * which would otherwise each make their own identical API call. Static
	 * (not an instance property) precisely because those two callers don't
	 * share an instance.
	 *
	 * @var array<int, object>|\WP_Error|null
	 */
	private static $live_methods_cache = null;

	/**
	 * The three Manual Payment Methods provisioned on activation.
	 *
	 * @var array<string, array{name: string, description: string}>
	 */
	private const METHODS = array(
		'multibanco' => array(
			'name'        => 'Multibanco (ifthenpay)',
			'description' => 'Pay by Multibanco reference at any ATM or via home banking. Payment instructions are shown after checkout.',
		),
		'mbway'      => array(
			'name'        => 'MB WAY (ifthenpay)',
			'description' => 'Pay with the MB WAY app. You will be asked for your phone number and receive a payment request to approve.',
		),
		'pbl'        => array(
			'name'        => 'ifthenpay Gateway',
			'description' => 'Pay securely by card, Apple Pay, Google Pay and others through ifthenpay\'s hosted payment page.',
		),
	);

	/**
	 * Registers the provisioning hook.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_init', array( $this, 'maybe_provision' ) );
	}

	/**
	 * Attempts provisioning if it hasn't already succeeded. Re-verifies
	 * against SureCart's live list (only on our settings page, not every
	 * `admin_init`), since a merchant can archive/delete a method
	 * independently of this plugin; missing/archived entries are recreated.
	 *
	 * @return void
	 */
	public function maybe_provision(): void {
		$created = get_option( self::OPTION_METHODS, array() );
		$created = is_array( $created ) ? $created : array();

		$never_fully_provisioned = count( $created ) < count( self::METHODS );


		if ( ! $never_fully_provisioned && ! $this->should_verify_now() ) {
			return;
		}

		$live_ids = $this->live_method_ids();

		if ( is_wp_error( $live_ids ) ) {
			update_option( self::OPTION_DEFERRED, true, false );
			return;
		}

		$missing = array();
		foreach ( self::METHODS as $key => $config ) {
			if ( empty( $created[ $key ] ) || ! in_array( $created[ $key ], $live_ids, true ) ) {
				$missing[ $key ] = $config;
			}
		}

		foreach ( $missing as $key => $config ) {
			$result = ManualPaymentMethod::create(
				array(
					'name'        => $config['name'],
					'description' => $config['description'],
					'archived'    => false,
					'reusable'    => false,
				)
			);

			if ( is_wp_error( $result ) || empty( $result->id ) ) {
				update_option( self::OPTION_DEFERRED, true, false );
				return;
			}

			$created[ $key ] = $result->id;
		}

		if ( ! empty( $missing ) ) {
			update_option( self::OPTION_METHODS, $created, false );
			set_transient( 'iftp_sc_provisioned_notice', true, DAY_IN_SECONDS );
		}

		if ( $this->patch_reusable_drift( $created, $missing ) ) {
			delete_option( self::OPTION_DEFERRED );
		} else {
			update_option( self::OPTION_DEFERRED, true, false );
		}
	}

	/**
	 * Only re-verify on our own settings page, so this never adds an API
	 * call to unrelated wp-admin page loads.
	 *
	 * @return bool
	 */
	private function should_verify_now(): bool {
		return isset( $_GET['page'] ) && 'iftp-sc-settings' === sanitize_text_field( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-context check, no state changes.
	}

	/**
	 * Fetches the ids of every manual payment method SureCart still has on
	 * file, archived or not — an archived method is intentionally disabled,
	 * not missing, so it must not be filtered out here or it gets recreated.
	 *
	 * @return array<int, string>|\WP_Error
	 */
	private function live_method_ids() {
		$live = $this->fetch_live_methods();
		if ( is_wp_error( $live ) ) {
			return $live;
		}

		$ids = array();
		foreach ( (array) $live as $method ) {
			if ( isset( $method->id ) ) {
				$ids[] = $method->id;
			}
		}

		return $ids;
	}

	/**
	 * Ensures every already-provisioned method (not just ones (re)created
	 * this run) is flagged `reusable => false` on SureCart's side.
	 * SureCart only offers `reusable` manual payment methods where a
	 * subscription requires one — e.g. its customer-dashboard "update
	 * payment method" screen. None of ifthenpay's methods can auto-renew a
	 * subscription, so this keeps them out of that picker. Merchants who
	 * installed this plugin before the flag existed would otherwise keep
	 * stale `reusable` records forever, since the checks above only
	 * recreate ids that are missing entirely.
	 *
	 * @param array<string, string> $created Map of method key to SureCart id.
	 * @param array<string, array>  $missing Keys (re)created this run; already correct, skipped here.
	 *
	 * @return bool False if any live lookup or update call failed, so the caller can retry later.
	 */
	private function patch_reusable_drift( array $created, array $missing ): bool {
		$live = $this->fetch_live_methods();
		if ( is_wp_error( $live ) ) {
			return false;
		}

		$by_id   = $this->index_live_methods_by_id( (array) $live );
		$success = true;

		foreach ( self::METHODS as $key => $config ) {
			if ( isset( $missing[ $key ] ) || empty( $created[ $key ] ) ) {
				continue;
			}

			$id     = $created[ $key ];
			$method = $by_id[ $id ] ?? null;

			if ( null === $method || false === ( $method->reusable ?? null ) ) {
				continue;
			}

			$result = ManualPaymentMethod::update(
				array(
					'id'       => $id,
					'reusable' => false,
				)
			);

			if ( is_wp_error( $result ) ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Fetches every tracked method's current `exists`/`archived` state
	 * straight from SureCart, for the Methods tab's activation checkboxes.
	 * A method can only be toggled active once it's genuinely provisioned —
	 * this is the authoritative check, not just "do we have an id on file".
	 *
	 * @return array<string, array{id: string, exists: bool, archived: bool}>|\WP_Error
	 */
	public function get_all_statuses() {
		$live = $this->fetch_live_methods();
		if ( is_wp_error( $live ) ) {
			return $live;
		}

		$by_id    = $this->index_live_methods_by_id( (array) $live );
		$created  = self::get_method_ids();
		$statuses = array();
		foreach ( self::METHODS as $key => $config ) {
			$id               = $created[ $key ] ?? '';
			$exists           = '' !== $id && isset( $by_id[ $id ] );
			$statuses[ $key ] = array(
				'id'       => $id,
				'exists'   => $exists,
				'archived' => $exists ? (bool) $by_id[ $id ]->archived : true,
			);
		}

		return $statuses;
	}

	/**
	 * Archives or unarchives a single tracked method on SureCart's side —
	 * this is how the Methods tab turns a payment method on/off at checkout
	 * without deleting its configured key or recreating the record.
	 *
	 * @param string $key      One of `multibanco`, `mbway`, `pbl`.
	 * @param bool   $archived True to archive (hide from checkout), false to unarchive.
	 *
	 * @return bool
	 */
	public function set_archived( string $key, bool $archived ): bool {
		$ids = self::get_method_ids();
		if ( empty( $ids[ $key ] ) ) {
			return false;
		}

		$result = ManualPaymentMethod::update(
			array(
				'id'       => $ids[ $key ],
				'archived' => $archived,
			)
		);

		return ! is_wp_error( $result );
	}

	/**
	 * Indexes a raw live-methods list (as returned by `fetch_live_methods()`)
	 * by SureCart id, for the O(1) lookups `get_all_statuses()` and
	 * `patch_reusable_drift()` both need.
	 *
	 * @param array<int, object> $live Raw method list from SureCart.
	 *
	 * @return array<string, object> Map of SureCart id to the live method object.
	 */
	private function index_live_methods_by_id( array $live ): array {
		$by_id = array();
		foreach ( $live as $method ) {
			if ( isset( $method->id ) ) {
				$by_id[ $method->id ] = $method;
			}
		}

		return $by_id;
	}

	/**
	 * Fetches every manual payment method SureCart has on file, memoized in
	 * `self::$live_methods_cache` for the current request — see that
	 * property's docblock for why this needs to be a static, cross-instance
	 * cache rather than a plain instance property.
	 *
	 * @return array<int, object>|\WP_Error
	 */
	private function fetch_live_methods() {
		if ( null === self::$live_methods_cache ) {
			self::$live_methods_cache = ManualPaymentMethod::where( array() )->get();
		}

		return self::$live_methods_cache;
	}

	/**
	 * Gets the map of our method key to the SureCart manual_payment_method id.
	 *
	 * @return array<string, string> Map of our method key (multibanco|mbway|pbl) to the SureCart manual_payment_method id.
	 */
	public static function get_method_ids(): array {
		$ids = get_option( self::OPTION_METHODS, array() );
		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * Gets the reverse map: SureCart manual_payment_method id to our method key.
	 *
	 * @return array<string, string> Reverse map: SureCart manual_payment_method id to our method key.
	 */
	public static function get_id_method_map(): array {
		return array_flip( self::get_method_ids() );
	}
}
