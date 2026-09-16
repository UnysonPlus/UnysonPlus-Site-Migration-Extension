<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The destination side.
 *
 * These endpoints accept a migration from another site. They are registered
 * `nopriv` because the source is a server with no session here — it cannot log
 * in, so it proves itself with a signature instead. That makes this file the
 * most security-sensitive code in the extension, and it is written accordingly:
 *
 *   - EVERY handler verifies the signature before touching anything. There is
 *     one guard function and no handler skips it.
 *   - Nothing is written to a live table. SQL loads into `_fwsm_` staging tables
 *     and is only swapped into place by the finalize call, so an abandoned or
 *     failed migration leaves this site exactly as it was.
 *   - File writes are constrained to the wp-content directories a migration is
 *     allowed to touch, and every resolved path is re-checked against its root
 *     before a byte is written.
 *
 * The failure mode to keep in mind while reading: an attacker who guesses or
 * steals the key can overwrite this site. That is inherent to the feature. What
 * must never be true is that an attacker WITHOUT the key can do anything at all.
 */
class FW_SM_Receiver {

	const ACTION_VERIFY   = 'fw_sm_r_verify';
	const ACTION_BEGIN    = 'fw_sm_r_begin';
	const ACTION_SQL      = 'fw_sm_r_sql';
	const ACTION_FILE     = 'fw_sm_r_file';
	const ACTION_FINALIZE = 'fw_sm_r_finalize';
	const ACTION_ABORT    = 'fw_sm_r_abort';
	const ACTION_HAVE     = 'fw_sm_r_have';
	const ACTION_BUNDLE   = 'fw_sm_r_bundle';
	const ACTION_PING     = 'fw_sm_r_ping';
	const ACTION_INSPECT  = 'fw_sm_r_inspect';
	const ACTION_DESCRIBE = 'fw_sm_r_describe';

	/**
	 * Fields above this size are released from the request superglobals once
	 * they have been read, rather than left duplicated for the whole request.
	 */
	const LARGE_FIELD = 65536; // 64 KB

	/**
	 * Destination-side record of the migration in progress.
	 */
	const SESSION_OPTION = 'fw_sm_incoming';

	/**
	 * An incoming migration with no activity for this long is assumed dead, and
	 * a new one may take over. Without this a crashed source would lock the
	 * destination out of ever receiving again.
	 */
	const SESSION_TTL = 3600;

