<?php
/**
 * Pending payment persistence.
 *
 * @package Ifthenpay\SureCart\Repository
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Repository;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Repository\DTO\PendingPayment;

/**
 * Persists the mapping between an ifthenpay payment reference and the
 * SureCart checkout it belongs to, so the webhook (which only knows the
 * ifthenpay reference) can find its way back to `Checkout::manuallyPay()`.
 */
class PendingPaymentRepository {

	/**
	 * Bumped whenever `install_table()`'s SQL changes shape, so
	 * `maybe_upgrade()` knows to re-run `dbDelta()` on already-active
	 * installs (activation only fires once, on first install).
	 */
	private const DB_VERSION = '4';

	/**
	 * Builds the fully-qualified table name.
	 *
	 * @return string Fully-qualified table name.
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ifthenpay_sc_payments';
	}

	/**
	 * Creates the table on plugin activation. Safe to call repeatedly —
	 * `dbDelta()` only adds what's missing, e.g. the `mtd` column added in
	 * `DB_VERSION` 2, or `redirect_url` added in `DB_VERSION` 4.
	 *
	 * @return void
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $this->table();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
			ref VARCHAR(100) NOT NULL,
			checkout_id VARCHAR(100) NOT NULL,
			order_id VARCHAR(100) NOT NULL DEFAULT '',
			method VARCHAR(20) NOT NULL DEFAULT '',
			amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
			entity VARCHAR(20) NOT NULL DEFAULT '',
			reference VARCHAR(20) NOT NULL DEFAULT '',
			phone VARCHAR(20) NOT NULL DEFAULT '',
			request_id VARCHAR(50) NOT NULL DEFAULT '',
			mtd VARCHAR(20) NOT NULL DEFAULT '',
			redirect_url VARCHAR(500) NOT NULL DEFAULT '',
			expires_at DATETIME NULL DEFAULT NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'PENDING',
			PRIMARY KEY  (id),
			UNIQUE KEY ref (ref),
			KEY checkout_id (checkout_id),
			KEY state (state)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'iftp_sc_db_version', self::DB_VERSION, false );
	}

	/**
	 * Re-runs `install_table()` when the stored schema version is behind —
	 * `register_activation_hook()` only fires on first install, so a site
	 * that installed before `DB_VERSION` 2 would otherwise never get the
	 * `mtd` column added by a plugin update.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( self::DB_VERSION === get_option( 'iftp_sc_db_version', '' ) ) {
			return;
		}

		$this->install_table();
	}

	/**
	 * Inserts a new pending payment row. Upserts on `ref` — `ref` reuses
	 * SureCart's own order number verbatim (see
	 * `IfthenpayHelper::generate_ref()`), so a ifthenpay Gateway retry on the same
	 * checkout deliberately generates a fresh session with the identical
	 * `ref` (see `PaymentModalController`'s retry path). A plain
	 * `$wpdb->insert()` would silently fail on the `UNIQUE KEY ref` in that
	 * case and drop the retry's data, so a duplicate-key failure falls back
	 * to refreshing the existing row instead.
	 *
	 * @param array<string, mixed> $data Column values.
	 *
	 * @return bool
	 */
	public function insert( array $data ): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$columns = array(
			'ref'          => $data['ref'],
			'checkout_id'  => $data['checkout_id'],
			'method'       => $data['method'],
			'amount'       => $data['amount'],
			'entity'       => $data['entity'] ?? '',
			'reference'    => $data['reference'] ?? '',
			'phone'        => $data['phone'] ?? '',
			'request_id'   => $data['request_id'] ?? '',
			'mtd'          => $data['mtd'] ?? '',
			'redirect_url' => $data['redirect_url'] ?? '',
			'expires_at'   => $data['expires_at'] ?? null,
			'state'        => $data['state'] ?? 'PENDING',
		);

