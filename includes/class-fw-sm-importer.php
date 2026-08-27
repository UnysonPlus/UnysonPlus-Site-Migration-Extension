<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Archive import.
 *
 * The rule this class exists to enforce: the destination site stays live and
 * correct until the whole import has succeeded.
 *
 * Rows are never written over a live table. Every statement in the dump is
 * rewritten to target a temporary table with a `_fwsm_` prefix, and only once
 * every table has loaded without error are those temporaries renamed into place
 * — an atomic-enough swap that an import killed at 80% leaves a site that is
 * still serving its old content rather than a half-replaced database.
 *
 * The other job here is the carve-out. A handful of destination values must
 * survive an import or the site becomes unreachable: this extension's own
 * settings, the active plugin list, and — the one that catches everybody — the
 * prefix-dependent user capability keys. `wp_capabilities` on the source is
 * meaningless on a destination whose prefix is `wp_abc123_`, and copying it
 * verbatim locks every user out of their own site.
 */
class FW_SM_Importer {

	/**
	 * Prefix for the staging tables.
	 */
	const TEMP_PREFIX = '_fwsm_';

	/**
	 * Option names whose destination value must survive an import.
	 *
	 * @return string[]
	 */
	/**
	 * Every option this extension owns starts with this.
	 *
	 * Treated as a namespace so that adding an option later cannot reintroduce
	 * the inheritance bug by being forgotten from a list.
	 */
	const OPTION_NAMESPACE = 'fw_sm_';

	public static function preserved_options() {
		$preserved = [
			// Replacing the destination's active plugins with the source's is how
			// you end up with an import that half-runs and then fatals.
			'active_plugins',
			// The destination's own address. An import is not a domain change.
			'siteurl',
			'home',
		];

		// This extension's own options are handled as a NAMESPACE rather than
		// as a list — see OPTION_NAMESPACE. Listing them individually was the
		// bug: a name absent from the destination was skipped rather than
		// removed, so the source's copy survived the swap and the destination
		// woke up believing it was mid-migration to somewhere else.

		/**
		 * Filters the options whose destination values survive an import.
		 *
		 * @param string[] $preserved
		 */
		return apply_filters( 'fw_ext_site_migration_preserved_options', $preserved );
	}

	/**
	 * Capture the destination values that must be restored after the swap.
	 *
	 * @return array
	 */
	public static function capture_preserved() {
		global $wpdb;

		$captured = [ 'options' => [], 'prefix' => $wpdb->prefix ];

		foreach ( self::preserved_options() as $name ) {
			$value = get_option( $name, null );

			if ( null !== $value ) {
				$captured['options'][ $name ] = $value;
			}
		}

		// Everything in this extension's namespace, recorded separately because
		// it is restored differently: the namespace is wiped after the swap and
		// rebuilt from this, so an option the destination did NOT have ends up
		// absent rather than inherited from the source.
		$captured['namespace'] = [];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( self::OPTION_NAMESPACE ) . '%'
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$captured['namespace'][ $row['option_name'] ] = $row['option_value'];
		}

