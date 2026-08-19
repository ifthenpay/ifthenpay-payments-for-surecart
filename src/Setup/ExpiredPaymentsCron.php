<?php
/**
 * Daily cleanup of stale pending ifthenpay Gateway and Multibanco payments.
 *
 * @package Ifthenpay\SureCart\Setup
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Setup;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Repository\PendingPaymentRepository;

/**
 * ifthenpay Gateway sessions the customer never completes stay `PENDING` forever
 * otherwise — this marks them `EXPIRED` after the "Expiry days" setting on
 * the ifthenpay Gateway tab, mirroring `ifthenpay-payments-for-contactform7`'s
 * `Plugin.php` → `EntryRepository::mark_expired_pending()` cron job.
 * Multibanco references get the same treatment, but against ifthenpay's own
 * per-reference `expires_at` where known, falling back to days-since-created
 * only when it isn't (see
 * `PendingPaymentRepository::mark_expired_multibanco_pending()`).
 */
class ExpiredPaymentsCron {

	private const HOOK = 'iftp_sc_expire_pending_pbl';

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
	 * Registers the cron hook and schedules the recurring event if needed.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( self::HOOK, array( $this, 'run' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * Marks stale pending ifthenpay Gateway and Multibanco rows as expired.
	 *
	 * @return void
	 */
	public function run(): void {
		$pbl_expire_days = max( 1, (int) get_option( 'iftp_sc_pbl_expire_days', 3 ) );
		$this->repository->mark_expired_pending( $pbl_expire_days );

		$mb_expire_days = max( 1, (int) get_option( 'iftp_sc_mb_expire_days', 3 ) );
		$this->repository->mark_expired_multibanco_pending( $mb_expire_days );
	}

	/**
	 * Clears the scheduled event on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}
}
