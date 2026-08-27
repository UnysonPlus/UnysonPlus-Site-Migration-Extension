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
	 * Upper bound on rows read per query.
	 *
	 * Only an upper bound — the real number is worked out per table from how
	 * large its rows actually are. A fixed count is the wrong unit: 250 rows of
	 * wp_options is nothing, while 250 rows of a page-builder wp_postmeta
	 * averaging 40 KB each is 10 MB fetched before a single byte is sent, then
	 * copied again by escaping. That is how a source runs out of memory reading
	 * its own database.
	 */
	const MAX_ROWS_PER_QUERY = 250;

	/**
	 * Fewest rows to fetch at once, however enormous they are.
	 *
	 * A single row can exceed any budget and cannot be split, so the floor is
	 * one: better to move a huge row on its own than to refuse to move it.
	 */
	const MIN_ROWS_PER_QUERY = 1;

	/**
	 * Raw row bytes to aim for per query.
	 */
	const TARGET_QUERY_BYTES = 2097152; // 2 MB

	/**
	 * Largest SQL batch to build for a single request.
	 *
	 * Row count alone is not a safe bound. 250 rows of `wp_options` on a site
	 * with big autoloaded blobs, or 250 posts with substantial content, can be
	 * tens of megabytes — far past the `post_max_size` (commonly 8M) and
	 * `memory_limit` of an ordinary shared host. Bounding by BYTES as well means
	 * the request size stays predictable whatever the table looks like.
	 */
	/**
	 * Largest SQL batch to build for one request, before compression.
	 *
	 * 25 MB, matching the ceiling WP Migrate settled on
	 * (wpmdb_post_max_upper_size). Request COUNT is what makes a transfer slow
	 * over a real network, and 4 MB was leaving most of an ordinary
	 * post_max_size unused — the sender still trims this to fit whatever the
	 * destination actually accepts, so a small host is not handed something it
	 * will silently discard.
	 */
	const MAX_BATCH_BYTES = 26214400; // 25 MB

	/**
	 * Largest single INSERT statement to emit.
	 *
	 * Rows are grouped into multi-row INSERTs, which is the difference between
	 * the destination executing ten thousand statements and executing fifty.
	 * The cap keeps any one statement comfortably inside max_allowed_packet,
	 * whose default is 1 MB on older MySQL — exceeding it drops the connection
	 * rather than returning a tidy error.
	 */
	const MAX_STATEMENT_BYTES = 524288; // 512 KB

	/** @var FW_SM_Replacer */
	private $replacer;

	/** @var string Absolute path of the .sql file being written. */
	private $dump_path;

	/** @var string[] ALTER statements deferred to the end of the dump. */
	private $deferred_alters = [];

	/**
	 * Mode and prefix context, used to rename tables on the way out so the
	 * destination receives SQL that already targets the right table names.
	 *
	 * @var array
	 */
	private $ctx = [];

	/**
	 * Batch ceiling for this migration, in bytes.
	 *
	 * Defaults to MAX_BATCH_BYTES, lowered to whatever the destination will
	 * actually accept. A POST larger than the destination's post_max_size is
	 * not refused — PHP discards the body — so overshooting fails silently,
	 * which is the worst way for it to fail.
	 *
	 * @var int
	 */
	private $max_batch = self::MAX_BATCH_BYTES;

	/**
	 * @param string         $dump_path Unused — retained so the signature stays
	 *                                  stable; SQL is streamed, never written.
	 * @param FW_SM_Replacer $replacer
	 */
	public function __construct( $dump_path, FW_SM_Replacer $replacer, array $ctx = [] ) {
		$this->dump_path = $dump_path;
		$this->replacer  = $replacer;
		$this->ctx       = $ctx;
	}

	/**
	 * The destination's name for a source table.
	 *
	 * @param string $table
	 *
	 * @return string
	 */
	/**
	 * Cap batches at what the destination can receive.
	 *
	 * @param int $bytes
	 *
	 * @return void
	 */
	public function set_max_batch( $bytes ) {
		$bytes = (int) $bytes;

		if ( $bytes > 0 ) {
			$this->max_batch = max( 65536, min( self::MAX_BATCH_BYTES, $bytes ) );
		}
	}

	private function target_table( $table ) {
		return empty( $this->ctx )
			? $table
			: FW_SM_Multisite::map_table( $table, $this->ctx );
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
	public static function list_tables( array $ctx = [] ) {
		global $wpdb;

		$tables = [];

		// On a network $wpdb->prefix is the CURRENT blog's prefix, which would
		// miss every other site. Table discovery always works from the base
		// prefix and lets the mode's filter decide what belongs.
		$prefix = $wpdb->base_prefix;
		$like   = $wpdb->esc_like( $prefix ) . '%';

		$mode   = $ctx['mode'] ?? FW_SM_Multisite::MODE_SINGLE;
		$filter = FW_SM_Multisite::table_filter( $mode, $prefix, (int) ( $ctx['source_blog_id'] ?? 1 ) );

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

			// Staging tables from an interrupted incoming migration are not data.
			if ( 0 === strpos( $name, FW_SM_Importer::TEMP_PREFIX ) ) {
				continue;
			}

			// The mode decides which of a network's tables are in scope.
			if ( ! $filter( $name ) ) {
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
	 * Write a table's schema. Returns the deferred ALTERs it produced.
	 *
	 * @param string $table
	 *
	 * @return true|WP_Error
	 */
	public function build_schema( $table ) {
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

		$target = $this->target_table( $table );

		$create = $this->defer_foreign_keys( $row[1], $target );

		// SHOW CREATE TABLE names the source table; the destination needs its
		// own name, so swap it in the statement itself.
		if ( $target !== $table ) {
			$create = preg_replace(
				'/^CREATE TABLE `' . preg_quote( $table, '/' ) . '`/',
				'CREATE TABLE `' . $target . '`',
				$create,
				1
			);
		}

		return "-- Table: {$target}\n"
		       . "DROP TABLE IF EXISTS `{$target}`;\n"
		       . $create . ";\n"
		       . implode( "\n", $this->deferred_alters )
		       . ( $this->deferred_alters ? "\n" : '' );
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
	public function build_rows( $table, $key_col, $after_key, $offset = 0 ) {
		global $wpdb;

		// Pages of rows are read in a LOOP until the batch is full, rather than
		// one query per request.
		//
		// This is the difference between a migration that takes minutes and one
		// that takes hours, and it is not obvious: the page size has to stay
		// small to bound memory when rows are large, but a request that carries
		// only one small page spends nearly all of its time on round-trip
		// latency. Sizing the page for memory and the BATCH for throughput lets
		// both be right — a 25 MB batch built from many 25-row pages.
		$page      = self::rows_per_query( $table );
		$ceiling   = self::memory_ceiling();
		$max_batch = $this->max_batch;

		$buffer    = '';
		$count     = 0;
		$last_key  = $after_key;
		$cur_offset = (int) $offset;
		$done      = false;
		$target    = $this->target_table( $table );

		while ( strlen( $buffer ) < $max_batch ) {
			// Stop filling if this process is running out of room. Better a
			// smaller batch than a fatal halfway through building one.
			if ( memory_get_usage( true ) > $ceiling ) {
				break;
			}

			if ( '' !== $key_col ) {
				// Keyset pagination — flat cost however deep into the table.
				if ( null === $last_key || '' === $last_key ) {
					$sql = $wpdb->prepare(
						"SELECT * FROM `{$table}` ORDER BY `{$key_col}` ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$page
					);
				} else {
					$sql = $wpdb->prepare(
						"SELECT * FROM `{$table}` WHERE `{$key_col}` > %s ORDER BY `{$key_col}` ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$last_key,
						$page
					);
				}
			} else {
				// No usable primary key. Offset is the only option; such tables
				// are rare and almost always small.
				$sql = $wpdb->prepare(
					"SELECT * FROM `{$table}` LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$page,
					$cur_offset
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
				$done = true;
				break;
			}

			$columns  = array_keys( $rows[0] );
			$col_list = '`' . implode( '`, `', $columns ) . '`';

			$tuples     = [];
			$tuple_size = 0;

			foreach ( $rows as $row ) {
				if ( '' !== $key_col && array_key_exists( $key_col, $row ) ) {
					$last_key = $row[ $key_col ];
				}

				$values = [];

				foreach ( $row as $value ) {
					// Replacement happens here, while the value is still a live
					// PHP value — after this it is a SQL string literal and
					// rewriting it safely would mean parsing it back out again.
					$values[] = $this->quote( $this->replacer->replace( $value ) );
				}

				$tuple       = '(' . implode( ', ', $values ) . ')';
				$tuples[]    = $tuple;
				$tuple_size += strlen( $tuple ) + 2;

				$count++;

				// One statement must stay under max_allowed_packet.
				if ( $tuple_size >= self::MAX_STATEMENT_BYTES ) {
					$buffer    .= "INSERT INTO `{$target}` ({$col_list}) VALUES " . implode( ', ', $tuples ) . ";\n";
					$tuples     = [];
					$tuple_size = 0;
				}
			}

			if ( ! empty( $tuples ) ) {
				$buffer .= "INSERT INTO `{$target}` ({$col_list}) VALUES " . implode( ', ', $tuples ) . ";\n";
			}

			$fetched     = count( $rows );
			$cur_offset += $fetched;

			unset( $rows, $tuples );

			// A short page means the table is exhausted.
			if ( $fetched < $page ) {
				$done = true;
				break;
			}

			// Checked AFTER appending as well as before. The condition at the
			// top of the loop can only ever be true of the previous page, so on
			// its own it lets a batch drift past the ceiling by however much the
			// last page added — and the ceiling exists because the destination
			// silently discards a body that exceeds post_max_size.
			if ( strlen( $buffer ) >= $max_batch ) {
				break;
			}
		}

		return [
			'sql'          => $buffer,
			'rows_written' => $count,
			'last_key'     => $last_key,
			'offset'       => $cur_offset,
			'done'         => $done,
		];
	}

	/**
	 * How much memory this process may use before it stops filling a batch.
	 *
	 * @return int Bytes.
	 */
	private static function memory_ceiling() {
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit <= 0 ) {
			return PHP_INT_MAX;
		}

		// Half. The batch itself, the rows behind it and WordPress all have to
		// fit, and gzip needs headroom afterwards.
		return (int) ( $limit * 0.5 );
	}

	/**
	 * How many rows of THIS table to fetch at once.
	 *
	 * Derived from the table's average row size, so the memory a query costs is
	 * roughly constant whatever the table looks like. information_schema's
	 * numbers are estimates and can be well off, which is fine — this only has
	 * to be the right order of magnitude to keep a query bounded.
	 *
	 * @param string $table
	 *
	 * @return int
	 */
	public static function rows_per_query( $table ) {
		global $wpdb;

		static $cache = [];

		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT data_length, table_rows FROM information_schema.TABLES
				  WHERE table_schema = %s AND table_name = %s',
				DB_NAME,
				$table
			),
			ARRAY_A
		);

		$limit = self::MAX_ROWS_PER_QUERY;

		$rows  = isset( $row['table_rows'] ) ? (int) $row['table_rows'] : 0;
		$bytes = isset( $row['data_length'] ) ? (int) $row['data_length'] : 0;

		$avg_row = 0;

		if ( $rows > 0 && $bytes > 0 ) {
			$avg_row = (int) ceil( $bytes / $rows );

			if ( $avg_row > 0 ) {
				$limit = (int) floor( self::TARGET_QUERY_BYTES / $avg_row );
			}
		}

		$limit = max( self::MIN_ROWS_PER_QUERY, min( self::MAX_ROWS_PER_QUERY, $limit ) );

		// The average is a poor guide when rows vary wildly, and in WordPress
		// they do: a page-builder postmeta table can average 25 KB while its
		// largest row is 3.4 MB — a factor of 140. Sizing the fetch from the
		// average then means a query that happens to land on the big rows pulls
		// hundreds of megabytes into memory at once, and the runner spends the
		// rest of the migration yielding on its memory budget after a single
		// batch, paying a fresh WordPress bootstrap for each one.
		//
		// So once rows are large on average, fetch far fewer of them. The batch
		// is bounded by bytes anyway, so this costs an extra query or two on
		// tables that turn out to be uniform, and averts an out-of-memory stall
		// on the ones that are not.
		if ( $avg_row > 8192 ) {
			$limit = min( $limit, 25 );
		}

		/**
		 * Filters how many rows of a table are read per query.
		 *
		 * @param int    $limit
		 * @param string $table
		 */
		$limit = (int) apply_filters( 'fw_ext_site_migration_rows_per_query', $limit, $table );

		$cache[ $table ] = max( 1, $limit );

		return $cache[ $table ];
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

		$escaped = $wpdb->_real_escape( (string) $value );

		// _real_escape() does not only escape. Its last act is
		// add_placeholder_escape(), which swaps every literal % in the value
		// for a hash of the form {<64 hex>} so that a later prepare() cannot
		// mistake it for a format specifier. WordPress undoes that on the way
		// into the database, via a 'query' filter, and the round trip is
		// invisible — as long as both halves happen in the same process.
		//
		// Here they do not. This SQL is executed by a DIFFERENT WordPress, and
		// the hash is randomly salted per wpdb instance, so the destination
		// looks for its own hash, does not find ours, and stores the
		// placeholder verbatim. Every % becomes 66 characters of hex in the
		// migrated data — which breaks the length prefix of any serialized
		// value containing one, so the site silently falls back to defaults
		// for that option. Theme Settings full of CSS percentages are exactly
		// the sort of value this ruins.
		//
		// Undone immediately, because only this instance can undo it.
		$escaped = $wpdb->remove_placeholder_escape( $escaped );

		return "'" . $escaped . "'";
	}

}
