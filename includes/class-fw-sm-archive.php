<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The migration archive — a plain .zip with a manifest at its root.
 *
 * Layout:
 *
 *     unysonplus-migration.json    manifest: source URL, paths, prefix, stages
 *     database.sql                 the dump, already URL-rewritten
 *     files/uploads/…              wp-content/uploads
 *     files/themes/…               wp-content/themes
 *     files/plugins/…              wp-content/plugins
 *     files/mu-plugins/…           wp-content/mu-plugins
 *     files/other/…                everything else in wp-content
 *
 * The archive is opened and closed around each batch rather than held open
 * across the whole migration. A ZipArchive handle cannot survive the end of a
 * request, and holding one open while a slice does other work risks losing the
 * central directory if the process is killed — reopening per batch costs a
 * little and means a killed request loses at most that batch.
 */
class FW_SM_Archive {

	const MANIFEST = 'unysonplus-migration.json';

	/**
	 * Files added per open/close cycle.
	 */
	const BATCH_SIZE = 400;

	/** @var string Absolute path to the .zip. */
	private $path;

	/**
	 * @param string $path
	 */
	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * @return string
	 */
	public function get_path() {
		return $this->path;
	}

	/**
	 * Where archives live: uploads/unysonplus/migration.
	 *
	 * Routed through the shared uploads helper so every plugin-created folder
	 * stays under the one parent, per the uploads convention.
	 *
	 * @return array{path:string,url:string}|WP_Error
	 */
	public static function storage_dir() {
		if ( ! function_exists( 'fw_upw_uploads_dir' ) ) {
			return new WP_Error(
				'fw_sm_uploads_helper',
				__( 'The shared uploads helper is unavailable. Update the UnysonPlus core.', 'fw' )
			);
		}

		$dir = fw_upw_uploads_dir( 'migration' );

		if ( ! wp_mkdir_p( $dir['path'] ) ) {
			return new WP_Error(
				'fw_sm_uploads_mkdir',
				sprintf(
					/* translators: %s: directory path. */
					__( 'Could not create the migration folder at %s.', 'fw' ),
					$dir['path']
				)
			);
		}

		self::protect_dir( $dir['path'] );

		return $dir;
	}

