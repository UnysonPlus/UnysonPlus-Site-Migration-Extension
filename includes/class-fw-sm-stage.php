<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The catalogue of migration stages.
 *
 * A migration IS an ordered array of these names, stored in the migration state.
 * Everything else — which jobs get enqueued, how progress is totalled, what the
 * admin page draws — keys off that array, so adding a stage is a matter of
 * adding a constant here and teaching the runner to initialize and process it.
 *
 * Each stage carries its own { processed_bytes, target_bytes } pair. Denominating
 * every stage in the same unit is what lets one progress bar honestly cover work
 * as different as a 12 MB posts table and a 400 MB uploads folder.
 */
class FW_SM_Stage {

	/**
	 * Import only: unpack the archive into a working directory before anything
	 * is read from it. Extraction is its own stage because a large archive
	 * cannot be unzipped inside one request any more than it could be built in
	 * one, and because nothing should touch the destination until the archive
	 * has proved itself readable.
	 */
	const EXTRACT = 'extract';

	const DATABASE   = 'database';
	const UPLOADS    = 'uploads';
	const THEMES     = 'themes';
	const PLUGINS    = 'plugins';
	const MUPLUGINS  = 'muplugins';
	const OTHER      = 'other';
	const FINALIZE   = 'finalize';

	/**
	 * Every stage a user may select, in the order they must run.
	 *
	 * FINALIZE is deliberately absent — it is appended by the runner and is not
	 * something a user opts out of.
	 *
	 * @return string[]
	 */
	public static function selectable() {
		return [
			self::DATABASE,
			self::UPLOADS,
			self::THEMES,
			self::PLUGINS,
			self::MUPLUGINS,
			self::OTHER,
		];
	}

	/**
	 * The stages an import runs, in order.
	 *
	 * Extraction first, then the database into staging tables, then the files,
	 * then the swap in finalize. The database goes before the files so that a
	 * dump which fails to load costs nothing but time — no file on the
	 * destination has been overwritten at that point.
	 *
	 * @param string[] $archive_stages Stages the archive actually contains.
	 *
	 * @return string[]
	 */
	public static function import_order( array $archive_stages ) {
		$order = [ self::EXTRACT ];

		if ( in_array( self::DATABASE, $archive_stages, true ) ) {
			$order[] = self::DATABASE;
		}

		foreach ( [ self::UPLOADS, self::THEMES, self::PLUGINS, self::MUPLUGINS, self::OTHER ] as $stage ) {
			if ( in_array( $stage, $archive_stages, true ) ) {
				$order[] = $stage;
			}
		}

		return $order;
	}

	/**
	 * Human label for a stage, for the admin page and log lines.
	 *
	 * @param string $stage
	 *
	 * @return string
	 */
	public static function label( $stage ) {
		$labels = [
			self::EXTRACT   => __( 'Unpacking the archive', 'fw' ),
			self::DATABASE  => __( 'Database', 'fw' ),
			self::UPLOADS   => __( 'Media uploads', 'fw' ),
			self::THEMES    => __( 'Themes', 'fw' ),
			self::PLUGINS   => __( 'Plugins', 'fw' ),
			self::MUPLUGINS => __( 'Must-use plugins', 'fw' ),
			self::OTHER     => __( 'Other wp-content files', 'fw' ),
			self::FINALIZE  => __( 'Finalizing', 'fw' ),
		];

		return $labels[ $stage ] ?? $stage;
	}

	/**
	 * Is this stage one that walks the filesystem?
	 *
	 * The runner uses this to decide between the file scanner and the database
	 * exporter, rather than switching on each name in three separate places.
	 *
	 * @param string $stage
	 *
	 * @return bool
	 */
	public static function is_file_stage( $stage ) {
		return in_array(
			$stage,
			[ self::UPLOADS, self::THEMES, self::PLUGINS, self::MUPLUGINS, self::OTHER ],
			true
		);
	}

	/**
	 * Absolute source directory a file stage reads from.
	 *
	 * Returns null for stages that are not file stages, and for mu-plugins when
	 * the directory does not exist (a perfectly normal install may have none).
	 *
	 * @param string $stage
	 *
	 * @return string|null Normalized, no trailing slash.
	 */
	public static function source_dir( $stage ) {
		switch ( $stage ) {
			case self::UPLOADS:
				$up = wp_upload_dir();
				$path = $up['basedir'] ?? '';
				break;
			case self::THEMES:
				$path = get_theme_root();
				break;
			case self::PLUGINS:
				$path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
				break;
			case self::MUPLUGINS:
				$path = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
				break;
			case self::OTHER:
				$path = WP_CONTENT_DIR;
				break;
			default:
				return null;
		}

		if ( ! $path || ! is_dir( $path ) ) {
			return null;
		}

		return untrailingslashit( wp_normalize_path( $path ) );
	}

	/**
	 * Path prefix a stage's files occupy inside the archive.
	 *
	 * Keeping this separate from the source directory is what lets an archive be
	 * restored onto an install whose wp-content lives somewhere else entirely.
	 *
	 * @param string $stage
	 *
	 * @return string
	 */
	public static function archive_dir( $stage ) {
		$dirs = [
			self::UPLOADS   => 'files/uploads',
			self::THEMES    => 'files/themes',
			self::PLUGINS   => 'files/plugins',
			self::MUPLUGINS => 'files/mu-plugins',
			self::OTHER     => 'files/other',
		];

		return $dirs[ $stage ] ?? 'files/' . $stage;
	}

	/**
	 * Directories inside wp-content that the OTHER stage must not descend into,
	 * because another stage already owns them.
	 *
	 * @return string[] Directory names, relative to wp-content.
	 */
	public static function other_stage_owned_dirs() {
		$owned = [ 'themes', 'plugins', 'mu-plugins', 'upgrade', 'cache' ];

		$up = wp_upload_dir();
		if ( ! empty( $up['basedir'] ) ) {
			$rel = ltrim(
				str_replace(
					wp_normalize_path( WP_CONTENT_DIR ),
					'',
					wp_normalize_path( $up['basedir'] )
				),
				'/'
			);
			if ( $rel !== '' && strpos( $rel, '/' ) === false ) {
				$owned[] = $rel;
			}
		}

		return $owned;
	}
}