		$insert_data = array_merge( array( 'created_at' => $now, 'updated_at' => $now ), $columns );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- no caching layer applies to this plugin-owned table.
		$result = $wpdb->insert( $this->table(), $insert_data, array_fill( 0, count( $insert_data ), '%s' ) );

		if ( false !== $result ) {
			return true;
		}

		if ( false === strpos( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
			return false;
		}

		$update_data = array_merge( array( 'updated_at' => $now ), array_diff_key( $columns, array( 'ref' => null ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- upsert fallback on a plugin-owned table, only reached on a `ref` duplicate-key conflict.
		$updated = $wpdb->update(
			$this->table(),
			$update_data,
			array( 'ref' => $data['ref'] ),
			array_fill( 0, count( $update_data ), '%s' ),
			array( '%s' )
		);

		return false !== $updated;
	}

	/**
	 * Finds a pending payment by its ifthenpay reference. `ref` is enforced
	 * unique via `insert()`'s upsert (see its docblock), so this only ever
	 * matches one row — `ORDER BY id DESC LIMIT 1` is just defensive.
	 *
	 * @param string $ref ifthenpay payment reference.
	 *
	 * @return PendingPayment|null
	 */
	public function find_by_ref( string $ref ): ?PendingPayment {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row lookup on a plugin-owned table, prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE ref = %s ORDER BY id DESC LIMIT 1', $this->table(), $ref )
		);

		return $row ? PendingPayment::from_row( $row ) : null;
	}

	/**
	 * Finds the most recent pending payment for a given checkout.
	 *
	 * @param string $checkout_id SureCart checkout id.
	 *
	 * @return PendingPayment|null
	 */
	public function find_latest_by_checkout_id( string $checkout_id ): ?PendingPayment {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row lookup on a plugin-owned table, prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE checkout_id = %s ORDER BY id DESC LIMIT 1',
				$this->table(),
				$checkout_id
			)
		);

		return $row ? PendingPayment::from_row( $row ) : null;
	}

