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

	const DATABASE   = 'database';
	const UPLOADS    = 'uploads';
	const THEMES     = 'themes';
	const PLUGINS    = 'plugins';
	const MUPLUGINS  = 'muplugins';
	const OTHER      = 'other';
	const FINALIZE   = 'finalize';

	/**
	 * The stages a migration runs, in order.
	 *
	 * There is no selection here, and that is the product decision rather than
	 * an omission: a migration moves the whole site. Choosing pieces is what the
	 * Backups extension is for, and offering it in both places would leave a
	 * user with two half-answers instead of one whole one.
	 *
	 * FINALIZE is appended by the runner.
	 *
	 * @return string[]
	 */
	public static function all() {
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
	 * Human label for a stage, for the admin page and log lines.
	 *
	 * @param string $stage
	 *
	 * @return string
	 */
	public static function label( $stage ) {
		$labels = [
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
	 * @param int    $blog_id Which site's uploads, on a network. 0/1 = the
	 *                        current site.
	 *
	 * @return string|null Normalized, no trailing slash.
	 */
	public static function source_dir( $stage, $blog_id = 0 ) {
		switch ( $stage ) {
			case self::UPLOADS:
				// On a network each site has its own uploads root, so the blog
				// being migrated decides which one this is.
				if ( $blog_id > 1 && is_multisite() ) {
					$path = FW_SM_Multisite::subsite_uploads_dir( $blog_id );
					break;
				}

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
	 * Where a stage's files are written on the DESTINATION.
	 *
	 * Separate from source_dir() on purpose. The source resolves its own paths;
	 * the destination resolves its own, which is what lets a site move between
	 * installs whose wp-content sits in different places. It also creates the
	 * directory when it is legitimately missing (a destination may have no
	 * mu-plugins folder yet), which source_dir() must never do.
	 *
	 * Returns null for anything that is not a file stage — the receiver treats
	 * that as a refusal, so an unknown stage name cannot be used to steer a
	 * write somewhere unintended.
	 *
	 * @param string $stage
	 *
	 * @return string|null Normalized, no trailing slash.
	 */
	public static function destination_dir( $stage, $blog_id = 0 ) {
		if ( ! self::is_file_stage( $stage ) ) {
			return null;
		}

		// Files arriving for a specific site on this network land in that
		// site's own uploads directory, which may not exist yet.
		if ( self::UPLOADS === $stage && $blog_id > 1 && is_multisite() ) {
			$dir = FW_SM_Multisite::subsite_uploads_dir( $blog_id );

			if ( null !== $dir ) {
				return $dir;
			}

			$path = wp_normalize_path( WP_CONTENT_DIR . '/uploads/sites/' . (int) $blog_id );

			return wp_mkdir_p( $path ) ? untrailingslashit( $path ) : null;
		}

		$existing = self::source_dir( $stage );

		if ( null !== $existing ) {
			return $existing;
		}

		// Only mu-plugins is legitimately absent on a fresh install.
		if ( self::MUPLUGINS === $stage ) {
			$path = defined( 'WPMU_PLUGIN_DIR' )
				? WPMU_PLUGIN_DIR
				: WP_CONTENT_DIR . '/mu-plugins';

			return wp_mkdir_p( $path )
				? untrailingslashit( wp_normalize_path( $path ) )
				: null;
		}

		return null;
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
