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

	/** Where the source stores the destination it is connected to. */
	const DESTINATION_OPTION_NAME = 'fw_sm_destination';

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
	 * Never shrink batches below this — past a point the round trips cost more
	 * than the data, and a destination that cannot take this much is broken
	 * rather than merely small.
	 */
	const MIN_BATCH_BYTES = 262144; // 256 KB

	/**
	 * How many queued tables a single database batch may look at.
	 *
	 * A bound on the work one pass does, not on the batch size — the byte
	 * cap already governs that. It exists so a network with thousands of
	 * empty tables cannot spend an entire slice building SQL instead of
	 * sending it.
	 */
	const PACK_MAX_TABLES = 60;

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
	 * @param string   $direction Always 'push' — kept so state records stay self-describing.
	 * @param string[] $stages
	 * @param array    $options
	 *
	 * @return array|WP_Error The new state.
	 */
	public function start( $direction, array $stages, array $options = [] ) {
		// A migration can be started from code, so the mode has to be validated
		// here as well as in the UI. An unresolved or unsupported combination
		// must never reach the queue.
		$mode = $options['mode'] ?? FW_SM_Multisite::MODE_SINGLE;

		if ( ! in_array(
			$mode,
			[
				FW_SM_Multisite::MODE_SINGLE,
				FW_SM_Multisite::MODE_NETWORK,
				FW_SM_Multisite::MODE_SUBSITE_TO_SINGLE,
				FW_SM_Multisite::MODE_SINGLE_TO_SUBSITE,
			],
			true
		) ) {
			return new WP_Error(
				'fw_sm_unknown_mode',
				__( 'That combination of source and destination is not supported.', 'fw' )
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

		// Open the migration on the destination before any work is queued. If
		// the destination will not accept it, the user finds out now rather than
		// after ten minutes of scanning.
		$sender = FW_SM_Sender::from_stored();

		if ( is_wp_error( $sender ) ) {
			FW_SM_State::finish( 'failed', $sender->get_error_message() );

			return $sender;
		}

		$opened = $sender->begin(
			$state['id'],
			[
				'mode'        => $options['mode'] ?? FW_SM_Multisite::MODE_SINGLE,
				'target_slug' => $options['target_slug'] ?? '',
			]
		);

		if ( is_wp_error( $opened ) ) {
			FW_SM_State::finish( 'failed', $opened->get_error_message() );

			return $opened;
		}

		// single -> subsite: the destination just created the receiving site and
		// told us its id and URL. Everything downstream — table names, uploads
		// paths, URL rewriting — depends on those, so fold them into the state
		// before any work is queued.
		if ( ! empty( $opened['dest_blog_id'] ) ) {
			$state['options']['dest_blog_id'] = (int) $opened['dest_blog_id'];
		}

		if ( ! empty( $opened['dest_url'] ) ) {
			$state['options']['target_url'] = untrailingslashit( $opened['dest_url'] );
		}

		FW_SM_State::save( $state );

		FW_SM_State::log(
			sprintf(
				/* translators: %s: destination site URL. */
				__( 'Connected to %s. Starting migration.', 'fw' ),
				$state['options']['target_url'] ?? $sender->get_url()
			)
		);

		// The destination sweeps staged files left by an earlier attempt before
		// it starts. Reported here so a littered destination is visible and
		// explained, rather than silently cleaned.
		$swept = (int) ( $opened['staged_swept'] ?? 0 );

		if ( $swept > 0 ) {
			FW_SM_State::log(
				sprintf(
					/* translators: %d: number of files. */
					_n(
						'Cleared %d leftover staged file from a previous migration before starting.',
						'Cleared %d leftover staged files from a previous migration before starting.',
						$swept,
						'fw'
					),
					$swept
				)
			);
		}

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

		// The destination is holding staging tables for a migration that is not
		// coming. Tell it to drop them. Its live data was never touched — the
		// swap only happens in finalize — so nothing there needs undoing.
		$sender = FW_SM_Sender::from_stored();

		if ( ! is_wp_error( $sender ) ) {
			$sender->abort();
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

		if ( FW_SM_Stage::DATABASE === $stage ) {
			$tables      = FW_SM_DB_Export::list_tables( $this->db_context( $state ) );
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
		$root = FW_SM_Stage::source_dir( $stage, (int) ( $state['options']['source_blog_id'] ?? 0 ) );

		if ( null === $root ) {
			// A missing mu-plugins directory is ordinary, not an error.
			return $this->mark_initialized( $index, 0 );
		}

		$excludes = FW_SM_File_Scanner::default_excludes( $stage );

		// A subsite's own root is already .../uploads/sites/<id>, so the
		// exclusion that keeps blog 1 out of sites/ would exclude everything.
		// Paths are matched relative to the stage root, so the guard is simply
		// not to apply it when the root is itself a subsite directory.
		if ( FW_SM_Stage::UPLOADS === $stage && (int) ( $state['options']['source_blog_id'] ?? 0 ) > 1 ) {
			$excludes = array_values(
				array_filter(
					$excludes,
					static function ( $pattern ) {
						return '*/sites/*' !== $pattern;
					}
				)
			);
		}

		// A quick migration may name the top-level folders it wants. Expressed
		// as exclusions for the rest, so the choice travels the same path the
		// scanner already uses and cannot contradict it.
		//
		// This is why selection is worth having on top of quick mode: quick
		// mode still WALKS every file to prove it need not send it. On a real
		// site that is thousands of hashes to conclude nothing changed.
		// Deselecting a folder skips the work rather than optimising it.
		$chosen = $state['options']['folders'][ $stage ] ?? [];

		if ( ! empty( $chosen ) ) {
			$excludes = array_merge(
				$excludes,
				FW_SM_Stage::excludes_for_selection( $stage, $chosen, (int) ( $state['options']['source_blog_id'] ?? 0 ) )
			);
		}

		$scanner = new FW_SM_File_Scanner( $stage, $root, $excludes );

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
			} elseif ( FW_SM_Stage::DATABASE === $stage ) {
				$result = $this->push_database( $state );
			} else {
				$result = $this->push_files( $stage, $state );
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
	 * Close out the migration.
	 *
	 * @param array $state
	 *
	 * @return array|WP_Error
	 */
	private function process_finalize( $state ) {
		$sender = FW_SM_Sender::from_stored();

		if ( is_wp_error( $sender ) ) {
			return $sender;
		}

		FW_SM_State::log( __( 'Asking the destination to put the migration live…', 'fw' ) );

		$result = $sender->finalize();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		FW_SM_State::log(
			sprintf(
				/* translators: 1: number of tables, 2: number of files. */
				__( 'Destination updated — %1$d tables and %2$d files.', 'fw' ),
				(int) ( $result['swapped'] ?? 0 ),
				(int) ( $result['files'] ?? 0 )
			)
		);

		// Files held back for the whole transfer go live in one pass here, so
		// the count is worth stating: it is the number of files the destination
		// was still running the old version of until a moment ago.
		$swapped_files = (int) ( $result['files_swapped'] ?? 0 );

		if ( $swapped_files > 0 ) {
			FW_SM_State::log(
				sprintf(
					/* translators: %d: number of files. */
					_n(
						'%d replaced file was put in place.',
						'%d replaced files were put in place, all at once, so the destination never ran a half-updated plugin.',
						$swapped_files,
						'fw'
					),
					$swapped_files
				)
			);
		}

		// The true count, not the length of the capped example list — a host that
		// refuses thousands of overwrites must say thousands, not "20".
		$swap_failed_count = (int) ( $result['swap_failed_count'] ?? count( (array) ( $result['swap_failed'] ?? [] ) ) );

		if ( $swap_failed_count > 0 ) {
			FW_SM_State::log(
				sprintf(
					/* translators: 1: count, 2: example paths. */
					__( 'Warning: %1$d file(s) could not be put in place and the destination is still running its old copy of them. For example: %2$s', 'fw' ),
					$swap_failed_count,
					implode( ', ', array_slice( (array) ( $result['swap_failed'] ?? [] ), 0, 3 ) )
				)
			);
		}

		// The "after" half of the staged-file check: with everything swapped,
		// the destination should hold no .fwsm-new at all. Reported either way
		// — a clean zero is the reassurance that the migration truly landed,
		// and a non-zero is the one signal that a change quietly did not.
		$staged_left = (int) ( $result['staged_left'] ?? 0 );

		if ( $staged_left > 0 ) {
			FW_SM_State::log(
				sprintf(
					/* translators: 1: count, 2: example paths. */
					__( 'Warning: %1$d staged file(s) (.fwsm-new) still remain on the destination and are NOT live — your changes to them did not apply. Migrate again; if they persist, the destination is refusing to overwrite those paths. For example: %2$s', 'fw' ),
					$staged_left,
					implode( ', ', array_slice( (array) ( $result['left_example'] ?? [] ), 0, 3 ) )
				)
			);
		} elseif ( isset( $result['staged_left'] ) ) {
			FW_SM_State::log(
				__( 'Checked the destination: no leftover staged files — every replacement is live.', 'fw' )
			);
		}

		// "Finished" is not the same as "works". The destination reports back
		// what it can actually READ, because every failure mode that survives
		// to this point is silent by nature.
		$verify = (array) ( $result['verify'] ?? [] );

		if ( ! empty( $verify ) ) {
			if ( empty( $verify['settings_ok'] ) ) {
				FW_SM_State::log(
					sprintf(
						/* translators: %s: theme id resolved on the destination. */
						__( 'Warning: the destination cannot read its Theme Settings. It resolved the theme id "%s" and found no readable settings under it, so the site will show defaults. Usually the theme files did not all arrive — check that the parent theme is complete on the destination, then migrate again.', 'fw' ),
						$verify['theme_id'] ? $verify['theme_id'] : __( 'unknown', 'fw' )
					)
				);
			} else {
				FW_SM_State::log(
					sprintf(
						/* translators: 1: theme id, 2: size. */
						__( 'Theme Settings verified on the destination — theme id "%1$s", %2$s readable.', 'fw' ),
						$verify['theme_id'],
						size_format( (int) $verify['settings_bytes'] )
					)
				);
			}

			if ( ! empty( $verify['unreadable'] ) ) {
				FW_SM_State::log(
					sprintf(
						/* translators: 1: count, 2: example option names. */
						__( 'Warning: %1$d option(s) on the destination are no longer readable and will fall back to defaults. For example: %2$s', 'fw' ),
						(int) $verify['unreadable'],
						implode( ', ', (array) ( $verify['unreadable_eg'] ?? [] ) )
					)
				);
			}
		}

		// Deleting files on someone else's live server is not something to do
		// quietly, so it is always named — with examples, since "removed 41
		// files" on its own is not something anyone can check.
		// Said out loud when it declines. Silence here would leave stale files
		// in place with no indication why, which is the sort of thing that only
		// surfaces months later as a fatal error.
		if ( ! empty( $result['prune_skipped'] ) ) {
			FW_SM_State::log(
				sprintf(
					/* translators: %d: number of files. */
					__( 'Stale files were NOT removed from the destination: %d file(s) failed to write, so what it holds cannot be judged against what it received. Run the migration again once those succeed.', 'fw' ),
					(int) $result['prune_skipped']
				)
			);
		}

		$pruned = (int) ( $result['pruned'] ?? 0 );

		if ( $pruned > 0 ) {
			$paths = (array) ( $result['pruned_paths'] ?? [] );

			FW_SM_State::log(
				sprintf(
					/* translators: 1: file count, 2: a few example paths. */
					_n(
						'Removed %1$d file from the destination that no longer exists here: %2$s',
						'Removed %1$d files from the destination that no longer exist here. For example: %2$s',
						$pruned,
						'fw'
					),
					$pruned,
					implode( ', ', array_slice( $paths, 0, 5 ) )
				)
			);
		}

		return [ 'processed_bytes' => 0, 'complete' => true ];
	}

	/**
	 * Export a slice of a table straight to the destination.
	 *
	 * No file is written on the way. The exporter builds SQL into a string, the
	 * sender posts it, and the destination loads it into staging tables — so the
	 * source never needs disk space for a copy of its own database, which it may
	 * well not have.
	 *
	 * @param array $state
	 *
	 * @return array|WP_Error
	 */
	private function push_database( $state ) {
		$sender = FW_SM_Sender::from_stored();

		if ( is_wp_error( $sender ) ) {
			return $sender;
		}

		$jobs = FW_SM_Queue::peek( 1 );
		$job  = null;

		foreach ( $jobs as $candidate ) {
			if ( FW_SM_Stage::DATABASE === $candidate['stage'] ) {
				$job = $candidate;
			}
		}

		if ( null === $job ) {
			$n = (int) FW_SM_State::cursor( 'timing_n', 0 );

			if ( $n > 0 ) {
				$build  = (int) FW_SM_State::cursor( 'timing_build', 0 );
				$send   = (int) FW_SM_State::cursor( 'timing_send', 0 );
				$remote = (int) FW_SM_State::cursor( 'timing_remote', 0 );
				$kb     = (int) FW_SM_State::cursor( 'timing_kb', 0 );

				// send includes remote, so the wire is what is left over.
				$wire = max( 0, $send - $remote );

				FW_SM_State::log(
					sprintf(
						/* translators: 1: batches, 2: MB, 3: build seconds, 4: wire seconds, 5: destination seconds. */
						__( 'Database sent — %1$d batches, %2$s MB of SQL. Time: %3$ss building, %4$ss on the wire, %5$ss on the destination.', 'fw' ),
						$n,
						number_format( $kb / 1024, 1 ),
						number_format( $build / 1000, 1 ),
						number_format( $wire / 1000, 1 ),
						number_format( $remote / 1000, 1 )
					)
				);
			} else {
				FW_SM_State::log( __( 'Database sent.', 'fw' ) );
			}

			return [ 'processed_bytes' => 0, 'complete' => true ];
		}

		$table  = $job['payload']['table'] ?? '';
		$export = new FW_SM_DB_Export( '', $this->make_replacer( $state ), $this->db_context( $state ) );

		// The destination's post_max_size governs how much SQL may be sent at
		// once; it reported its own limits during the handshake.
		$export->set_max_batch( self::batch_ceiling() );

		// Per-phase timing. Guessing at which part of a migration is slow has
		// cost more time in this project than any actual bug, so each batch
		// records where its seconds went.
		$t_build = microtime( true );

		$sql = '';

		// First slice for this table: send its structure before its rows.
		//
		// The cursor is NOT written here. Recording the table as started before
		// the batch has landed means a failed send loses the CREATE TABLE while
		// keeping the note that it was sent — so every retry ships INSERTs for
		// a table the destination was never told to create, and the migration
		// dies on "table doesn't exist" rather than on the original error.
		$started = FW_SM_State::cursor( 'db_table' ) === $table;
		$key_col = $started
			? (string) FW_SM_State::cursor( 'db_key_col', '' )
			: FW_SM_DB_Export::primary_key( $table );

		if ( ! $started ) {
			$schema = $export->build_schema( $table );

			if ( is_wp_error( $schema ) ) {
				return $schema;
			}

			$sql .= $schema;
		}

		$rows = $export->build_rows(
			$table,
			$key_col,
			$started ? FW_SM_State::cursor( 'db_last_key', '' ) : '',
			$started ? (int) FW_SM_State::cursor( 'db_offset', 0 ) : 0
		);

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$sql .= $rows['sql'];

		$build_ms = (int) round( ( microtime( true ) - $t_build ) * 1000 );
		$sql_kb   = (int) round( strlen( $sql ) / 1024 );

		// A batch stops at the end of a table, and on a single site that costs
		// nothing: its tables are big enough to fill the byte cap on their own,
		// so the loop below never runs.
		//
		// A network is the opposite. Six hundred tables, most of them a few
		// kilobytes of WooCommerce bookkeeping, means six hundred round trips
		// to move almost nothing — measured at 58 KB per request against an
		// 8 MB cap, with the destination working for 10 seconds out of five
		// minutes. The rest was latency.
		//
		// So once this table is finished, whole further tables are appended
		// until the cap is reached. WHOLE ones only: a table is packed only if
		// its recorded size fits in what is left, which keeps every cursor in
		// this method about a single table and leaves the retry path unchanged.
		$packed = [];

		if ( ! empty( $rows['done'] ) ) {
			$budget = self::batch_ceiling() - strlen( $sql );

			foreach ( FW_SM_Queue::peek( self::PACK_MAX_TABLES ) as $next ) {
				$name = self::packable_table( $next, $budget, (int) $job['id'] );

				if ( '' === $name ) {
					continue;
				}

				$schema = $export->build_schema( $name );

				if ( is_wp_error( $schema ) ) {
					break;
				}

				$more = $export->build_rows( $name, FW_SM_DB_Export::primary_key( $name ), '', 0 );

				if ( is_wp_error( $more ) || empty( $more['done'] ) ) {
					// Bigger than its estimate suggested. Leave it to a pass of its
					// own rather than carrying half of it here.
					break;
				}

				$sql    .= $schema . $more['sql'];
				$budget -= strlen( $schema ) + strlen( $more['sql'] );

				$packed[] = [ 'id' => (int) $next['id'], 'bytes' => (int) $next['bytes'] ];

				if ( $budget <= 0 ) {
					break;
				}
			}
		}

		if ( '' !== trim( $sql ) ) {
			$t_send = microtime( true );

			$sent = $sender->send_sql( $sql );

			$send_ms = (int) round( ( microtime( true ) - $t_send ) * 1000 );

			if ( is_wp_error( $sent ) ) {
				$retry = $this->maybe_retry_job( $job, $sent );

				if ( is_wp_error( $retry ) ) {
					return $retry;
				}

				// Retryable: leave the cursor where it is and come back to it.
				return [ 'processed_bytes' => 0, 'complete' => false ];
			}
		}

		// One line per table, updated as it goes, rather than one per batch —
		// a log with a thousand timing lines in it is not a log anyone reads.
		if ( isset( $send_ms ) ) {
			$remote_ms = (int) ( $sent['remote_ms'] ?? 0 );

			// Reported every few batches rather than only at the end of the
			// stage, because a stage that is slow is exactly the one you never
			// get to see the end of.
			$n_before = (int) FW_SM_State::cursor( 'timing_n', 0 );

			if ( $n_before > 0 && 0 === ( $n_before % 5 ) ) {
				$b = (int) FW_SM_State::cursor( 'timing_build', 0 );
				$w = max( 0, (int) FW_SM_State::cursor( 'timing_send', 0 ) - (int) FW_SM_State::cursor( 'timing_remote', 0 ) );
				$d = (int) FW_SM_State::cursor( 'timing_remote', 0 );
				$k = (int) FW_SM_State::cursor( 'timing_kb', 0 );

				FW_SM_State::log(
					sprintf(
						/* translators: 1: batches, 2: MB, 3-5: seconds. */
						__( '%1$d batches, %2$s MB — %3$ss building, %4$ss wire, %5$ss destination.', 'fw' ),
						$n_before,
						number_format( $k / 1024, 1 ),
						number_format( $b / 1000, 1 ),
						number_format( $w / 1000, 1 ),
						number_format( $d / 1000, 1 )
					)
				);
			}

			FW_SM_State::set_cursor(
				[
					'timing_table'  => $table,
					'timing_build'  => (int) FW_SM_State::cursor( 'timing_build', 0 ) + $build_ms,
					'timing_send'   => (int) FW_SM_State::cursor( 'timing_send', 0 ) + $send_ms,
					'timing_remote' => (int) FW_SM_State::cursor( 'timing_remote', 0 ) + $remote_ms,
					'timing_kb'     => (int) FW_SM_State::cursor( 'timing_kb', 0 ) + $sql_kb,
					'timing_n'      => (int) FW_SM_State::cursor( 'timing_n', 0 ) + 1,
				]
			);
		}

		// Only now that the destination has taken it. Everything the retry
		// would need to rebuild this exact batch is left untouched until here.
		FW_SM_State::set_cursor(
			[
				'db_table'    => $table,
				'db_key_col'  => $key_col,
				'db_last_key' => $rows['last_key'],
				'db_offset'   => $rows['offset'],
			]
		);

		$total_rows = max( 1, (int) ( $job['payload']['rows'] ?? 1 ) );

		$processed = (int) min(
			$job['bytes'],
			floor( $job['bytes'] / $total_rows ) * $rows['rows_written']
		);

		if ( $rows['done'] ) {
			FW_SM_Queue::delete( $job['id'] );
			FW_SM_State::set_cursor( [ 'db_table' => '' ] );
		}

		// The tables that rode along. Cleared here for the same reason as the
		// cursor above — a failed send must leave every one of them queued.
		foreach ( $packed as $done ) {
			FW_SM_Queue::delete( $done['id'] );
			$processed += $done['bytes'];
		}

		return [ 'processed_bytes' => $processed, 'complete' => false ];
	}

	/**
	 * Send files to the destination.
	 *
	 * One request per file. That is slower than bundling, and it is the right
	 * trade for a first version: a failure is attributable to a single file, a
	 * retry costs a single file, and there is no payload-framing format to get
	 * subtly wrong. Bundling is the obvious optimisation once this is proven.
	 *
	 * @param string $stage
	 * @param array  $state
	 *
	 * @return array|WP_Error
	 */
	private function push_files( $stage, $state ) {
		$sender = FW_SM_Sender::from_stored();

		if ( is_wp_error( $sender ) ) {
			return $sender;
		}

		// Enough jobs to fill a bundle. A wp-content tree is mostly small files,
		// so peeking 25 at a time would cap a bundle far below its useful size.
		$jobs = FW_SM_Queue::peek( FW_SM_Sender::BUNDLE_FILES );

		$bytes   = 0;
		$sent    = 0;
		$skipped = 0;

		// Quick migration: ask the destination which of these it already has,
		// byte-for-byte, and drop the rest from the queue without sending them.
		// On a repeat push almost everything is unchanged — plugins, themes,
		// existing uploads — so this is the difference between minutes and
		// hours, and the end state is identical either way.
		if ( ! empty( $state['options']['quick'] ) ) {
			$already = $this->drop_files_already_there( $sender, $stage, $jobs );

			if ( is_wp_error( $already ) ) {
				return $already;
			}

			if ( $already['dropped'] > 0 ) {
				$bytes  += $already['bytes'];
				$skipped += $already['dropped'];
				$jobs    = $already['remaining'];
			}
		}

		// Small files go together in one request; large ones keep the chunked
		// path, where the cost is already the bytes rather than the round trip.
		// Gather as many bundles as it is worth sending at once. One connection
		// over a long round trip is limited by its window rather than by the
		// link, so filling several at the same time is the difference between
		// a link that is busy and one that spends most of its time waiting.
		// Capped by anything a capacity failure has since taught us.
		$streams = min( self::stream_count(), (int) FW_SM_State::cursor( 'stream_cap', self::stream_count() ) );

		$bundles     = [];
		$bundle_ids  = [];
		$bundle      = [];
		$ids         = [];
		$bundle_raw  = 0;

		foreach ( $jobs as $job ) {
			if ( $stage !== $job['stage'] ) {
				break;
			}

			$absolute = $job['payload']['absolute'] ?? '';
			$relative = $job['payload']['path'] ?? '';

			if ( '' === $absolute || ! is_readable( $absolute ) ) {
				// Vanished between the scan and now — a cache purge, a plugin
				// update. Ordinary on a live site.
				FW_SM_Queue::delete( $job['id'] );
				$skipped++;
				continue;
			}

			if ( (int) $job['bytes'] >= FW_SM_Sender::BUNDLE_FILE_MAX ) {
				// A large file ends the gathering: send whatever is collected
				// first, so the two paths never interleave within one pass.
				break;
			}

			$bundle[]    = [ 'path' => $relative, 'absolute' => $absolute ];
			$ids[]       = $job['id'];
			$bundle_raw += (int) $job['bytes'];

			if ( $bundle_raw >= FW_SM_Sender::BUNDLE_BYTES
			     || count( $bundle ) >= FW_SM_Sender::BUNDLE_FILES ) {
				$bundles[]    = $bundle;
				$bundle_ids[] = $ids;

				$bundle     = [];
				$ids        = [];
				$bundle_raw = 0;

				if ( count( $bundles ) >= $streams ) {
					break;
				}
			}
		}

		// Whatever is left over is a bundle too, unless there is already a full
		// set — a part-filled request still costs a whole round trip.
		if ( ! empty( $bundle ) && count( $bundles ) < $streams ) {
			$bundles[]    = $bundle;
			$bundle_ids[] = $ids;
		}

		if ( ! empty( $bundles ) ) {
			$results = count( $bundles ) > 1
				? $sender->send_bundles( $stage, $bundles )
				: [ $sender->send_bundle( $stage, $bundles[0] ) ];

			$failure     = null;
			$failed_first = 0;

			foreach ( $bundles as $i => $group ) {
				$result = $results[ $i ] ?? null;

				if ( null === $result || is_wp_error( $result ) ) {
					// One bundle failing does not undo the others: they landed,
					// and re-sending them would be pure waste. Their jobs are
					// still cleared below; only the failed one is left in the
					// queue to be retried.
					if ( null === $failure ) {
						$failure = $result ?: new WP_Error(
							'fw_sm_transport',
							__( 'No response.', 'fw' ),
							[ 'retryable' => true ]
						);

						// Must be a job from the bundle that actually failed.
						// The successful bundles' jobs are deleted below, and a
						// retry counter attached to one of those would be
						// counting against a job that no longer exists.
						$failed_first = $bundle_ids[ $i ][0];
					}

					continue;
				}

				// The sender may have packed fewer than offered, once the byte
				// cap was reached. Only those were even attempted.
				$done = (int) ( $result['sent_files'] ?? count( $group ) );

				// Attempted is not the same as written. The destination skips a
				// file it could not place -- an unwritable directory, a failed
				// rename -- and names each one. Clearing those jobs anyway would
				// drop the file silently: never retried, and absent from the
				// received list, which then invites the prune to delete the copy
				// already on the destination. They stay queued instead.
				$lost    = array_flip( (array) ( $result['skipped_paths'] ?? [] ) );
				$gave_up = [];

				for ( $j = 0; $j < $done; $j++ ) {
					$path = $group[ $j ]['path'] ?? '';
					$id   = $bundle_ids[ $i ][ $j ];

					if ( isset( $lost[ $path ] ) ) {
						// A skip is retried a few times — it can be a transient
						// hiccup — but some destinations refuse a path PERMANENTLY: a
						// managed host blocks writes to its own cache and mu-plugin
						// directories, and no number of retries will place them. Left
						// re-queued forever, those files leave the migration stuck at
						// 100% with a queue that never drains. So each skip counts
						// against the same MAX_ATTEMPTS budget as any other failure,
						// and once it is spent the file is given up and dropped from
						// the queue so the rest of the migration can finish.
						if ( FW_SM_Queue::bump_attempts( $id ) >= self::MAX_ATTEMPTS ) {
							FW_SM_Queue::delete( $id );
							$gave_up[] = $path;
						}

						continue;
					}

					FW_SM_Queue::delete( $id );
				}

				// Only the give-up is worth a log line. The plain "still retrying"
				// case used to log every pass, which on a permanently-refused set
				// buried the whole log in the same sentence hundreds of times.
				if ( ! empty( $gave_up ) ) {
					FW_SM_State::log(
						sprintf(
							/* translators: 1: count, 2: example paths. */
							__( 'Gave up on %1$d file(s) the destination would not write after several tries — the migration will finish without them. This is normal on a managed host that blocks writes to certain folders. For example: %2$s', 'fw' ),
							count( $gave_up ),
							implode( ', ', array_slice( $gave_up, 0, 3 ) )
						)
					);
				}

				$bytes += (int) ( $result['bytes'] ?? 0 );
				$sent  += (int) ( $result['written'] ?? 0 );

				if ( ! empty( $result['skipped'] ) ) {
					$skipped += (int) $result['skipped'];
				}
			}

			if ( null !== $failure ) {
				// Attributed to a job that is still queued, so the retry
				// counter has somewhere to live.
				$retry = $this->maybe_retry_job( [ 'id' => $failed_first, 'bytes' => 0, 'stage' => $stage ], $failure );

				if ( is_wp_error( $retry ) ) {
					return $retry;
				}
			}

			return [ 'processed_bytes' => $bytes, 'complete' => false ];
		}

		// Nothing small to bundle: the head of the queue is a large file.
		foreach ( $jobs as $job ) {
			if ( $stage !== $job['stage'] ) {
				break;
			}

			if ( ! $this->has_budget() ) {
				break;
			}

			$absolute = $job['payload']['absolute'] ?? '';
			$relative = $job['payload']['path'] ?? '';

			if ( '' === $absolute || ! is_readable( $absolute ) ) {
				FW_SM_Queue::delete( $job['id'] );
				$skipped++;
				continue;
			}

			$offset_key = 'file_offset_' . $job['id'];
			$offset     = (int) FW_SM_State::cursor( $offset_key, 0 );

			$result = $sender->send_file( $stage, $relative, $absolute, $offset );

			if ( is_wp_error( $result ) ) {
				$retry = $this->maybe_retry_job( $job, $result );

				if ( is_wp_error( $retry ) ) {
					return $retry;
				}

				return [ 'processed_bytes' => $bytes, 'complete' => false ];
			}

			$bytes += (int) ( $result['sent_bytes'] ?? 0 );

			// The destination took the request but could not place the file — an
			// unwritable path, the same refusal a bundle can hit. Without a cap
			// this would reset to offset 0 and try forever; with one, a few
			// attempts then give up, so a single refused large file cannot stall
			// the whole migration.
			if ( ! empty( $result['skipped'] ) && empty( $result['written'] ) && empty( $result['done'] ) ) {
				if ( FW_SM_Queue::bump_attempts( $job['id'] ) >= self::MAX_ATTEMPTS ) {
					FW_SM_Queue::delete( $job['id'] );
					FW_SM_State::set_cursor( [ $offset_key => null ] );
					$skipped++;

					FW_SM_State::log(
						sprintf(
							/* translators: %s: file path. */
							__( 'Gave up on %s — the destination would not write it. The migration will finish without it.', 'fw' ),
							$relative
						)
					);

					break;
				}

				// Not yet spent: start the next attempt from the beginning.
				FW_SM_State::set_cursor( [ $offset_key => 0 ] );

				return [ 'processed_bytes' => $bytes, 'complete' => false ];
			}

			if ( empty( $result['done'] ) || ! empty( $result['resync'] ) ) {
				$next = isset( $result['at'] )
					? (int) $result['at']
					: (int) $result['next_offset'];

				FW_SM_State::set_cursor( [ $offset_key => $next ] );

				return [ 'processed_bytes' => $bytes, 'complete' => false ];
			}

			FW_SM_Queue::delete( $job['id'] );
			FW_SM_State::set_cursor( [ $offset_key => null ] );

			$sent++;

			// One large file per pass keeps the slice bounded.
			break;
		}

		if ( 0 === $sent + $skipped ) {
			$unchanged = (int) FW_SM_State::cursor( 'unchanged_' . $stage, 0 );

			FW_SM_State::log(
				$unchanged > 0
					? sprintf(
						/* translators: 1: stage label, 2: number of files. */
						__( '%1$s sent — %2$d file(s) were already up to date.', 'fw' ),
						FW_SM_Stage::label( $stage ),
						$unchanged
					)
					: sprintf(
						/* translators: %s: stage label. */
						__( '%s sent.', 'fw' ),
						FW_SM_Stage::label( $stage )
					)
			);

			return [ 'processed_bytes' => 0, 'complete' => true ];
		}

		if ( $skipped > 0 ) {
			FW_SM_State::set_cursor(
				[ 'unchanged_' . $stage => (int) FW_SM_State::cursor( 'unchanged_' . $stage, 0 ) + $skipped ]
			);
		}

		return [ 'processed_bytes' => $bytes, 'complete' => false ];
	}

	/**
	 * Remove from the queue any file the destination already has identically.
	 *
	 * One extra request per batch buys skipping the transfer of every unchanged
	 * file in it, which on a second push is nearly all of them.
	 *
	 * Their bytes still count toward progress: the work of getting that file to
	 * the destination IS done, and a progress bar that only advanced for files
	 * that happened to have changed would sit at 4% through a successful
	 * migration.
	 *
	 * @param FW_SM_Sender $sender
	 * @param string       $stage
	 * @param array[]      $jobs
	 *
	 * @return array|WP_Error [ 'dropped', 'bytes', 'remaining' ]
	 */
	private function drop_files_already_there( $sender, $stage, array $jobs ) {
		$manifest = [];
		$index_of = [];

		foreach ( $jobs as $i => $job ) {
			if ( $stage !== $job['stage'] ) {
				continue;
			}

			$absolute = $job['payload']['absolute'] ?? '';

			if ( '' === $absolute || ! is_readable( $absolute ) ) {
				continue;
			}

			$index_of[ count( $manifest ) ] = $i;

			$manifest[] = [
				'path'  => $job['payload']['path'] ?? '',
				'size'  => (int) @filesize( $absolute ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'mtime' => (int) @filemtime( $absolute ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			];
		}

		if ( empty( $manifest ) ) {
			return [ 'dropped' => 0, 'bytes' => 0, 'remaining' => $jobs ];
		}

		$answer = $sender->which_needed( $stage, $manifest );

		if ( is_wp_error( $answer ) ) {
			// If the destination cannot answer, send everything. Slower, never
			// wrong — which is the right way round for a failure here.
			return [ 'dropped' => 0, 'bytes' => 0, 'remaining' => $jobs ];
		}

		$needed = array_flip( array_map( 'intval', (array) ( $answer['needed'] ?? [] ) ) );

		$dropped = 0;
		$bytes   = 0;

		foreach ( $index_of as $manifest_index => $job_index ) {
			if ( isset( $needed[ $manifest_index ] ) ) {
				continue;
			}

			$job = $jobs[ $job_index ];

			FW_SM_Queue::delete( $job['id'] );

			$bytes += (int) $job['bytes'];
			$dropped++;

			unset( $jobs[ $job_index ] );
		}

		return [
			'dropped'   => $dropped,
			'bytes'     => $bytes,
			'remaining' => array_values( $jobs ),
		];
	}

	/**
	 * Decide whether a failed job gets another go.
	 *
	 * @param array    $job
	 * @param WP_Error $error
	 *
	 * @return true|WP_Error True to retry, the error itself to give up.
	 */
	private function maybe_retry_job( $job, WP_Error $error ) {
		$data = $error->get_error_data();

		// Retrying an identical request that ran the destination out of memory
		// will run it out of memory again, three times, and then give up — which
		// is what used to happen. The size is the thing that has to change, so
		// each attempt halves it and the next one carries less.
		//
		// This is also the only mechanism that can find a workable size on a
		// destination we cannot measure directly: the connection test learns a
		// ceiling from payloads it survives, but a batch can still be too big
		// for what the IMPORT of it costs, which is several times the bytes on
		// the wire.
		if ( self::is_capacity_error( $error ) ) {
			// A file stage does not read batch_ceiling -- bundles are sized by
			// BUNDLE_BYTES and chunks by CHUNK_BYTES -- so shrinking it there
			// achieves nothing at all. It was observed walking 8 MB down to the
			// 256 KB floor while the Plugins stage failed identically at every
			// size, because the number being reduced was never consulted.
			//
			// What a file stage can give back is concurrency: several requests
			// in flight means several PHP processes on the destination at once,
			// and shared hosts cap the account, not just the process.
			// The job carries its own stage, which is exactly the one that failed.
			$stage = (string) ( $job['stage'] ?? '' );

			if ( FW_SM_Stage::DATABASE !== $stage ) {
				$streams = (int) FW_SM_State::cursor( 'stream_cap', self::stream_count() );

				if ( $streams > 1 ) {
					$fewer = max( 1, (int) floor( $streams / 2 ) );

					FW_SM_State::set_cursor( [ 'stream_cap' => $fewer ] );
					FW_SM_Queue::reset_attempts( $job['id'] );

					FW_SM_State::log(
						sprintf(
							/* translators: 1: previous count, 2: new count. */
							__( 'The destination ran out of memory. Sending fewer files at once — %1$d down to %2$d.', 'fw' ),
							$streams,
							$fewer
						)
					);

					return true;
				}
			}

			$before = self::batch_ceiling();
			$after  = max( self::MIN_BATCH_BYTES, (int) ( $before / 2 ) );

			if ( $after < $before ) {
				FW_SM_State::set_cursor( [ 'batch_ceiling' => $after ] );

				// A smaller batch is a different request, so it starts with a
				// clean slate. Without this the three attempts are spent on the
				// way down and it gives up just as the size becomes workable.
				// The floor above is what stops this looping forever: once
				// batches cannot shrink further, attempts accumulate normally.
				FW_SM_Queue::reset_attempts( $job['id'] );

				FW_SM_State::log(
					sprintf(
						/* translators: 1: previous size, 2: new size. */
						__( 'The destination ran out of memory. Retrying with smaller batches — %1$s down to %2$s.', 'fw' ),
						size_format( $before ),
						size_format( $after )
					)
				);

				// Said once, above. Falling through would add a second line
				// claiming an attempt number that the reset just invalidated,
				// which is how the log came to read "attempt 2" three times in
				// a row while the size was in fact changing each time.
				return true;
			}
		}

		// A checksum failure means the destination threw the partial file away,
		// so the next attempt has to start at byte zero rather than resuming
		// into a file that no longer exists.
		if ( false !== strpos( $error->get_error_message(), 'checksum' ) ) {
			FW_SM_State::set_cursor( [ 'file_offset_' . $job['id'] => 0 ] );
		}

		if ( empty( $data['retryable'] ) ) {
			return $error;
		}

		$attempts = FW_SM_Queue::bump_attempts( $job['id'] );

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return new WP_Error(
				$error->get_error_code(),
				sprintf(
					/* translators: 1: attempt count, 2: underlying error. */
					__( 'Gave up after %1$d attempts: %2$s', 'fw' ),
					$attempts,
					$error->get_error_message()
				)
			);
		}

		FW_SM_State::log(
			sprintf(
				/* translators: 1: attempt number, 2: error message. */
				__( 'Retrying (attempt %1$d) — %2$s', 'fw' ),
				$attempts + 1,
				$error->get_error_message()
			)
		);

		return true;
	}

	/**
	 * The replacer for this migration: source URL and path to destination.
	 *
	 * @param array $state
	 *
	 * @return FW_SM_Replacer
	 */
	private function make_replacer( $state ) {
		$options = (array) $state['options'];

		$replacer = FW_SM_Replacer::for_site_move(
			$options['source_url'] ?? '',
			$options['target_url'] ?? '',
			$options['source_path'] ?? '',
			$options['target_path'] ?? ''
		);

		// Promoting a subsite moves its uploads out of /uploads/sites/<id>/, and
		// folding one in does the reverse. Every attachment URL and every
		// serialized reference to that path has to follow.
		$extra = FW_SM_Multisite::extra_replace_pairs(
			$options['mode'] ?? FW_SM_Multisite::MODE_SINGLE,
			(int) ( $options['source_blog_id'] ?? 0 ),
			(int) ( $options['dest_blog_id'] ?? 0 )
		);

		foreach ( $extra as $pair ) {
			$replacer->add_pair( $pair['search'], $pair['replace'], ! empty( $pair['ci'] ) );
		}

		return $replacer;
	}

	/**
	 * Did this failure mean the destination could not cope with the size?
	 *
	 * Deliberately narrow. A refusal, a bad signature, or a missing table are
	 * all permanent and shrinking the batch would only make the migration take
	 * longer to fail. Only the shapes that a smaller request could plausibly
	 * fix count.
	 *
	 * @param WP_Error $error
	 *
	 * @return bool
	 */
	private static function is_capacity_error( WP_Error $error ) {
		$message = strtolower( $error->get_error_message() );

		foreach ( [ 'allowed memory size', 'memory exhausted', 'max_allowed_packet', 'critical error', 'http 500' ] as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The batch size in force for this migration.
	 *
	 * Starts from what the connection test measured and only ever comes down,
	 * as failures reveal that the measurement was optimistic. It is held on the
	 * migration rather than in an option so a bad destination on one migration
	 * does not permanently shrink batches to a different one.
	 *
	 * @return int
	 */
	public static function batch_ceiling() {
		$learned = (int) FW_SM_State::cursor( 'batch_ceiling', 0 );

		return $learned > 0 ? $learned : self::safe_payload_size();
	}

	/**
	 * May this queued job ride along in a database batch already being built?
	 *
	 * Separated from the loop that uses it because it is the whole safety
	 * argument for packing: a table is only ever added WHOLE, and only when
	 * it comfortably fits what is left of the byte cap. Everything that keeps
	 * the retry path unchanged follows from that.
	 *
	 * @param array $job     A queued job, as peek() returns it.
	 * @param int   $budget  Bytes left in the current batch.
	 * @param int   $current The job already being sent, which must not repeat.
	 *
	 * @return string The table name, or '' if it may not be packed.
	 */
	public static function packable_table( $job, $budget, $current ) {
		if ( FW_SM_Stage::DATABASE !== ( $job['stage'] ?? '' ) ) {
			return '';
		}

		if ( (int) ( $job['id'] ?? 0 ) === (int) $current ) {
			return '';
		}

		// Sizes come from information_schema and are estimates — for InnoDB
		// they can be well off. Requiring twice the estimate to fit means an
		// optimistic one cannot push the batch past the size the destination
		// has actually proved it survives.
		if ( 2 * (int) ( $job['bytes'] ?? 0 ) > (int) $budget ) {
			return '';
		}

		$payload = $job['payload'] ?? [];
		$payload = is_array( $payload ) ? $payload : (array) json_decode( (string) $payload, true );

		return (string) ( $payload['table'] ?? '' );
	}

	/**
	 * How many requests to keep in flight at once.
	 *
	 * Measured, never assumed. The connection test sends the same payload over
	 * one, four, and eight connections; if the link were already saturated the
	 * throughput would not move, and opening more connections would only add
	 * load to the destination for nothing. Concurrency is used only where it
	 * was shown to help, and only up to the point where it stopped helping.
	 *
	 * @return int At least 1.
	 */
	public static function stream_count() {
		$diag = get_option( 'fw_sm_diagnostic', null );

		if ( ! is_array( $diag ) || empty( $diag['parallel'] ) ) {
			return 1;
		}

		$best      = 1;
		$best_rate = 0;
		$base_rate = 0;

		foreach ( $diag['parallel'] as $row ) {
			if ( ! empty( $row['error'] ) || empty( $row['total_ms'] ) ) {
				continue;
			}

			$rate    = (int) $row['sent'] / ( (int) $row['total_ms'] / 1000 );
			$streams = (int) $row['streams'];

			if ( 1 === $streams ) {
				$base_rate = $rate;
			}

			if ( $rate > $best_rate ) {
				$best_rate = $rate;
				$best      = $streams;
			}
		}

		// A margin, so ordinary run-to-run variance in the measurement does not
		// get mistaken for a gain worth opening connections for.
		if ( $base_rate <= 0 || $best_rate < $base_rate * 1.3 ) {
			return 1;
		}

		return max( 1, min( 8, $best ) );
	}

	/**
	 * The largest request this destination is known to survive.
	 *
	 * Prefers what the connection test actually measured over what the
	 * destination claims it allows, because the two disagree in the direction
	 * that matters: a host can advertise a 128 MB post_max_size and still fatal
	 * on 8 MB.
	 *
	 * Compression works in our favour here — the ceiling applies to the SQL
	 * before gzip, and SQL compresses by roughly an order of magnitude, so a
	 * batch sized to a measured limit is comfortably inside it on the wire.
	 *
	 * @return int Bytes, 0 for "no information, use the default".
	 */
	public static function safe_payload_size() {
		$diag = get_option( 'fw_sm_diagnostic', null );

		if ( is_array( $diag ) && ! empty( $diag['safe'] ) ) {
			// The measurement is of RAW POST bytes; this cap governs SQL BEFORE
			// it is gzipped, and SQL compresses by roughly ten times. Treating
			// the two as the same unit would shrink batches by an order of
			// magnitude for no safety gain — and with latency measured at over a
			// second per request, small batches are the expensive mistake.
			//
			// A factor of four assumes far worse compression than SQL actually
			// achieves, so the payload on the wire stays well inside what the
			// destination survived.
			return max( 1048576, min( FW_SM_DB_Export::MAX_BATCH_BYTES, (int) $diag['safe'] * 4 ) );
		}

		$stored = get_option( self::DESTINATION_OPTION_NAME, [] );
		$info   = is_array( $stored ) ? ( $stored['info'] ?? [] ) : [];

		$size = FW_SM_Sender::usable_payload( (int) ( $info['post_max_size'] ?? 0 ) );

		if ( $size <= 0 ) {
			return FW_SM_DB_Export::MAX_BATCH_BYTES;
		}

		// post_max_size is a poor proxy for what a destination can IMPORT. A
		// host advertising 256 MB yields a 154 MB batch, which no 128 MB
		// memory_limit can survive — and the failure is an uncatchable fatal,
		// so it costs a round trip and a confusing error rather than a refusal.
		//
		// Importing costs several times the bytes on the wire: decompress,
		// split into statements, execute. A fraction of the destination's
		// memory is a far better bound than a fraction of what it will accept.
		// A thirty-second, not a half. Measured rather than guessed: a
		// destination with a 128 MB limit was observed to fatal on an 8 MB
		// payload, so the working batch for such a host is nearer 4 MB — and
		// 128 / 32 gives exactly that. Hosts with real memory still reach the
		// cap (512 MB yields 16 MB, 1 GB yields the full 25 MB).
		$memory = (int) ( $info['memory_limit'] ?? 0 );

		if ( $memory > 0 ) {
			$size = min( $size, (int) ( $memory / 32 ) );
		}

		return max( self::MIN_BATCH_BYTES, min( FW_SM_DB_Export::MAX_BATCH_BYTES, $size ) );
	}

	/**
	 * The table mapping context for this migration.
	 *
	 * @param array $state
	 *
	 * @return array
	 */
	private function db_context( $state ) {
		global $wpdb;

		$options = (array) $state['options'];

		return [
			'mode'           => $options['mode'] ?? FW_SM_Multisite::MODE_SINGLE,
			'source_prefix'  => $wpdb->base_prefix,
			'dest_prefix'    => $options['target_prefix'] ?? $wpdb->base_prefix,
			'source_blog_id' => (int) ( $options['source_blog_id'] ?? 1 ),
			'dest_blog_id'   => (int) ( $options['dest_blog_id'] ?? 1 ),
		];
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

		// Tell the destination to let go. Without this a failed migration leaves
		// it holding a session — and refusing every later attempt — until that
		// session ages out, which is a miserable thing to debug because the
		// error you see next has nothing to do with the error that caused it.
		//
		// Best effort by design: if the destination is unreachable, that is very
		// likely WHY we are failing, and the failure worth reporting is the
		// original one, not this one.
		$sender = FW_SM_Sender::from_stored();

		if ( ! is_wp_error( $sender ) ) {
			$aborted = $sender->abort();

			if ( is_wp_error( $aborted ) ) {
				FW_SM_State::log(
					__( 'Could not tell the destination to stand down; it will release the migration on its own shortly.', 'fw' )
				);
			}
		}

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
