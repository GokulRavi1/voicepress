<?php
/**
 * VoicePress database layer: the call log and captured carts.
 *
 * Custom tables created with dbDelta. Queries use portable SQL only (works on MySQL/MariaDB and on the
 * SQLite Database Integration drop-in): no MySQL-only functions, date maths done in PHP.
 *
 * @package VoicePress
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Custom tables: table names come from $wpdb->prefix, every value is prepared.

/**
 * Static data access for {prefix}voicepress_calls and {prefix}voicepress_carts.
 */
class VoicePress_DB {

	const DB_VERSION     = '1';
	const VERSION_OPTION = 'voicepress_db_version';

	/**
	 * Calls columns: name => [ type (str|json|int|ref|float|dt), max characters (0 = no limit), nullable ].
	 *
	 * @var array
	 */
	private static $call_columns = array(
		'execution_id'    => array( 'str', 64, true ),
		'use_case'        => array( 'str', 32, false ),
		'direction'       => array( 'str', 16, false ),
		'agent_id'        => array( 'str', 64, true ),
		'order_id'        => array( 'ref', 0, true ),
		'cart_id'         => array( 'ref', 0, true ),
		'customer_name'   => array( 'str', 191, true ),
		'phone'           => array( 'str', 32, true ),
		'status'          => array( 'str', 32, false ),
		'outcome'         => array( 'str', 64, true ),
		'outcome_applied' => array( 'int', 0, false ),
		'rating'          => array( 'int', 0, true ),
		'summary'         => array( 'str', 16000, true ),
		'transcript'      => array( 'str', 0, true ),
		'recording_url'   => array( 'str', 16000, true ),
		'duration'        => array( 'int', 0, true ),
		'cost'            => array( 'float', 0, true ),
		'extracted'       => array( 'json', 0, true ),
		'user_data'       => array( 'json', 0, true ),
		'attempt'         => array( 'int', 0, false ),
		'error'           => array( 'str', 16000, true ),
		'scheduled_at'    => array( 'dt', 0, true ),
		'created_at'      => array( 'dt', 0, false ),
		'updated_at'      => array( 'dt', 0, false ),
	);

	/**
	 * Carts columns (same format as $call_columns).
	 *
	 * @var array
	 */
	private static $cart_columns = array(
		'session_key'   => array( 'str', 191, false ),
		'customer_name' => array( 'str', 191, true ),
		'phone'         => array( 'str', 32, true ),
		'email'         => array( 'str', 191, true ),
		'cart'          => array( 'json', 0, true ),
		'cart_total'    => array( 'float', 0, true ),
		'currency'      => array( 'str', 8, true ),
		'status'        => array( 'str', 20, false ),
		'order_id'      => array( 'ref', 0, true ),
		'call_id'       => array( 'ref', 0, true ),
		'created_at'    => array( 'dt', 0, false ),
		'updated_at'    => array( 'dt', 0, false ),
	);

	/* ------------------------------------------------------------------ Schema */

	/**
	 * Calls table name.
	 *
	 * @return string
	 */
	public static function calls_table() {
		global $wpdb;
		return $wpdb->prefix . 'voicepress_calls';
	}

	/**
	 * Carts table name.
	 *
	 * @return string
	 */
	public static function carts_table() {
		global $wpdb;
		return $wpdb->prefix . 'voicepress_carts';
	}

