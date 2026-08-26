<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site Migration.
 *
 * Packages a site into a portable archive and restores one onto another install.
 * The interesting work lives in includes/; this class is the wiring: the admin
 * screen, the control endpoints the screen talks to, and the lifecycle hooks.
 */
class FW_Extension_Site_Migration extends FW_Extension {

	const PARENT_SLUG = 'unysonplus';
	const PAGE_SLUG   = 'fw-site-migration';

	/**
	 * Capability required on a single-site install.
	 *
	 * Do not read this directly — call capability(), which elevates the
	 * requirement on multisite. See the note there.
	 */
	const CAPABILITY = 'export';

	const ACTION_START    = 'fw_sm_start';
	const ACTION_CANCEL   = 'fw_sm_cancel';
	const ACTION_DISMISS  = 'fw_sm_dismiss';
	const ACTION_DOWNLOAD = 'fw_sm_download';
	const ACTION_STATUS   = 'fw_sm_status';
	const ACTION_IMPORT   = 'fw_sm_import';

	/** @var string|null Hook suffix from add_submenu_page(). */
	private $page_hook = null;

	/** @var FW_SM_Runner|null */
	private $runner = null;

	/**
	 * @internal
	 */
	public function _init() {
		$this->load_engine();

		$this->runner = new FW_SM_Runner();
		$this->runner->register();

		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, '_action_admin_menu' ], 30 );
			add_filter( 'fw_unysonplus_admin_submenu_order', [ $this, '_filter_submenu_order' ] );

			add_action( 'admin_post_' . self::ACTION_START, [ $this, 'handle_start' ] );
			add_action( 'admin_post_' . self::ACTION_CANCEL, [ $this, 'handle_cancel' ] );
			add_action( 'admin_post_' . self::ACTION_DISMISS, [ $this, 'handle_dismiss' ] );
			add_action( 'admin_post_' . self::ACTION_DOWNLOAD, [ $this, 'handle_download' ] );
			add_action( 'admin_post_' . self::ACTION_IMPORT, [ $this, 'handle_import' ] );

			// Progress polling. Logged-in and capability-checked — there is no
			// reason for this one to be reachable without a session.
			add_action( 'wp_ajax_' . self::ACTION_STATUS, [ $this, 'handle_status' ] );
		}

		add_action( 'fw_ext_site_migration_finished', [ $this, 'on_migration_finished' ], 10, 2 );
	}

	/**
	 * Pull in the engine classes.
	 *
	 * @return void
	 */
	private function load_engine() {
		$dir = $this->get_path( '/includes/' );

		require_once $dir . 'class-fw-sm-stage.php';
		require_once $dir . 'class-fw-sm-state.php';
		require_once $dir . 'class-fw-sm-queue.php';
		require_once $dir . 'class-fw-sm-replacer.php';
		require_once $dir . 'class-fw-sm-db-export.php';
		require_once $dir . 'class-fw-sm-file-scanner.php';
		require_once $dir . 'class-fw-sm-archive.php';
		require_once $dir . 'class-fw-sm-importer.php';
		require_once $dir . 'class-fw-sm-runner.php';
	}

	/**
	 * The capability required to migrate this site.
	 *
	 * On multisite this MUST be a network-level capability, and the reason is
	 * not theoretical. Table discovery selects on `LIKE 'wp\_%'`, and because
	 * esc_like() turns the underscore into a literal rather than a wildcard,
	 * that pattern matches `wp_2_posts` and `wp_17_options` just as happily as
	 * `wp_posts`. A plain site administrator on the network's main site holds
	 * `export` — so without this elevation they could export every other site
	 * on the network and download it as a zip.
	 *
	 * @return string
	 */
	public static function capability() {
		return is_multisite() ? 'manage_network_options' : self::CAPABILITY;
	}

	/**
	 * Is this install one the extension can currently migrate?
	 *
	 * Multisite is refused outright in this version. It is not merely untested:
	 * the stages disagree about what "the site" even is. The database stage
	 * picks up every subsite's tables, while the uploads stage resolves to a
	 * single subsite's `uploads/sites/<id>` directory, so the archive would be
	 * internally inconsistent. Import is worse — capability keys on multisite
	 * are per-blog (`wp_2_capabilities`, `wp_3_capabilities`), and the prefix
	 * repair only handles one pair, which would strip roles from every user
	 * outside the first subsite.
	 *
	 * Refusing plainly is better than half-working, so this returns false until
	 * network-aware stages are built.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return ! is_multisite();
	}

	/**
	 * Why the install is unsupported, for the screen.
	 *
	 * @return string
	 */
	public static function unsupported_reason() {
		return __(
			'Site Migration does not support multisite networks yet. On a network the database and the files belong to different scopes, so an archive taken here would not be a coherent copy of any one site — and restoring one would strip user roles across the network. Support for networks is planned as a deliberate feature rather than an accident of the single-site code.',
			'fw'
		);
	}

	/**
	 * @return string
	 */
	public static function get_page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @internal
	 * Slot this page after the Asset Optimizer in the shared Unyson+ submenu.
	 *
	 * @param string[] $order
	 *
	 * @return string[]
	 */
	public function _filter_submenu_order( $order ) {
		if ( ! is_array( $order ) || in_array( self::PAGE_SLUG, $order, true ) ) {
			return $order;
		}

		$pos = array_search( 'fw-asset-optimizer', $order, true );

		if ( false === $pos ) {
			$order[] = self::PAGE_SLUG;
		} else {
			array_splice( $order, $pos + 1, 0, self::PAGE_SLUG );
		}

		return $order;
	}

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$this->page_hook = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Site Migration', 'fw' ),
			__( 'Site Migration', 'fw' ),
			self::capability(),
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);

		if ( $this->page_hook ) {
			add_action( 'admin_enqueue_scripts', [ $this, '_enqueue_page_static' ] );
		}
	}

	/**
	 * @internal
	 *
	 * @param string $hook
	 *
	 * @return void
	 */
	public function _enqueue_page_static( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		// 'fw' in both arrays: fw.confirm() renders WordPress's own media-modal
		// markup and takes its appearance from the fw STYLE handle. Declaring
		// only the script leaves the dialog as unstyled text at the foot of the
		// page, which makes a destructive action unconfirmable.
		wp_enqueue_script( 'fw' );
		wp_enqueue_style( 'fw' );
	}

	/**
	 * Extension settings, read once with defaults applied.
	 *
	 * @return array
	 */
	public function get_settings() {
		$values = (array) fw_get_db_ext_settings_option( $this->get_name() );

		return [
			'install_compat_muplugin' => (bool) ( $values['install_compat_muplugin'] ?? true ),
			'keep_archives'           => max( 1, (int) ( $values['keep_archives'] ?? 3 ) ),
		];
	}

	/**
	 * Render the migration screen.
	 *
	 * Native nav-tabs and postbox chrome, per the extension conventions — this
	 * is not a place to hand-roll a bespoke UI.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to migrate this site.', 'fw' ) );
		}

		$state = FW_SM_State::get();

		require $this->get_path( '/views/page.php' );
	}

	/**
	 * Start an export.
	 *
	 * @return void
	 * @handles admin_post_fw_sm_start
	 */
	public function handle_start() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to migrate this site.', 'fw' ) );
		}

		check_admin_referer( self::ACTION_START );

		if ( ! self::is_supported() ) {
			$this->redirect_with_notice( 'unsupported', self::unsupported_reason() );
		}

		$stages = isset( $_POST['stages'] ) && is_array( $_POST['stages'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['stages'] ) )
			: [];

		$stages = array_values( array_intersect( FW_SM_Stage::selectable(), $stages ) );

		if ( empty( $stages ) ) {
			$this->redirect_with_notice( 'no-stages' );
		}

		$storage = FW_SM_Archive::storage_dir();

		if ( is_wp_error( $storage ) ) {
			$this->redirect_with_notice( 'storage', $storage->get_error_message() );
		}

		$source_url = untrailingslashit( home_url() );
		$target_url = isset( $_POST['target_url'] )
			? untrailingslashit( esc_url_raw( wp_unslash( $_POST['target_url'] ) ) )
			: '';

		$archive_path = trailingslashit( $storage['path'] )
		                . FW_SM_Archive::generate_filename( $source_url );

		$archive = new FW_SM_Archive( $archive_path );

		$created = $archive->create(
			[
				'generator'    => 'UnysonPlus Site Migration',
				'version'      => $this->manifest->get_version(),
				'created_at'   => gmdate( 'c' ),
				'source_url'   => $source_url,
				'target_url'   => $target_url,
				'source_path'  => untrailingslashit( wp_normalize_path( ABSPATH ) ),
				'table_prefix' => $GLOBALS['wpdb']->prefix,
				'stages'       => $stages,
				'wp_version'   => get_bloginfo( 'version' ),
				'php_version'  => PHP_VERSION,
			]
		);

		if ( is_wp_error( $created ) ) {
			$this->redirect_with_notice( 'archive', $created->get_error_message() );
		}

		$result = $this->runner->start(
			'export',
			$stages,
			[
				'archive_path'   => $archive_path,
				'source_url'     => $source_url,
				'target_url'     => $target_url,
				'source_path'    => untrailingslashit( wp_normalize_path( ABSPATH ) ),
				'target_path'    => '',
				'modified_after' => 0,
			]
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'start', $result->get_error_message() );
		}

		wp_safe_redirect( self::get_page_url() );
		exit;
	}

	/**
	 * Start an import from an uploaded archive.
	 *
	 * @return void
	 * @handles admin_post_fw_sm_import
	 */
	public function handle_import() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to migrate this site.', 'fw' ) );
		}

		check_admin_referer( self::ACTION_IMPORT );

		if ( ! self::is_supported() ) {
			$this->redirect_with_notice( 'unsupported', self::unsupported_reason() );
		}

		if ( empty( $_FILES['archive']['name'] ) ) {
			$this->redirect_with_notice( 'import', __( 'Choose an archive file to import.', 'fw' ) );
		}

		$storage = FW_SM_Archive::storage_dir();

		if ( is_wp_error( $storage ) ) {
			$this->redirect_with_notice( 'import', $storage->get_error_message() );
		}

		// Restrict the upload to .zip regardless of what the browser claims the
		// MIME type is, and move it into the protected migration folder rather
		// than the media library — an archive is not media and must never become
		// a publicly addressable attachment.
		$overrides = [
			'test_form' => false,
			'mimes'     => [ 'zip' => 'application/zip' ],
			'unique_filename_callback' => static function ( $dir, $name, $ext ) {
				return 'import-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 16, false ) . $ext;
			},
		];

		$upload_dir_filter = static function ( $dirs ) use ( $storage ) {
			$dirs['path']   = $storage['path'];
			$dirs['url']    = $storage['url'];
			$dirs['subdir'] = '';

			return $dirs;
		};

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		add_filter( 'upload_dir', $upload_dir_filter );

		$uploaded = wp_handle_upload( $_FILES['archive'], $overrides ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		remove_filter( 'upload_dir', $upload_dir_filter );

		if ( ! is_array( $uploaded ) || ! empty( $uploaded['error'] ) ) {
			$this->redirect_with_notice(
				'import',
				is_array( $uploaded ) && ! empty( $uploaded['error'] )
					? $uploaded['error']
					: __( 'The archive could not be uploaded.', 'fw' )
			);
		}

		$archive_path = $uploaded['file'];

		// Read the manifest before committing to anything. An archive without one
		// was not produced here, and running an unknown zip through the importer
		// is not something to attempt hopefully.
		$archive  = new FW_SM_Archive( $archive_path );
		$manifest = $archive->read_manifest();

		if ( is_wp_error( $manifest ) ) {
			@unlink( $archive_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged

			$this->redirect_with_notice( 'import', $manifest->get_error_message() );
		}

		$stages = FW_SM_Stage::import_order( (array) ( $manifest['stages'] ?? [] ) );

		$result = $this->runner->start(
			'import',
			$stages,
			[
				'archive_path'  => $archive_path,
				'source_url'    => $manifest['source_url'] ?? '',
				'source_prefix' => $manifest['table_prefix'] ?? '',
				'source_path'   => $manifest['source_path'] ?? '',
				'target_url'    => untrailingslashit( home_url() ),
				'target_path'   => untrailingslashit( wp_normalize_path( ABSPATH ) ),
			]
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'import', $result->get_error_message() );
		}

		wp_safe_redirect( self::get_page_url() );
		exit;
	}

	/**
	 * @return void
	 * @handles admin_post_fw_sm_cancel
	 */
	public function handle_cancel() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to migrate this site.', 'fw' ) );
		}

		check_admin_referer( self::ACTION_CANCEL );

		$this->runner->cancel();

		wp_safe_redirect( self::get_page_url() );
		exit;
	}

	/**
	 * Clear a finished migration record off the screen.
	 *
	 * @return void
	 * @handles admin_post_fw_sm_dismiss
	 */
	public function handle_dismiss() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to migrate this site.', 'fw' ) );
		}

		check_admin_referer( self::ACTION_DISMISS );

		FW_SM_State::clear();

		wp_safe_redirect( self::get_page_url() );
		exit;
	}

	/**
	 * Stream a finished archive to the browser.
	 *
	 * An archive is a complete copy of the site, so this handler is deliberately
	 * strict: a capability check, a nonce, and a path that must resolve inside
	 * the migration folder. The realpath comparison is what stops a crafted
	 * `file` parameter walking out of the directory and serving wp-config.php.
	 *
	 * @return void
	 * @handles admin_post_fw_sm_download
	 */
	public function handle_download() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to download a site archive.', 'fw' ) );
		}

		check_admin_referer( self::ACTION_DOWNLOAD );

		$requested = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';

		if ( '' === $requested ) {
			wp_die( esc_html__( 'No archive was specified.', 'fw' ) );
		}

		$storage = FW_SM_Archive::storage_dir();

		if ( is_wp_error( $storage ) ) {
			wp_die( esc_html( $storage->get_error_message() ) );
		}

		$base = wp_normalize_path( realpath( $storage['path'] ) );
		$path = wp_normalize_path( (string) realpath( trailingslashit( $storage['path'] ) . $requested ) );

		if ( '' === $path || ! $base || 0 !== strpos( $path, trailingslashit( $base ) ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'That archive could not be found.', 'fw' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		// Clear any buffering so a large archive streams instead of being held
		// in memory in its entirety.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		exit;
	}

	/**
	 * Progress endpoint for the screen's poller.
	 *
	 * @return void
	 * @handles wp_ajax_fw_sm_status
	 */
	public function handle_status() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Not permitted.', 'fw' ) ], 403 );
		}

		check_ajax_referer( self::ACTION_STATUS );

		$state = FW_SM_State::get();

		if ( null === $state ) {
			wp_send_json_success( [ 'status' => 'none' ] );
		}

		$target    = max( 1, (int) $state['total']['target_bytes'] );
		$processed = (int) $state['total']['processed_bytes'];

		$stages = [];

		foreach ( $state['stages'] as $stage_data ) {
			$stages[] = [
				'stage'     => $stage_data['stage'],
				'label'     => FW_SM_Stage::label( $stage_data['stage'] ),
				'processed' => ! empty( $stage_data['processed'] ),
				'bytes'     => (int) $stage_data['total']['processed_bytes'],
				'target'    => (int) $stage_data['total']['target_bytes'],
			];
		}

		$log = array_slice( (array) $state['log'], -12 );

		wp_send_json_success(
			[
				'status'      => $state['status'],
				'initialized' => ! empty( $state['initialized'] ),
				'percent'     => min( 100, (int) round( ( $processed / $target ) * 100 ) ),
				'processed'   => size_format( $processed ),
				'target'      => size_format( $target ),
				'error'       => $state['error'],
				'stages'      => $stages,
				'log'         => array_map(
					static function ( $entry ) {
						return $entry['message'];
					},
					$log
				),
			]
		);
	}

	/**
	 * Tidy up after a migration ends, whatever the outcome.
	 *
	 * @param string $status
	 * @param array  $state
	 *
	 * @return void
	 * @handles fw_ext_site_migration_finished
	 */
	public function on_migration_finished( $status, $state ) {
		if ( 'complete' !== $status ) {
			return;
		}

		$this->prune_archives();
	}

	/**
	 * Keep only the most recent archives, per the retention setting.
	 *
	 * An archive is a full copy of the site; a few of them will fill a disk
	 * quota faster than anything else this plugin does.
	 *
	 * @return void
	 */
	private function prune_archives() {
		$settings = $this->get_settings();
		$storage  = FW_SM_Archive::storage_dir();

		if ( is_wp_error( $storage ) ) {
			return;
		}

		$files = glob( trailingslashit( $storage['path'] ) . '*.zip' );

		if ( ! is_array( $files ) || count( $files ) <= $settings['keep_archives'] ) {
			return;
		}

		// Newest first, then drop everything past the retention count.
		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);

		foreach ( array_slice( $files, $settings['keep_archives'] ) as $old ) {
			@unlink( $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Redirect back to the screen carrying an error code.
	 *
	 * @param string $code
	 * @param string $message
	 *
	 * @return void
	 */
	private function redirect_with_notice( $code, $message = '' ) {
		$args = [ 'fw_sm_error' => $code ];

		if ( '' !== $message ) {
			set_transient( 'fw_sm_notice', $message, MINUTE_IN_SECONDS * 5 );
		}

		wp_safe_redirect( add_query_arg( $args, self::get_page_url() ) );
		exit;
	}
}