		return $captured;
	}

	/**
	 * Restore the carved-out values, and repair prefix-dependent keys.
	 *
	 * @param array $captured Output of capture_preserved().
	 * @param string $source_prefix The source site's table prefix.
	 *
	 * @return void
	 */
	public static function restore_preserved( array $captured, $source_prefix ) {
		global $wpdb;

		foreach ( (array) ( $captured['options'] ?? [] ) as $name => $value ) {
			update_option( $name, $value );
		}

		// The whole namespace is destination-owned, so it is emptied and then
		// rebuilt. Updating in place would leave any of the source's entries
		// that the destination lacked — which is how a freshly migrated site
		// inherits a migration in progress, a run token, and a pointer at
		// somebody else's destination.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( self::OPTION_NAMESPACE ) . '%'
			)
		);

		foreach ( (array) ( $captured['namespace'] ?? [] ) as $name => $value ) {
			$wpdb->insert(
				$wpdb->options,
				[ 'option_name' => $name, 'option_value' => $value, 'autoload' => 'no' ]
			);
		}

		// The swap replaced the table under WordPress's feet and the deletes
		// above went straight to SQL, so anything cached is now a lie.
		wp_cache_flush();

		$destination_prefix = $wpdb->prefix;

		if ( $source_prefix === $destination_prefix || '' === $source_prefix ) {
			return;
		}

		// Rename the prefix-dependent usermeta keys. These are the ones that
		// decide whether anybody can log in, so getting this wrong is not a
		// cosmetic problem.
		$keys = [ 'capabilities', 'user_level', 'dashboard_quick_press_last_post_id', 'user-settings', 'user-settings-time' ];

		foreach ( $keys as $key ) {
			$from = $source_prefix . $key;
			$to   = $destination_prefix . $key;

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$to,
					$from
				)
			);
		}

		// The same pattern in options: user roles are stored prefixed too.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_name = %s WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$destination_prefix . 'user_roles',
				$source_prefix . 'user_roles'
			)
		);
	}

	/**
	 * Execute a batch of SQL arriving over the wire.
	 *
	 * Same rules as loading from a file — statements are redirected to staging
	 * tables and constraints are set aside — but the source is a string that
	 * came from another server, so it is parsed defensively: statement
	 * boundaries are found the same way the exporter writes them, one statement
	 * per line ending in a semicolon.
	 *
	 * @param string $sql
	 *
	 * @return array|WP_Error [ 'statements', 'tables', 'deferred' ]
	 */
	public function run_statements( $sql ) {
		global $wpdb;

		$executed = 0;
		$tables   = [];
		$deferred = [];
		$buffer   = '';

		// Bulk-load settings for the duration of this batch.
		//
		// autocommit off means one commit for the whole batch instead of one
		// per statement, which on InnoDB is a disk flush each time. unique and
		// foreign key checks are redundant here: the data came from a database
		// that already enforced them, and re-verifying every row against
		// indexes that are still being built is pure cost.
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'SET unique_checks = 0' );
		$wpdb->query( 'SET foreign_key_checks = 0' );

		$restore = static function () use ( $wpdb ) {
			$wpdb->query( 'COMMIT' );
			$wpdb->query( 'SET unique_checks = 1' );
			$wpdb->query( 'SET foreign_key_checks = 1' );
			$wpdb->query( 'SET autocommit = 1' );
		};

		foreach ( preg_split( "/\r\n|\n|\r/", (string) $sql ) as $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) ) {
				continue;
			}

			$buffer .= $line . "\n";

			if ( ';' !== substr( $trimmed, -1 ) ) {
				continue;
			}

			$raw    = trim( $buffer );
			$buffer = '';

			if ( '' === $raw ) {
				continue;
			}

			if ( self::is_deferred_constraint( $raw ) ) {
				$deferred[] = $raw;
				continue;
			}

			$statement = $this->rewrite_to_temp( $raw );

			if ( '' === $statement ) {
				continue;
			}

			if ( preg_match( '/^CREATE TABLE `' . preg_quote( self::TEMP_PREFIX, '/' ) . '([^`]+)`/i', $statement, $match ) ) {
				$tables[] = $match[1];
			}

			$result = $wpdb->query( $statement ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( false === $result && '' !== $wpdb->last_error ) {
				$error = $wpdb->last_error;

				// Commit what did land: the staging tables are discarded on
				// failure anyway, and leaving a transaction open would hold
				// locks on the destination until its connection times out.
				$restore();

				return new WP_Error(
					'fw_sm_import_query',
					sprintf(
						/* translators: %s: database error message. */
						__( 'The destination stopped on a database error: %s', 'fw' ),
						$error
					)
				);
			}

			$executed++;
		}

		$restore();

		return [
			'statements' => $executed,
			'tables'     => $tables,
			'deferred'   => $deferred,
		];
	}

	/**
	 * Point a statement at the staging table instead of the live one.
	 *
	 * Patterns are anchored to the START of the statement, which matters more
	 * than it looks. An unanchored `INSERT INTO \`table\`` pattern also matches
	 * inside a quoted value, so a post whose content happens to contain a SQL
	 * snippet would have that snippet silently rewritten on the way in. The
	 * exporter writes exactly one statement per line beginning with its keyword,
	 * so anchoring costs nothing and removes the whole class of problem.
	 *
	 * @param string $statement
	 *
	 * @return string
	 */
	private function rewrite_to_temp( $statement ) {
		// DROP TABLE against a live table is exactly what must not happen, so
		// the exporter's DROP lines are rewritten to target the staging copy.
		$patterns = [
			'/^DROP TABLE IF EXISTS `([^`]+)`/i',
			'/^CREATE TABLE `([^`]+)`/i',
			'/^INSERT INTO `([^`]+)`/i',
		];

		foreach ( $patterns as $pattern ) {
			$statement = preg_replace_callback(
				$pattern,
				function ( $match ) {
					return str_replace(
						'`' . $match[1] . '`',
						'`' . self::TEMP_PREFIX . $match[1] . '`',
						$match[0]
					);
				},
				$statement,
				1
			);
		}

		return $statement;
	}

	/**
	 * Is this a deferred constraint statement?
	 *
	 * Foreign keys are deliberately NOT applied to the staging tables. A
	 * constraint added to `_fwsm_wp_a` pointing at `_fwsm_wp_b` does not survive
	 * the rename intact, because the tables are renamed one pair at a time
	 * rather than in a single statement — so the constraint would end up
	 * pointing at whichever table happened to hold that name at the time.
	 *
	 * Collecting them and replaying them after the swap, against the live names,
	 * is both simpler and correct.
	 *
	 * @param string $statement
	 *
	 * @return bool
	 */
	public static function is_deferred_constraint( $statement ) {
		return (bool) preg_match( '/^ALTER TABLE `[^`]+` ADD /i', $statement );
	}

	/**
	 * Replay the deferred constraints against the live tables, after the swap.
	 *
	 * Best effort by design: a constraint that cannot be created (because the
	 * destination's storage engine differs, or the referenced data does not
	 * satisfy it) is logged and skipped rather than failing an import whose data
	 * is otherwise completely intact.
	 *
	 * @param string[] $statements
	 *
	 * @return string[] Statements that could not be applied.
	 */
	public function apply_deferred_constraints( array $statements ) {
		global $wpdb;

		$failed = [];

		foreach ( $statements as $statement ) {
			$suppressed = $wpdb->suppress_errors( true );

			if ( false === $wpdb->query( $statement ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$failed[] = $statement;
			}

			$wpdb->suppress_errors( $suppressed );
		}

		return $failed;
	}

	/**
	 * Swap every staging table into place.
	 *
	 * Uses a single RENAME TABLE statement per table pair so the live table is
	 * only unavailable for the instant of the rename. MySQL executes a multi-pair
	 * RENAME atomically, so both directions are done or neither is.
	 *
	 * @param string[] $tables Live table names that have staging copies.
	 *
	 * @return true|WP_Error
	 */
	public function swap_into_place( array $tables ) {
		global $wpdb;

		if ( empty( $tables ) ) {
			return new WP_Error(
				'fw_sm_nothing_to_swap',
				__( 'The import produced no tables, so there is nothing to put in place.', 'fw' )
			);
		}

		$old_suffix = '_fwsm_old';

		// Clear anything a previous attempt left displaced. Without this, the
		// rename of a live table to its _fwsm_old name collides with the corpse
		// of the last failed run and the whole swap fails — on a network of 600
		// tables that is a near-certainty after any earlier failure.
		foreach ( $tables as $live ) {
			$stale = $live . $old_suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stale ) ) === $stale ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$stale}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		$pairs   = [];
		$swapped = [];

		foreach ( $tables as $live ) {
			$temp = self::TEMP_PREFIX . $live;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $temp ) ) !== $temp ) {
				continue;
			}

			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $live ) ) === $live;

			if ( $exists ) {
				$pairs[] = "`{$live}` TO `{$live}{$old_suffix}`";
			}

			$pairs[]   = "`{$temp}` TO `{$live}`";
			$swapped[] = $live;
		}

		if ( empty( $pairs ) ) {
			return new WP_Error(
				'fw_sm_nothing_to_swap',
				__( 'No staging tables were found to put in place.', 'fw' )
			);
		}

		// ONE statement, not one per table.
		//
		// MySQL executes a multi-pair RENAME TABLE atomically: every pair moves
		// or none does. Renaming in a loop meant a failure partway through left
		// the destination half-swapped — some tables from the migration, some
		// its own — which is precisely the state this class promises can never
		// happen. With 600 tables a mid-loop failure stops being hypothetical.
		$sql = 'RENAME TABLE ' . implode( ', ', $pairs );

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Read the error BEFORE any other query. A successful statement clears
		// $wpdb->last_error, so cleaning up first throws away the only
		// explanation there was — which is how this failure first appeared as
		// "Could not put the imported tables in place:" with nothing after it.
		$error = (string) $wpdb->last_error;

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

		if ( false === $result ) {
			return new WP_Error(
				'fw_sm_swap_failed',
				sprintf(
					/* translators: 1: database error, 2: number of tables. */
					__( 'Could not put the imported tables in place (%2$d tables): %1$s', 'fw' ),
					'' !== $error ? $error : __( 'the database gave no reason', 'fw' ),
					count( $swapped )
				),
				[ 'tables' => count( $swapped ), 'statement_bytes' => strlen( $sql ) ]
			);
		}

		return true;
	}

	/**
	 * Drop the displaced originals once the import is known good.
	 *
	 * Deliberately a separate step from the swap: if anything is going to go
	 * wrong, it goes wrong while the old tables still exist.
	 *
	 * @param string[] $tables
	 *
	 * @return void
	 */
	public function drop_displaced( array $tables ) {
		global $wpdb;

		foreach ( $tables as $live ) {
			$old = $live . '_fwsm_old';

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) === $old ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$old}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	/**
	 * Remove every staging table — the cleanup path for a failed import.
	 *
	 * @return void
	 */
	public static function drop_staging_tables() {
		global $wpdb;

		$like = $wpdb->esc_like( self::TEMP_PREFIX ) . '%';

		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		foreach ( (array) $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
