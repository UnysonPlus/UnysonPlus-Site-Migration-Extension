<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The source side: a signed HTTP client for talking to a destination.
 *
 * Everything that crosses the wire goes through post(). That is deliberate —
 * signing, error translation and retry policy all live in exactly one place, so
 * there is no path to the destination that accidentally skips one of them.
 *
 * On errors: a migration runs for minutes across dozens of requests, so
 * transient failures are normal rather than exceptional. A 502 from a proxy or
 * a dropped connection is retryable and says nothing about whether the
 * migration can succeed; a 403 means the key is wrong and retrying is pointless
 * and slow. The two are distinguished rather than lumped together.
 */
class FW_SM_Sender {

	/**
	 * Seconds to wait for the destination. Generous: the destination may be
	 * writing a large batch of rows before it answers.
	 */
	const TIMEOUT = 120;

	/**
	 * Bytes of a file sent per request.
	 *
	 * Whole-file transfer was the wrong shape and it showed up as an out-of-
	 * memory fatal on the destination. A file of size N cost roughly 3N on the
	 * source (contents, a compressed copy, a base64 copy) and 3.3N on the
	 * destination (the base64 string in $_POST, the decoded bytes, the inflated
	 * bytes) — so a single 40 MB plugin file exhausted a 128 MB limit.
	 *
	 * Chunking makes the cost of a transfer independent of the size of the file:
	 * a 4 GB video and a 4 KB stylesheet both move through the same small
	 * buffer.
	 */
	const CHUNK_BYTES = 1048576; // 1 MB of raw file per request

	/**
	 * Largest file that travels inside a bundle rather than on its own.
	 *
	 * Anything at or above this uses the chunked path, where the cost is already
	 * dominated by the bytes rather than the round trip.
	 */
	const BUNDLE_FILE_MAX = 524288; // 512 KB

	/**
	 * Raw bytes of files to pack into one bundled request.
	 *
	 * Sized against post_max_size on an ordinary host: 2 MB of raw files is at
	 * most ~2.7 MB once base64-encoded, and usually far less because the bundle
	 * is gzipped as a whole first.
	 */
	const BUNDLE_BYTES = 2097152; // 2 MB

	/**
	 * Most files in one bundle, however small they are.
	 */
	const BUNDLE_FILES = 250;

	/** @var string Destination site URL, no trailing slash. */
	private $url;

	/** @var string Shared secret. */
	private $key;

	/**
	 * @param string $url
	 * @param string $key
	 */
	/** @var string The migration these requests belong to. */
	private $migration_id = '';

	/**
	 * The destination's post_max_size, when known.
	 *
	 * @var int
	 */
	private $remote_post_max = 0;

	public function __construct( $url, $key, $migration_id = '' ) {
		$this->url          = untrailingslashit( $url );
		$this->key          = $key;
		$this->migration_id = (string) $migration_id;
	}


	/**
	 * Size requests to what the destination will actually accept.
	 *
	 * A request larger than the destination's post_max_size is not rejected
	 * with an error — PHP silently discards the body, so it arrives looking
	 * like an empty request. Fitting inside the limit is far better than
	 * detecting that afterwards.
	 *
	 * @param int $post_max_size Bytes.
	 *
	 * @return self
	 */
	public function with_limits( $post_max_size ) {
		$this->remote_post_max = (int) $post_max_size;

		return $this;
	}

	/**
	 * Largest raw payload to build for one request.
	 *
	 * Base64 inflates by a third and the form carries other fields, so only a
	 * portion of the destination's limit is usable. Being conservative here
	 * costs a few extra requests; being optimistic costs a failed migration.
	 *
	 * @param int $preferred The size we would use with no constraint.
	 *
	 * @return int
	 */
	public static function usable_payload( $remote_post_max ) {
		$remote_post_max = (int) $remote_post_max;

		if ( $remote_post_max <= 0 ) {
			return 0;
		}

		return max( 65536, (int) floor( $remote_post_max * 0.6 ) - 1024 );
	}

