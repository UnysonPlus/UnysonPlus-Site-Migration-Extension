<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The background runner.
 *
 * This is the answer to the question every migration tool has to answer: how do
 * you run twenty minutes of work inside a thirty-second PHP request?
 *
 * You do not. You run a slice, save your place, and ask the server to call you
 * back. Each slice is a fresh PHP process that reads the migration state, does
 * as much as it safely can inside a time and memory budget, writes the state
 * back, and fires a non-blocking loopback request at itself before exiting. A
 * WP-Cron healthcheck watches the chain and restarts it if a slice dies without
 * dispatching a successor.
 *
 * Two consequences worth stating plainly, because they are the whole point:
 * closing the browser does not stop a migration, and a PHP timeout costs one
 * slice rather than the job.
 *
 * The loop deliberately does ONE stage's worth of work per pass and then yields.
 * Draining everything in a single pass would be faster in the happy case and far
 * worse in every other one: state would be saved rarely, the progress bar would
 * sit still, and a crash would lose more.
 */
class FW_SM_Runner {

	const AJAX_ACTION   = 'fw_sm_run';
	const CRON_HOOK     = 'fw_sm_healthcheck';
	const LOCK_TRANSIENT = 'fw_sm_lock';
	const TOKEN_OPTION  = 'fw_sm_run_token';

	/**
	 * How long the process lock is held. Longer than the minimum cron interval,
	 * so the healthcheck cannot barge in on a slice that is merely slow.
	 */
	const LOCK_SECONDS = 120;

	/**
	 * Fraction of max_execution_time a slice will consume before yielding.
	 */
	const TIME_BUDGET_RATIO = 0.6;

	/**
	 * Fraction of memory_limit a slice will consume before yielding.
	 */
	const MEMORY_BUDGET_RATIO = 0.8;

	/**
	 * Give up on a job after this many failed attempts.
	 */
	const MAX_ATTEMPTS = 3;

	/** @var float Unix timestamp with microseconds when this slice started. */
	private $started_at;

	/** @var int Bytes of memory this slice may use before yielding. */
	private $memory_ceiling;

	/** @var float Seconds this slice may run before yielding. */
	private $time_ceiling;