	/**
	 * Make the archive folder non-browsable and non-servable.
	 *
	 * An archive contains the entire database, wp-config values inside it, and
	 * every uploaded file. Leaving it fetchable over HTTP by anyone who guesses
	 * the name would be a full site disclosure, so the folder gets an index stub
	 * and a deny rule. On nginx neither file does anything, which is why the
	 * filename also carries 24 random characters.
	 *
	 * @param string $path
	 *
	 * @return void
	 */
	private static function protect_dir( $path ) {
		$index = trailingslashit( $path ) . 'index.html';

		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$htaccess = trailingslashit( $path ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Migration archives contain a full copy of this site.\n"
			         . "<IfModule mod_authz_core.c>\n"
			         . "\tRequire all denied\n"
			         . "</IfModule>\n"
			         . "<IfModule !mod_authz_core.c>\n"
			         . "\tOrder deny,allow\n"
			         . "\tDeny from all\n"
			         . "</IfModule>\n";

			@file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Build a hard-to-guess archive filename.
	 *
	 * @param string $site_url
	 *
	 * @return string
	 */
	public static function generate_filename( $site_url ) {
		$host = wp_parse_url( $site_url, PHP_URL_HOST );
		$slug = sanitize_title( $host ? $host : 'site' );

		return sprintf(
			'%s-%s-%s.zip',
			$slug,
			gmdate( 'Ymd-His' ),
			wp_generate_password( 24, false )
		);
	}

	/**
	 * Create the archive and write its manifest.
	 *
	 * @param array $manifest
	 *
	 * @return true|WP_Error
	 */
	public function create( array $manifest ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'fw_sm_no_zip',
				__( 'The PHP zip extension is not available on this server, so an archive cannot be created. Ask your host to enable ext-zip.', 'fw' )
			);
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error(
				'fw_sm_zip_create',
				sprintf(
					/* translators: %s: file path. */
					__( 'Could not create the archive at %s.', 'fw' ),
					$this->path
				)
			);
		}

		$zip->addFromString( self::MANIFEST, (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		$zip->close();

		return true;
	}

	/**
	 * Add a batch of files.
	 *
	 * @param array[] $files Each: [ 'absolute' => string, 'archive' => string ].
	 *
	 * @return array|WP_Error [ 'added', 'bytes', 'skipped' ]
	 */
	public function add_batch( array $files ) {
		if ( empty( $files ) ) {
			return [ 'added' => 0, 'bytes' => 0, 'skipped' => 0 ];
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $this->path ) ) {
			return new WP_Error(
				'fw_sm_zip_open',
				sprintf(
					/* translators: %s: file path. */
					__( 'Could not open the archive at %s.', 'fw' ),
					$this->path
				)
			);
		}

		$added   = 0;
		$bytes   = 0;
		$skipped = 0;

		foreach ( $files as $file ) {
			$absolute = $file['absolute'] ?? '';
			$archive  = $file['archive'] ?? '';

			if ( '' === $absolute || '' === $archive || ! is_readable( $absolute ) ) {
				// A file that vanished between the scan and now is normal on a
				// live site — a cache purge, a plugin update. Skip it rather
				// than ending a migration that is otherwise fine.
				$skipped++;
				continue;
			}

			if ( $zip->addFile( $absolute, $archive ) ) {
				$added++;
				$bytes += (int) @filesize( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				$skipped++;
			}
		}

		if ( true !== $zip->close() ) {
			return new WP_Error(
				'fw_sm_zip_close',
				__( 'Could not finish writing to the archive. The disk may be full.', 'fw' )
			);
		}

		return [ 'added' => $added, 'bytes' => $bytes, 'skipped' => $skipped ];
	}

	/**
	 * Add one already-built file, such as the database dump.
	 *
	 * @param string $absolute
	 * @param string $archive_path
	 *
	 * @return true|WP_Error
	 */
	public function add_file( $absolute, $archive_path ) {
		$result = $this->add_batch( [ [ 'absolute' => $absolute, 'archive' => $archive_path ] ] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $result['added'] < 1 ) {
			return new WP_Error(
				'fw_sm_zip_add',
				sprintf(
					/* translators: %s: file path. */
					__( 'Could not add %s to the archive.', 'fw' ),
					$archive_path
				)
			);
		}

		return true;
	}

	/**
	 * Read the manifest out of an existing archive.
	 *
	 * @return array|WP_Error
	 */
	public function read_manifest() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'fw_sm_no_zip',
				__( 'The PHP zip extension is not available on this server.', 'fw' )
			);
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $this->path ) ) {
			return new WP_Error(
				'fw_sm_zip_open',
				__( 'Could not open the archive. It may be incomplete or corrupt.', 'fw' )
			);
		}

		$json = $zip->getFromName( self::MANIFEST );

		$zip->close();

		if ( false === $json ) {
			return new WP_Error(
				'fw_sm_no_manifest',
				__( 'This archive has no migration manifest, so it was not created by this extension.', 'fw' )
			);
		}

		$manifest = json_decode( $json, true );

		if ( ! is_array( $manifest ) ) {
			return new WP_Error(
				'fw_sm_bad_manifest',
				__( 'The archive manifest could not be read.', 'fw' )
			);
		}

		return $manifest;
	}

	/**
	 * How many entries the archive holds, and their uncompressed size.
	 *
	 * @return array|WP_Error [ 'entries' => int, 'bytes' => int ]
	 */
	public function measure() {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $this->path ) ) {
			return new WP_Error(
				'fw_sm_zip_open',
				__( 'Could not open the archive. It may be incomplete or corrupt.', 'fw' )
			);
		}

		$entries = $zip->numFiles;
		$bytes   = 0;

		for ( $i = 0; $i < $entries; $i++ ) {
			$stat = $zip->statIndex( $i );

			if ( is_array( $stat ) ) {
				$bytes += (int) ( $stat['size'] ?? 0 );
			}
		}

		$zip->close();

		return [ 'entries' => $entries, 'bytes' => $bytes ];
	}

	/**
	 * Extract a slice of entries into a working directory.
	 *
	 * Every entry name is validated before it is written. A zip is an untrusted
	 * container — an entry called `../../wp-config.php` is the classic "zip slip"
	 * and would let a crafted archive overwrite files anywhere the web user can
	 * write. Absolute paths and any path that escapes the destination after
	 * normalization are refused outright rather than sanitized, because a name
	 * that needed sanitizing was not a name we should trust.
	 *
	 * @param string $destination Working directory.
	 * @param int    $offset      Entry index to resume from.
	 * @param int    $limit       Entries to extract this slice.
	 *
	 * @return array|WP_Error [ 'offset', 'bytes', 'skipped', 'done' ]
	 */
	public function extract_slice( $destination, $offset = 0, $limit = 300 ) {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $this->path ) ) {
			return new WP_Error(
				'fw_sm_zip_open',
				__( 'Could not open the archive. It may be incomplete or corrupt.', 'fw' )
			);
		}

		if ( ! wp_mkdir_p( $destination ) ) {
			$zip->close();

			return new WP_Error(
				'fw_sm_extract_mkdir',
				sprintf(
					/* translators: %s: directory path. */
					__( 'Could not create the working folder at %s.', 'fw' ),
					$destination
				)
			);
		}

		$base    = wp_normalize_path( untrailingslashit( realpath( $destination ) ) );
		$total   = $zip->numFiles;
		$end     = min( $total, $offset + $limit );
		$bytes   = 0;
		$skipped = 0;

		for ( $i = $offset; $i < $end; $i++ ) {
			$stat = $zip->statIndex( $i );

			if ( ! is_array( $stat ) || empty( $stat['name'] ) ) {
				$skipped++;
				continue;
			}

			$name = $stat['name'];

			// Reject anything that is not a plain relative path.
			if ( '' === $name
			     || '/' === $name[0]
			     || preg_match( '#^[a-zA-Z]:#', $name )
			     || false !== strpos( $name, '../' )
			     || false !== strpos( $name, '..\\' )
			) {
				$skipped++;
				continue;
			}

			$target = wp_normalize_path( trailingslashit( $base ) . $name );

			// Belt and braces: even after the checks above, the resolved path
			// must still sit inside the working directory.
			if ( 0 !== strpos( $target, trailingslashit( $base ) ) ) {
				$skipped++;
				continue;
			}

			// Directory entries carry a trailing slash and no content.
			if ( '/' === substr( $name, -1 ) ) {
				wp_mkdir_p( $target );
				continue;
			}

			if ( ! wp_mkdir_p( dirname( $target ) ) ) {
				$skipped++;
				continue;
			}

			$stream = $zip->getStream( $name );

			if ( ! $stream ) {
				$skipped++;
				continue;
			}

			$handle = @fopen( $target, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

			if ( ! $handle ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$skipped++;
				continue;
			}

			// Streamed rather than read whole: an archive can contain a file
			// larger than the memory limit, and getFromIndex() would fatal on it.
			$written = stream_copy_to_stream( $stream, $handle );

			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			$bytes += (int) $written;
		}

		$zip->close();

		return [
			'offset'  => $end,
			'bytes'   => $bytes,
			'skipped' => $skipped,
			'done'    => $end >= $total,
		];
	}

	/**
	 * @return int Archive size in bytes, 0 when it does not exist yet.
	 */
	public function size() {
		return file_exists( $this->path ) ? (int) filesize( $this->path ) : 0;
	}

	/**
	 * Delete the archive.
	 *
	 * @return void
	 */
	public function delete() {
		if ( file_exists( $this->path ) ) {
			@unlink( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
