<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The job queue — one row per unit of work.
 *
 * The reason a migration engine needs a real queue rather than a loop is
 * resumption. A crash, a timeout, or a user closing the tab costs at most the
 * one job that was in flight; everything already done stays done, and everything
 * still to do is sitting in a table waiting. That property is worth a custom
 * table on its own.
 *
 * Jobs are stored as JSON, not serialized PHP. Serialized objects in a database
 * table are an object-injection hole waiting for someone to find a way to write
 * to it — JSON has no such failure mode, and a job here is only ever a few
 * scalars.
 *
 * Job order encodes stage order, which means the processor can find a stage
 * boundary just by peeking at the next row: if the job on top belongs to this
 * stage and the one after it does not, this is the last job of the stage.
 */
class FW_SM_Queue {

	/**
	 * Bump when the table definition changes so installs upgrade on next load.
	 */
	const SCHEMA_VERSION = 1;

	const SCHEMA_OPTION = 'fw_sm_queue_schema';

	/**
	 * @return string Fully-prefixed jobs table name.
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'fw_sm_jobs';
	}

	/**
	 * Create the jobs table if it is missing or out of date.
	 *
	 * Called lazily when a migration starts rather than on plugin activation, so
	 * an install that never migrates never grows the table.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure_table() {
		global $wpdb;

		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) === self::SCHEMA_VERSION ) {
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is particular about formatting: two spaces after PRIMARY KEY,
		// lower-case types, one field per line.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stage varchar(32) NOT NULL,
			payload longtext NOT NULL,
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY stage (stage)
		) {$collate};";

		dbDelta( $sql );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return new WP_Error(
				'fw_sm_queue_table',
				sprintf(
					/* translators: %s: database error message. */
					__( 'Could not create the migration queue table. %s', 'fw' ),
					$wpdb->last_error
				)
			);
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );

		return true;
	}


	/**
	 * Push many jobs in one statement.
	 *
	 * A 50,000-file uploads directory is 50,000 jobs; inserting them one round
	 * trip at a time is the difference between a scan that finishes and one that
	 * times out. Rows are chunked so no single statement exceeds max_allowed_packet.
	 *
	 * @param string $stage
	 * @param array  $jobs Each entry: [ 'payload' => array, 'bytes' => int ].
	 *
	 * @return int Number of rows inserted.
	 */
	public static function push_many( $stage, array $jobs ) {
		global $wpdb;

		if ( empty( $jobs ) ) {
			return 0;
		}

		$table    = self::table();
		$now      = current_time( 'mysql', true );
		$inserted = 0;

		foreach ( array_chunk( $jobs, 200 ) as $chunk ) {
			$values = [];
			$args   = [];

			foreach ( $chunk as $job ) {
				$encoded = wp_json_encode( $job['payload'] ?? [] );

				if ( false === $encoded ) {
					continue;
				}

				$values[] = '(%s, %s, %d, %s)';
				$args[]   = $stage;
				$args[]   = $encoded;
				$args[]   = max( 0, (int) ( $job['bytes'] ?? 0 ) );
				$args[]   = $now;
			}

			if ( empty( $values ) ) {
				continue;
			}

			$sql = "INSERT INTO {$table} (stage, payload, bytes, created_at) VALUES "
			       . implode( ', ', $values );

			// $table is built from $wpdb->prefix and the placeholders above carry
			// every user-supplied value, so this prepare() call is complete.
			$result = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( $result ) {
				$inserted += (int) $result;
			}
		}

		return $inserted;
	}

	/**
	 * Read the next jobs without removing them.
	 *
	 * The runner asks for two: the one to work on, and the one after it, so it
	 * can tell whether the current job closes out the stage.
	 *
	 * @param int $limit
	 *
	 * @return array[] Each: [ 'id', 'stage', 'payload' (decoded), 'bytes', 'attempts' ].
	 */
	public static function peek( $limit = 1 ) {
		global $wpdb;

		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, stage, payload, bytes, attempts FROM {$table} ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return [];
		}

		foreach ( $rows as &$row ) {
			$row['payload']  = json_decode( $row['payload'], true );
			$row['bytes']    = (int) $row['bytes'];
			$row['attempts'] = (int) $row['attempts'];
			$row['id']       = (int) $row['id'];
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Remove a finished job.
	 *
	 * @param int $id
	 *
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		$wpdb->delete( self::table(), [ 'id' => (int) $id ], [ '%d' ] );
	}

	/**
	 * Record another failed attempt against a job.
	 *
	 * @param int $id
	 *
	 * @return int The new attempt count.
	 */
	/**
	 * Put a job's attempt counter back to zero.
	 *
	 * For when the conditions genuinely changed rather than the same thing
	 * being tried again — a smaller batch after the destination ran out of
	 * memory is a different request, and it should not inherit the failures of
	 * the larger one.
	 *
	 * @param int $id
	 *
	 * @return void
	 */
	public static function reset_attempts( $id ) {
		global $wpdb;

		$table = self::table();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET attempts = 0 WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $id
			)
		);
	}

	public static function bump_attempts( $id ) {
		global $wpdb;

		$table = self::table();
		$id    = (int) $id;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attempts FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);
	}

	/**
	 * How many jobs remain, optionally for one stage.
	 *
	 * @param string $stage
	 *
	 * @return int
	 */
	public static function count( $stage = '' ) {
		global $wpdb;

		$table = self::table();

		if ( '' === $stage ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE stage = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$stage
			)
		);
	}


	/**
	 * Empty the queue — on cancel, and at the end of a successful migration.
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Drop the table entirely. Used by the uninstall scrubber.
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		delete_option( self::SCHEMA_OPTION );
	}
}
