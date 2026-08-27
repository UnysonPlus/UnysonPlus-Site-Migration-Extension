<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Resumable recursive directory scan.
 *
 * Scanning a 50,000-file uploads directory does not fit in one PHP request on
 * most hosting, so the scan carries its own state: a stack of directories still
 * to visit, persisted between slices. Each slice pops directories off the stack,
 * enqueues the files it finds, and hands the remaining stack back. A scan that
 * runs out of time simply resumes with the stack it left behind.
 *
 * Every file becomes a queue job carrying its size, which is what gives the
 * progress bar its denominator before a single byte has been copied.
 */
class FW_SM_File_Scanner {

	/**
	 * Files to enqueue before yielding back to the runner. Keeps any one slice
	 * bounded regardless of how pathological the directory tree is.
	 */
	const FILES_PER_SLICE = 3000;

	/**
	 * Never descend deeper than this. A symlink loop that survives the realpath
	 * check would otherwise spin until the request dies.
	 */
	const MAX_DEPTH = 24;

	/** @var string Stage being scanned. */
	private $stage;

	/** @var string Absolute root directory, normalized, no trailing slash. */
	private $root;

	/** @var string[] Exclusion patterns, matched against the path relative to root. */
	private $excludes;

	/**
	 * @param string   $stage
	 * @param string   $root
	 * @param string[] $excludes
	 */
	public function __construct( $stage, $root, array $excludes = [] ) {
		$this->stage    = $stage;
		$this->root     = untrailingslashit( wp_normalize_path( $root ) );
		$this->excludes = $excludes;
	}

	/**
	 * The default exclusion list.
	 *
	 * Two kinds of thing are excluded. First, anything regenerable — caches,
	 * build output, dependency directories — which would otherwise dominate an
	 * archive with content the destination can rebuild for itself. Second,
	 * anything host-specific: caching drop-ins and object caches that are
	 * correct for the source server and actively wrong on the destination.
	 *
	 * @param string $stage
	 *
	 * @return string[]
	 */
	public static function default_excludes( $stage ) {
		$common = [
			'*/node_modules/*',
			'*/.git/*',
			'*/.svn/*',
			'*/.DS_Store',
			'*/Thumbs.db',
			'*.log',
			'*/.idea/*',
			'*/.vscode/*',
		];

		// Built per stage rather than as one map, so resolving the OTHER stage's
		// owned directories — which reads WP_CONTENT_DIR and the uploads path —
		// does not happen every time some other stage asks for its excludes.
		switch ( $stage ) {
			case FW_SM_Stage::UPLOADS:
				$specific = [
					// On a network, blog 1's uploads root physically CONTAINS
					// every other site's media under sites/<id>/. Scanning it
					// without this silently drags the whole network into a
					// single-site migration. Harmless on a single site: the
					// directory simply does not exist.
					'*/sites/*',
					// Regenerable image sizes are NOT excluded — regenerating
					// them on the destination is slow and needs a plugin, so
					// they travel.
					'*/backup*/*',
					'*/cache/*',
				];
				break;

			case FW_SM_Stage::PLUGINS:
				$specific = [
					// Caching plugins whose configuration is bound to the source host.
					'*/w3-total-cache/*',
					'*/wp-super-cache/*',
					'*/wp-file-cache/*',
					'*/hyper-cache/*',
					'*/unysonplus/migration/*',
				];
				break;

			case FW_SM_Stage::MUPLUGINS:
				$specific = [
					// Host-injected drop-ins the destination provides itself.
					'*/object-cache.php',
					'*/advanced-cache.php',
					'*/db.php',
				];
				break;

			case FW_SM_Stage::OTHER:
				// Directories another stage already owns.
				//
				// Without these the OTHER stage walks straight back into themes
				// and plugins and sends every one of their files a SECOND time.
				// It is invisible in the result — the destination receives the
				// same bytes twice — but it doubles the work, and on a real site
				// that was 111 MB of pure duplication.
				//
				// Anchored at the stage root rather than '*/themes/*', so a
				// plugin containing a 'themes' folder of its own is not dropped.
				$specific = array_merge(
					array_map(
						static function ( $dir ) {
							return '/' . $dir . '/*';
						},
						FW_SM_Stage::other_stage_owned_dirs()
					),
					[
						'*/w3tc-config/*',
						'*/advanced-cache.php',
						'*/object-cache.php',
						'*/db.php',
						'*/db-error.php',
						'*/wp-cache-config.php',
					]
				);
				break;

			default:
				$specific = [];
		}

		$excludes = array_merge( $common, $specific );

		/**
		 * Filters the glob patterns excluded from a migration file stage.
		 *
		 * Patterns are matched with fnmatch() against the path relative to the
		 * stage root, with a leading slash.
		 *
		 * @param string[] $excludes
		 * @param string   $stage
		 */
		return apply_filters( 'fw_ext_site_migration_excludes', $excludes, $stage );
	}

