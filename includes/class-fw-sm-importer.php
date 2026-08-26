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
	 * Statements executed per slice.
	 */
	const STATEMENTS_PER_SLICE = 400;

	/** @var string Absolute path of the extracted dump. */
	private $dump_path;

	/**
	 * @param string $dump_path
	 */
	public function __construct( $dump_path ) {
		$this->dump_path = $dump_path;
	}

	/**
	 * Option names whose destination value must survive an import.
	 *
	 * @return string[]
	 */
	public static function preserved_options() {
		$preserved = [
			// Losing these mid-import would strand the migration itself.
			FW_SM_State::OPTION,
			FW_SM_Runner::TOKEN_OPTION,
			FW_SM_Queue::SCHEMA_OPTION,
			'fw_sm_settings',
			// Replacing the destination's active plugins with the source's is how
			// you end up with an import that half-runs and then fatals.
			'active_plugins',
			// The destination's own address. An import is not a domain change.
			'siteurl',
			'home',
		];

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
	 * Execute a slice of the dump.
	 *
	 * @param int $byte_offset Where in the file to resume from.
	 *
	 * @return array|WP_Error [ 'offset', 'statements', 'tables', 'deferred', 'done' ]
	 */
	public function run_slice( $byte_offset = 0 ) {
		global $wpdb;

		$handle = @fopen( $this->dump_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return new WP_Error(
				'fw_sm_dump_missing',
				__( 'The database dump could not be opened. The archive may be incomplete.', 'fw' )
			);
		}

		if ( $byte_offset > 0 ) {
			fseek( $handle, $byte_offset );
		}

		$executed = 0;
		$tables   = [];
		$deferred = [];
		$buffer   = '';

		while ( $executed < self::STATEMENTS_PER_SLICE && ! feof( $handle ) ) {
			$line = fgets( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $line ) {
				break;
			}

			$trimmed = trim( $line );

			// Comments and blank lines carry no statement.
			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) ) {
				continue;
			}

			$buffer .= $line;

			// Statements are written one per line by the exporter, so a line
			// ending in a semicolon closes one. Multi-line values inside a
			// quoted string keep accumulating until their statement ends.
			if ( ';' !== substr( $trimmed, -1 ) ) {
				continue;
			}

			$raw    = trim( $buffer );
			$buffer = '';

			if ( '' === $raw ) {
				continue;
			}

			// Constraints are set aside for after the swap, against live names.
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
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

				return new WP_Error(
					'fw_sm_import_query',
					sprintf(
						/* translators: %s: database error message. */
						__( 'The import stopped on a database error: %s', 'fw' ),
						$wpdb->last_error
					)
				);
			}

			$executed++;
		}

		$offset = ftell( $handle );
		$done   = feof( $handle ) && '' === trim( $buffer );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return [
			'offset'     => (int) $offset,
			'statements' => $executed,
			'tables'     => $tables,
			'deferred'   => $deferred,
			'done'       => $done,
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
		$pairs      = [];

		foreach ( $tables as $live ) {
			$temp = self::TEMP_PREFIX . $live;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $temp ) ) !== $temp ) {
				continue;
			}

			// Move the live table aside and the staging table in, in one
			// statement, so there is no moment where neither exists.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $live ) ) === $live;

			if ( $exists ) {
				$pairs[] = "`{$live}` TO `{$live}{$old_suffix}`, `{$temp}` TO `{$live}`";
			} else {
				$pairs[] = "`{$temp}` TO `{$live}`";
			}
		}

		if ( empty( $pairs ) ) {
			return new WP_Error(
				'fw_sm_nothing_to_swap',
				__( 'No staging tables were found to put in place.', 'fw' )
			);
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		foreach ( $pairs as $pair ) {
			$result = $wpdb->query( "RENAME TABLE {$pair}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

			if ( false === $result ) {
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

				return new WP_Error(
					'fw_sm_swap_failed',
					sprintf(
						/* translators: %s: database error message. */
						__( 'Could not put the imported tables in place: %s', 'fw' ),
						$wpdb->last_error
					)
				);
			}
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

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