	/**
	 * Create/upgrade the tables (dbDelta) and record the schema version.
	 *
	 * @return bool True when both tables exist afterwards.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$calls           = self::calls_table();
		$carts           = self::carts_table();

		$sql = "CREATE TABLE {$calls} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
execution_id varchar(64) NULL,
use_case varchar(32) NOT NULL DEFAULT '',
direction varchar(16) NOT NULL DEFAULT 'outbound',
agent_id varchar(64) NULL,
order_id bigint(20) unsigned NULL,
cart_id bigint(20) unsigned NULL,
customer_name varchar(191) NULL,
phone varchar(32) NULL,
status varchar(32) NOT NULL DEFAULT 'scheduled',
outcome varchar(64) NULL,
outcome_applied tinyint(1) NOT NULL DEFAULT 0,
rating tinyint(4) NULL,
summary text NULL,
transcript longtext NULL,
recording_url text NULL,
duration int(11) NULL,
cost decimal(10,4) NULL,
extracted longtext NULL,
user_data longtext NULL,
attempt int(11) NOT NULL DEFAULT 1,
error text NULL,
scheduled_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY execution_id (execution_id),
KEY order_id (order_id),
KEY status (status)
) {$charset_collate};
CREATE TABLE {$carts} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
session_key varchar(191) NOT NULL DEFAULT '',
customer_name varchar(191) NULL,
phone varchar(32) NULL,
email varchar(191) NULL,
cart longtext NULL,
cart_total decimal(12,2) NULL,
currency varchar(8) NULL,
status varchar(20) NOT NULL DEFAULT 'captured',
order_id bigint(20) unsigned NULL,
call_id bigint(20) unsigned NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY session_key (session_key),
KEY status (status)
) {$charset_collate};";

		dbDelta( $sql );

		if ( self::tables_exist() ) {
			update_option( self::VERSION_OPTION, self::DB_VERSION );
			delete_transient( 'voicepress_db_install_failed' );
			return true;
		}

		// Do not retry dbDelta on every request when something is wrong; try again in 5 minutes.
		set_transient( 'voicepress_db_install_failed', 1, 5 * MINUTE_IN_SECONDS );
		if ( class_exists( 'VoicePress_Helpers' ) ) {
			VoicePress_Helpers::log( 'Database install failed', array( 'error' => $wpdb->last_error ) );
		}
		return false;
	}

	/**
	 * Install when the stored schema version differs from DB_VERSION.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}
		if ( get_transient( 'voicepress_db_install_failed' ) ) {
			return;
		}
		self::install();
	}

	/* ------------------------------------------------------------------- Calls */