	/**
	 * Run one slice of the scan.
	 *
	 * @param string[] $stack       Directories still to visit, relative to root.
	 *                              Pass [ '' ] to start at the root itself.
	 * @param int      $modified_after Only include files modified after this
	 *                                 timestamp. 0 includes everything.
	 *
	 * @return array [ 'stack', 'files_found', 'bytes_found', 'done' ]
	 */
	public function scan_slice( array $stack, $modified_after = 0 ) {
		$files       = [];
		$bytes_found = 0;

		while ( ! empty( $stack ) && count( $files ) < self::FILES_PER_SLICE ) {
			$relative_dir = array_shift( $stack );
			$absolute_dir = '' === $relative_dir
				? $this->root
				: $this->root . '/' . ltrim( $relative_dir, '/' );

			if ( substr_count( trim( $relative_dir, '/' ), '/' ) > self::MAX_DEPTH ) {
				continue;
			}

			$handle = @opendir( $absolute_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

			if ( ! $handle ) {
				// An unreadable directory is a fact of life on shared hosting —
				// note it and carry on rather than failing the whole migration.
				continue;
			}

			while ( false !== ( $entry = readdir( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$absolute_path = $absolute_dir . '/' . $entry;
				$relative_path = ltrim( $relative_dir . '/' . $entry, '/' );

				if ( $this->is_excluded( '/' . $relative_path ) ) {
					continue;
				}

				if ( is_link( $absolute_path ) ) {
					// Symlinks are not followed. Following them risks escaping the
					// stage root entirely and archiving half the server.
					continue;
				}

				if ( is_dir( $absolute_path ) ) {
					$stack[] = $relative_path;
					continue;
				}

				if ( ! is_file( $absolute_path ) || ! is_readable( $absolute_path ) ) {
					continue;
				}

				$size     = (int) @filesize( $absolute_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$modified = (int) @filemtime( $absolute_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( $modified_after > 0 && $modified <= $modified_after ) {
					continue;
				}

				$files[] = [
					'payload' => [
						'path'     => $relative_path,
						'absolute' => $absolute_path,
					],
					'bytes'   => $size,
				];

				$bytes_found += $size;
			}

			closedir( $handle );
		}

		if ( ! empty( $files ) ) {
			FW_SM_Queue::push_many( $this->stage, $files );
		}

		return [
			'stack'       => array_values( $stack ),
			'files_found' => count( $files ),
			'bytes_found' => $bytes_found,
			'done'        => empty( $stack ),
		];
	}

	/**
	 * Does this path match any exclusion pattern?
	 *
	 * @param string $relative_path Leading-slash path relative to the stage root.
	 *
	 * @return bool
	 */
	private function is_excluded( $relative_path ) {
		foreach ( $this->excludes as $pattern ) {
			// fnmatch() is unavailable on some Windows PHP builds, so fall back
			// to a translated regex rather than silently excluding nothing.
			if ( function_exists( 'fnmatch' ) ) {
				if ( fnmatch( $pattern, $relative_path, FNM_CASEFOLD ) ) {
					return true;
				}
			} elseif ( preg_match( self::pattern_to_regex( $pattern ), $relative_path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Translate a glob pattern into a case-insensitive regex.
	 *
	 * @param string $pattern
	 *
	 * @return string
	 */
	private static function pattern_to_regex( $pattern ) {
		$quoted = preg_quote( $pattern, '#' );

		$quoted = str_replace( [ '\*', '\?' ], [ '.*', '.' ], $quoted );

		return '#^' . $quoted . '$#i';
	}
}
