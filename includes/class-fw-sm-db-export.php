<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Resumable database export.
 *
 * Writes a table out as SQL, a slice of rows at a time, appending to the dump
 * file and recording where it stopped so the next request continues from exactly
 * that row. Two decisions do most of the work here:
 *
 * 1. Pagination is by PRIMARY KEY, not LIMIT/OFFSET. `LIMIT 500 OFFSET 400000`
 *    makes MySQL walk and discard 400,000 rows to hand back 500, so a large
 *    table gets quadratically slower the further in you go and eventually cannot
 *    finish inside a request at all. Remembering the last key seen and asking for
 *    `WHERE id > ?` is flat.
 *
 * 2. Every value goes through the replacer on the way out, while it is still a
 *    live PHP value. Rewriting URLs at export time means the dump is already
 *    correct for its destination and no separate find-and-replace pass has to
 *    re-read the whole database afterwards.
 *
 * The CREATE statement has its foreign keys stripped into deferred ALTERs, so
 * the importer never has to care what order tables arrive in.
 */
class FW_SM_DB_Export {

	/**
	 * Rows read per query. Small enough that one slice fits comfortably in
	 * memory even for a table of large post_content values.
	 */
	const ROWS_PER_QUERY = 250;

	/**
	 * Bytes to buffer before flushing to disk.
	 */
	const WRITE_BUFFER = 262144; // 256 KB

	/** @var FW_SM_Replacer */
	private $replacer;

	/** @var string Absolute path of the .sql file being written. */
	private $dump_path;

	/** @var string[] ALTER statements deferred to the end of the dump. */
	private $deferred_alters = [];

	/**
	 * @param string         $dump_path
	 * @param FW_SM_Replacer $replacer
	 */
	public function __construct( $dump_path, FW_SM_Replacer $replacer ) {
		$this->dump_path = $dump_path;
		$this->replacer  = $replacer;
	}

	/**
	 * Every table this site owns, with its byte size and row count.
	 *
	 * Sizes come from information_schema, which is an estimate — for InnoDB it
	 * can be off by a good margin. That is fine: it is used to weight a progress
	 * bar, not to allocate anything.
	 *
	 * @return array[] Each: [ 'name', 'bytes', 'rows' ].
	 */
	public static function list_tables() {
		global $wpdb;

		$tables = [];

		$prefix = $wpdb->prefix;
		$like   = $wpdb->esc_like( $prefix ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT table_name AS name,
				        (data_length + index_length) AS bytes,
				        table_rows AS `rows`
				   FROM information_schema.TABLES
				  WHERE table_schema = %s
				    AND table_name LIKE %s
				    AND table_type = %s
			   ORDER BY table_name ASC',
				DB_NAME,
				$like,
				'BASE TABLE'
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$name = $row['name'];

			/**
			 * Filters whether a table is included in a migration export.
			 *
			 * Returning false skips the table entirely — useful for large
			 * regenerable caches (analytics logs, search indexes) that would
			 * dominate the archive for no benefit.
			 *
			 * @param bool   $include
			 * @param string $name Table name, including prefix.
			 */
			if ( ! apply_filters( 'fw_ext_site_migration_include_table', true, $name ) ) {
				continue;
			}

			// This extension's own queue is migration scaffolding, never content.
			if ( $name === FW_SM_Queue::table() ) {
				continue;
			}

			$tables[] = [
				'name'  => $name,
				'bytes' => max( 1024, (int) $row['bytes'] ),
				'rows'  => max( 0, (int) $row['rows'] ),
			];
		}

		return $tables;
	}

	/**
	 * Write the dump header — charset, timezone and the manifest comment.
	 *
	 * @param array $meta Source site metadata to record in the dump.
	 *
	 * @return true|WP_Error
	 */
	public function write_header( array $meta ) {
		$lines = [
			'-- UnysonPlus Site Migration database export',
			'-- Source: ' . ( $meta['site_url'] ?? '' ),
			'-- Generated: ' . gmdate( 'c' ),
			'-- Table prefix: ' . ( $meta['table_prefix'] ?? '' ),
			'',
			'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";',
			'SET time_zone = "+00:00";',
			'SET FOREIGN_KEY_CHECKS = 0;',
			'',
		];

		return $this->append( implode( "\n", $lines ) . "\n" );
	}