	/**
	 * Insert a call row. Sets created_at/updated_at (unless given); arrays in user_data/extracted are JSON-encoded.
	 *
	 * @param array $d Column => value.
	 * @return int New row id, 0 on failure.
	 */
	public static function insert_call( array $d ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		unset( $d['id'] );
		$d['created_at'] = ( isset( $d['created_at'] ) ? self::to_datetime( $d['created_at'] ) : null ) ?: $now;
		$d['updated_at'] = ( isset( $d['updated_at'] ) ? self::to_datetime( $d['updated_at'] ) : null ) ?: $now;
		list( $data, $formats ) = self::prepare_row( $d, self::$call_columns );
		$ok                     = $wpdb->insert( self::calls_table(), $data, $formats );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a call row (sets updated_at; arrays in user_data/extracted are JSON-encoded).
	 *
	 * @param int   $id Row id.
	 * @param array $d  Column => value.
	 * @return bool
	 */
	public static function update_call( $id, array $d ): bool {
		global $wpdb;
		$id = is_numeric( $id ) ? (int) $id : 0;
		if ( $id <= 0 ) {
			return false;
		}
		unset( $d['id'] );
		$d['updated_at']        = current_time( 'mysql', true );
		list( $data, $formats ) = self::prepare_row( $d, self::$call_columns );
		return false !== $wpdb->update( self::calls_table(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * One call row (JSON columns decoded), or null.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get_call( $id ): ?array {
		global $wpdb;
		$id = is_numeric( $id ) ? (int) $id : 0;
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::calls_table();
		return self::hydrate_call( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) );
	}

	/**
	 * Latest call row for a Bolna execution id (or web-call run id), or null.
	 *
	 * @param string $eid Execution id.
	 * @return array|null
	 */
	public static function get_call_by_execution( $eid ): ?array {
		global $wpdb;
		$eid = is_scalar( $eid ) ? trim( (string) $eid ) : '';
		if ( '' === $eid ) {
			return null;
		}
		$table = self::calls_table();
		return self::hydrate_call( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE execution_id = %s ORDER BY id DESC LIMIT 1", $eid ), ARRAY_A ) );
	}

	/**
	 * List calls, newest first.
	 *
	 * @param array $args status (string|array), use_case (string|array), order_id, direction (string|array),
	 *                    search (customer name / phone / order id), limit (50), offset (0).
	 * @return array[]
	 */
	public static function list_calls( array $args = array() ): array {
		global $wpdb;
		$table                  = self::calls_table();
		list( $where, $params ) = self::calls_where( $args );
		$limit                  = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$limit                  = $limit > 0 ? min( $limit, 1000 ) : 50;
		$offset                 = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$params[]               = $limit;
		$params[]               = $offset;
		$rows                   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ), ARRAY_A );
		return self::hydrate_all( $rows, 'hydrate_call' );
	}

	/**
	 * Count calls matching the same filters as list_calls().
	 *
	 * @param array $args Filters.
	 * @return int
	 */
	public static function count_calls( array $args = array() ): int {
		global $wpdb;
		$table                  = self::calls_table();
		list( $where, $params ) = self::calls_where( $args );
		return (int) $wpdb->get_var( self::prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
	}

	/**
	 * Most recent call for an order, or null.
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	public static function latest_call_for_order( $order_id ): ?array {
		global $wpdb;
		$order_id = is_numeric( $order_id ) ? (int) $order_id : 0;
		if ( $order_id <= 0 ) {
			return null;
		}
		$table = self::calls_table();
		return self::hydrate_call( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1", $order_id ), ARRAY_A ) );
	}

	/**
	 * Scheduled calls whose time has come (status 'scheduled' AND scheduled_at <= now), earliest first.
	 *
	 * @param int $limit Max rows.
	 * @return array[]
	 */
	public static function due_scheduled_calls( $limit = 5 ): array {
		global $wpdb;
		$table = self::calls_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND ( scheduled_at IS NULL OR scheduled_at <= %s ) ORDER BY scheduled_at ASC, id ASC LIMIT %d",
				'scheduled',
				self::gmt(),
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
		return self::hydrate_all( $rows, 'hydrate_call' );
	}

	/**
	 * Calls placed in the last 6 hours that are not finished yet and need a status sync with Bolna.
	 * Least recently updated first, so every pending call gets its turn.
	 *
	 * @param int $limit Max rows.
	 * @return array[]
	 */
	public static function pending_sync_calls( $limit = 10 ): array {
		global $wpdb;
		$table    = self::calls_table();
		$terminal = self::terminal_statuses();
		$params   = array_merge( $terminal, array( self::gmt( -6 * HOUR_IN_SECONDS ), max( 1, (int) $limit ) ) );
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE execution_id IS NOT NULL AND execution_id <> '' AND status NOT IN (" . self::placeholders( $terminal ) . ') AND created_at >= %s ORDER BY updated_at ASC, id ASC LIMIT %d',
				$params
			),
			ARRAY_A
		);
		return self::hydrate_all( $rows, 'hydrate_call' );
	}

	/**
	 * Dashboard numbers, computed with a handful of aggregate queries.
	 *
	 * Rows that never became calls (status scheduled/skipped) are excluded. cost is in cents (as reported by Bolna).
	 *
	 * @return array
	 */
	public static function stats(): array {
		global $wpdb;
		$calls = self::calls_table();
		$carts = self::carts_table();

		$finished = array_values( array_diff( self::terminal_statuses(), array( 'skipped' ) ) );
		$today    = get_gmt_from_date( current_time( 'Y-m-d' ) . ' 00:00:00' ); // Local midnight, in UTC.

		// 1) Totals in one pass.
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total_calls,
					SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS calls_today,
					SUM(CASE WHEN status = 'completed' AND duration > 0 THEN 1 ELSE 0 END) AS connected,
					SUM(CASE WHEN direction = 'outbound' AND execution_id IS NOT NULL AND status IN (" . self::placeholders( $finished ) . ") THEN 1 ELSE 0 END) AS finished_outbound,
					SUM(CASE WHEN direction = 'outbound' AND status = 'completed' AND duration > 0 THEN 1 ELSE 0 END) AS connected_outbound,
					SUM(COALESCE(duration, 0)) AS total_seconds,
					SUM(COALESCE(cost, 0)) AS total_cost,
					SUM(CASE WHEN rating >= 1 AND rating <= 5 THEN rating ELSE 0 END) AS rating_sum,
					SUM(CASE WHEN rating >= 1 AND rating <= 5 THEN 1 ELSE 0 END) AS rating_count
				FROM {$calls} WHERE status NOT IN ('scheduled', 'skipped')",
				array_merge( array( $today ), $finished )
			),
			ARRAY_A
		);
		$totals = is_array( $totals ) ? $totals : array();
		$num    = static function ( $key ) use ( $totals ) {
			return isset( $totals[ $key ] ) && is_numeric( $totals[ $key ] ) ? (float) $totals[ $key ] : 0.0;
		};

		// 2) Counts per use case and per outcome.
		$by_use_case = array();
		$outcomes    = array();
		$groups      = $wpdb->get_results( "SELECT use_case, outcome, COUNT(*) AS n FROM {$calls} WHERE status NOT IN ('scheduled', 'skipped') GROUP BY use_case, outcome", ARRAY_A );
		foreach ( (array) $groups as $group ) {
			$count    = (int) $group['n'];
			$use_case = (string) $group['use_case'];
			if ( '' !== $use_case ) {
				$by_use_case[ $use_case ] = ( isset( $by_use_case[ $use_case ] ) ? $by_use_case[ $use_case ] : 0 ) + $count;
			}
			if ( null !== $group['outcome'] && '' !== (string) $group['outcome'] ) {
				$outcome              = (string) $group['outcome'];
				$outcomes[ $outcome ] = ( isset( $outcomes[ $outcome ] ) ? $outcomes[ $outcome ] : 0 ) + $count;
			}
		}
		arsort( $by_use_case );
		arsort( $outcomes );

		// 3) COD decisions per order (the latest decision wins when an order was called more than once).
		// A new date or a corrected address still means "deliver it" (VoicePress_Calls sets cod_status 'confirmed').
		$decisions = array();
		$cod_rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id, outcome FROM {$calls} WHERE use_case = %s AND outcome IN (%s, %s, %s, %s) AND order_id IS NOT NULL ORDER BY id ASC",
				'cod_confirmation',
				'confirmed',
				'cancelled',
				'reschedule',
				'address_change'
			),
			ARRAY_A
		);
		foreach ( (array) $cod_rows as $cod_row ) {
			$decisions[ (int) $cod_row['order_id'] ] = 'cancelled' === (string) $cod_row['outcome'] ? 'cancelled' : 'confirmed';
		}
		$cancelled_ids = array_keys( $decisions, 'cancelled', true );

		// 4) Carts recovered after a call.
		$recovered = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, SUM(COALESCE(cart_total, 0)) AS total FROM {$carts} WHERE status = %s AND call_id IS NOT NULL",
				'recovered'
			),
			ARRAY_A
		);

		$finished_outbound = (int) $num( 'finished_outbound' );
		$rating_count      = (int) $num( 'rating_count' );

		return array(
			'total_calls'          => (int) $num( 'total_calls' ),
			'calls_today'          => (int) $num( 'calls_today' ),
			'connected'            => (int) $num( 'connected' ),
			'connect_rate'         => $finished_outbound > 0 ? (int) max( 0, min( 100, round( 100 * $num( 'connected_outbound' ) / $finished_outbound ) ) ) : 0,
			'total_minutes'        => round( $num( 'total_seconds' ) / 60, 1 ),
			'total_cost'           => round( $num( 'total_cost' ), 2 ),
			'cod_confirmed'        => count( array_keys( $decisions, 'confirmed', true ) ),
			'cod_cancelled'        => count( $cancelled_ids ),
			'rto_saved_value'      => self::orders_total( $cancelled_ids ),
			'carts_recovered'      => is_array( $recovered ) ? (int) $recovered['n'] : 0,
			'cart_recovered_value' => is_array( $recovered ) ? round( (float) $recovered['total'], 2 ) : 0.0,
			'feedback_avg'         => $rating_count > 0 ? round( $num( 'rating_sum' ) / $rating_count, 1 ) : 0.0,
			'feedback_count'       => $rating_count,
			'by_use_case'          => $by_use_case,
			'outcomes'             => $outcomes,
		);
	}

	/* ------------------------------------------------------------------- Carts */

	/**
	 * Insert a cart row (sets created_at/updated_at; an array `cart` is JSON-encoded).
	 *
	 * @param array $d Column => value.
	 * @return int New row id, 0 on failure.
	 */
	public static function insert_cart( array $d ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		unset( $d['id'] );
		$d['created_at'] = ( isset( $d['created_at'] ) ? self::to_datetime( $d['created_at'] ) : null ) ?: $now;
		$d['updated_at'] = ( isset( $d['updated_at'] ) ? self::to_datetime( $d['updated_at'] ) : null ) ?: $now;
		list( $data, $formats ) = self::prepare_row( $d, self::$cart_columns );
		$ok                     = $wpdb->insert( self::carts_table(), $data, $formats );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a cart row (sets updated_at).
	 *
	 * @param int   $id Row id.
	 * @param array $d  Column => value.
	 * @return bool
	 */
	public static function update_cart( $id, array $d ): bool {
		global $wpdb;
		$id = is_numeric( $id ) ? (int) $id : 0;
		if ( $id <= 0 ) {
			return false;
		}
		unset( $d['id'] );
		$d['updated_at']        = current_time( 'mysql', true );
		list( $data, $formats ) = self::prepare_row( $d, self::$cart_columns );
		return false !== $wpdb->update( self::carts_table(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * One cart row (cart JSON decoded), or null.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get_cart( $id ): ?array {
		global $wpdb;
		$id = is_numeric( $id ) ? (int) $id : 0;
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::carts_table();
		return self::hydrate_cart( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) );
	}

	/**
	 * Latest still-captured cart for a session key, or null.
	 *
	 * @param string $key Session key.
	 * @return array|null
	 */
	public static function get_cart_by_session( $key ): ?array {
		global $wpdb;
		$key = is_scalar( $key ) ? trim( (string) $key ) : '';
		if ( '' === $key ) {
			return null;
		}
		$table = self::carts_table();
		return self::hydrate_cart( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_key = %s AND status = %s ORDER BY id DESC LIMIT 1", $key, 'captured' ), ARRAY_A ) );
	}

	/**
	 * Abandoned carts ready for a recovery call: status 'captured', has a phone, untouched for $delay_minutes
	 * (and captured within the last 72 hours, so stale carts are never called).
	 *
	 * @param int $delay_minutes Minutes since the last update.
	 * @param int $limit         Max rows.
	 * @return array[]
	 */
	public static function due_carts( $delay_minutes, $limit = 3 ): array {
		global $wpdb;
		$table = self::carts_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND phone IS NOT NULL AND phone <> '' AND updated_at <= %s AND created_at >= %s ORDER BY updated_at ASC, id ASC LIMIT %d",
				'captured',
				self::gmt( -60 * max( 0, (int) $delay_minutes ) ),
				self::gmt( -72 * HOUR_IN_SECONDS ),
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
		return self::hydrate_all( $rows, 'hydrate_cart' );
	}

	/**
	 * Most recent carts.
	 *
	 * @param int $limit Max rows.
	 * @return array[]
	 */
	public static function list_carts( $limit = 20 ): array {
		global $wpdb;
		$table = self::carts_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, min( 500, (int) $limit ) ) ), ARRAY_A );
		return self::hydrate_all( $rows, 'hydrate_cart' );
	}

	/**
	 * Open carts (captured/calling/called, created within 72 hours) matching ANY non-empty key:
	 * the session key, the phone (last 10 digits) or the email.
	 *
	 * @param string $session_key Session key.
	 * @param string $phone       Phone number.
	 * @param string $email       Email.
	 * @return array[]
	 */
	public static function find_open_carts_for( $session_key, $phone, $email ): array {
		global $wpdb;
		$table  = self::carts_table();
		$or     = array();
		$params = array( 'captured', 'calling', 'called', self::gmt( -72 * HOUR_IN_SECONDS ) );

		$session_key = is_scalar( $session_key ) ? trim( (string) $session_key ) : '';
		if ( '' !== $session_key ) {
			$or[]     = 'session_key = %s';
			$params[] = $session_key;
		}
		$tail = preg_replace( '/\D+/', '', is_scalar( $phone ) ? (string) $phone : '' );
		$tail = strlen( $tail ) > 10 ? substr( $tail, -10 ) : $tail;
		if ( strlen( $tail ) >= 7 ) {
			$or[]     = 'phone LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $tail );
		}
		$email = is_scalar( $email ) ? strtolower( trim( (string) $email ) ) : '';
		if ( '' !== $email ) {
			$or[]     = 'LOWER(email) = %s';
			$params[] = $email;
		}
		if ( empty( $or ) ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status IN (%s, %s, %s) AND created_at >= %s AND (" . implode( ' OR ', $or ) . ') ORDER BY id DESC LIMIT 50', $params ),
			ARRAY_A
		);
		return self::hydrate_all( $rows, 'hydrate_cart' );
	}

	/* --------------------------------------------------------------- Internals */

	/**
	 * Do both tables exist?
	 *
	 * @return bool
	 */
	private static function tables_exist() {
		global $wpdb;
		foreach ( array( self::calls_table(), self::carts_table() ) as $table ) {
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * WHERE clause + params for list_calls()/count_calls().
	 *
	 * @param array $args Filters.
	 * @return array [ string $where, array $params ]
	 */
	private static function calls_where( array $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'status', 'use_case', 'direction' ) as $column ) {
			if ( empty( $args[ $column ] ) ) {
				continue;
			}
			$values = array_values( array_filter( array_map( 'strval', array_filter( (array) $args[ $column ], 'is_scalar' ) ), 'strlen' ) );
			if ( $values ) {
				$where[] = $column . ' IN (' . self::placeholders( $values ) . ')';
				$params  = array_merge( $params, $values );
			}
		}

		if ( ! empty( $args['order_id'] ) && (int) $args['order_id'] > 0 ) {
			$where[]  = 'order_id = %d';
			$params[] = (int) $args['order_id'];
		}

		$search = isset( $args['search'] ) && is_scalar( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$or     = array( 'customer_name LIKE %s', 'phone LIKE %s' );
			$values = array( $like, $like );
			$digits = preg_replace( '/\D+/', '', $search );
			if ( strlen( $digits ) >= 4 && $digits !== $search ) {
				$or[]     = 'phone LIKE %s';
				$values[] = '%' . $wpdb->esc_like( $digits ) . '%';
			}
			if ( preg_match( '/^\d+$/', $search ) ) {
				$or[]     = 'order_id = %d';
				$values[] = (int) $search;
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
			$params  = array_merge( $params, $values );
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Validate/convert values for $wpdb->insert()/update(): known columns only, typed, length-limited.
	 *
	 * @param array $d       Column => value.
	 * @param array $columns Column definitions.
	 * @return array [ array $data, array $formats ]
	 */
	private static function prepare_row( array $d, array $columns ) {
		$data    = array();
		$formats = array();
		foreach ( $d as $column => $value ) {
			if ( ! is_string( $column ) || ! isset( $columns[ $column ] ) ) {
				continue;
			}
			list( $type, $max, $nullable ) = $columns[ $column ];

			if ( 'json' === $type && ( is_array( $value ) || is_object( $value ) ) ) {
				$value = wp_json_encode( $value );
				$value = false === $value ? null : $value;
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 1 : 0;
			}
			if ( null !== $value && ! is_scalar( $value ) ) {
				$value = null;
			}

			switch ( $type ) {
				case 'int':
				case 'ref':
					$value = ( null === $value || '' === $value || ! is_numeric( $value ) ) ? null : (int) round( (float) $value );
					if ( 'ref' === $type && null !== $value && $value <= 0 ) {
						$value = null; // No order/cart/call: store NULL, not 0.
					}
					if ( 'rating' === $column && null !== $value && ( $value < 1 || $value > 5 ) ) {
						$value = null;
					}
					if ( 'outcome_applied' === $column && null !== $value ) {
						$value = $value ? 1 : 0;
					}
					$format = '%d';
					break;

				case 'float':
					$value  = ( null === $value || '' === $value || ! is_numeric( $value ) ) ? null : (float) $value;
					$format = '%f';
					break;

				case 'dt':
					$value  = self::to_datetime( $value );
					$format = '%s';
					break;

				default: // str, json.
					if ( null !== $value ) {
						$value = (string) $value;
						if ( $max > 0 ) {
							$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
						}
						if ( '' === $value && 'execution_id' === $column ) {
							$value = null;
						}
					}
					$format = '%s';
			}

			if ( null === $value && ! $nullable ) {
				continue; // Leave NOT NULL columns to their DB default instead of failing the query.
			}
			$data[ $column ] = $value;
			$formats[]       = $format;
		}
		return array( $data, $formats );
	}

	/**
	 * 'Y-m-d H:i:s' from a datetime string or Unix timestamp; null when empty/invalid.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function to_datetime( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\d{9,}$/', $value ) ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}
		$value = trim( (string) $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}
		$timestamp = strtotime( $value );
		return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Decode JSON columns and cast numeric columns of a calls row.
	 *
	 * @param mixed $row Row from $wpdb (ARRAY_A).
	 * @return array|null
	 */
	private static function hydrate_call( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['user_data'] = self::decode_json( isset( $row['user_data'] ) ? $row['user_data'] : null );
		$row['extracted'] = self::decode_json( isset( $row['extracted'] ) ? $row['extracted'] : null );
		foreach ( array( 'id', 'outcome_applied', 'attempt' ) as $column ) {
			$row[ $column ] = isset( $row[ $column ] ) ? (int) $row[ $column ] : 0;
		}
		foreach ( array( 'order_id', 'cart_id', 'rating', 'duration' ) as $column ) {
			$row[ $column ] = isset( $row[ $column ] ) && '' !== $row[ $column ] ? (int) $row[ $column ] : null;
		}
		$row['cost'] = isset( $row['cost'] ) && '' !== $row['cost'] ? (float) $row['cost'] : null;
		return $row;
	}

	/**
	 * Decode the cart JSON and cast numeric columns of a carts row.
	 *
	 * @param mixed $row Row from $wpdb (ARRAY_A).
	 * @return array|null
	 */
	private static function hydrate_cart( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['cart'] = self::decode_json( isset( $row['cart'] ) ? $row['cart'] : null );
		$row['id']   = isset( $row['id'] ) ? (int) $row['id'] : 0;
		foreach ( array( 'order_id', 'call_id' ) as $column ) {
			$row[ $column ] = isset( $row[ $column ] ) && '' !== $row[ $column ] ? (int) $row[ $column ] : null;
		}
		$row['cart_total'] = isset( $row['cart_total'] ) && '' !== $row['cart_total'] ? (float) $row['cart_total'] : null;
		return $row;
	}

	/**
	 * Hydrate a list of rows.
	 *
	 * @param mixed  $rows   Rows.
	 * @param string $method hydrate_call|hydrate_cart.
	 * @return array[]
	 */
	private static function hydrate_all( $rows, $method ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			$hydrated = self::$method( $row );
			if ( null !== $hydrated ) {
				$out[] = $hydrated;
			}
		}
		return $out;
	}

	/**
	 * JSON column → array.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	private static function decode_json( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Sum of order totals (cached until the set of orders changes).
	 *
	 * @param int[] $order_ids Order ids.
	 * @return float
	 */
	private static function orders_total( array $order_ids ) {
		if ( empty( $order_ids ) || ! function_exists( 'wc_get_order' ) ) {
			return 0.0;
		}
		sort( $order_ids );
		$signature = md5( implode( ',', $order_ids ) );
		$cached    = get_transient( 'voicepress_rto_cache' );
		if ( is_array( $cached ) && isset( $cached['sig'], $cached['value'] ) && $cached['sig'] === $signature ) {
			return (float) $cached['value'];
		}
		$sum = 0.0;
		try {
			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order instanceof WC_Order ) {
					$sum += (float) $order->get_total();
				}
			}
		} catch ( Throwable $e ) {
			return round( $sum, 2 );
		}
		$sum = round( $sum, 2 );
		set_transient(
			'voicepress_rto_cache',
			array(
				'sig'   => $signature,
				'value' => $sum,
			),
			HOUR_IN_SECONDS
		);
		return $sum;
	}

	/**
	 * Terminal call statuses (VoicePress_Calls::TERMINAL when available).
	 *
	 * @return string[]
	 */
	private static function terminal_statuses() {
		if ( class_exists( 'VoicePress_Calls' ) && defined( 'VoicePress_Calls::TERMINAL' ) ) {
			$terminal = constant( 'VoicePress_Calls::TERMINAL' );
			if ( is_array( $terminal ) && ! empty( $terminal ) ) {
				return array_values( array_map( 'strval', $terminal ) );
			}
		}
		return array( 'completed', 'no-answer', 'busy', 'failed', 'canceled', 'stopped', 'error', 'balance-low', 'skipped' );
	}

	/**
	 * "%s, %s, %s" for a list of values.
	 *
	 * @param array $values Values.
	 * @return string
	 */
	private static function placeholders( array $values ) {
		return implode( ', ', array_fill( 0, max( 1, count( $values ) ), '%s' ) );
	}

	/**
	 * Prepare only when there are params (prepare() without placeholders triggers a notice).
	 *
	 * @param string $sql    SQL.
	 * @param array  $params Params.
	 * @return string
	 */
	private static function prepare( $sql, array $params ) {
		global $wpdb;
		return empty( $params ) ? $sql : $wpdb->prepare( $sql, $params );
	}

	/**
	 * UTC 'Y-m-d H:i:s' for now + $offset seconds.
	 *
	 * @param int $offset Seconds (negative = past).
	 * @return string
	 */
	private static function gmt( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $offset );
	}
}