	/**
	 * @return void
	 */
	public function register() {
		$actions = [
			self::ACTION_VERIFY   => 'handle_verify',
			self::ACTION_BEGIN    => 'handle_begin',
			self::ACTION_SQL      => 'handle_sql',
			self::ACTION_FILE     => 'handle_file',
			self::ACTION_FINALIZE => 'handle_finalize',
			self::ACTION_ABORT    => 'handle_abort',
			self::ACTION_HAVE     => 'handle_have',
			self::ACTION_BUNDLE   => 'handle_bundle',
			self::ACTION_PING     => 'handle_ping',
			self::ACTION_INSPECT  => 'handle_inspect',
			self::ACTION_DESCRIBE => 'handle_describe',
		];

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_nopriv_' . $action, [ $this, $method ] );
			add_action( 'wp_ajax_' . $action, [ $this, $method ] );
		}
	}

	/**
	 * Turn a fatal on the destination into a message the source can show.
	 *
	 * Without this, a fatal here reaches the source as WordPress's generic
	 * "There has been a critical error on this website" HTML — which tells the
	 * person running the migration nothing at all, and tells the person
	 * debugging it even less, because the destination's error log is usually on
	 * a server they cannot read.
	 *
	 * The real message is worth far more than the generic one, and there is no
	 * disclosure concern: this only runs for callers who already proved they
	 * hold the key.
	 *
	 * @return void
	 */
	private function catch_fatals() {
		// Buffer, so a notice or warning printed before the JSON does not
		// corrupt the response body.
		ob_start();

		register_shutdown_function(
			function () {
				$error = error_get_last();

				if ( ! $error || ! in_array(
					$error['type'],
					[ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ],
					true
				) ) {
					return;
				}

				// Whatever WordPress was about to render instead.
				while ( ob_get_level() ) {
					ob_end_clean();
				}

				if ( ! headers_sent() ) {
					status_header( 500 );
					header( 'Content-Type: application/json; charset=utf-8' );
				}

				echo wp_json_encode(
					[
						'success' => false,
						'data'    => [
							'message' => sprintf(
								/* translators: 1: error message, 2: file, 3: line. */
								__( 'The destination hit a fatal error: %1$s (in %2$s on line %3$d)', 'fw' ),
								$error['message'],
								// Only the path below wp-content is useful, and it
								// avoids printing the server's directory layout.
								preg_replace( '#^.*?(?=wp-content/)#', '', wp_normalize_path( $error['file'] ) ),
								(int) $error['line']
							),
							'fatal'   => true,
						],
					]
				);
			}
		);
	}

	/**
	 * Read and authenticate the incoming request.
	 *
	 * Every handler starts here. On failure it responds and exits, so a handler
	 * that forgets to check the return value still cannot proceed unauthenticated.
	 *
	 * @return array The verified payload.
	 */
	private function authenticate() {
		// Armed before any work, so a fatal anywhere in a handler comes back as
		// a readable message instead of WordPress's generic error page.
		$this->catch_fatals();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- signature-authenticated, see class docblock.
		$payload = wp_unslash( $_POST );

		if ( ! is_array( $payload ) || empty( $payload ) ) {
			// An empty $_POST on a request that certainly had a body almost
			// always means PHP discarded it for exceeding post_max_size, and
			// saying so saves an afternoon of guessing.
			$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;

			if ( $length > 0 ) {
				$this->fail(
					sprintf(
						/* translators: 1: request size, 2: the post_max_size limit. */
						__( 'The destination discarded a %1$s request because its post_max_size is %2$s. Raise post_max_size on the destination, or the migration cannot send data of this size.', 'fw' ),
						size_format( $length ),
						ini_get( 'post_max_size' )
					),
					413
				);
			}

			$this->fail( __( 'Empty request.', 'fw' ), 400 );
		}

		// WordPress slashes $_POST on load, so the unslash above is not a view
		// of the request — it is a second full copy of it. For an ordinary form
		// that is invisible; for a migration batch carrying megabytes in one
		// field it doubles the request's memory before a single row is imported,
		// and running out of memory here is uncatchable: PHP dies mid-request
		// and the source sees a bare 500 with nothing to explain it.
		//
		// The originals are dead weight from this point on — everything below
		// reads $payload — so the large ones are released. Small fields are left
		// alone, because anything else on the request that expects to find them
		// (a shutdown hook, a logger) should still see a normal $_POST.
		//
		// $_REQUEST is built from $_POST and shares the same strings until one
		// of them is written to, so it has to be released as well or the copy
		// simply lives on there instead.
		foreach ( $payload as $field => $value ) {
			if ( is_string( $value ) && strlen( $value ) > self::LARGE_FIELD ) {
				unset( $_POST[ $field ], $_REQUEST[ $field ] );
			}
		}

		$verified = FW_SM_Connection::verify( $payload, FW_SM_Connection::get_key() );

		if ( is_wp_error( $verified ) ) {
			// Deliberately terse and identical for every auth failure: a caller
			// without the key learns nothing about why it was rejected.
			$this->fail( $verified->get_error_message(), 403 );
		}

		return $payload;
	}

	/**
	 * Handshake. Tells the source what it is connected to.
	 *
	 * @return void
	 */
	public function handle_verify() {
		$this->authenticate();

		global $wpdb;

		$this->respond(
			[
				'site_url'      => untrailingslashit( home_url() ),
				'site_name'     => get_bloginfo( 'name' ),
				'abspath'       => untrailingslashit( wp_normalize_path( ABSPATH ) ),
				'table_prefix'  => $wpdb->prefix,
				'wp_version'    => get_bloginfo( 'version' ),
				'php_version'   => PHP_VERSION,
				'plugin_version'=> self::plugin_version(),
				'protocol'      => self::PROTOCOL,
				'multisite'     => is_multisite(),
				// Distinguishes "not a network" from "a network was started but
				// never finished". WP_ALLOW_MULTISITE only unlocks Tools →
				// Network Setup; is_multisite() stays false until that wizard
				// has written MULTISITE and the DOMAIN/PATH constants. Reporting
				// both lets the source say which of the two it is looking at,
				// which is the difference between a useful message and a
				// baffling one.
				'ms_allowed'    => defined( 'WP_ALLOW_MULTISITE' ) && WP_ALLOW_MULTISITE,
				'subdomain'     => is_multisite() && is_subdomain_install(),
				'base_prefix'   => $wpdb->base_prefix,
				// What the destination can actually READ of its Theme Settings.
				//
				// Reported at handshake, not only after a migration, because
				// this is the question you want answered when a migration has
				// already finished and the site is showing defaults — and by
				// then nobody wants to run another one to find out. Re-check on
				// the source and it is on screen.
				'theme_settings' => self::theme_settings_report(),
				'max_packet'    => (int) $wpdb->get_var( "SELECT @@max_allowed_packet" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				// The DESTINATION's limits are the ones that bind: the source
				// POSTs into them. Reporting them lets the source size its
				// requests to fit rather than discovering the ceiling by
				// bouncing off it.
				'post_max_size'   => wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ),
				'upload_max'      => wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ),
				'memory_limit'    => wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ),
				'max_execution'   => (int) ini_get( 'max_execution_time' ),
				'max_input_time'  => (int) ini_get( 'max_input_time' ),
			]
		);
	}

	/**
	 * Open an incoming migration.
	 *
	 * @return void
	 */
	public function handle_begin() {
		$payload = $this->authenticate();

		$their_protocol = isset( $payload['protocol'] ) ? (int) $payload['protocol'] : 0;

		if ( $their_protocol !== self::PROTOCOL ) {
			$this->fail(
				sprintf(
					/* translators: 1: source protocol version, 2: destination protocol version. */
					__( 'The two sites are running incompatible versions of the Site Migration extension (source speaks protocol %1$d, this site speaks %2$d). Update the extension on both sites so they match.', 'fw' ),
					$their_protocol,
					self::PROTOCOL
				),
				409
			);
		}

		$existing = get_option( self::SESSION_OPTION, null );

		if ( is_array( $existing ) && ( time() - (int) ( $existing['seen_at'] ?? 0 ) ) < self::SESSION_TTL ) {
			$holder = untrailingslashit( (string) ( $existing['source_url'] ?? '' ) );
			$ours   = untrailingslashit( esc_url_raw( $payload['source_url'] ?? '' ) );

			// The same source starting again is a RETRY, not a competing
			// migration. Refusing it would mean a single failure locks the
			// destination for an hour, which is the opposite of helpful — and
			// nothing of the previous attempt survives anyway, because the live
			// tables are only touched at finalize.
			if ( '' === $holder || $holder !== $ours ) {
				$this->fail(
					sprintf(
						/* translators: 1: the site holding the migration, 2: human-readable duration. */
						__( 'Another site (%1$s) started migrating here %2$s ago and has not finished. Wait for it, or clear it from the Destination tab of this site.', 'fw' ),
						$holder ? $holder : __( 'unknown', 'fw' ),
						human_time_diff( (int) ( $existing['seen_at'] ?? time() ) )
					),
					409
				);
			}
		}

		// Whatever a previous attempt left behind is not ours and not wanted.
		FW_SM_Importer::drop_staging_tables();

		// The same is true of staged FILES. A .fwsm-new sitting beside a live
		// file, or a .fwsm-part from a transfer that never finished, is debris
		// from an earlier attempt — successful, cancelled or failed. A finished
		// migration promotes every .fwsm-new and discards the rest, so any that
		// survive are always leftovers, and left alone they accumulate across
		// attempts and quietly mask that a file's update never went live (the
		// live .php keeps running beside the new one). Sweep them so this
		// migration starts from a clean tree; the count is reported so the
		// source can say what it cleared.
		$swept_examples = [];
		$swept          = $this->sweep_staged_files( true, $swept_examples );

		$mode      = sanitize_key( $payload['mode'] ?? FW_SM_Multisite::MODE_SINGLE );
		$dest_blog = 0;
		$dest_url  = untrailingslashit( home_url() );

		// A site arriving into a network needs somewhere to arrive.
		if ( FW_SM_Multisite::MODE_SINGLE_TO_SUBSITE === $mode ) {
			if ( ! is_multisite() ) {
				$this->fail(
					__( 'The destination is not a network, so it cannot receive a site as a new subsite.', 'fw' ),
					400
				);
			}

			$created = FW_SM_Multisite::create_receiving_site(
				sanitize_title( $payload['target_slug'] ?? '' ),
				sanitize_text_field( $payload['source_name'] ?? __( 'Migrated site', 'fw' ) )
			);

			if ( is_wp_error( $created ) ) {
				$this->fail( $created->get_error_message(), 400 );
			}

			$dest_blog = (int) $created['blog_id'];
			$dest_url  = untrailingslashit( $created['url'] );
		}

		if ( FW_SM_Multisite::MODE_NETWORK === $mode && ! is_multisite() ) {
			$this->fail(
				__( 'The destination is not a network, so it cannot receive a whole network.', 'fw' ),
				400
			);
		}

		if ( FW_SM_Multisite::MODE_SUBSITE_TO_SINGLE === $mode && is_multisite() ) {
			$this->fail(
				__( 'The destination is a network. Send the site to a single-site install, or add it to this network as a new site instead.', 'fw' ),
				400
			);
		}

		$session = [
			'migration_id'  => sanitize_key( $payload['migration_id'] ?? '' ),
			'source_url'    => esc_url_raw( $payload['source_url'] ?? '' ),
			'source_prefix' => sanitize_text_field( $payload['source_prefix'] ?? '' ),
			'mode'          => $mode,
			// Decides whether files the destination does NOT receive count as
			// orphans at finalize, or merely as files that did not need sending.
			'quick'         => ! empty( $payload['quick'] ),
			'dest_blog_id'  => $dest_blog,
			'dest_url'      => $dest_url,
			'started_at'    => time(),
			'seen_at'       => time(),
			'tables'        => [],
			'deferred'      => [],
			'statements'    => 0,
			'files'         => 0,
			'bytes'         => 0,
		];

		update_option( self::SESSION_OPTION, $session, false );

		$this->respond(
			[
				'ready'         => true,
				'dest_blog_id'  => $dest_blog,
				'dest_url'      => $dest_url,
				'staged_swept'  => $swept,
				'swept_example' => $swept_examples,
			]
		);
	}

	/**
	 * Receive a batch of SQL and load it into staging tables.
	 *
	 * @return void
	 */
	public function handle_sql() {
		$started = microtime( true );

		$payload = $this->authenticate();
		$session = $this->session( $payload );

		$sql = isset( $payload['sql'] ) ? (string) $payload['sql'] : '';

		if ( '' === $sql ) {
			$this->respond( [ 'statements' => 0 ] );
		}

		// The source gzips and base64s the batch: SQL compresses extremely well,
		// and the round trip is usually the slowest part of a migration.
		if ( ! empty( $payload['encoded'] ) ) {
			$decoded = base64_decode( $sql, true );

			if ( false === $decoded ) {
				$this->fail( __( 'The SQL batch could not be decoded.', 'fw' ), 400 );
			}

			if ( function_exists( 'gzuncompress' ) ) {
				$inflated = @gzuncompress( $decoded ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( false !== $inflated ) {
					$decoded = $inflated;
				}
			}

			$sql = $decoded;
		}

		$importer = new FW_SM_Importer();

		$result = $importer->run_statements( $sql );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message(), 500 );
		}

		$session['tables']     = array_values( array_unique( array_merge( $session['tables'], $result['tables'] ) ) );
		$session['deferred']   = array_merge( $session['deferred'], $result['deferred'] );
		$session['statements'] += $result['statements'];
		$session['seen_at']    = time();

		update_option( self::SESSION_OPTION, $session, false );

		// How long the destination itself spent. Reported so the source can
		// separate "the far end is slow" from "the wire is slow" — two problems
		// with completely different fixes, and indistinguishable from one side.
		$this->respond(
			[
				'statements' => $result['statements'],
				'remote_ms'  => (int) round( ( microtime( true ) - $started ) * 1000 ),
			]
		);
	}

	/**
	 * Receive one file and write it into place.
	 *
	 * @return void
	 */
	public function handle_file() {
		$payload = $this->authenticate();
		$session = $this->session( $payload );

		$stage    = sanitize_key( $payload['stage'] ?? '' );
		$relative = (string) ( $payload['path'] ?? '' );

		$root = FW_SM_Stage::destination_dir( $stage, (int) ( $session['dest_blog_id'] ?? 0 ) );

		if ( null === $root ) {
			$this->fail(
				sprintf(
					/* translators: %s: stage name. */
					__( 'The destination has nowhere to put %s files.', 'fw' ),
					$stage
				),
				400
			);
		}

		$target = self::safe_target( $root, $relative );

		if ( null === $target ) {
			// A path that tried to escape its root is not a mistake to work
			// around, it is an attack to refuse.
			$this->fail( __( 'Refused a file path outside the destination directory.', 'fw' ), 400 );
		}

		$contents = isset( $payload['contents'] ) ? (string) $payload['contents'] : '';
		$decoded  = base64_decode( $contents, true );

		// Freed as soon as it is decoded; on a large chunk the base64 string is
		// the single biggest thing in memory.
		unset( $payload['contents'], $contents );

		if ( false === $decoded ) {
			$this->fail( __( 'The file could not be decoded.', 'fw' ), 400 );
		}

		if ( ! empty( $payload['gzipped'] ) && function_exists( 'gzuncompress' ) ) {
			$inflated = @gzuncompress( $decoded ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false !== $inflated ) {
				$decoded = $inflated;
			}

			unset( $inflated );
		}

		$offset   = isset( $payload['offset'] ) ? (int) $payload['offset'] : 0;
		$is_final = ! empty( $payload['final'] );

		// Chunks accumulate in a .part file. Nothing appears at the real path
		// until the whole file has arrived and its checksum matched, so an
		// interrupted transfer cannot leave a half-written plugin behind that
		// PHP would happily try to execute.
		$part = $target . '.fwsm-part';

		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			$this->note_skip( $session );
			$this->respond( [ 'written' => false, 'skipped' => true ] );
		}

		// Writes are POSITIONED, not appended, and that distinction is the whole
		// bug this replaced. Appending assumed every chunk arrives exactly once;
		// a retried chunk — which is ordinary, the transport retries on any 5xx —
		// appended the same bytes a second time, and the file then failed its
		// checksum for reasons that looked like corruption but were duplication.
		//
		// Seeking to the declared offset and truncating there makes re-sending a
		// chunk harmless: the same bytes land in the same place.
		$handle = @fopen( $part, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			// One unwritable file must not end a migration — read-only plugin
			// directories are ordinary on managed hosts. Report and move on.
			$this->note_skip( $session );
			$this->respond( [ 'written' => false, 'skipped' => true ] );
		}

		// filesize() is served from PHP's stat cache, which can hold a value from
		// before this request touched the file. Getting this wrong means writing
		// at the wrong offset, so the cache is dropped for this path first.
		clearstatcache( true, $part );

		$have = (int) @filesize( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		// A gap would mean a chunk went missing. Writing at the offset anyway
		// would leave a hole full of NUL bytes that still passes every check
		// except the checksum, so instead tell the source where we actually are
		// and let it resume from there.
		if ( $offset > $have ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			$this->respond( [ 'written' => false, 'at' => $have, 'resync' => true ] );
		}

		ftruncate( $handle, $offset );
		fseek( $handle, $offset );

		$written = fwrite( $handle, $decoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		fflush( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		unset( $decoded );

		if ( false === $written ) {
			@unlink( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

			$this->note_skip( $session );
			$this->respond( [ 'written' => false, 'skipped' => true ] );
		}

		$session['bytes']  += (int) $written;
		$session['seen_at'] = time();

		if ( ! $is_final ) {
			update_option( self::SESSION_OPTION, $session, false );

			// 'at' is where the destination now is. The source trusts this over
			// its own arithmetic, so a retry or a partial write cannot leave the
			// two disagreeing about how much of the file has arrived.
			$this->respond(
				[
					'written' => true,
					'bytes'   => (int) $written,
					'at'      => $offset + (int) $written,
					'partial' => true,
				]
			);
		}

		// Last chunk: verify the whole file, then put it in place.
		clearstatcache( true, $part );

		if ( ! empty( $payload['sha1'] ) ) {
			$actual = (string) @hash_file( 'sha1', $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( $actual !== $payload['sha1'] ) {
				$got_size = (int) @filesize( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				@unlink( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

				$this->fail(
					sprintf(
						/* translators: 1: file path, 2: size received. */
						__( 'The file %1$s did not match its checksum after transfer (%2$s received), so it was discarded. Its transfer will start again from the beginning.', 'fw' ),
						$relative,
						size_format( $got_size )
					),
					409
				);
			}
		}

		// Where the file lands now: beside the live copy as .fwsm-new for a
		// code stage (swapped in at finalize) or a replacement elsewhere,
		// otherwise straight to its final path. See landing_path().
		$landing = self::landing_path( $target, $stage );

		// rename() is atomic on the same filesystem, so nothing ever observes a
		// half-written file at either location.
		if ( ! @rename( $part, $landing ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

			$this->note_skip( $session );
			$this->respond( [ 'written' => false, 'skipped' => true ] );
		}

		if ( $landing !== $target ) {
			$this->record_staged( $session, $landing );
		}

		// Only once it is actually in place. Recording a file that failed to
		// land would mark it a survivor, and the prune would then leave the
		// destination's older copy of it sitting there.
		$this->record_received( $session, $stage, $relative );

		// Carry the source's modification time across. Without it every file
		// looks new on the next push, and the whole point of a quick migration
		// is being able to tell what has actually changed.
		if ( ! empty( $payload['mtime'] ) ) {
			@touch( $target, (int) $payload['mtime'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$session['files']++;

		update_option( self::SESSION_OPTION, $session, false );

		$this->respond( [ 'written' => true, 'bytes' => (int) $written ] );
	}

	/**
	 * Put the migration live.
	 *
	 * @return void
	 */
	public function handle_finalize() {
		$payload = $this->authenticate();
		$session = $this->session( $payload );

		$importer = new FW_SM_Importer();

		// Replacements go live here, all together, having sat beside their
		// targets for the whole transfer. Until this moment the destination has
		// been running exactly the files it started with, which is what makes an
		// interrupted migration harmless rather than fatal.
		// Nothing goes live unless every received file is genuinely on disk.
		// A migration that finishes with a file absent is not a finished
		// migration — it is a site that will fatal on its next request, and
		// the destination on its old, complete files is strictly better than
		// that. Refused as a decision (HTTP 400), not a hiccup, so the source
		// reports it rather than retrying an outcome that cannot change.
		$missing = $this->verify_received( $session );

		if ( ! empty( $missing ) ) {
			$this->discard_staged_files( $session );
			FW_SM_Importer::drop_staging_tables();

			$this->fail(
				sprintf(
					/* translators: 1: count, 2: example paths. */
					__( 'Refusing to finish: %1$d file(s) the destination received are no longer on its disk, so putting the rest live would leave the site broken. Nothing was changed. Something on the destination is removing files after they are written — a malware scanner or a disk quota are the usual causes. For example: %2$s', 'fw' ),
					count( $missing ),
					implode( ', ', array_slice( $missing, 0, 5 ) )
				),
				400
			);
		}

		$swap = $this->swap_staged_files( $session );

		// Before the swap, and before either exit — a files-only migration
		// finalises early and needs this just as much.
		$pruned = $this->prune_all( $session );

		if ( empty( $session['tables'] ) ) {
			// Files-only migrations are legitimate; there is simply no swap.
			$this->finish_session();

			$fo_examples = [];
			$fo_left     = $this->sweep_staged_files( false, $fo_examples );

			$this->respond(
				[ 'swapped' => 0, 'files' => (int) $session['files'] ]
				+ $pruned
				+ [
					'verify'            => $this->verify_landed(),
					'files_swapped'     => $swap['swapped'],
					'swap_failed'       => $swap['failed'],
					'swap_failed_count' => (int) ( $swap['failed_count'] ?? 0 ),
					'staged_left'       => $fo_left,
					'left_example'      => $fo_examples,
				]
			);
		}

		// Captured BEFORE the swap. Read afterwards, these would be the SOURCE's
		// values, which is the opposite of preserving them.
		$preserved = FW_SM_Importer::capture_preserved();

		$swapped = $importer->swap_into_place( $session['tables'] );

		if ( is_wp_error( $swapped ) ) {
			FW_SM_Importer::drop_staging_tables();

			$this->fail( $swapped->get_error_message(), 500 );
		}

		FW_SM_Importer::restore_preserved( $preserved, (string) $session['source_prefix'] );

		if ( ! empty( $session['deferred'] ) ) {
			$importer->apply_deferred_constraints( $session['deferred'] );
		}

		$importer->drop_displaced( $session['tables'] );

		// Network bookkeeping that the swap alone cannot fix.
		$mode = $session['mode'] ?? FW_SM_Multisite::MODE_SINGLE;

		if ( FW_SM_Multisite::MODE_NETWORK === $mode ) {
			// Every blog record still names the source's domain and path.
			//
			// The destination URL comes from the session, captured at begin()
			// BEFORE anything was swapped. Calling home_url() here instead reads
			// an options table that is now the SOURCE's, so it can answer with
			// the source's path — and then src_path === dst_path, the path
			// rewrite is skipped as a no-op, and every subsite ends up at
			// https://destination/source-path/subsite/. Exactly the ordering
			// trap that capture_preserved() exists to avoid, made twice.
			$dest_url = ! empty( $session['dest_url'] )
				? (string) $session['dest_url']
				: untrailingslashit( home_url() );

			$repaired = FW_SM_Multisite::repair_network_records(
				(string) $session['source_url'],
				$dest_url
			);

			$session['network_repair'] = $repaired;
		}

		if ( FW_SM_Multisite::MODE_SINGLE_TO_SUBSITE === $mode && ! empty( $session['dest_blog_id'] ) ) {
			// Users were not migrated, so without this the new site has no one
			// who can edit it.
			FW_SM_Multisite::grant_admin_on_site( (int) $session['dest_blog_id'] );
		}

		wp_cache_flush();
		flush_rewrite_rules( false );

		$this->finish_session();

		// The "after" check: with the swap done, nothing should still be staged.
		// Anything left is a replacement that could not be put in place, so its
		// update is NOT live — count it (do not delete it; it is the new copy,
		// and the source reports it so the user knows a retry is needed).
		$left_examples = [];
		$left_over     = $this->sweep_staged_files( false, $left_examples );

		$this->respond(
			[
				'swapped' => count( $session['tables'] ),
				'files'   => (int) $session['files'],
			] + $pruned + [
				'verify'            => $this->verify_landed(),
				'files_swapped'     => $swap['swapped'],
				'swap_failed'       => $swap['failed'],
				'swap_failed_count' => (int) ( $swap['failed_count'] ?? 0 ),
				'staged_left'       => $left_over,
				'left_example'      => $left_examples,
			]
		);
	}

	/**
	 * Check that what landed is actually readable, and report it.
	 *
	 * A migration can succeed by every measure it already takes — every row
	 * sent, every statement executed, the swap clean — and still leave a site
	 * showing default settings, because "the data is present" and "the site can
	 * find the data" are different claims.
	 *
	 * Two ways that happens, both seen in the wild:
	 *
	 *   - A serialized value arrives with a length prefix that no longer
	 *     matches its contents. unserialize() returns false, callers fall back
	 *     to defaults, and nothing anywhere reports an error.
	 *   - The value is perfect but the site looks it up under a different key.
	 *     Theme Settings live in fw_theme_settings_options:{theme-id}, and the
	 *     id comes from the theme's own manifest, defaulting to 'default'. A
	 *     destination whose theme files did not fully arrive resolves a
	 *     different id and reads an option that was never written.
	 *
	 * Reported rather than repaired: what to do about it depends on which of
	 * the two it is, and guessing on someone's live site is worse than telling
	 * them precisely what is wrong.
	 *
	 * @return array
	 */
	private function verify_landed() {
		global $wpdb;

		$report = [
			'theme_id'       => '',
			'settings_bytes' => 0,
			'settings_ok'    => false,
			'unreadable'     => 0,
			'unreadable_eg'  => [],
		];

		if ( function_exists( 'fw' ) && isset( fw()->theme ) && isset( fw()->theme->manifest ) ) {
			$report['theme_id'] = (string) fw()->theme->manifest->get_id();
		}

		if ( '' !== $report['theme_id'] ) {
			$value = get_option( 'fw_theme_settings_options:' . $report['theme_id'], null );

			if ( is_string( $value ) ) {
				$report['settings_bytes'] = strlen( $value );
				$report['settings_ok']    = false !== @unserialize( $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( is_array( $value ) ) {
				// WordPress already unserialized it on the way out, which is
				// itself proof that it is readable.
				$report['settings_bytes'] = strlen( (string) maybe_serialize( $value ) );
				$report['settings_ok']    = true;
			}
		}

		// A sweep for values that no longer unserialize. Bounded, because this
		// runs inside the finalize request on someone's live site.
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			  WHERE option_value LIKE 'a:%' OR option_value LIKE 'O:%'
			  LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( ! is_serialized( $row['option_value'] ) ) {
				continue;
			}

			if ( false !== @unserialize( $row['option_value'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				continue;
			}

			$report['unreadable']++;

			if ( count( $report['unreadable_eg'] ) < 5 ) {
				$report['unreadable_eg'][] = $row['option_name'];
			}
		}

		return $report;
	}

	/**
	 * Prune every prunable stage, if this migration is entitled to.
	 *
	 * A quick migration sends only what changed, so its received list is a list
	 * of CHANGES rather than of everything the source holds — pruning against
	 * it would delete every file that was skipped precisely because it was
	 * already correct. It is skipped outright rather than made clever.
	 *
	 * @param array $session
	 *
	 * @return array [ 'pruned' => int, 'pruned_paths' => string[] ]
	 */
	private function prune_all( $session ) {
		if ( ! empty( $session['quick'] ) ) {
			return [ 'pruned' => 0, 'pruned_paths' => [] ];
		}

		// And not if anything failed to land.
		//
		// Pruning reasons backwards: whatever was not received is stale, so it
		// goes. That is only true if everything the source sent arrived. A file
		// that failed to write is absent from the received list for a quite
		// different reason, and deleting the destination's existing copy of it
		// turns a recoverable hiccup into a missing file -- which, inside a
		// plugin, is a fatal error on a live site. Seen exactly once, and once
		// is enough.
		if ( ! empty( $session['skipped_files'] ) ) {
			return [
				'pruned'       => 0,
				'pruned_paths' => [],
				'prune_skipped' => (int) $session['skipped_files'],
			];
		}

		$count = 0;
		$paths = [];

		foreach ( self::prunable_stages() as $stage ) {
			$result = $this->prune_stage( $session, $stage );

			$count += $result['removed'];
			$paths  = array_merge( $paths, $result['paths'] );
		}

		return [ 'pruned' => $count, 'pruned_paths' => array_slice( $paths, 0, 50 ) ];
	}

	/**
	 * Measure the link without migrating anything.
	 *
	 * Accepts a payload of a given size, does nothing with it, and reports how
	 * long the destination itself spent. With the source timing the whole round
	 * trip, the difference is the wire — which is the number that decides
	 * whether a slow migration is the network, the far end, or this code, and
	 * it cannot be inferred from a progress bar.
	 *
	 * Deliberately does no work: it needs no migration session, so it can be
	 * run before starting one, or while wondering why one is slow.
	 *
	 * @return void
	 */
	public function handle_ping() {
		$started = microtime( true );

		$payload = $this->authenticate();

		$received = isset( $payload['blob'] ) ? strlen( (string) $payload['blob'] ) : 0;

		// Optionally exercise the database the same way a real batch would, so
		// "the destination is slow" can be attributed to MySQL or to PHP.
		$db_ms = 0;

		if ( ! empty( $payload['probe_db'] ) ) {
			global $wpdb;

			$t = microtime( true );

			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS _fwsm_ping' );
			$wpdb->query( 'CREATE TEMPORARY TABLE _fwsm_ping ( id bigint AUTO_INCREMENT PRIMARY KEY, v longtext )' );

			$blob   = str_repeat( 'x', 4096 );
			$tuples = array_fill( 0, 100, "('" . $blob . "')" );

			$wpdb->query( 'INSERT INTO _fwsm_ping (v) VALUES ' . implode( ',', $tuples ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS _fwsm_ping' );

			$db_ms = (int) round( ( microtime( true ) - $t ) * 1000 );
		}

		$this->respond(
			[
				'received'      => $received,
				'remote_ms'     => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'db_ms'         => $db_ms,
				'post_max_size' => wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ),
				'memory_limit'  => wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ),
				// So the source can tell whether the destination has been
				// updated. Both halves of a migration run their own copy of
				// this code, and a fix deployed to one of them is invisible
				// from the other — which is indistinguishable from the fix not
				// working.
				'version'       => self::extension_version(),
			]
		);
	}

	/**
	 * Remove files the source no longer has, within the folders it sent.
	 *
	 * The boundary is deliberate and narrower than "make the destination
	 * match". Inside a plugin or theme the source also has, the source is
	 * authoritative and anything else is stale — that is the case that takes a
	 * site down, because the framework loads whatever PHP it finds. A plugin or
	 * theme that exists ONLY on the destination is left completely alone: it
	 * was installed there on purpose, and deleting it would be a surprise of a
	 * far worse kind than a stale file.
	 *
	 * So: prune below the top level, never at it.
	 *
	 * @param array  $session
	 * @param string $stage
	 *
	 * @return array [ 'removed' => int, 'paths' => string[] ]
	 */
	private function prune_stage( $session, $stage ) {
		$empty = [ 'removed' => 0, 'paths' => [] ];

		if ( ! in_array( $stage, self::prunable_stages(), true ) ) {
			return $empty;
		}

		$root = FW_SM_Stage::destination_dir( $stage, (int) ( $session['dest_blog_id'] ?? 0 ) );
		$path = self::manifest_path( $session, $stage );

		if ( null === $root || null === $path || ! is_file( $path ) || ! is_dir( $root ) ) {
			return $empty;
		}

		// The received list, as a lookup. Tens of thousands of short strings,
		// read once — the alternative is re-reading the file per candidate.
		$kept  = [];
		$units = [];

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return $empty;
		}

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$kept[ $line ] = true;

			// The top-level folder this file sits in — the plugin or theme it
			// belongs to. A file directly in the stage root has no unit and
			// cannot make one authoritative.
			$slash = strpos( $line, '/' );

			if ( false !== $slash ) {
				$units[ substr( $line, 0, $slash ) ] = true;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( empty( $units ) ) {
			return $empty;
		}

		$removed = [];

		foreach ( array_keys( $units ) as $unit ) {
			$dir = self::safe_target( $root, $unit );

			if ( null === $dir || ! is_dir( $dir ) ) {
				continue;
			}

			$this->prune_dir( $dir, $unit, $kept, $removed );
		}

		return [ 'removed' => count( $removed ), 'paths' => array_slice( $removed, 0, 50 ) ];
	}

	/**
	 * Delete everything under a directory that is not in the received list.
	 *
	 * @param string $dir      Absolute.
	 * @param string $relative Path of $dir relative to the stage root.
	 * @param array  $kept     Received paths, as keys.
	 * @param array  $removed  Collects what was deleted.
	 *
	 * @return bool True if the directory is now empty.
	 */
	private function prune_dir( $dir, $relative, array &$kept, array &$removed ) {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $entries ) {
			return false;
		}

		$left = 0;

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$absolute = $dir . '/' . $entry;
			$child    = $relative . '/' . $entry;

			if ( is_dir( $absolute ) && ! is_link( $absolute ) ) {
				if ( $this->prune_dir( $absolute, $child, $kept, $removed ) ) {
					// Emptied by the prune, so the directory itself was part of
					// what the source removed.
					@rmdir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$removed[] = $child . '/';
					continue;
				}

				$left++;
				continue;
			}

			if ( isset( $kept[ $child ] ) ) {
				$left++;
				continue;
			}

			// Never touch a transfer's own scratch files; a concurrent chunked
			// write is not an orphan.
			if ( '.fwsm-part' === substr( $entry, -10 )
				|| self::STAGED_SUFFIX === substr( $entry, -strlen( self::STAGED_SUFFIX ) ) ) {
				$left++;
				continue;
			}

			if ( @unlink( $absolute ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
				$removed[] = $child;
				continue;
			}

			$left++;
		}

		return 0 === $left;
	}

	/**
	 * Stages whose orphaned files are removed after a full migration.
	 *
	 * Code only. A stale PHP file inside a plugin folder is loaded by the
	 * framework and can bring the whole site down — which is exactly how a
	 * rewritten extension's deleted sub-extension took out a destination that
	 * had otherwise migrated perfectly. Stale *media* does nothing of the sort,
	 * and on a live site the extra uploads are usually there on purpose, so
	 * uploads and loose wp-content files are left alone.
	 *
	 * @return string[]
	 */
	public static function prunable_stages() {
		return [ FW_SM_Stage::THEMES, FW_SM_Stage::PLUGINS, FW_SM_Stage::MUPLUGINS ];
	}

	/**
	 * Suffix for a replacement waiting to be put into place.
	 *
	 * Distinct from .fwsm-part, which is a transfer still in progress. A
	 * .fwsm-new file is complete and verified — it is simply not live yet.
	 */
	const STAGED_SUFFIX = '.fwsm-new';

	/**
	 * Where to write an incoming file: over the target, or beside it.
	 *
	 * This is the difference between an interrupted migration being harmless
	 * and being a fatal error on a live site.
	 *
	 * The database has always been safe here — it loads into `_fwsm_` tables
	 * and swaps at the end — but files were written straight into the live
	 * tree, one at a time. A migration that stopped partway therefore left
	 * plugins half-updated, and half a plugin is not a degraded plugin, it is a
	 * dead site: a new bootstrap.php requiring an include that had not arrived
	 * yet takes down every request. Seen twice.
	 *
	 * The distinction that keeps this cheap: a file that does not exist on the
	 * destination yet is safe to write immediately, because nothing references
	 * it until the code that uses it is itself replaced. Only files that
	 * REPLACE something have to wait, so the staging costs disk for the changed
	 * files rather than for the whole site.
	 *
	 * @param string $target Absolute path the file is destined for.
	 *
	 * @return string Where to actually write it now.
	 */
	private static function landing_path( $target, $stage ) {
		// In a code stage EVERY file waits — new files as much as replacements.
		//
		// The original rule staged only replacements, on the reasoning that a
		// file the destination did not have yet is harmless until the code that
		// references it is swapped in. That holds for a new file added to an
		// existing extension, but not for a whole new extension: its files are
		// ALL new, they land directly, and the framework discovers extensions
		// by scanning the folder — so a half-arrived new extension is loaded
		// mid-migration, before finalize, and a required include that has not
		// arrived yet fatals the live site. Staging the whole set behind
		// .fwsm-new keeps it invisible to WordPress and the framework until the
		// atomic swap. (This is the asset-optimizer fatal, and two before it.)
		if ( in_array( $stage, self::prunable_stages(), true ) ) {
			return $target . self::STAGED_SUFFIX;
		}

		// Elsewhere — uploads, loose wp-content files — only a replacement
		// waits. A new image or data file is not auto-loaded by anything, so it
		// is safe the moment it lands, and staging the bulk of a site's uploads
		// would double the disk a migration needs for no safety gained.
		return file_exists( $target ) ? $target . self::STAGED_SUFFIX : $target;
	}

	/**
	 * Note a replacement that is waiting to go live.
	 *
	 * Kept as a list rather than discovered by walking the tree at the end: a
	 * wp-content directory is tens of thousands of files, and the swap should
	 * cost the number of files that changed, not the number that exist.
	 *
	 * @param array  $session
	 * @param string $absolute Path of the .fwsm-new file.
	 *
	 * @return void
	 */
	private function record_staged( $session, $absolute ) {
		$path = self::staged_list_path( $session );

		if ( null === $path ) {
			return;
		}

		$handle = @fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return;
		}

		fwrite( $handle, str_replace( [ "\r", "\n" ], '', $absolute ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Note that the destination could not place a file it was sent.
	 *
	 * Any skip disables the finalize prune: what was not fully received cannot
	 * be judged complete, so orphans must not be deleted against it. The bundle
	 * path records this the same way (see handle_bundle); this keeps a chunked
	 * skip — a single large file the destination refused — just as safe.
	 *
	 * @param array $session
	 *
	 * @return void
	 */
	private function note_skip( $session ) {
		$session['skipped_files'] = (int) ( $session['skipped_files'] ?? 0 ) + 1;
		update_option( self::SESSION_OPTION, $session, false );
	}

	/**
	 * Where this migration's list of pending replacements lives.
	 *
	 * @param array $session
	 *
	 * @return string|null
	 */
	private static function staged_list_path( $session ) {
		$id = preg_replace( '/[^a-z0-9]/i', '', (string) ( $session['migration_id'] ?? '' ) );

		if ( '' === $id || ! function_exists( 'fw_upw_uploads_dir' ) ) {
			return null;
		}

		$dir = fw_upw_uploads_dir( 'site-migration' );
		$dir = ( $dir['path'] ?? '' ) . '/' . $id;

		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		return $dir . '/staged.txt';
	}

	/**
	 * Confirm every file the destination said it received is actually on disk.
	 *
	 * "Received" is recorded the moment a write succeeds. That is the right
	 * moment to record it and the wrong moment to trust it: a file can be
	 * removed again before finalize by something this code never sees — a
	 * malware scanner on shared hosting quarantining a PHP file it dislikes, a
	 * disk quota tripping mid-transfer, an older build on the far side that
	 * lost a skipped write silently. Three separate live sites have gone down
	 * with a new plugin file requiring an include that was not there, and in
	 * every case the migration had reported success.
	 *
	 * So before anything goes live the received lists are walked and each entry
	 * checked for — either in place already (a new file) or waiting as a staged
	 * replacement. Anything missing means the swap must not happen: a site on
	 * its OLD, complete files works; a site on NEW files with one absent does
	 * not.
	 *
	 * Only the code stages are checked. A missing upload is a broken image;
	 * a missing include is a dead site.
	 *
	 * @param array $session
	 *
	 * @return string[] Relative paths (stage-prefixed) that are not on disk.
	 */
	private function verify_received( $session ) {
		$missing = [];

		foreach ( self::prunable_stages() as $stage ) {
			$path = self::manifest_path( $session, $stage );
			$root = FW_SM_Stage::destination_dir( $stage, (int) ( $session['dest_blog_id'] ?? 0 ) );

			if ( null === $path || null === $root || ! is_file( $path ) ) {
				continue;
			}

			$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

			if ( ! $handle ) {
				continue;
			}

			while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$relative = trim( $line );

				if ( '' === $relative ) {
					continue;
				}

				$target = self::safe_target( $root, $relative );

				if ( null === $target ) {
					continue;
				}

				clearstatcache( true, $target );

				if ( is_file( $target ) || is_file( $target . self::STAGED_SUFFIX ) ) {
					continue;
				}

				if ( count( $missing ) < 200 ) {
					$missing[] = $stage . '/' . $relative;
				}
			}

			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $missing;
	}

	/**
	 * Put every staged replacement live.
	 *
	 * Called at finalize, so the destination's files change only once the whole
	 * migration has succeeded — the same guarantee the database swap gives.
	 *
	 * Renames are metadata operations, so thousands of them cost milliseconds
	 * rather than the minutes a transfer takes. The window in which the site
	 * could see a mixed set of files shrinks from the length of the migration
	 * to the length of this loop.
	 *
	 * @param array $session
	 *
	 * @return array [ 'swapped' => int, 'failed' => string[], 'failed_count' => int ]
	 */
	private function swap_staged_files( $session ) {
		$path = self::staged_list_path( $session );

		if ( null === $path || ! is_file( $path ) ) {
			return [ 'swapped' => 0, 'failed' => [], 'failed_count' => 0 ];
		}

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return [ 'swapped' => 0, 'failed' => [], 'failed_count' => 0 ];
		}

		$swapped      = 0;
		$failed       = [];
		$failed_count = 0;

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$staged = trim( $line );

			if ( '' === $staged || ! is_file( $staged ) ) {
				continue;
			}

			$target = substr( $staged, 0, -strlen( self::STAGED_SUFFIX ) );

			// A rename is atomic and cheap, so it is the first choice. But some
			// managed hosts serve deployed code from a layer where the file's
			// CONTENTS are writable yet the directory will not accept a new
			// entry — so creating the .fwsm-new beside it succeeds while renaming
			// over the live file fails. On those hosts a rename-only swap leaves
			// thousands of replacements stranded (the exact symptom: a migrated
			// theme whose changes never appear). So when the rename is refused,
			// fall back to overwriting the live file's bytes in place, which asks
			// only for write on the file, not the directory.
			if ( @rename( $staged, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$this->invalidate_opcode( $target );
				$swapped++;
				continue;
			}

			// The copy is not atomic the way the rename is, but the staged file
			// was fully written and checksum-verified before finalize, so the
			// bytes are known-good; the only cost is a millisecond window where a
			// concurrent request could read a partly-written file. That is a far
			// better outcome than the change never going live at all, and it only
			// applies to files the rename could not place.
			if ( @copy( $staged, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $staged ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
				$this->invalidate_opcode( $target );
				$swapped++;
				continue;
			}

			// Genuinely could not be put in place by either route. Left as it is
			// — the old file is still correct, and the .fwsm-new beside it is
			// evidence. The full count is kept even though only a few are named,
			// so the log states the true scale rather than the example cap.
			$failed_count++;

			if ( count( $failed ) < 20 ) {
				$failed[] = $target;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

		return [ 'swapped' => $swapped, 'failed' => $failed, 'failed_count' => $failed_count ];
	}

	/**
	 * Find — and optionally remove — staged files left under wp-content.
	 *
	 * A .fwsm-new (a replacement waiting to go live) or .fwsm-part (a transfer
	 * still in flight) should never outlive the migration that created it: a
	 * successful finalize promotes every .fwsm-new and a failed one discards
	 * them. One found on disk afterwards is therefore always debris — from a run
	 * that was cancelled, that failed before finalize, or whose swap could not
	 * overwrite a live file. This walks the whole content tree because a staged
	 * file can sit anywhere a file was replaced, not only in the code stages.
	 *
	 * Called two ways: to CLEAN (delete) at the start of a migration, so it
	 * begins from a clean tree; and to COUNT (no delete) at the end, so the
	 * source can warn if anything was left un-promoted.
	 *
	 * @param bool     $delete   Remove each one (begin) or only count it (finalize).
	 * @param string[] $examples Collects a handful of absolute paths, by reference.
	 *
	 * @return int How many were found (and, when $delete, removed).
	 */
	private function sweep_staged_files( $delete, array &$examples ) {
		$root = wp_normalize_path( WP_CONTENT_DIR );

		if ( ! is_dir( $root ) ) {
			return 0;
		}

		return $this->scan_staged_dir( $root, $delete, $examples, 0 );
	}

	/**
	 * Recursive worker for sweep_staged_files().
	 *
	 * @param string   $dir      Absolute directory to walk.
	 * @param bool     $delete   Remove matches, or only count them.
	 * @param string[] $examples Collects a handful of absolute paths, by reference.
	 * @param int      $depth    Recursion guard against a pathological tree.
	 *
	 * @return int
	 */
	private function scan_staged_dir( $dir, $delete, array &$examples, $depth ) {
		if ( $depth > 40 ) {
			return 0;
		}

		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $entries ) {
			return 0;
		}

		$count = 0;

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$absolute = $dir . '/' . $entry;

			if ( is_dir( $absolute ) && ! is_link( $absolute ) ) {
				$count += $this->scan_staged_dir( $absolute, $delete, $examples, $depth + 1 );
				continue;
			}

			if ( '.fwsm-part' !== substr( $entry, -10 )
				&& self::STAGED_SUFFIX !== substr( $entry, -strlen( self::STAGED_SUFFIX ) ) ) {
				continue;
			}

			// Counting mode records every one; cleaning mode counts it only if
			// the delete actually succeeds, so the reported figure is what truly
			// went away rather than what was merely attempted.
			if ( ! $delete || @unlink( $absolute ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
				if ( count( $examples ) < 5 ) {
					$examples[] = $absolute;
				}

				$count++;
			}
		}

		return $count;
	}

	/**
	 * Drop a just-replaced PHP file from the opcode cache.
	 *
	 * A swap changes a file's CONTENTS in place — same path, new code. PHP does
	 * not re-read a file it has already compiled unless the opcode cache is told
	 * to, and a managed host commonly runs OPcache with timestamp validation
	 * OFF for speed, so the destination keeps EXECUTING the file it started with
	 * even though the new bytes are on disk. That is why a migrated template or
	 * shortcode can look unchanged no matter how many times the WordPress page
	 * and object caches are cleared: those never touch OPcache. Invalidating the
	 * file here makes the swap actually take effect on the next request.
	 *
	 * Only PHP files carry bytecode, and force is required precisely because the
	 * timestamp may not have moved. Where a host disables the function it simply
	 * no-ops — the site owner then clears the opcode cache from the host's own
	 * tools once, which every managed host exposes.
	 *
	 * @param string $target Absolute path just swapped into place.
	 *
	 * @return void
	 */
	private function invalidate_opcode( $target ) {
		if ( '.php' !== strtolower( (string) substr( $target, -4 ) ) ) {
			return;
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $target, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Throw away replacements that never went live.
	 *
	 * A migration that failed leaves its staged files behind; they are worth
	 * nothing without the rest of it, and left alone they would accumulate.
	 *
	 * @param array $session
	 *
	 * @return int How many were discarded.
	 */
	private function discard_staged_files( $session ) {
		$path = self::staged_list_path( $session );

		if ( null === $path || ! is_file( $path ) ) {
			return 0;
		}

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return 0;
		}

		$gone = 0;

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$staged = trim( $line );

			if ( '' !== $staged && is_file( $staged ) && @unlink( $staged ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
				$gone++;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

		return $gone;
	}

	/**
	 * Note that a file arrived, so orphans can be told from survivors later.
	 *
	 * Recorded as it is written rather than reconstructed at the end: the
	 * source would otherwise have to re-send a list of every path it holds,
	 * which for a wp-content tree is tens of thousands of entries and a second
	 * traversal of the whole site.
	 *
	 * @param array  $session
	 * @param string $stage
	 * @param string $relative
	 *
	 * @return void
	 */
	private function record_received( $session, $stage, $relative ) {
		if ( ! in_array( $stage, self::prunable_stages(), true ) ) {
			return;
		}

		$path = self::manifest_path( $session, $stage );

		if ( null === $path ) {
			return;
		}

		$handle = @fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return;
		}

		// Appended, so a migration that spans hundreds of requests never holds
		// the list in memory.
		fwrite( $handle, str_replace( [ "
", "
" ], '', $relative ) . "
" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Where a stage's received-file list lives for this migration.
	 *
	 * @param array  $session
	 * @param string $stage
	 *
	 * @return string|null
	 */
	private static function manifest_path( $session, $stage ) {
		$id = preg_replace( '/[^a-z0-9]/i', '', (string) ( $session['migration_id'] ?? '' ) );

		if ( '' === $id || ! function_exists( 'fw_upw_uploads_dir' ) ) {
			return null;
		}

		$dir = fw_upw_uploads_dir( 'site-migration' );
		$dir = ( $dir['path'] ?? '' ) . '/' . $id;

		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		return $dir . '/received-' . sanitize_key( $stage ) . '.txt';
	}

	/**
	 * Describe an option closely enough to see HOW it differs.
	 *
	 * Knowing that a value "does not unserialize" narrows nothing — a value can
	 * be truncated, re-encoded, slashed an extra time, or simply be a different
	 * value that was never replaced. Those have completely different causes and
	 * completely different fixes, and every one of them looks identical from
	 * the front end.
	 *
	 * So: the length, a hash, the counts of the characters that escaping
	 * touches, and the exact byte offset where unserialize gives up. Run the
	 * same on both sides and the difference names the mechanism.
	 *
	 * @param string $name
	 *
	 * @return array
	 */
	public static function describe_option( $name ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$name
			)
		);

		if ( null === $value ) {
			return [ 'present' => false ];
		}

		$report = [
			'present'    => true,
			'bytes'      => strlen( $value ),
			'sha1'       => sha1( $value ),
			'quotes'     => substr_count( $value, '"' ),
			'backslash'  => substr_count( $value, chr( 92 ) ),
			'newlines'   => substr_count( $value, "\n" ),
			'non_ascii'  => strlen( $value ) - strlen( preg_replace( '/[\x80-\xFF]/', '', $value ) ),
			'head'       => substr( $value, 0, 80 ),
			'tail'       => substr( $value, -40 ),
			'readable'   => false,
			'fails_at'   => -1,
		];

		if ( false !== @unserialize( $value ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$report['readable'] = true;

			return $report;
		}

		// Where it stops making sense. Bisecting on a prefix is not meaningful
		// for serialized data, so instead the first string whose declared
		// length disagrees with what follows is located directly — that offset
		// is almost always the exact point of damage.
		if ( preg_match_all( '/s:(\d+):\\\\?"/', $value, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $i => $match ) {
				$declared = (int) $matches[1][ $i ][0];
				$start    = $match[1] + strlen( $match[0] );

				// What should follow a string of the declared length.
				$after = substr( $value, $start + $declared, 3 );

				if ( '";' !== substr( $after, 0, 2 )
					&& '"}' !== substr( $after, 0, 2 )
					&& '\\";' !== substr( $after, 0, 3 )
					&& '\\"}' !== substr( $after, 0, 3 ) ) {
					$report['fails_at'] = $match[1];
					$report['context']  = substr( $value, max( 0, $match[1] - 40 ), 160 );
					$report['declared'] = $declared;

					break;
				}
			}
		}

		return $report;
	}

	/**
	 * Describe the options this site cannot read.
	 *
	 * @param string[] $names
	 *
	 * @return array
	 */
	public function handle_describe() {
		$this->authenticate();

		$payload = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$names   = json_decode( (string) ( $payload['names'] ?? '' ), true );

		if ( ! is_array( $names ) ) {
			$this->fail( __( 'Could not read the option list.', 'fw' ), 400 );
		}

		$out = [];

		foreach ( array_slice( $names, 0, 20 ) as $name ) {
			$out[ (string) $name ] = self::describe_option( (string) $name );
		}

		$this->respond( [ 'options' => $out ] );
	}

	/**
	 * Everything worth comparing between two sites, in one shape.
	 *
	 * Deliberately the SAME function on both ends. A migration problem is
	 * almost never "this value is wrong" in isolation — it is "this value
	 * differs from the source", and a snapshot that is gathered differently on
	 * each side cannot answer that. Running identical code on both means any
	 * difference in the output is a real difference between the sites.
	 *
	 * Cheap enough to run inside a normal request: counts and lengths, no table
	 * scans of content.
	 *
	 * @return array
	 */
	public static function site_snapshot() {
		global $wpdb;

		$stylesheet = get_option( 'stylesheet' );
		$template   = get_option( 'template' );
		$themes_dir = untrailingslashit( wp_normalize_path( get_theme_root() ) );

		$snapshot = [
			'site_url'    => untrailingslashit( home_url() ),
			'abspath'     => untrailingslashit( wp_normalize_path( ABSPATH ) ),
			'wp'          => get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'extension'   => self::extension_version(),
			'stylesheet'  => (string) $stylesheet,
			'template'    => (string) $template,
			'prefix'      => $wpdb->prefix,
		];

		// Whether the theme directories are actually THERE. A migrated site
		// whose parent theme did not fully arrive still reports the right name
		// in the options table, so the name alone proves nothing.
		foreach ( [ 'stylesheet' => $stylesheet, 'template' => $template ] as $which => $slug ) {
			$dir = $themes_dir . '/' . $slug;

			$snapshot[ $which . '_dir_exists' ] = is_dir( $dir );
			$snapshot[ $which . '_manifest' ]   = is_file( $dir . '/framework-customizations/theme/manifest.php' );
			$snapshot[ $which . '_files' ]      = is_dir( $dir ) ? self::count_files( $dir ) : 0;
		}

		$snapshot['theme_settings'] = self::theme_settings_report();

		// Options that no longer unserialize. Every caller of such an option
		// silently falls back to a default, which is exactly what "the site
		// looks reset" means.
		$rows       = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			  WHERE option_value LIKE 'a:%' OR option_value LIKE 'O:%' LIMIT 3000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$unreadable = [];

		foreach ( (array) $rows as $row ) {
			if ( ! is_serialized( $row['option_value'] ) ) {
				continue;
			}

			if ( false === @unserialize( $row['option_value'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$unreadable[] = $row['option_name'];
			}
		}

		$snapshot['options_total']      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$snapshot['options_unreadable'] = count( $unreadable );
		$snapshot['unreadable_eg']      = array_slice( $unreadable, 0, 5 );

		$active = get_option( 'active_plugins', [] );
		$exts   = get_option( 'fw_active_extensions', [] );

		$snapshot['active_plugins']    = is_array( $active ) ? count( $active ) : 0;
		$snapshot['active_extensions'] = is_array( $exts ) ? count( $exts ) : 0;

		// Row counts for this site's own tables. A table that arrived empty is
		// invisible from the front end until somebody looks for the content.
		$snapshot['tables'] = [];

		foreach ( (array) $wpdb->get_col( "SHOW TABLES LIKE '" . $wpdb->esc_like( $wpdb->prefix ) . "%'" ) as $table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$snapshot['tables'][ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $snapshot;
	}

	/**
	 * How many files sit under a directory.
	 *
	 * @param string $dir
	 *
	 * @return int
	 */
	private static function count_files( $dir ) {
		$count = 0;

		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $it as $file ) {
				$count++;

				// A theme with a hundred thousand files is not a theme worth
				// counting exactly; the number is only ever compared for a
				// gross mismatch.
				if ( $count > 50000 ) {
					break;
				}
			}
		} catch ( Exception $e ) {
			return 0;
		}

		return $count;
	}

	/**
	 * Report this site's snapshot to the source.
	 *
	 * @return void
	 */
	public function handle_inspect() {
		$this->authenticate();
		$this->respond( self::site_snapshot() );
	}

	/**
	 * What Theme Settings this site holds, and under which key.
	 *
	 * Theme Settings live in `fw_theme_settings_options:{theme-id}` where the
	 * id comes from the theme's own manifest and falls back to 'default'. Two
	 * quite different failures look identical from the front end — the option
	 * missing, and the option present but looked up under another id — so both
	 * the resolved id AND every key actually present are reported. The pair of
	 * them distinguishes the cases outright.
	 *
	 * @return array
	 */
	public static function theme_settings_report() {
		global $wpdb;

		$report = [ 'theme_id' => '', 'readable' => false, 'bytes' => 0, 'keys' => [] ];

		if ( function_exists( 'fw' ) && isset( fw()->theme->manifest ) ) {
			$report['theme_id'] = (string) fw()->theme->manifest->get_id();
		}

		$rows = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options}
			  WHERE option_name LIKE 'fw\_theme\_settings\_options:%'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$report['keys'][ $row['option_name'] ] = (int) $row['bytes'];
		}

		if ( '' !== $report['theme_id'] ) {
			$value              = get_option( 'fw_theme_settings_options:' . $report['theme_id'], null );
			$report['readable'] = is_array( $value ) || ( is_string( $value ) && false !== @unserialize( $value ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$report['bytes']    = $report['keys'][ 'fw_theme_settings_options:' . $report['theme_id'] ] ?? 0;
		}

		return $report;
	}

	/**
	 * This site's Site Migration version.
	 *
	 * @return string
	 */
	public static function extension_version() {
		$ext = function_exists( 'fw_ext' ) ? fw_ext( 'site-migration' ) : null;

		return $ext && isset( $ext->manifest ) ? (string) $ext->manifest->get_version() : '';
	}

	/**
	 * Receive many small files in one request.
	 *
	 * Each file is written whole — they are small by definition — with the same
	 * path safety and checksum verification a chunked transfer gets, and the
	 * same .part-then-rename so nothing half-written ever appears at a real
	 * path. A file that fails is reported and skipped rather than failing the
	 * bundle: one unwritable file in a directory of ten thousand should not end
	 * a migration.
	 *
	 * @return void
	 */
	public function handle_bundle() {
		$payload = $this->authenticate();
		$session = $this->session( $payload );

		$stage = sanitize_key( $payload['stage'] ?? '' );
		$root  = FW_SM_Stage::destination_dir( $stage, (int) ( $session['dest_blog_id'] ?? 0 ) );

		if ( null === $root ) {
			$this->fail(
				sprintf(
					/* translators: %s: stage name. */
					__( 'The destination has nowhere to put %s files.', 'fw' ),
					$stage
				),
				400
			);
		}

		$body = base64_decode( (string) ( $payload['bundle'] ?? '' ), true );

		unset( $payload['bundle'] );

		if ( false === $body ) {
			$this->fail( __( 'The bundle could not be decoded.', 'fw' ), 400 );
		}

		if ( ! empty( $payload['gzipped'] ) && function_exists( 'gzuncompress' ) ) {
			$inflated = @gzuncompress( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false !== $inflated ) {
				$body = $inflated;
			}

			unset( $inflated );
		}

		$files = json_decode( $body, true );

		unset( $body );

		if ( ! is_array( $files ) ) {
			$this->fail( __( 'The bundle contents could not be read.', 'fw' ), 400 );
		}

		$written = 0;
		$bytes   = 0;
		$skipped = 0;

		// WHICH files were skipped, not merely how many. The source deletes a
		// queue job per file it believes landed, so a count alone lets a
		// skipped file be forgotten: never retried, and never recorded as
		// received -- which then invites the prune to delete the destination's
		// existing copy of it.
		$skipped_paths = [];

		foreach ( $files as $file ) {
			$relative = (string) ( $file['path'] ?? '' );
			$target   = self::safe_target( $root, $relative );

			if ( null === $target ) {
				// A path that tried to escape its root is refused, exactly as in
				// a single-file transfer. Bundling must not become a way around
				// the check.
				$skipped++;
				$skipped_paths[] = $relative;
				continue;
			}

			$data = base64_decode( (string) ( $file['data'] ?? '' ), true );

			if ( false === $data ) {
				$skipped++;
				$skipped_paths[] = $relative;
				continue;
			}

			if ( ! empty( $file['sha1'] ) && sha1( $data ) !== $file['sha1'] ) {
				$skipped++;
				$skipped_paths[] = $relative;
				continue;
			}

			if ( ! wp_mkdir_p( dirname( $target ) ) ) {
				$skipped++;
				$skipped_paths[] = $relative;
				continue;
			}

			$part = $target . '.fwsm-part';

			if ( false === @file_put_contents( $part, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
				$skipped++;
				$skipped_paths[] = $relative;
				continue;
			}

			$landing = self::landing_path( $target, $stage );

			if ( ! @rename( $part, $landing ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
				$skipped++;
				$skipped_paths[] = $relative;
				continue;
			}

			if ( $landing !== $target ) {
				$this->record_staged( $session, $landing );
			}

			if ( ! empty( $file['mtime'] ) ) {
				@touch( $landing, (int) $file['mtime'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$this->record_received( $session, $stage, $relative );

			$written++;
			$bytes += strlen( $data );

			unset( $data );
		}

		$session['files']  += $written;
		$session['bytes']  += $bytes;
		$session['seen_at'] = time();

		update_option( self::SESSION_OPTION, $session, false );

		if ( ! empty( $skipped_paths ) ) {
			// Remembered on the session so finalize can refuse to prune. A prune
			// decides what to delete from what it received, and that reasoning is
			// only sound if everything that should have arrived did.
			$session['skipped_files'] = (int) ( $session['skipped_files'] ?? 0 ) + count( $skipped_paths );
			update_option( self::SESSION_OPTION, $session, false );
		}

		$this->respond(
			[
				'written'       => $written,
				'bytes'         => $bytes,
				'skipped'       => $skipped,
				'skipped_paths' => $skipped_paths,
			]
		);
	}

	/**
	 * Which of these files does the destination already have, identically?
	 *
	 * The source sends a batch of {path, size, mtime} and gets back only the
	 * ones it needs to send. Comparing size and modification time is what rsync
	 * does by default and for the same reason: it is nearly free, needs no
	 * reading of file contents on either side, and errs in the safe direction —
	 * a false "differs" costs one wasted transfer, while a false "identical" is
	 * impossible unless something rewrote a file to exactly the same length at
	 * exactly the same second.
	 *
	 * @return void
	 */
	public function handle_have() {
		$payload = $this->authenticate();
		$session = $this->session( $payload );

		$stage = sanitize_key( $payload['stage'] ?? '' );
		$root  = FW_SM_Stage::destination_dir( $stage, (int) ( $session['dest_blog_id'] ?? 0 ) );

		$files = json_decode( (string) ( $payload['files'] ?? '' ), true );

		if ( ! is_array( $files ) ) {
			$this->fail( __( 'Could not read the file list.', 'fw' ), 400 );
		}

		$needed = [];

		foreach ( $files as $index => $file ) {
			$relative = (string) ( $file['path'] ?? '' );
			$size     = (int) ( $file['size'] ?? -1 );
			$mtime    = (int) ( $file['mtime'] ?? 0 );

			// No destination directory means nothing can be there yet.
			if ( null === $root ) {
				$needed[] = (int) $index;
				continue;
			}

			$target = self::safe_target( $root, $relative );

			if ( null === $target || ! is_file( $target ) ) {
				$needed[] = (int) $index;
				continue;
			}

			clearstatcache( true, $target );

			if ( (int) filesize( $target ) !== $size || (int) filemtime( $target ) !== $mtime ) {
				$needed[] = (int) $index;
			}
		}

		$this->respond( [ 'needed' => $needed, 'checked' => count( $files ) ] );
	}

	/**
	 * Abandon an incoming migration and undo nothing, because nothing was done.
	 *
	 * @return void
	 */
	public function handle_abort() {
		$payload = $this->authenticate();

		$session = get_option( self::SESSION_OPTION, null );
		$theirs  = sanitize_key( $payload['migration_id'] ?? '' );
		$ours    = is_array( $session ) ? (string) ( $session['migration_id'] ?? '' ) : '';

		// An abort must only abort the migration it belongs to. Without this
		// check, a source giving up on an OLD migration deletes whatever session
		// happens to be open — including a newer one from the same site that is
		// running perfectly well. The newer migration then fails with "no
		// migration has been started", which points nowhere near the cause.
		if ( '' !== $ours && '' !== $theirs && $ours !== $theirs ) {
			$this->respond( [ 'aborted' => false, 'reason' => 'not-yours' ] );
		}

		FW_SM_Importer::drop_staging_tables();

		$this->finish_session();

		// Staged replacements die with the migration that produced them. Left
		// behind they would be invisible clutter beside every file that had
		// changed, and a later migration would have no way to tell whose they
		// were.
		$discarded = $this->discard_staged_files( $session );

		$this->respond( [ 'aborted' => true, 'discarded' => $discarded ] );
	}

	/**
	 * Resolve a relative path against a root, refusing anything that escapes it.
	 *
	 * @param string $root     Absolute, normalized, no trailing slash.
	 * @param string $relative
	 *
	 * @return string|null Null when the path is not acceptable.
	 */
	public static function safe_target( $root, $relative ) {
		$relative = wp_normalize_path( (string) $relative );

		if ( '' === $relative
		     || '/' === $relative[0]
		     || preg_match( '#^[a-zA-Z]:#', $relative )
		     || false !== strpos( $relative, '../' )
		     || false !== strpos( $relative, "\0" )
		) {
			return null;
		}

		$root   = untrailingslashit( wp_normalize_path( $root ) );
		$target = wp_normalize_path( $root . '/' . ltrim( $relative, '/' ) );

		// realpath() cannot be used — the file does not exist yet — so the
		// check is done on the normalized string, which is why '../' is
		// rejected outright above rather than resolved.
		if ( 0 !== strpos( $target, $root . '/' ) ) {
			return null;
		}

		return $target;
	}

	/**
	 * The incoming migration this site is currently holding, if any.
	 *
	 * For the Destination tab, so a stuck session is visible and clearable
	 * rather than being an hour-long mystery.
	 *
	 * @return array|null
	 */
	public static function get_incoming() {
		$session = get_option( self::SESSION_OPTION, null );

		return is_array( $session ) && ! empty( $session['migration_id'] ) ? $session : null;
	}

	/**
	 * Forget an incoming migration and drop whatever it staged.
	 *
	 * Safe at any point before finalize: staging tables are not live data, so
	 * discarding them cannot damage this site.
	 *
	 * @return void
	 */
	public static function clear_incoming() {
		FW_SM_Importer::drop_staging_tables();

		delete_option( self::SESSION_OPTION );
	}

	/**
	 * The current incoming session, or a refusal.
	 *
	 * @return array
	 */
	private function session( array $payload = [] ) {
		$session = get_option( self::SESSION_OPTION, null );

		if ( ! is_array( $session ) || empty( $session['migration_id'] ) ) {
			$this->fail(
				__( 'The destination is not holding this migration any more. It may have been cleared from its Destination tab, or an earlier migration from this site told it to stop. Start the migration again.', 'fw' ),
				410
			);
		}

		// Belonging matters as much as existing: a request from a different
		// migration must not be written into this one's staging tables.
		$theirs = sanitize_key( $payload['migration_id'] ?? '' );

		if ( '' !== $theirs && $theirs !== (string) $session['migration_id'] ) {
			$this->fail(
				__( 'The destination is holding a different migration. Start this one again.', 'fw' ),
				409
			);
		}

		return $session;
	}

	/**
	 * @return void
	 */
	private function finish_session() {
		$session = get_option( self::SESSION_OPTION, [] );

		if ( is_array( $session ) ) {
			self::forget_manifests( $session );
		}

		delete_option( self::SESSION_OPTION );
	}

	/**
	 * Delete this migration's received-file lists.
	 *
	 * They are scratch, they can run to a megabyte or two, and leaving them
	 * behind would slowly fill the uploads directory with the debris of every
	 * migration a site has ever received.
	 *
	 * @param array $session
	 *
	 * @return void
	 */
	private static function forget_manifests( $session ) {
		$dir = null;

		foreach ( self::prunable_stages() as $stage ) {
			$path = self::manifest_path( $session, $stage );

			if ( null === $path ) {
				continue;
			}

			$dir = dirname( $path );

			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( $dir && is_dir( $dir ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * This extension's version, so the source can warn about a mismatch.
	 *
	 * Reported rather than enforced. The wire format is versioned by the action
	 * names, so two builds that differ by a patch release interoperate fine, and
	 * refusing to migrate over a version difference would be a support burden
	 * with very little safety bought.
	 *
	 * @return string
	 */
	private static function plugin_version() {
		if ( ! function_exists( 'fw' ) ) {
			return '';
		}

		$extension = fw()->extensions->get( 'site-migration' );

		if ( ! $extension ) {
			return '';
		}

		return (string) $extension->manifest->get_version();
	}

	/**
	 * The wire-protocol revision this build speaks.
	 *
	 * Separate from the extension version on purpose. Patch releases that do not
	 * change what crosses the wire must not make two sites refuse to talk, and
	 * a change that DOES alter the protocol must be impossible to miss. Bump
	 * this only when the request or response shape changes.
	 */
	const PROTOCOL = 1;

	/**
	 * @param array $data
	 *
	 * @return void
	 */
	private function respond( array $data ) {
		$this->discard_output();

		wp_send_json_success( $data );
	}

	/**
	 * Throw away anything PHP printed before our JSON.
	 *
	 * catch_fatals() opens a buffer so a fatal can be replaced with a readable
	 * message. It has a second job that was going unused: a notice, warning or
	 * deprecation printed by ANY plugin on the destination would otherwise be
	 * flushed in front of the JSON, and the source would receive
	 * "Warning: ...{"success":true}" — which fails to decode and surfaces as
	 * "the destination returned something unexpected".
	 *
	 * The destination's own logs still have the notice; the wire does not need
	 * it.
	 *
	 * @return void
	 */
	private function discard_output() {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * @param string $message
	 * @param int    $status
	 *
	 * @return void
	 */
	private function fail( $message, $status = 400 ) {
		$this->discard_output();

		wp_send_json_error( [ 'message' => $message ], $status );
	}
}