	private function fit_to_remote( $preferred ) {
		if ( $this->remote_post_max <= 0 ) {
			return $preferred;
		}

		// Base64 inflates by a third, so the raw payload can be at most ~70% of
		// the limit; 60% leaves room for the other form fields and the headers.
		// WP Migrate subtracts a flat 1 KB for the same reason — a request that
		// exceeds post_max_size is not rejected, it is silently emptied, which
		// is a far worse failure than being slightly conservative.
		$usable = (int) floor( $this->remote_post_max * 0.6 ) - 1024;

		return max( 65536, min( $preferred, $usable ) );
	}

	/**
	 * Build a sender from the stored connection, if there is one.
	 *
	 * @return self|WP_Error
	 */
	public static function from_stored() {
		$stored = get_option( 'fw_sm_destination', null );

		if ( ! is_array( $stored ) || empty( $stored['url'] ) || empty( $stored['key'] ) ) {
			return new WP_Error(
				'fw_sm_not_connected',
				__( 'This site is not connected to a destination yet.', 'fw' )
			);
		}

		$state = FW_SM_State::get();

		$sender = new self(
			$stored['url'],
			$stored['key'],
			( null !== $state && ! empty( $state['id'] ) ) ? $state['id'] : ''
		);

		return $sender->with_limits( (int) ( $stored['info']['post_max_size'] ?? 0 ) );
	}

	/**
	 * Handshake with the destination.
	 *
	 * @return array|WP_Error Destination site info.
	 */
	public function verify() {
		return $this->post( FW_SM_Receiver::ACTION_VERIFY, [] );
	}

	/**
	 * Open a migration on the destination.
	 *
	 * @param string $migration_id
	 *
	 * @return array|WP_Error
	 */
	public function begin( $migration_id, array $options = [] ) {
		global $wpdb;

		return $this->post(
			FW_SM_Receiver::ACTION_BEGIN,
			[
				'migration_id'  => $migration_id,
				'source_url'    => untrailingslashit( home_url() ),
				// The BASE prefix: on a network $wpdb->prefix is whichever blog
				// happens to be current, which is not what the destination needs
				// in order to rename tables.
				'source_prefix' => $wpdb->base_prefix,
				'source_name'   => get_bloginfo( 'name' ),
				'mode'          => $options['mode'] ?? FW_SM_Multisite::MODE_SINGLE,
				'target_slug'   => $options['target_slug'] ?? '',
				// The destination needs this to know whether the files it
				// receives are the whole site or only what changed — which
				// decides whether anything it does NOT receive is an orphan.
				'quick'         => ! empty( $options['quick'] ) ? 1 : 0,
				'protocol'      => FW_SM_Receiver::PROTOCOL,
			]
		);
	}

	/**
	 * Send a batch of SQL.
	 *
	 * @param string $sql
	 *
	 * @return array|WP_Error
	 */
	public function send_sql( $sql ) {
		$encoded = false;
		$payload = $sql;

		// SQL compresses by an order of magnitude, and the round trip usually
		// dominates the cost of a migration.
		if ( function_exists( 'gzcompress' ) ) {
			$compressed = gzcompress( $sql, 6 );

			if ( false !== $compressed ) {
				$payload = $compressed;
				$encoded = true;
			}
		}

		return $this->post(
			FW_SM_Receiver::ACTION_SQL,
			[
				'sql'     => base64_encode( $payload ),
				'encoded' => $encoded ? 1 : 0,
			]
		);
	}