	/**
	 * Wire up the loopback endpoint and the healthcheck.
	 *
	 * @return void
	 */
	public function register() {
		// The loopback request carries no cookies, so it arrives logged out and
		// must be registered nopriv. It is authorised by a single-use-per-run
		// token compared with hash_equals(), not by a capability.
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ $this, 'handle_run' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle_run' ] );

		add_action( self::CRON_HOOK, [ $this, 'handle_healthcheck' ] );
		add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );
	}

	/**
	 * A one-minute schedule for the healthcheck.
	 *
	 * @param array $schedules
	 *
	 * @return array
	 * @handles cron_schedules
	 */
	public function add_cron_schedule( $schedules ) {
		if ( ! isset( $schedules['fw_sm_minute'] ) ) {
			$schedules['fw_sm_minute'] = [
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every minute (UnysonPlus migration)', 'fw' ),
			];
		}

		return $schedules;
	}

	/**
	 * Start a migration: create state, ensure the queue table, kick the chain.
	 *
	 * @param string   $direction 'export' or 'import'.
	 * @param string[] $stages
	 * @param array    $options
	 *
	 * @return array|WP_Error The new state.
	 */
	public function start( $direction, array $stages, array $options = [] ) {
		// The last line of defence, not the first. The screen hides the form and
		// handle_start() refuses too, but a migration can also be started from
		// code, and on multisite starting one is actively harmful — so the gate
		// lives here as well, where every path has to pass through it.
		if ( ! FW_Extension_Site_Migration::is_supported() ) {
			return new WP_Error(
				'fw_sm_unsupported_install',
				FW_Extension_Site_Migration::unsupported_reason()
			);
		}

		if ( FW_SM_State::is_running() ) {
			return new WP_Error(
				'fw_sm_already_running',
				__( 'A migration is already running. Cancel it before starting another.', 'fw' )
			);
		}

		$table = FW_SM_Queue::ensure_table();

		if ( is_wp_error( $table ) ) {
			return $table;
		}

		FW_SM_Queue::truncate();
		FW_SM_State::clear();

		$state = FW_SM_State::create( $direction, $stages, $options );

		// A fresh token per migration: a token that leaked from a previous run's
		// logs cannot be replayed against this one.
		update_option( self::TOKEN_OPTION, wp_generate_password( 40, false ), false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'fw_sm_minute', self::CRON_HOOK );
		}

		FW_SM_State::log( __( 'Migration started.', 'fw' ) );

		$this->dispatch();

		return $state;
	}

	/**
	 * Stop a migration and clean up after it.
	 *
	 * @return void
	 */
	public function cancel() {
		$state = FW_SM_State::get();

		if ( null === $state ) {
			return;
		}

		FW_SM_State::log( __( 'Migration cancelled.', 'fw' ) );
		FW_SM_State::finish( 'cancelled' );

		FW_SM_Queue::truncate();

		$this->release_lock();
		$this->unschedule();

		// A half-written export archive is useless and can be very large.
		if ( 'export' === $state['direction'] && ! empty( $state['options']['archive_path'] ) ) {
			$archive = new FW_SM_Archive( $state['options']['archive_path'] );
			$archive->delete();

			$this->delete_working_dump( $state );
		}

		// A cancelled import has staging tables and an unpacked copy of the whole
		// archive on disk. The destination was never touched — the swap only
		// happens in finalize — so both are pure waste and go now.
		if ( 'import' === $state['direction'] ) {
			FW_SM_Importer::drop_staging_tables();
			$this->delete_work_dir( $state );
		}
	}

	/**
	 * Fire a non-blocking loopback request to continue the chain.
	 *
	 * @return void
	 */
	public function dispatch() {
		if ( ! FW_SM_State::is_running() ) {
			return;
		}

		$url = add_query_arg(
			[
				'action' => self::AJAX_ACTION,
				'token'  => get_option( self::TOKEN_OPTION, '' ),
			],
			admin_url( 'admin-ajax.php' )
		);

		wp_remote_post(
			$url,
			[
				// Fire and forget. Waiting for the response would serialise the
				// whole migration into one request, which is the thing we are
				// specifically avoiding.
				'blocking'  => false,
				'timeout'   => 0.01,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'   => [],
			]
		);
	}

	/**
	 * The loopback endpoint.
	 *
	 * @return void
	 * @handles wp_ajax_nopriv_fw_sm_run
	 */
	public function handle_run() {
		$expected = (string) get_option( self::TOKEN_OPTION, '' );
		$given    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		// hash_equals, not ===, so a token cannot be recovered a byte at a time
		// by timing the comparison.
		if ( '' === $expected || ! hash_equals( $expected, $given ) ) {
			status_header( 403 );
			wp_die( '', '', [ 'response' => 403 ] );
		}

		// The response body is never read — the caller is not blocking on it —
		// so close the connection and let the slice run without holding a socket.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			echo 'ok';
			fastcgi_finish_request();
		}

		ignore_user_abort( true );

		$this->run_slice();

		wp_die( '', '', [ 'response' => 200 ] );
	}

	/**
	 * The healthcheck: restart a chain that stopped dispatching.
	 *
	 * @return void
	 * @handles fw_sm_healthcheck
	 */
	public function handle_healthcheck() {
		if ( ! FW_SM_State::is_running() ) {
			$this->unschedule();

			return;
		}

		// A held lock means a slice is genuinely working; leave it alone.
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}

		FW_SM_State::log( __( 'Resuming after an interrupted step.', 'fw' ) );

		$this->dispatch();
	}

	/**
	 * Do one slice of work, then hand off to the next.
	 *
	 * @return void
	 */
	public function run_slice() {
		if ( ! $this->acquire_lock() ) {
			return;
		}

		$this->begin_budget();

		try {
			while ( $this->has_budget() ) {
				$state = FW_SM_State::get();

				if ( null === $state || 'running' !== $state['status'] ) {
					break;
				}

				$result = empty( $state['initialized'] )
					? $this->initialize_next_stage( $state )
					: $this->process_next_stage( $state );

				if ( is_wp_error( $result ) ) {
					$this->fail( $result );

					return;
				}

				// Nothing left to do at all.
				if ( 'complete' === $result ) {
					$this->complete();

					return;
				}
			}
		} catch ( Throwable $e ) {
			// A fatal in one slice must not leave the migration wedged at
			// "running" forever with no explanation.
			$this->fail(
				new WP_Error(
					'fw_sm_exception',
					sprintf(
						/* translators: %s: error message. */
						__( 'The migration stopped unexpectedly: %s', 'fw' ),
						$e->getMessage()
					)
				)
			);

			return;
		} finally {
			$this->release_lock();
		}

		// Budget spent but work remains — hand off to a fresh process.
		if ( FW_SM_State::is_running() ) {
			$this->dispatch();
		}
	}

	/**
	 * Initialize the first stage that has not been sized yet.
	 *
	 * @param array $state
	 *
	 * @return string|WP_Error 'continue' | 'complete'
	 */
	private function initialize_next_stage( $state ) {
		$all_done = true;

		foreach ( $state['stages'] as $index => $stage_data ) {
			if ( ! empty( $stage_data['initialized'] ) ) {
				continue;
			}

			$all_done = false;

			$stage = $stage_data['stage'];

			$result = $this->initialize_stage( $stage, $state, $index );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			break; // One stage per pass, so the UI sees scanning progress.
		}

		if ( $all_done ) {
			FW_SM_State::update( [ 'initialized' => true ] );
			FW_SM_State::reset_cursor();
			FW_SM_State::log( __( 'Finished sizing the migration.', 'fw' ) );
		}

		return 'continue';
	}

	/**
	 * Size one stage and enqueue its jobs.
	 *
	 * @param string $stage
	 * @param array  $state
	 * @param int    $index
	 *
	 * @return true|WP_Error
	 */
	private function initialize_stage( $stage, $state, $index ) {
		if ( FW_SM_Stage::FINALIZE === $stage ) {
			return $this->mark_initialized( $index, 0 );
		}

		if ( 'import' === $state['direction'] ) {
			return $this->initialize_import_stage( $stage, $state, $index );
		}

		if ( FW_SM_Stage::DATABASE === $stage ) {
			// The dump is written to a working file and only added to the archive
			// once it is complete, so a half-written dump never lands inside a zip.
			$tables      = FW_SM_DB_Export::list_tables();
			$total_bytes = 0;
			$jobs        = [];

			foreach ( $tables as $table ) {
				$jobs[] = [
					'payload' => [ 'table' => $table['name'], 'rows' => $table['rows'] ],
					'bytes'   => $table['bytes'],
				];

				$total_bytes += $table['bytes'];
			}

			FW_SM_Queue::push_many( FW_SM_Stage::DATABASE, $jobs );

			FW_SM_State::log(
				sprintf(
					/* translators: %d: number of database tables. */
					_n( 'Found %d database table.', 'Found %d database tables.', count( $jobs ), 'fw' ),
					count( $jobs )
				)
			);

			return $this->mark_initialized( $index, $total_bytes );
		}

		// File stages scan incrementally, carrying a directory stack between passes.
		$root = FW_SM_Stage::source_dir( $stage );

		if ( null === $root ) {
			// A missing mu-plugins directory is ordinary, not an error.
			return $this->mark_initialized( $index, 0 );
		}

		$scanner = new FW_SM_File_Scanner(
			$stage,
			$root,
			FW_SM_File_Scanner::default_excludes( $stage )
		);

		$stack_key = 'scan_stack_' . $stage;
		$bytes_key = 'scan_bytes_' . $stage;

		$stack = FW_SM_State::cursor( $stack_key, [ '' ] );
		$bytes = (int) FW_SM_State::cursor( $bytes_key, 0 );

		$modified_after = (int) ( $state['options']['modified_after'] ?? 0 );

		$result = $scanner->scan_slice( (array) $stack, $modified_after );

		$bytes += $result['bytes_found'];

		if ( $result['done'] ) {
			FW_SM_State::set_cursor( [ $stack_key => [], $bytes_key => $bytes ] );

			FW_SM_State::log(
				sprintf(
					/* translators: 1: stage label, 2: formatted byte size. */
					__( 'Scanned %1$s — %2$s to copy.', 'fw' ),
					FW_SM_Stage::label( $stage ),
					size_format( $bytes )
				)
			);

			return $this->mark_initialized( $index, $bytes );
		}

		FW_SM_State::set_cursor( [ $stack_key => $result['stack'], $bytes_key => $bytes ] );

		return true;
	}

	/**
	 * Record a stage's target size and mark it ready to process.
	 *
	 * @param int $index
	 * @param int $bytes
	 *
	 * @return true
	 */
	private function mark_initialized( $index, $bytes ) {
		$state = FW_SM_State::get();

		if ( null === $state ) {
			return true;
		}

		$state['stages'][ $index ]['initialized']            = true;
		$state['stages'][ $index ]['total']['target_bytes']  = (int) $bytes;
		$state['total']['target_bytes']                     += (int) $bytes;

		FW_SM_State::save( $state );

		return true;
	}

	/**
	 * Process the first stage that is not finished.
	 *
	 * @param array $state
	 *
	 * @return string|WP_Error 'continue' | 'complete'
	 */
	private function process_next_stage( $state ) {
		foreach ( $state['stages'] as $index => $stage_data ) {
			if ( ! empty( $stage_data['processed'] ) ) {
				continue;
			}

			$stage = $stage_data['stage'];

			if ( FW_SM_Stage::FINALIZE === $stage ) {
				$result = $this->process_finalize( $state );
			} elseif ( 'import' === $state['direction'] ) {
				$result = $this->process_import_stage( $stage, $state );
			} elseif ( FW_SM_Stage::DATABASE === $stage ) {
				$result = $this->process_database( $state );
			} else {
				$result = $this->process_files( $stage, $state );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$this->record_progress( $index, $result );

			return 'continue';
		}

		return 'complete';
	}

	/**
	 * Fold a stage result back into the totals, and close the stage if done.
	 *
	 * @param int   $index
	 * @param array $result [ 'processed_bytes' => int, 'complete' => bool ]
	 *
	 * @return void
	 */
	private function record_progress( $index, array $result ) {
		$state = FW_SM_State::get();

		if ( null === $state ) {
			return;
		}

		$bytes = max( 0, (int) ( $result['processed_bytes'] ?? 0 ) );

		$state['stages'][ $index ]['total']['processed_bytes'] += $bytes;
		$state['total']['processed_bytes']                     += $bytes;

		if ( ! empty( $result['complete'] ) ) {
			$state['stages'][ $index ]['processed'] = true;

			// A stage's estimate is an estimate; snap the bar to the truth so a
			// finished stage never shows as 94% complete.
			$state['stages'][ $index ]['total']['processed_bytes'] =
				$state['stages'][ $index ]['total']['target_bytes'];
		}

		// Estimates can undershoot, and a progress bar past 100% looks broken.
		if ( $state['total']['processed_bytes'] > $state['total']['target_bytes'] ) {
			$state['total']['processed_bytes'] = $state['total']['target_bytes'];
		}

		FW_SM_State::save( $state );

		if ( ! empty( $result['complete'] ) ) {
			FW_SM_State::reset_cursor();
		}
	}

	/**
	 * Export one table, or a slice of one.
	 *
	 * @param array $state
	 *
	 * @return array|WP_Error
	 */
	private function process_database( $state ) {
		$jobs = FW_SM_Queue::peek( 2 );

		$current = null;
		$next    = null;

		foreach ( $jobs as $job ) {
			if ( FW_SM_Stage::DATABASE !== $job['stage'] ) {
				continue;
			}

			if ( null === $current ) {
				$current = $job;
			} else {
				$next = $job;
			}
		}

		if ( null === $current ) {
			// Queue drained: close the dump and fold it into the archive.
			$export = $this->make_exporter( $state );

			$export->set_deferred_alters( (array) FW_SM_State::cursor( 'deferred_alters', [] ) );

			$footer = $export->write_footer();

			if ( is_wp_error( $footer ) ) {
				return $footer;
			}

			$archive = new FW_SM_Archive( $state['options']['archive_path'] );

			$added = $archive->add_file( $this->dump_path( $state ), 'database.sql' );

			if ( is_wp_error( $added ) ) {
				return $added;
			}

			$this->delete_working_dump( $state );

			FW_SM_State::log( __( 'Database export finished.', 'fw' ) );

			return [ 'processed_bytes' => 0, 'complete' => true ];
		}

		$table  = $current['payload']['table'] ?? '';
		$export = $this->make_exporter( $state );

		$export->set_deferred_alters( (array) FW_SM_State::cursor( 'deferred_alters', [] ) );

		// First slice for this table: header (once per dump) and schema.
		if ( FW_SM_State::cursor( 'db_table' ) !== $table ) {
			if ( ! FW_SM_State::cursor( 'db_header_written' ) ) {
				$header = $export->write_header(
					[
						'site_url'     => $state['options']['source_url'] ?? '',
						'table_prefix' => $GLOBALS['wpdb']->prefix,
					]
				);

				if ( is_wp_error( $header ) ) {
					return $header;
				}

				FW_SM_State::set_cursor( [ 'db_header_written' => true ] );
			}

			$schema = $export->write_schema( $table );

			if ( is_wp_error( $schema ) ) {
				return $schema;
			}

			FW_SM_State::set_cursor(
				[
					'db_table'        => $table,
					'db_last_key'     => '',
					'db_offset'       => 0,
					'db_key_col'      => FW_SM_DB_Export::primary_key( $table ),
					'deferred_alters' => $export->get_deferred_alters(),
				]
			);
		}

		$key_col = (string) FW_SM_State::cursor( 'db_key_col', '' );

		$result = $export->write_rows(
			$table,
			$key_col,
			FW_SM_State::cursor( 'db_last_key', '' ),
			(int) FW_SM_State::cursor( 'db_offset', 0 )
		);

		if ( is_wp_error( $result ) ) {
			$attempts = FW_SM_Queue::bump_attempts( $current['id'] );

			if ( $attempts >= self::MAX_ATTEMPTS ) {
				return $result;
			}

			// Retryable: leave the job in place and let the next slice have a go.
			return [ 'processed_bytes' => 0, 'complete' => false ];
		}

		FW_SM_State::set_cursor(
			[
				'db_last_key' => $result['last_key'],
				'db_offset'   => $result['offset'],
			]
		);

		$rows = max( 1, (int) ( $current['payload']['rows'] ?? 1 ) );

		// Prorate this table's estimated bytes across its rows so a large table
		// shows steady movement instead of jumping when it finally finishes.
		$processed = (int) min(
			$current['bytes'],
			floor( $current['bytes'] / $rows ) * $result['rows_written']
		);

		if ( $result['done'] ) {
			FW_SM_Queue::delete( $current['id'] );

			FW_SM_State::set_cursor( [ 'db_table' => '' ] );

			// The last table of the stage is the one with nothing after it.
			if ( null === $next ) {
				return [ 'processed_bytes' => $processed, 'complete' => false ];
			}
		}

		return [ 'processed_bytes' => $processed, 'complete' => false ];
	}

	/**
	 * Copy a batch of files into the archive.
	 *
	 * @param string $stage
	 * @param array  $state
	 *
	 * @return array|WP_Error
	 */
	private function process_files( $stage, $state ) {
		$jobs = FW_SM_Queue::peek( FW_SM_Archive::BATCH_SIZE );

		$batch    = [];
		$job_ids  = [];
		$archive_dir = FW_SM_Stage::archive_dir( $stage );

		foreach ( $jobs as $job ) {
			if ( $stage !== $job['stage'] ) {
				break; // Different stage on top: this one is finished.
			}

			$batch[] = [
				'absolute' => $job['payload']['absolute'] ?? '',
				'archive'  => $archive_dir . '/' . ( $job['payload']['path'] ?? '' ),
			];

			$job_ids[] = $job['id'];
		}

		if ( empty( $batch ) ) {
			FW_SM_State::log(
				sprintf(
					/* translators: %s: stage label. */
					__( '%s finished.', 'fw' ),
					FW_SM_Stage::label( $stage )
				)
			);

			return [ 'processed_bytes' => 0, 'complete' => true ];
		}

		$archive = new FW_SM_Archive( $state['options']['archive_path'] );

		$result = $archive->add_batch( $batch );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $job_ids as $id ) {
			FW_SM_Queue::delete( $id );
		}

		return [ 'processed_bytes' => $result['bytes'], 'complete' => false ];
	}

	/**
	 * Size an import stage.
	 *
	 * @param string $stage
	 * @param array  $state
	 * @param int    $index
	 *
	 * @return true|WP_Error
	 */
	private function initialize_import_stage( $stage, $state, $index ) {
		$archive = new FW_SM_Archive( $state['options']['archive_path'] );

		if ( FW_SM_Stage::EXTRACT === $stage ) {
			$measured = $archive->measure();

			if ( is_wp_error( $measured ) ) {
				return $measured;
			}

			FW_SM_State::log(
				sprintf(
					/* translators: 1: number of files, 2: formatted byte size. */
					__( 'Archive holds %1$d files, %2$s unpacked.', 'fw' ),
					$measured['entries'],
					size_format( $measured['bytes'] )
				)
			);

			FW_SM_State::set_cursor( [ 'extract_total' => $measured['entries'] ] );

			return $this->mark_initialized( $index, $measured['bytes'] );
		}

		if ( FW_SM_Stage::DATABASE === $stage ) {
			$dump = $this->import_work_dir( $state ) . '/database.sql';

			$bytes = file_exists( $dump ) ? (int) filesize( $dump ) : 0;

			return $this->mark_initialized( $index, $bytes );
		}

		// File stages: scan the extracted tree and enqueue a copy job per file.
		$source = $this->import_work_dir( $state ) . '/' . FW_SM_Stage::archive_dir( $stage );

		if ( ! is_dir( $source ) ) {
			return $this->mark_initialized( $index, 0 );
		}

		// No exclusions on the way back in — the archive already holds exactly
		// what the export decided to keep, and second-guessing it here would
		// silently drop files the user asked for.
		$scanner = new FW_SM_File_Scanner( $stage, $source, [] );

		$stack_key = 'scan_stack_' . $stage;
		$bytes_key = 'scan_bytes_' . $stage;

		$stack = FW_SM_State::cursor( $stack_key, [ '' ] );
		$bytes = (int) FW_SM_State::cursor( $bytes_key, 0 );

		$result = $scanner->scan_slice( (array) $stack, 0 );

		$bytes += $result['bytes_found'];

		if ( $result['done'] ) {
			FW_SM_State::set_cursor( [ $stack_key => [], $bytes_key => $bytes ] );

			return $this->mark_initialized( $index, $bytes );
		}

		FW_SM_State::set_cursor( [ $stack_key => $result['stack'], $bytes_key => $bytes ] );

		return true;
	}

	/**
	 * Process an import stage.
	 *
	 * @param string $stage
	 * @param array  $state
	 *
	 * @return array|WP_Error
	 */
	private function process_import_stage( $stage, $state ) {
		if ( FW_SM_Stage::EXTRACT === $stage ) {
			return $this->process_extract( $state );
		}

		if ( FW_SM_Stage::DATABASE === $stage ) {
			return $this->process_import_database( $state );
		}

		return $this->process_import_files( $stage, $state );
	}

	/**
	 * Unpack a slice of the archive.
	 *
	 * @param array $state
	 *
	 * @return array|WP_Error
	 */
	private function process_extract( $state ) {
		$archive = new FW_SM_Archive( $state['options']['archive_path'] );

		$offset = (int) FW_SM_State::cursor( 'extract_offset', 0 );

		$result = $archive->extract_slice( $this->import_work_dir( $state ), $offset, 300 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		FW_SM_State::set_cursor( [ 'extract_offset' => $result['offset'] ] );

		if ( $result['skipped'] > 0 ) {
			FW_SM_State::log(
				sprintf(
					/* translators: %d: number of entries. */
					_n(
						'Skipped %d archive entry with an unsafe or unreadable path.',
						'Skipped %d archive entries with unsafe or unreadable paths.',
						$result['skipped'],
						'fw'
					),
					$result['skipped']
				)
			);
		}

		if ( $result['done'] ) {
			FW_SM_State::log( __( 'Archive unpacked.', 'fw' ) );
		}

		return [ 'processed_bytes' => $result['bytes'], 'complete' => ! empty( $result['done'] ) ];
	}

	/**
	 * Load a slice of the dump into the staging tables.
	 *
	 * @param array $state
	 *
	 * @return array|WP_Error
	 */
	private function process_import_database( $state ) {
		$dump = $this->import_work_dir( $state ) . '/database.sql';

		if ( ! file_exists( $dump ) ) {
			return new WP_Error(
				'fw_sm_no_dump',
				__( 'The archive contains no database dump, so there is nothing to import.', 'fw' )
			);
		}

		$importer = new FW_SM_Importer( $dump );

		$offset = (int) FW_SM_State::cursor( 'import_offset', 0 );

		$result = $importer->run_slice( $offset );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Table names and deferred constraints accumulate across slices; finalize
		// needs the complete set to do the swap.
		$tables   = array_values( array_unique( array_merge( (array) FW_SM_State::cursor( 'import_tables', [] ), $result['tables'] ) ) );
		$deferred = array_merge( (array) FW_SM_State::cursor( 'import_deferred', [] ), $result['deferred'] );

		FW_SM_State::set_cursor(
			[
				'import_offset'   => $result['offset'],
				'import_tables'   => $tables,
				'import_deferred' => $deferred,
			]
		);

		$processed = max( 0, $result['offset'] - $offset );

		if ( $result['done'] ) {
			FW_SM_State::log(
				sprintf(
					/* translators: %d: number of database tables. */
					_n(
						'Loaded %d table into staging.',
						'Loaded %d tables into staging.',
						count( $tables ),
						'fw'
					),
					count( $tables )
				)
			);
		}

		return [ 'processed_bytes' => $processed, 'complete' => ! empty( $result['done'] ) ];
	}

	/**
	 * Copy a batch of extracted files into place.
	 *
	 * @param string $stage
	 * @param array  $state
	 *
	 * @return array|WP_Error
	 */
	private function process_import_files( $stage, $state ) {
		$jobs = FW_SM_Queue::peek( 200 );

		$destination_root = FW_SM_Stage::source_dir( $stage );

		if ( null === $destination_root ) {
			// mu-plugins may not exist on the destination yet; create it rather
			// than silently dropping the stage's files.
			$destination_root = $this->fallback_destination( $stage );

			if ( null === $destination_root || ! wp_mkdir_p( $destination_root ) ) {
				return new WP_Error(
					'fw_sm_no_destination',
					sprintf(
						/* translators: %s: stage label. */
						__( 'There is nowhere to restore %s to on this install.', 'fw' ),
						FW_SM_Stage::label( $stage )
					)
				);
			}
		}

		$copied  = 0;
		$bytes   = 0;
		$skipped = 0;

		foreach ( $jobs as $job ) {
			if ( $stage !== $job['stage'] ) {
				break;
			}

			$source = $job['payload']['absolute'] ?? '';
			$target = trailingslashit( $destination_root ) . ( $job['payload']['path'] ?? '' );

			FW_SM_Queue::delete( $job['id'] );

			if ( '' === $source || ! is_readable( $source ) ) {
				$skipped++;
				continue;
			}

			if ( ! wp_mkdir_p( dirname( $target ) ) ) {
				$skipped++;
				continue;
			}

			if ( @copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$copied++;
				$bytes += (int) @filesize( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				// One unwritable file should not end a restore that is otherwise
				// fine — a read-only plugin directory is common on managed hosts.
				$skipped++;
			}
		}

		if ( empty( $jobs ) || 0 === $copied + $skipped ) {
			FW_SM_State::log(
				sprintf(
					/* translators: %s: stage label. */
					__( '%s restored.', 'fw' ),
					FW_SM_Stage::label( $stage )
				)
			);

			return [ 'processed_bytes' => 0, 'complete' => true ];
		}

		if ( $skipped > 0 ) {
			FW_SM_State::log(
				sprintf(
					/* translators: 1: number of files, 2: stage label. */
					__( 'Could not write %1$d file(s) in %2$s — continuing.', 'fw' ),
					$skipped,
					FW_SM_Stage::label( $stage )
				)
			);
		}

		return [ 'processed_bytes' => $bytes, 'complete' => false ];
	}

	/**
	 * Where a file stage restores to when the directory does not exist yet.
	 *
	 * @param string $stage
	 *
	 * @return string|null
	 */
	private function fallback_destination( $stage ) {
		switch ( $stage ) {
			case FW_SM_Stage::MUPLUGINS:
				return defined( 'WPMU_PLUGIN_DIR' )
					? wp_normalize_path( WPMU_PLUGIN_DIR )
					: wp_normalize_path( WP_CONTENT_DIR . '/mu-plugins' );
			default:
				return null;
		}
	}

	/**
	 * The working directory an import unpacks into.
	 *
	 * @param array $state
	 *
	 * @return string
	 */
	private function import_work_dir( $state ) {
		return ( $state['options']['archive_path'] ?? '' ) . '-work';
	}

	/**
	 * Close out the migration.
	 *
	 * @param array $state
	 *
	 * @return array|WP_Error
	 */
	private function process_finalize( $state ) {
		if ( 'import' === $state['direction'] ) {
			return $this->finalize_import( $state );
		}

		if ( 'export' === $state['direction'] ) {
			$archive = new FW_SM_Archive( $state['options']['archive_path'] );

			FW_SM_State::log(
				sprintf(
					/* translators: %s: formatted file size. */
					__( 'Archive complete — %s.', 'fw' ),
					size_format( $archive->size() )
				)
			);
		}

		return [ 'processed_bytes' => 0, 'complete' => true ];
	}

	/**
	 * Put an import live.
	 *
	 * This is the only moment the destination's own data is replaced, and the
	 * order matters. The carve-out is captured BEFORE the swap, because after it
	 * the options table is the archive's, not this site's — read it any later and
	 * you are preserving the source's values, which defeats the point.
	 *
	 * @param array $state
	 *
	 * @return array|WP_Error
	 */
	private function finalize_import( $state ) {
		$tables = (array) FW_SM_State::cursor( 'import_tables', [] );

		if ( empty( $tables ) ) {
			return new WP_Error(
				'fw_sm_import_no_tables',
				__( 'The import loaded no tables, so nothing was put live.', 'fw' )
			);
		}

		$dump     = $this->import_work_dir( $state ) . '/database.sql';
		$importer = new FW_SM_Importer( $dump );

		// Capture first — see the note above.
		$preserved = FW_SM_Importer::capture_preserved();

		FW_SM_State::log( __( 'Putting the imported database live…', 'fw' ) );

		$swapped = $importer->swap_into_place( $tables );

		if ( is_wp_error( $swapped ) ) {
			// The swap failed, so the destination is untouched. Clear the staging
			// tables rather than leaving a half-set behind.
			FW_SM_Importer::drop_staging_tables();

			return $swapped;
		}

		// Roles and this extension's own settings come back from the destination.
		FW_SM_Importer::restore_preserved(
			$preserved,
			(string) ( $state['options']['source_prefix'] ?? '' )
		);

		$deferred = (array) FW_SM_State::cursor( 'import_deferred', [] );

		if ( ! empty( $deferred ) ) {
			$failed = $importer->apply_deferred_constraints( $deferred );

			if ( ! empty( $failed ) ) {
				FW_SM_State::log(
					sprintf(
						/* translators: %d: number of constraints. */
						_n(
							'%d foreign key could not be recreated and was skipped.',
							'%d foreign keys could not be recreated and were skipped.',
							count( $failed ),
							'fw'
						),
						count( $failed )
					)
				);
			}
		}

		// Only now are the displaced originals expendable.
		$importer->drop_displaced( $tables );

		$this->delete_work_dir( $state );

		// Object caches and rewrite rules both describe a database that no longer
		// exists; leaving them would serve the old site from cache.
		wp_cache_flush();
		flush_rewrite_rules( false );

		FW_SM_State::log( __( 'Import complete. Log in again if your session ends.', 'fw' ) );

		return [ 'processed_bytes' => 0, 'complete' => true ];
	}

	/**
	 * Remove an import's working directory.
	 *
	 * @param array $state
	 *
	 * @return void
	 */
	private function delete_work_dir( $state ) {
		$dir = $this->import_work_dir( $state );

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Build an exporter bound to this migration's dump file and replacer.
	 *
	 * @param array $state
	 *
	 * @return FW_SM_DB_Export
	 */
	private function make_exporter( $state ) {
		$options = (array) $state['options'];

		$replacer = FW_SM_Replacer::for_site_move(
			$options['source_url'] ?? '',
			$options['target_url'] ?? '',
			$options['source_path'] ?? '',
			$options['target_path'] ?? ''
		);

		return new FW_SM_DB_Export( $this->dump_path( $state ), $replacer );
	}

	/**
	 * Working path of the .sql file, alongside the archive.
	 *
	 * @param array $state
	 *
	 * @return string
	 */
	private function dump_path( $state ) {
		return ( $state['options']['archive_path'] ?? '' ) . '.sql';
	}

	/**
	 * @param array $state
	 *
	 * @return void
	 */
	private function delete_working_dump( $state ) {
		$path = $this->dump_path( $state );

		if ( '' !== $path && file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * @return void
	 */
	private function complete() {
		FW_SM_State::log( __( 'Migration complete.', 'fw' ) );
		FW_SM_State::finish( 'complete' );

		FW_SM_Queue::truncate();

		$this->unschedule();
	}

	/**
	 * @param WP_Error $error
	 *
	 * @return void
	 */
	private function fail( WP_Error $error ) {
		FW_SM_State::log( $error->get_error_message() );
		FW_SM_State::finish( 'failed', $error->get_error_message() );

		$this->release_lock();
		$this->unschedule();
	}

	/**
	 * @return void
	 */
	private function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Take the process lock, so two chains cannot both work the queue.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_SECONDS );

		return true;
	}

	/**
	 * @return void
	 */
	private function release_lock() {
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Work out how long and how much memory this slice may use.
	 *
	 * @return void
	 */
	private function begin_budget() {
		$this->started_at = microtime( true );

		$max_execution = (int) ini_get( 'max_execution_time' );

		// 0 means unlimited (CLI, some FPM pools). Cap anyway so the loop still
		// yields, saves state and stays observable.
		if ( $max_execution <= 0 ) {
			$max_execution = 30;
		}

		$this->time_ceiling = max( 5, $max_execution * self::TIME_BUDGET_RATIO );

		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		$this->memory_ceiling = $limit > 0
			? (int) ( $limit * self::MEMORY_BUDGET_RATIO )
			: PHP_INT_MAX;
	}

	/**
	 * Is there room for another pass in this slice?
	 *
	 * @return bool
	 */
	private function has_budget() {
		if ( ( microtime( true ) - $this->started_at ) >= $this->time_ceiling ) {
			return false;
		}

		if ( memory_get_usage( true ) >= $this->memory_ceiling ) {
			return false;
		}

		// Refresh the lock so a genuinely long slice is not mistaken for a dead
		// one by the healthcheck.
		set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_SECONDS );

		return true;
	}
}