	/**
	 * Write a table's schema. Returns the deferred ALTERs it produced.
	 *
	 * @param string $table
	 *
	 * @return true|WP_Error
	 */
	public function write_schema( $table ) {
		global $wpdb;

		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $row[1] ) ) {
			return new WP_Error(
				'fw_sm_no_schema',
				sprintf(
					/* translators: %s: database table name. */
					__( 'Could not read the structure of table %s.', 'fw' ),
					$table
				)
			);
		}

		$create = $row[1];

		// Pull foreign keys out into ALTERs applied after every table exists, so
		// the importer can create tables in any order without tripping over a
		// constraint pointing at a table that has not arrived yet.
		$create = $this->defer_foreign_keys( $create, $table );

		$sql = "\n-- Table: {$table}\n"
		       . "DROP TABLE IF EXISTS `{$table}`;\n"
		       . $create . ";\n";

		return $this->append( $sql );
	}

	/**
	 * Export a slice of a table's rows.
	 *
	 * @param string     $table     Table name.
	 * @param string     $key_col   Primary key column, or '' when the table has none.
	 * @param string|int $after_key Export rows after this key value.
	 * @param int        $offset    Fallback offset, for keyless tables only.
	 *
	 * @return array|WP_Error [ 'rows_written', 'last_key', 'offset', 'done' ]
	 */
	public function write_rows( $table, $key_col, $after_key, $offset = 0 ) {
		global $wpdb;

		$limit = self::ROWS_PER_QUERY;

		if ( '' !== $key_col ) {
			// Keyset pagination — flat cost however deep into the table we are.
			if ( null === $after_key || '' === $after_key ) {
				$sql = $wpdb->prepare(
					"SELECT * FROM `{$table}` ORDER BY `{$key_col}` ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$limit
				);
			} else {
				$sql = $wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE `{$key_col}` > %s ORDER BY `{$key_col}` ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$after_key,
					$limit
				);
			}
		} else {
			// No primary key. Offset is the only option; such tables are rare and
			// almost always small (WooCommerce lookup tables, some plugin logs).
			$sql = $wpdb->prepare(
				"SELECT * FROM `{$table}` LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit,
				(int) $offset
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( null === $rows ) {
			return new WP_Error(
				'fw_sm_read_rows',
				sprintf(
					/* translators: 1: table name, 2: database error. */
					__( 'Could not read rows from %1$s. %2$s', 'fw' ),
					$table,
					$wpdb->last_error
				)
			);
		}

		if ( empty( $rows ) ) {
			return [
				'rows_written' => 0,
				'last_key'     => $after_key,
				'offset'       => (int) $offset,
				'done'         => true,
			];
		}

		$buffer   = '';
		$last_key = $after_key;
		$columns  = array_keys( $rows[0] );
		$col_list = '`' . implode( '`, `', $columns ) . '`';

		foreach ( $rows as $row ) {
			if ( '' !== $key_col && array_key_exists( $key_col, $row ) ) {
				$last_key = $row[ $key_col ];
			}

			$values = [];

			foreach ( $row as $value ) {
				$values[] = $this->quote( $this->replacer->replace( $value ) );
			}

			$buffer .= "INSERT INTO `{$table}` ({$col_list}) VALUES (" . implode( ', ', $values ) . ");\n";

			if ( strlen( $buffer ) >= self::WRITE_BUFFER ) {
				$written = $this->append( $buffer );

				if ( is_wp_error( $written ) ) {
					return $written;
				}

				$buffer = '';
			}
		}

		if ( '' !== $buffer ) {
			$written = $this->append( $buffer );

			if ( is_wp_error( $written ) ) {
				return $written;
			}
		}

		$count = count( $rows );

		return [
			'rows_written' => $count,
			'last_key'     => $last_key,
			'offset'       => (int) $offset + $count,
			// A short read means we have reached the end of the table.
			'done'         => $count < $limit,
		];
	}

	/**
	 * Write the deferred ALTER statements and close the dump.
	 *
	 * @return true|WP_Error
	 */
	public function write_footer() {
		$sql = "\n-- Deferred constraints\n";

		foreach ( $this->deferred_alters as $alter ) {
			$sql .= $alter . "\n";
		}

		$sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

		return $this->append( $sql );
	}

	/**
	 * Remember ALTERs across requests, since each slice runs in a fresh process.
	 *
	 * @param string[] $alters
	 *
	 * @return void
	 */
	public function set_deferred_alters( array $alters ) {
		$this->deferred_alters = $alters;
	}

	/**
	 * @return string[]
	 */
	public function get_deferred_alters() {
		return $this->deferred_alters;
	}

	/**
	 * Find a table's single-column primary key, if it has one.
	 *
	 * Composite keys are treated as no key at all — keyset pagination over a
	 * composite is doable but the added complexity is not worth it for the
	 * handful of small tables that have one.
	 *
	 * @param string $table
	 *
	 * @return string Column name, or '' when there is no usable key.
	 */
	public static function primary_key( $table ) {
		global $wpdb;

		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $keys ) || count( $keys ) > 1 ) {
			return '';
		}

		return (string) $keys[0]['Column_name'];
	}

	/**
	 * Strip FOREIGN KEY clauses out of a CREATE TABLE and stash them as ALTERs.
	 *
	 * @param string $create
	 * @param string $table
	 *
	 * @return string The CREATE statement without its constraints.
	 */
	private function defer_foreign_keys( $create, $table ) {
		if ( false === stripos( $create, 'FOREIGN KEY' ) ) {
			return $create;
		}

		$pattern = '/,?\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN KEY[^,)]+(?:\([^)]*\))?[^,)]*/i';

		if ( preg_match_all( $pattern, $create, $matches ) ) {
			foreach ( $matches[0] as $clause ) {
				$clause = trim( ltrim( trim( $clause ), ',' ) );

				if ( '' !== $clause ) {
					$this->deferred_alters[] = "ALTER TABLE `{$table}` ADD {$clause};";
				}
			}

			$create = preg_replace( $pattern, '', $create );
		}

		return $create;
	}

	/**
	 * Quote a value for SQL.
	 *
	 * NULL has to be written literally rather than as an empty string, or every
	 * nullable column arrives on the destination as '' and queries that test
	 * `IS NULL` quietly stop matching.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	private function quote( $value ) {
		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			$value = serialize( $value );
		}

		return "'" . $wpdb->_real_escape( (string) $value ) . "'";
	}

	/**
	 * Append to the dump file.
	 *
	 * @param string $sql
	 *
	 * @return true|WP_Error
	 */
	private function append( $sql ) {
		$handle = @fopen( $this->dump_path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return new WP_Error(
				'fw_sm_dump_open',
				sprintf(
					/* translators: %s: file path. */
					__( 'Could not open the database dump for writing: %s', 'fw' ),
					$this->dump_path
				)
			);
		}

		$written = fwrite( $handle, $sql ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $written ) {
			return new WP_Error(
				'fw_sm_dump_write',
				__( 'Could not write to the database dump. The disk may be full or read-only.', 'fw' )
			);
		}

		return true;
	}
}