	/**
	 * Send one file.
	 *
	 * @param string $stage
	 * @param string $relative_path
	 * @param string $absolute_path
	 *
	 * @return array|WP_Error
	 */
	public function send_file( $stage, $relative_path, $absolute_path, $offset = 0 ) {
		$size = (int) @filesize( $absolute_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$handle = @fopen( $absolute_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return new WP_Error(
				'fw_sm_unreadable_file',
				sprintf(
					/* translators: %s: file path. */
					__( 'Could not read %s.', 'fw' ),
					$relative_path
				)
			);
		}

		if ( $offset > 0 && -1 === fseek( $handle, $offset ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			return new WP_Error(
				'fw_sm_seek_failed',
				sprintf(
					/* translators: %s: file path. */
					__( 'Could not resume reading %s.', 'fw' ),
					$relative_path
				)
			);
		}

		$chunk = fread( $handle, $this->fit_to_remote( self::CHUNK_BYTES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $chunk ) {
			return new WP_Error(
				'fw_sm_read_failed',
				sprintf(
					/* translators: %s: file path. */
					__( 'Could not read from %s.', 'fw' ),
					$relative_path
				)
			);
		}

		$read       = strlen( $chunk );
		$next       = $offset + $read;
		$is_final   = $next >= $size;
		$gzipped    = false;

		// Each chunk compresses independently, so the destination never has to
		// hold more than one inflated chunk at a time.
		if ( function_exists( 'gzcompress' ) && $read > 1024 ) {
			$compressed = gzcompress( $chunk, 6 );

			// Already-compressed formats (jpg, png, zip) grow rather than shrink.
			if ( false !== $compressed && strlen( $compressed ) < $read ) {
				$chunk   = $compressed;
				$gzipped = true;
			}
		}

		$payload = [
			'stage'    => $stage,
			'path'     => $relative_path,
			'offset'   => $offset,
			'contents' => base64_encode( $chunk ),
			'gzipped'  => $gzipped ? 1 : 0,
			'final'    => $is_final ? 1 : 0,
		];

		if ( $is_final ) {
			$payload['mtime'] = (int) @filemtime( $absolute_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// The checksum covers the WHOLE file and is computed by streaming, so a
		// 400 MB file costs no more memory to hash than a 400 byte one. It only
		// travels with the last chunk, which is the only point it can be checked.
		if ( $is_final ) {
			$payload['sha1'] = (string) @hash_file( 'sha1', $absolute_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$result = $this->post( FW_SM_Receiver::ACTION_FILE, $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['done']        = $is_final;
		$result['next_offset'] = $next;
		$result['sent_bytes']  = $read;

		return $result;
	}

	/**
	 * Send many small files in a single request.
	 *
	 * This exists because request COUNT, not bandwidth, is what makes a file
	 * transfer slow across the internet. A wp-content tree is tens of thousands
	 * of ~8 KB files; sending each on its own means tens of thousands of round
	 * trips, and at a couple of hundred milliseconds each that is over an hour
	 * spent waiting rather than transferring.
	 *
	 * Packing ~250 of them into one request turns that hour into a couple of
	 * minutes. Gzipping the bundle as a whole helps a second time: many small
	 * text files share a compression dictionary far better than each does alone.
	 *
	 * @param string  $stage
	 * @param array[] $files Each: [ 'path', 'absolute' ].
	 *
	 * @return array|WP_Error [ 'written', 'bytes', 'skipped', 'sent_files' ]
	 */
	public function send_bundle( $stage, array $files ) {
		$packed = $this->pack_bundle( $stage, $files );

		if ( null === $packed ) {
			return [ 'written' => 0, 'bytes' => 0, 'skipped' => 0, 'sent_files' => 0 ];
		}

		$result = $this->post( $packed['call']['action'], $packed['call']['data'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['sent_files'] = $packed['sent_files'];

		return $result;
	}

	/**
	 * Send several bundles at once.
	 *
	 * Each group is packed independently and they all go together, so the cost
	 * of the round trip is paid once for all of them rather than once each.
	 *
	 * @param string $stage
	 * @param array  $groups Array of file lists, as send_bundle() takes.
	 *
	 * @return array One result (or WP_Error) per group, in the order given.
	 */
	public function send_bundles( $stage, array $groups ) {
		$calls = [];
		$packs = [];

		foreach ( $groups as $i => $files ) {
			$packed = $this->pack_bundle( $stage, $files );

			if ( null === $packed ) {
				$packs[ $i ] = null;
				continue;
			}

			$packs[ $i ] = $packed;
			$calls[ $i ] = $packed['call'];
		}

		$results = $this->post_many( array_values( $calls ) );
		$out     = [];
		$n       = 0;

		foreach ( $groups as $i => $files ) {
			if ( null === $packs[ $i ] ) {
				$out[] = [ 'written' => 0, 'bytes' => 0, 'skipped' => 0, 'sent_files' => 0 ];
				continue;
			}

			$result = $results[ $n++ ] ?? new WP_Error( 'fw_sm_transport', __( 'No response.', 'fw' ), [ 'retryable' => true ] );

			if ( ! is_wp_error( $result ) ) {
				$result['sent_files'] = $packs[ $i ]['sent_files'];
			}

			$out[] = $result;
		}

		return $out;
	}

	/**
	 * Read, hash, and compress a group of files into one signed request.
	 *
	 * Separated from sending so the same bundle can go on its own or alongside
	 * others without the packing rules diverging between the two.
	 *
	 * @param string $stage
	 * @param array  $files
	 *
	 * @return array|null [ 'call' => [ 'action', 'data' ], 'sent_files' => int ]
	 */
	private function pack_bundle( $stage, array $files ) {
		$packed = [];
		$raw    = 0;

		foreach ( $files as $file ) {
			$absolute = $file['absolute'] ?? '';

			if ( '' === $absolute || ! is_readable( $absolute ) ) {
				continue;
			}

			$contents = @file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $contents ) {
				continue;
			}

			$packed[] = [
				'path'  => $file['path'] ?? '',
				'mtime' => (int) @filemtime( $absolute ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'sha1'  => sha1( $contents ),
				// Encoded per file so the bundle survives being JSON, which
				// cannot carry raw bytes.
				'data'  => base64_encode( $contents ),
			];

			$raw += strlen( $contents );

			unset( $contents );

			if ( $raw >= $this->fit_to_remote( self::BUNDLE_BYTES )
			     || count( $packed ) >= self::BUNDLE_FILES ) {
				break;
			}
		}

		if ( empty( $packed ) ) {
			return null;
		}

		$body    = (string) wp_json_encode( $packed );
		$gzipped = false;

		if ( function_exists( 'gzcompress' ) ) {
			$compressed = gzcompress( $body, 6 );

			if ( false !== $compressed && strlen( $compressed ) < strlen( $body ) ) {
				$body    = $compressed;
				$gzipped = true;
			}
		}

		return [
			'sent_files' => count( $packed ),
			'call'       => [
				'action' => FW_SM_Receiver::ACTION_BUNDLE,
				'data'   => [
					'stage'   => $stage,
					'bundle'  => base64_encode( $body ),
					'gzipped' => $gzipped ? 1 : 0,
					'count'   => count( $packed ),
				],
			],
		];
	}

	/**
	 * Ask the destination to describe specific options in detail.
	 *
	 * @param string[] $names
	 *
	 * @return array|WP_Error
	 */
	public function describe_options( array $names ) {
		return $this->post(
			FW_SM_Receiver::ACTION_DESCRIBE,
			[ 'names' => (string) wp_json_encode( array_values( $names ) ) ]
		);
	}

	/**
	 * Ask the destination for its snapshot.
	 *
	 * @return array|WP_Error
	 */
	public function inspect() {
		return $this->post( FW_SM_Receiver::ACTION_INSPECT, [] );
	}

	/**
	 * Time a round trip carrying a payload of a given size.
	 *
	 * @param int  $bytes
	 * @param bool $probe_db Also exercise the destination's database.
	 *
	 * @return array|WP_Error [ 'total_ms', 'remote_ms', 'wire_ms', 'sent', 'db_ms' ]
	 */
	public function ping( $bytes, $probe_db = false ) {
		// Random bytes, so compression cannot flatter the result: real SQL
		// compresses well, but a run of zeroes would compress to nothing and
		// report a link far faster than it is.
		$blob = $bytes > 0 ? base64_encode( random_bytes( (int) ( $bytes * 0.75 ) ) ) : '';

		$started = microtime( true );

		$result = $this->post(
			FW_SM_Receiver::ACTION_PING,
			[
				'blob'     => $blob,
				'probe_db' => $probe_db ? 1 : 0,
			]
		);

		$total_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$remote_ms = (int) ( $result['remote_ms'] ?? 0 );

		return [
			'sent'      => strlen( $blob ),
			'received'  => (int) ( $result['received'] ?? 0 ),
			'total_ms'  => $total_ms,
			'remote_ms' => $remote_ms,
			'wire_ms'   => max( 0, $total_ms - $remote_ms ),
			'db_ms'     => (int) ( $result['db_ms'] ?? 0 ),
			'version'   => (string) ( $result['version'] ?? '' ),
		];
	}

	/**
	 * Ask which of these files the destination still needs.
	 *
	 * @param string  $stage
	 * @param array[] $files Each: [ 'path', 'size', 'mtime' ].
	 *
	 * @return array|WP_Error [ 'needed' => int[] ] — indices into $files.
	 */
	public function which_needed( $stage, array $files ) {
		return $this->post(
			FW_SM_Receiver::ACTION_HAVE,
			[
				'stage' => $stage,
				'files' => (string) wp_json_encode( $files ),
			]
		);
	}

	/**
	 * Ask the destination to put the migration live.
	 *
	 * @return array|WP_Error
	 */
	public function finalize() {
		return $this->post( FW_SM_Receiver::ACTION_FINALIZE, [] );
	}

	/**
	 * Tell the destination to throw the migration away.
	 *
	 * @return array|WP_Error
	 */
	public function abort() {
		return $this->post( FW_SM_Receiver::ACTION_ABORT, [] );
	}

	/**
	 * Sign and POST.
	 *
	 * @param string $action
	 * @param array  $data
	 *
	 * @return array|WP_Error
	 */
	private function post( $action, array $data ) {
		$data['action'] = $action;

		// Every request says which migration it belongs to, so the destination
		// can refuse one that is not the migration it is holding.
		if ( '' !== $this->migration_id && ! isset( $data['migration_id'] ) ) {
			$data['migration_id'] = $this->migration_id;
		}

		$body = FW_SM_Connection::prepare( $data, $this->key );

		$response = wp_remote_post(
			$this->url . '/wp-admin/admin-ajax.php',
			[
				'timeout'   => self::TIMEOUT,
				'body'      => $body,
				'sslverify' => apply_filters( 'fw_ext_site_migration_sslverify', true ),
				'headers'   => [ 'Expect' => '' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'fw_sm_transport',
				sprintf(
					/* translators: %s: underlying network error. */
					__( 'Could not reach the destination site: %s', 'fw' ),
					$response->get_error_message()
				),
				[ 'retryable' => true ]
			);
		}

		return $this->interpret(
			(int) wp_remote_retrieve_response_code( $response ),
			wp_remote_retrieve_body( $response ),
			$action
		);
	}

	/**
	 * Time the same payload sent as N concurrent requests.
	 *
	 * The number that matters is the comparison with ping(): if four streams
	 * move four times the bytes in about the same wall time, the link was never
	 * full and the transfer is limited by round-trip time rather than
	 * bandwidth. If the wall time scales with the total instead, the link
	 * genuinely is the ceiling and concurrency will not help.
	 *
	 * @param int $bytes   Size of each request.
	 * @param int $streams How many to send at once.
	 *
	 * @return array|WP_Error
	 */
	public function ping_parallel( $bytes, $streams ) {
		$calls = [];

		for ( $i = 0; $i < $streams; $i++ ) {
			// Each stream gets its own random payload. Identical bodies could
			// be collapsed by a proxy somewhere in between and report a link
			// far faster than it is.
			$calls[] = [
				'action' => FW_SM_Receiver::ACTION_PING,
				'data'   => [
					'blob'     => base64_encode( random_bytes( (int) ( $bytes * 0.75 ) ) ),
					'probe_db' => 0,
				],
			];
		}

		$sent = 0;

		foreach ( $calls as $call ) {
			$sent += strlen( $call['data']['blob'] );
		}

		$started = microtime( true );
		$results = $this->post_many( $calls );
		$total   = (int) round( ( microtime( true ) - $started ) * 1000 );

		foreach ( $results as $result ) {
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return [
			'streams'  => $streams,
			'sent'     => $sent,
			'total_ms' => $total,
		];
	}

	/**
	 * Send several requests at once and return their results in order.
	 *
	 * The reason this exists is bandwidth-delay product. A single TCP
	 * connection can only have as much data unacknowledged as its window
	 * allows, so its ceiling is roughly window ÷ round-trip-time — a limit that
	 * has nothing to do with how much bandwidth the link actually has. Over a
	 * long round trip that ceiling is reached well before the link is full, and
	 * the connection sits idle waiting for acknowledgements. Sending one
	 * request at a time cannot get past it no matter how large the requests
	 * are; several connections at once can, because each carries its own
	 * window.
	 *
	 * Falls back to sending them one after another when the concurrent
	 * transport is unavailable, so callers can use this unconditionally.
	 *
	 * @param array $calls Each [ 'action' => string, 'data' => array ].
	 *
	 * @return array Results in the same order — array or WP_Error per call.
	 */
	public function post_many( array $calls ) {
		if ( empty( $calls ) ) {
			return [];
		}

		$requests_class = self::requests_class();

		if ( null === $requests_class || count( $calls ) < 2 ) {
			$out = [];

			foreach ( $calls as $call ) {
				$out[] = $this->post( $call['action'], $call['data'] );
			}

			return $out;
		}

		$url      = $this->url . '/wp-admin/admin-ajax.php';
		$requests = [];

		foreach ( $calls as $i => $call ) {
			$data           = $call['data'];
			$data['action'] = $call['action'];

			if ( '' !== $this->migration_id && ! isset( $data['migration_id'] ) ) {
				$data['migration_id'] = $this->migration_id;
			}

			$requests[ $i ] = [
				'url'     => $url,
				'type'    => 'POST',
				'headers' => [ 'Expect' => '' ],
				'data'    => FW_SM_Connection::prepare( $data, $this->key ),
				'options' => [
					'timeout'          => self::TIMEOUT,
					'connect_timeout'  => 30,
					'verify'           => apply_filters( 'fw_ext_site_migration_sslverify', true ),
				],
			];
		}

		try {
			$responses = $requests_class::request_multiple( $requests );
		} catch ( Exception $e ) {
			// Any failure of the concurrent transport itself is a reason to
			// fall back, not to fail the migration: the sequential path always
			// works, it is only slower.
			$out = [];

			foreach ( $calls as $call ) {
				$out[] = $this->post( $call['action'], $call['data'] );
			}

			return $out;
		}

		$out = [];

		foreach ( $calls as $i => $call ) {
			$response = $responses[ $i ] ?? null;

			if ( ! is_object( $response ) || ! isset( $response->status_code ) ) {
				$message = is_object( $response ) && method_exists( $response, 'getMessage' )
					? $response->getMessage()
					: __( 'no response', 'fw' );

				$out[] = new WP_Error(
					'fw_sm_transport',
					sprintf(
						/* translators: %s: underlying network error. */
						__( 'Could not reach the destination site: %s', 'fw' ),
						$message
					),
					[ 'retryable' => true ]
				);

				continue;
			}

			$out[] = $this->interpret(
				(int) $response->status_code,
				(string) $response->body,
				$call['action']
			);
		}

		return $out;
	}

	/**
	 * The Requests class, whatever it is called in this WordPress.
	 *
	 * WordPress 6.2 moved Requests into a namespace and left the old name as a
	 * deprecated alias, so both spellings are in the wild.
	 *
	 * @return string|null Class name, or null if concurrent requests are not
	 *                     available and the caller should send them one by one.
	 */
	private static function requests_class() {
		foreach ( [ 'WpOrg\Requests\Requests', 'Requests' ] as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'request_multiple' ) ) {
				return $class;
			}
		}

		return null;
	}

	/**
	 * Turn a raw HTTP response into a result or a diagnosable error.
	 *
	 * Separate from the request itself so that a response means the same thing
	 * however it arrived — one at a time, or as one of several in flight.
	 *
	 * @param int    $code
	 * @param string $raw
	 * @param string $action
	 *
	 * @return array|WP_Error
	 */
	private function interpret( $code, $raw, $action ) {
		$decoded = json_decode( $raw, true );

		// WordPress answers an unregistered AJAX action with exactly this:
		//   if ( ! has_action( "wp_ajax_nopriv_{$action}" ) ) { wp_die( '0', 400 ); }
		// so a 400 whose body is '0' is not a malformed request — it is the
		// destination saying it has never heard of this endpoint, which means
		// it is running an older build. Worth saying outright, because
		// "something unexpected" sends people looking at firewalls.
		if ( 400 === $code && '0' === trim( $raw ) ) {
			return new WP_Error(
				'fw_sm_unknown_action',
				sprintf(
					/* translators: %s: the action name. */
					__( 'The destination does not recognise this request (%s). It is running an older version of the Site Migration extension — update it there so both sites match.', 'fw' ),
					$action
				),
				[ 'retryable' => false ]
			);
		}

		if ( ! is_array( $decoded ) || ! array_key_exists( 'success', $decoded ) ) {
			// Not our JSON. Almost always a PHP fatal, a WAF block page, or a
			// login wall in front of the destination — all worth showing the
			// user a hint about rather than "unexpected response".
			return new WP_Error(
				'fw_sm_bad_response',
				sprintf(
					/* translators: 1: HTTP status code, 2: first part of the response body. */
					__( 'The destination returned something unexpected (HTTP %1$d). Check that the site is reachable and not behind a password or firewall. Response began: %2$s', 'fw' ),
					$code,
					esc_html( substr( trim( wp_strip_all_tags( $raw ) ), 0, 200 ) )
				),
				[ 'retryable' => $this->is_retryable_code( $code ) ]
			);
		}

		if ( empty( $decoded['success'] ) ) {
			$message = $decoded['data']['message'] ?? __( 'The destination refused the request.', 'fw' );

			// A fatal on the destination will not fix itself on a retry, and
			// retrying it three times just delays the report by a minute.
			$fatal = ! empty( $decoded['data']['fatal'] );

			return new WP_Error(
				'fw_sm_remote_error',
				$message,
				// A refusal is a decision, not a hiccup — except for 5xx.
				[ 'retryable' => ! $fatal && $this->is_retryable_code( $code ) ]
			);
		}

		return (array) ( $decoded['data'] ?? [] );
	}

	/**
	 * Is this status code worth trying again?
	 *
	 * @param int $code
	 *
	 * @return bool
	 */
	private function is_retryable_code( $code ) {
		// 410 means the destination has genuinely let go of this migration.
		// No number of retries brings it back, and retrying only delays the
		// report — which is exactly what happened when a stale abort deleted a
		// live session and the source spent four attempts rediscovering it.
		if ( 410 === $code ) {
			return false;
		}

		// 409 is used for "we disagree about state" — a checksum mismatch or a
		// resync. Both are worth another attempt, from a corrected position.
		if ( 409 === $code ) {
			return true;
		}

		// 5xx is the server having a bad moment; 408/429 are explicit
		// "try again" signals. 4xx otherwise means we asked for something wrong,
		// and asking again identically will get the same answer.
		return $code >= 500 || in_array( $code, [ 408, 429 ], true );
	}

	/**
	 * @return string
	 */
	public function get_url() {
		return $this->url;
	}
}