	/**
	 * Atomically claims a row for `PAID` confirmation — only the caller whose
	 * `UPDATE` actually flips a non-`PAID` row returns `true`. Used so that
	 * the ifthenpay webhook (`CallbackController`) and MB WAY's active
	 * status poll (`StatusController`) — which can both reach
	 * `PaymentConfirmationService::confirm_paid()` for the same payment at
	 * nearly the same time — can't both win a plain check-then-write race
	 * and both call `Checkout::manuallyPay()` for the same order.
	 *
	 * @param string $ref ifthenpay payment reference.
	 *
	 * @return bool True if this call won the claim, false if the row was
	 *              already `PAID` (or didn't exist).
	 */
	public function claim_paid( string $ref ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- conditional update on a plugin-owned table, prepared; the `state != 'PAID'` guard is what makes this an atomic claim.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET state = 'PAID', updated_at = %s WHERE ref = %s AND state != 'PAID'",
				$this->table(),
				current_time( 'mysql', true ),
				$ref
			)
		);

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Updates the state (and optional extra columns) of an existing row.
	 *
	 * @param string               $ref   ifthenpay payment reference.
	 * @param string               $state New state.
	 * @param array<string, mixed> $extra Additional columns to update alongside state.
	 *
	 * @return bool
	 */
	public function update_state( string $ref, string $state, array $extra = array() ): bool {
		global $wpdb;

		$data    = array_merge(
			array(
				'state'      => $state,
				'updated_at' => current_time( 'mysql', true ),
			),
			$extra
		);
		$formats = array_fill( 0, count( $data ), '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no caching layer applies to this plugin-owned table.
		$result = $wpdb->update( $this->table(), $data, array( 'ref' => $ref ), $formats, array( '%s' ) );

		return false !== $result;
	}

	/**
	 * Resolves a Method dropdown filter value (see
	 * `SettingsPage::entry_method_labels()`) into a boolean SQL expression +
	 * bound params. Values are either a raw `method` column value
	 * (`multibanco`, `mbway`), a `pbl` sub-method (`pbl_ccard`, `pbl_apple`,
	 * `pbl_google`, `pbl_cofidis`, `pbl_pix`), or the bare `pbl` bucket for
	 * `pbl` rows whose sub-method isn't resolved yet — `mtd` is only ever
	 * stamped once the webhook confirms it (see
	 * `Webhook\CallbackController::handle_pbl()`), so an empty `mtd` means
	 * still pending. `multibanco`/`mbway` additionally match a `pbl` row
	 * that resolved to that same real-world method (`mtd` `MB`/`MBWAY`) —
	 * from the merchant's perspective it's the same payment method
	 * regardless of which internal channel it came through.
	 *
	 * @param string $method_filter Raw filter value, `''` for none.
	 *
	 * @return array{0: string, 1: array<int, string>} `['' if no filter, else a parenthesised boolean expression, bound params]`.
	 */
	private function method_filter_condition( string $method_filter ): array {
		if ( '' === $method_filter ) {
			return array( '', array() );
		}

		if ( 'multibanco' === $method_filter ) {
			return array( "(method = %s OR (method = 'pbl' AND mtd = %s))", array( 'multibanco', 'MB' ) );
		}

		if ( 'mbway' === $method_filter ) {
			return array( "(method = %s OR (method = 'pbl' AND mtd = %s))", array( 'mbway', 'MBWAY' ) );
		}

		if ( 'pbl' === $method_filter ) {
			return array( "(method = %s AND mtd = '')", array( 'pbl' ) );
		}

		if ( 0 === strpos( $method_filter, 'pbl_' ) ) {
			return array( '(method = %s AND mtd = %s)', array( 'pbl', strtoupper( substr( $method_filter, 4 ) ) ) );
		}

		return array( '', array() );
	}

	/**
	 * Builds the `WHERE` clause + bound params shared by `find_all()`/`count()`.
	 * `$search` matches across the Payment ID, Request ID, phone and method
	 * columns — the fields the Entries tab's search box is documented to
	 * cover.
	 *
	 * @param string $state_filter  Exact state to filter by (e.g. `PAID`), `''` for none.
	 * @param string $method_filter Method dropdown value — see `method_filter_condition()`. `''` for none.
	 * @param string $search        Free-text search, `''` for none.
	 *
	 * @return array{0: string, 1: array<int, string>} `[WHERE clause SQL (or '' if no filter is active), bound params in placeholder order]`.
	 */
	private function build_filter_clause( string $state_filter, string $method_filter, string $search ): array {
		global $wpdb;

		$conditions = array();
		$params     = array();

		if ( '' !== $state_filter ) {
			$conditions[] = 'state = %s';
			$params[]     = $state_filter;
		}

		list( $method_condition, $method_params ) = $this->method_filter_condition( $method_filter );
		if ( '' !== $method_condition ) {
			$conditions[] = $method_condition;
			array_push( $params, ...$method_params );
		}

		if ( '' !== $search ) {
			$like         = '%' . $wpdb->esc_like( $search ) . '%';
			$conditions[] = '(ref LIKE %s OR request_id LIKE %s OR phone LIKE %s OR method LIKE %s)';
			array_push( $params, $like, $like, $like, $like );
		}

		if ( empty( $conditions ) ) {
			return array( '', array() );
		}

		return array( 'WHERE ' . implode( ' AND ', $conditions ), $params );
	}

	/**
	 * Returns a page of payment rows for the Entries tab, most recent first.
	 *
	 * @param int    $per_page      Rows per page.
	 * @param int    $offset        Row offset.
	 * @param string $state_filter  Optional exact state to filter by (e.g. `PAID`). Empty string means no filter.
	 * @param string $method_filter Optional exact method to filter by (`multibanco`/`mbway`/`pbl`). Empty string means no filter.
	 * @param string $search        Optional free-text search across Payment ID/Request ID/phone/method. Empty string means no filter.
	 *
	 * @return PendingPayment[]
	 */
	public function find_all( int $per_page, int $offset, string $state_filter = '', string $method_filter = '', string $search = '' ): array {
		global $wpdb;

		list( $where, $where_params ) = $this->build_filter_clause( $state_filter, $method_filter, $search );

		$sql    = "SELECT * FROM %i {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params = array_merge( array( $this->table() ), $where_params, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- listing query on a plugin-owned table; `$sql` is passed through `$wpdb->prepare()` immediately below, and `$where` only ever interpolates hardcoded `column = %s`-style fragments from `build_filter_clause()`, never user input directly.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		return array_map( array( PendingPayment::class, 'from_row' ), $rows ? $rows : array() );
	}

	/**
	 * Counts payment rows for the Entries tab's pagination.
	 *
	 * @param string $state_filter  Optional exact state to filter by. Empty string means no filter.
	 * @param string $method_filter Optional exact method to filter by (`multibanco`/`mbway`/`pbl`). Empty string means no filter.
	 * @param string $search        Optional free-text search across Payment ID/Request ID/phone/method. Empty string means no filter.
	 *
	 * @return int
	 */
	public function count( string $state_filter = '', string $method_filter = '', string $search = '' ): int {
		global $wpdb;

		list( $where, $where_params ) = $this->build_filter_clause( $state_filter, $method_filter, $search );

		$sql    = "SELECT COUNT(*) FROM %i {$where}";
		$params = array_merge( array( $this->table() ), $where_params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- count query on a plugin-owned table; `$sql` is passed through `$wpdb->prepare()` immediately below, and `$where` only ever interpolates hardcoded `column = %s`-style fragments from `build_filter_clause()`, never user input directly.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Marks ifthenpay Gateway rows still `PENDING` after `$expire_days` as
	 * `EXPIRED` — mirrors `ifthenpay-payments-for-contactform7`'s
	 * `EntryRepository::mark_expired_pending()`. Run daily by
	 * `Setup\ExpiredPaymentsCron`.
	 *
	 * @param int $expire_days Days after creation a pending PBL row is considered expired.
	 *
	 * @return int Number of rows marked expired.
	 */
	public function mark_expired_pending( int $expire_days ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $expire_days ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk update on a plugin-owned table, prepared.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET state = 'EXPIRED', updated_at = %s WHERE method = 'pbl' AND state = 'PENDING' AND created_at < %s",
				$this->table(),
				current_time( 'mysql', true ),
				$cutoff
			)
		);

		return false === $updated ? 0 : (int) $updated;
	}

	/**
	 * Marks Multibanco rows still `PENDING` as `EXPIRED` — once ifthenpay's
	 * own `expires_at` (parsed from the reference's `ExpiryDate`) has passed,
	 * or, for a row with no `expires_at` (ifthenpay didn't return one, or the
	 * row predates `expiryDays` being configurable), after
	 * `$fallback_expire_days` since creation. Run daily by
	 * `Setup\ExpiredPaymentsCron`, alongside `mark_expired_pending()`.
	 *
	 * @param int $fallback_expire_days Days after creation to expire a row with no `expires_at`.
	 *
	 * @return int Number of rows marked expired.
	 */
	public function mark_expired_multibanco_pending( int $fallback_expire_days ): int {
		global $wpdb;

		$now    = current_time( 'mysql', true );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $fallback_expire_days ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk update on a plugin-owned table, prepared.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET state = 'EXPIRED', updated_at = %s
					WHERE method = 'multibanco' AND state = 'PENDING'
					AND ( ( expires_at IS NOT NULL AND expires_at < %s ) OR ( expires_at IS NULL AND created_at < %s ) )",
				$this->table(),
				$now,
				$now,
				$cutoff
			)
		);

		return false === $updated ? 0 : (int) $updated;
	}
}
