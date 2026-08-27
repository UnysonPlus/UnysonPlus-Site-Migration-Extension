<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site Migration.
 *
 * Moves a whole site to another WordPress install over HTTP. The destination
 * shows a connection key, the user pastes it into the source, and the source
 * pushes everything. There is no stage selection and no archive: a migration
 * moves the site, and choosing pieces is what the Backups extension is for.
 *
 * The engine lives in includes/; this class is the wiring.
 */
class FW_Extension_Site_Migration extends FW_Extension {

	/**
	 * The Unyson+ top-level menu, registered by the extensions manager.
	 *
	 * It is 'fw-extensions', not 'unysonplus'. add_submenu_page() against a
	 * parent that does not exist fails silently — no menu item, no warning, and
	 * the page is simply unreachable.
	 */
	const PARENT_SLUG = 'fw-extensions';
	const PAGE_SLUG   = 'fw-site-migration';

	/**
	 * Capability required on a single-site install. Read it through
	 * capability(), which elevates the requirement on multisite.
	 */
	const CAPABILITY = 'export';

	const ACTION_CONNECT    = 'fw_sm_connect';
	const ACTION_DISCONNECT = 'fw_sm_disconnect';
	const ACTION_MIGRATE    = 'fw_sm_migrate';
	const ACTION_CANCEL     = 'fw_sm_cancel';
	const ACTION_DISMISS    = 'fw_sm_dismiss';
	const ACTION_RESET_KEY  = 'fw_sm_reset_key';
	const ACTION_CLEAR_IN   = 'fw_sm_clear_incoming';
	const ACTION_RECHECK    = 'fw_sm_recheck';
	const ACTION_DIAGNOSE   = 'fw_sm_diagnose';
	const ACTION_INSPECT    = 'fw_sm_inspect';

	/** Where the last diagnostic result is kept, for the screen to render. */
	const DIAGNOSTIC_OPTION = 'fw_sm_diagnostic';

	/** Where the last source/destination comparison is kept. */
	const INSPECT_OPTION    = 'fw_sm_inspect';

	/**
	 * The memory_limit a destination wants in order to migrate comfortably.
	 *
	 * A sensible MINIMUM for a live site rather than a target, and more is
	 * better: the extension shrinks its batches to fit whatever it finds, so
	 * 128 MB works, just slowly. Above this the batches stop being the
	 * constraint and the migration is limited by the link instead. Chosen from
	 * a destination that failed repeatedly at 128 MB and ran cleanly at 256 MB.
	 */
	const RECOMMENDED_MEMORY = 268435456; // 256 MB
	const ACTION_STATUS     = 'fw_sm_status';

	/** Where the source remembers the destination it is connected to. */
	const DESTINATION_OPTION = 'fw_sm_destination';

	/** @var string|null */
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

		// The receiving half. Registered on every install, because any site may
		// be someone else's destination — that is the whole point.
		$receiver = new FW_SM_Receiver();
		$receiver->register();

		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, '_action_admin_menu' ], 30 );
			add_filter( 'fw_unysonplus_admin_submenu_order', [ $this, '_filter_submenu_order' ] );

			foreach ( [
				self::ACTION_CONNECT,
				self::ACTION_DISCONNECT,
				self::ACTION_MIGRATE,
				self::ACTION_CANCEL,
				self::ACTION_DISMISS,
				self::ACTION_RESET_KEY,
				self::ACTION_CLEAR_IN,
				self::ACTION_RECHECK,
				self::ACTION_DIAGNOSE,
				self::ACTION_INSPECT,
			] as $action ) {
				add_action( 'admin_post_' . $action, [ $this, 'handle_' . substr( $action, 6 ) ] );
			}

			add_action( 'wp_ajax_' . self::ACTION_STATUS, [ $this, 'handle_status' ] );
		}

		// Leave nothing behind when the extension is turned off.
		add_action( 'fw_extensions_before_deactivation', [ $this, '_maybe_clean_up' ] );
	}

	/**
	 * Remove everything this extension created, when it is deactivated.
	 *
	 * Migration leaves more debris than most extensions — a jobs table, a
	 * secret, a stored connection to another site, and possibly staging tables
	 * from an interrupted run. Leaving a shared secret and a half-loaded copy of
	 * somebody else's database sitting in the schema is not a tidy way to be
	 * switched off.
	 *
	 * Only fires for THIS extension: the hook is network-wide and reports every
	 * extension being deactivated.
	 *
	 * @param array $extensions Extension names being deactivated.
	 *
	 * @return void
	 * @handles fw_extensions_before_deactivation
	 */
	public function _maybe_clean_up( $extensions ) {
		$names = is_array( $extensions ) ? array_keys( $extensions ) : (array) $extensions;

		if ( ! in_array( $this->get_name(), $names, true ) && ! in_array( 'site-migration', $names, true ) ) {
			return;
		}

		// A migration in flight is told to stop, so the destination is not left
		// holding staging tables for a source that has gone away.
		if ( FW_SM_State::is_running() && $this->runner ) {
			$this->runner->cancel();
		}

		FW_SM_Importer::drop_staging_tables();
		FW_SM_Queue::drop_table();

		foreach ( [
			FW_SM_State::OPTION,
			FW_SM_Runner::TOKEN_OPTION,
			FW_SM_Queue::SCHEMA_OPTION,
			FW_SM_Receiver::SESSION_OPTION,
			FW_SM_Connection::KEY_OPTION,
			FW_SM_Connection::KEY_SET_OPTION,
			self::DESTINATION_OPTION,
		] as $option ) {
			delete_option( $option );
		}

		delete_transient( 'fw_sm_notice' );
		delete_transient( FW_SM_Runner::LOCK_TRANSIENT );
	}

	/**
	 * @return void
	 */
	private function load_engine() {
		$dir = $this->get_path( '/includes/' );

		foreach ( [
			'class-fw-sm-stage.php',
			'class-fw-sm-state.php',
			'class-fw-sm-queue.php',
			'class-fw-sm-replacer.php',
			'class-fw-sm-multisite.php',
			'class-fw-sm-connection.php',
			'class-fw-sm-db-export.php',
			'class-fw-sm-file-scanner.php',
			'class-fw-sm-importer.php',
			'class-fw-sm-sender.php',
			'class-fw-sm-receiver.php',
			'class-fw-sm-runner.php',
		] as $file ) {
			require_once $dir . $file;
		}
	}

	/**
	 * The capability required to migrate this site.
	 *
	 * On multisite this MUST be a network-level capability. A migration from a
	 * network can move the whole network, and even a single-site migration reads
	 * the shared users table — neither is something a plain site administrator,
	 * who holds `export`, should be able to do to the other sites around them.
	 *
	 * @return string
	 */
	public static function capability() {
		return is_multisite() ? 'manage_network_options' : self::CAPABILITY;
	}

	/**
	 * Can this install take part in a migration?
	 *
	 * Both single sites and networks can. What a network CANNOT do is become a
	 * single site or vice versa — that would mean rewriting wp-config.php on the
	 * destination — so the unsupported combinations are refused per migration,
	 * by resolve_mode(), rather than by refusing the install outright.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return true;
	}

	/**
	 * @return string
	 */
	public static function unsupported_reason() {
		return '';
	}

	/**
	 * Is this install a network?
	 *
	 * The network controls on the screen are shown only when they mean
	 * something — a single site never sees a site picker, and a network never
	 * sees the single-site wording.
	 *
	 * @return bool
	 */
	public static function is_network() {
		return is_multisite();
	}

	/**
	 * Work out the mode for the current connection and the user's scope choice.
	 *
	 * @param array  $destination
	 * @param string $scope
	 *
	 * @return string|WP_Error
	 */
	public static function resolve_mode( $destination, $scope ) {
		$info = (array) ( $destination['info'] ?? [] );

		return FW_SM_Multisite::resolve_mode(
			is_multisite(),
			! empty( $info['multisite'] ),
			$scope
		);
	}

	/**
	 * The destination this site is connected to, if any.
	 *
	 * @return array|null [ 'url', 'key', 'info' ]
	 */
	public static function get_destination() {
		$stored = get_option( self::DESTINATION_OPTION, null );

		return is_array( $stored ) && ! empty( $stored['url'] ) ? $stored : null;
	}

	/**
	 * @return string
	 */
	public static function get_page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @internal
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

		// 'fw' in BOTH arrays: fw.confirm() renders WordPress's media-modal
		// markup and takes its appearance from the fw STYLE handle. Declaring
		// only the script leaves the dialog as unstyled text at the foot of the
		// page, which makes a destructive action unconfirmable.
		wp_enqueue_script( 'fw' );
		wp_enqueue_style( 'fw' );
	}

	/**
	 * @return array
	 */
	public function get_settings() {
		$values = (array) fw_get_db_ext_settings_option( $this->get_name() );

		return [
			'install_compat_muplugin' => (bool) ( $values['install_compat_muplugin'] ?? true ),
		];
	}

	/**
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to migrate this site.', 'fw' ) );
		}

		$state       = FW_SM_State::get();
		$destination = self::get_destination();

		require $this->get_path( '/views/page.php' );
	}

	/**
	 * Connect this site to a destination.
	 *
	 * @return void
	 * @handles admin_post_fw_sm_connect
	 */
	public function handle_connect() {
		$this->guard( self::ACTION_CONNECT );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer.
		$raw = isset( $_POST['connection'] ) ? wp_unslash( $_POST['connection'] ) : '';

		$parsed = FW_SM_Connection::parse( $raw );

		if ( is_wp_error( $parsed ) ) {
			$this->redirect_with_notice( $parsed->get_error_message() );
		}

		$sender = new FW_SM_Sender( $parsed['url'], $parsed['key'] );

		$info = $sender->verify();

		if ( is_wp_error( $info ) ) {
			$this->redirect_with_notice( $info->get_error_message() );
		}

		// Compared loosely rather than as strings: an exact match misses the
		// same install reached as www, over a different scheme, or by siteurl
		// where home_url differs — all of which are still this site.
		if ( self::is_same_site( $parsed['url'], (array) $info ) ) {
			$this->redirect_with_notice(
				__( 'That connection information is for this site. Copy it from the destination site instead.', 'fw' )
			);
		}

		// A version difference is worth saying out loud at connect time rather
		// than discovering it as a fatal halfway through sending a database.
		$their_protocol = (int) ( $info['protocol'] ?? 0 );

		if ( $their_protocol !== FW_SM_Receiver::PROTOCOL ) {
			$this->redirect_with_notice(
				sprintf(
					/* translators: 1: destination version, 2: this site's version. */
					__( 'The destination is running an incompatible version of the Site Migration extension (it reports "%1$s"; this site is %2$s). Update the extension on both sites so they match, then connect again.', 'fw' ),
					$info['plugin_version'] ?? __( 'unknown', 'fw' ),
					$this->manifest->get_version()
				)
			);
		}

		update_option(
			self::DESTINATION_OPTION,
			[
				'url'        => $parsed['url'],
				'key'        => $parsed['key'],
				'info'       => $info,
				'checked_at' => time(),
			],
			false
		);

		wp_safe_redirect( self::get_page_url() );
		exit;
	}

	/**
	 * @return void
	 * @handles admin_post_fw_sm_disconnect
	 */
	public function handle_disconnect() {
		$this->guard( self::ACTION_DISCONNECT );

		delete_option( self::DESTINATION_OPTION );

		wp_safe_redirect( self::get_page_url() );
		exit;
	}

	/**
	 * Start the migration. Whole site, no options.
	 *
	 * @return void
	 * @handles admin_post_fw_sm_migrate
	 */
	public function handle_migrate() {
		$this->guard( self::ACTION_MIGRATE );

		$destination = self::get_destination();

		if ( null === $destination ) {
			$this->redirect_with_notice( __( 'Connect to a destination site first.', 'fw' ) );
		}

		$info = (array) ( $destination['info'] ?? [] );

		// A site must never migrate to itself.
		//
		// This is not hypothetical. A destination inherits the source's options
		// through the swap, so a bug in what is carved out can leave a freshly
		// migrated site pointing at the address it was just migrated TO — which
		// is its own. It would then export itself, send itself to itself, and
		// swap the result in. Refusing here means such a state is inert rather
		// than self-destructive, whatever put it there.
		if ( self::is_same_site( $destination['url'], $info ) ) {
			$this->redirect_with_notice(
				__( 'The destination is this same site. A site cannot be migrated to itself — connect to a different WordPress install.', 'fw' )
			);
		}

		// One picker, one value. 'all' means the whole network; anything else is
		// a blog id. Two controls that had to agree with each other was a bug
		// waiting to happen.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer.
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';

		$whole_network = ( FW_SM_Multisite::SCOPE_ALL === $target );
		$scope         = $whole_network ? 'network' : 'subsite';
		$blog_id       = $whole_network ? 0 : (int) $target;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer.
		$slug = isset( $_POST['target_slug'] ) ? sanitize_title( wp_unslash( $_POST['target_slug'] ) ) : '';

		$mode = self::resolve_mode( $destination, $scope );

		if ( is_wp_error( $mode ) ) {
			$this->redirect_with_notice( $mode->get_error_message() );
		}

		// Promoting a subsite needs to know WHICH subsite, and that choice
		// decides the source URL as well as the tables and uploads.
		$source_url = untrailingslashit( home_url() );

		if ( is_multisite() && $blog_id > 1 && in_array(
			$mode,
			[ FW_SM_Multisite::MODE_SUBSITE_TO_SINGLE, FW_SM_Multisite::MODE_SINGLE_TO_SUBSITE ],
			true
		) ) {
			switch_to_blog( $blog_id );
			$source_url = untrailingslashit( home_url() );
			restore_current_blog();
		} else {
			$blog_id = is_multisite() ? (int) get_current_blog_id() : 1;
		}

		// Quick migration: skip files the destination already has byte-for-byte,
		// and optionally leave its database alone entirely.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer.
		$quick = ! empty( $_POST['quick'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer.
		$chosen = isset( $_POST['stages'] ) && is_array( $_POST['stages'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['stages'] ) )
			: [];

		$stages = FW_SM_Stage::all();

		// A stage list is only honoured in quick mode. A full migration means
		// the whole site, which is the promise the rest of this screen makes.
		if ( $quick && ! empty( $chosen ) ) {
			$stages = array_values( array_intersect( $stages, $chosen ) );
		}

		if ( empty( $stages ) ) {
			$this->redirect_with_notice( __( 'Choose at least one thing to migrate.', 'fw' ) );
		}

		$result = $this->runner->start(
			'push',
			$stages,
			[
				'mode'           => $mode,
				'quick'          => $quick,
				'source_blog_id' => $blog_id,
				'target_slug'    => '' !== $slug ? $slug : sanitize_title( get_bloginfo( 'name' ) ),
				'source_url'     => $source_url,
				'source_path'    => untrailingslashit( wp_normalize_path( ABSPATH ) ),
				'target_url'     => untrailingslashit( $info['site_url'] ?? $destination['url'] ),
				'target_path'    => untrailingslashit( $info['abspath'] ?? '' ),
				'target_prefix'  => $info['base_prefix'] ?? ( $info['table_prefix'] ?? '' ),
			]
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( $result->get_error_message() );
		}

		wp_safe_redirect( self::get_page_url() );
		exit;
	}

	/**
	 * @return void
	 * @handles admin_post_fw_sm_cancel
	 */
	public function handle_cancel() {
		$this->guard( self::ACTION_CANCEL );

		$this->runner->cancel();

		wp_safe_redirect( self::get_page_url() );
		exit;
	}

	/**
	 * @return void
	 * @handles admin_post_fw_sm_dismiss
	 */
	public function handle_dismiss() {
		$this->guard( self::ACTION_DISMISS );

		FW_SM_State::clear();

		wp_safe_redirect( self::get_page_url() );
		exit;
	}

	/**
	 * Roll this site's secret, breaking every existing connection to it.
	 *
	 * @return void
	 * @handles admin_post_fw_sm_reset_key
	 */
	public function handle_reset_key() {
		$this->guard( self::ACTION_RESET_KEY );

		FW_SM_Connection::reset_key();

		$this->redirect_with_notice(
			__( 'A new key was generated. Any site already connected to this one will need the new connection information.', 'fw' ),
			'success',
			'destination'
		);
	}

	/**
	 * Ask the destination again what it is.
	 *
	 * What the source knows about the destination is a snapshot taken when the
	 * two were connected, and it never expires on its own. That is fine until
	 * the destination changes underneath it — the extension is updated there, or
	 * it becomes a network — at which point the source keeps offering choices
	 * based on facts that are no longer true, with no way for the user to tell.
	 *
	 * @return void
	 * @handles admin_post_fw_sm_recheck
	 */
	public function handle_recheck() {
		$this->guard( self::ACTION_RECHECK );

		$destination = self::get_destination();

		if ( null === $destination ) {
			$this->redirect_with_notice( __( 'Connect to a destination first.', 'fw' ) );
		}

		$sender = new FW_SM_Sender( $destination['url'], $destination['key'] );

		$info = $sender->verify();

		if ( is_wp_error( $info ) ) {
			$this->redirect_with_notice( $info->get_error_message() );
		}

		$was_network = ! empty( $destination['info']['multisite'] );
		$is_network  = ! empty( $info['multisite'] );

		$destination['info']       = $info;
		$destination['checked_at'] = time();

		update_option( self::DESTINATION_OPTION, $destination, false );

		if ( $was_network !== $is_network ) {
			$this->redirect_with_notice(
				$is_network
					? __( 'The destination is a network. Migrating the entire network is now available.', 'fw' )
					: __( 'The destination is a single site, so only one site at a time can be sent to it.', 'fw' ),
				'success'
			);
		}

		$this->redirect_with_notice(
			sprintf(
				/* translators: %s: what the destination is. */
				__( 'Re-checked. The destination is %s.', 'fw' ),
				$is_network ? __( 'a multisite network', 'fw' ) : __( 'a single site', 'fw' )
			),
			'success'
		);
	}

	/**
	 * Is the connected destination this very site?
	 *
	 * Compared on two independent things, because either alone is fooled:
	 * addresses differ by scheme, www and trailing slash while naming the same
	 * install, and two genuinely different installs can share a database. Only
	 * a match of BOTH the normalised address and the install's own path is
	 * treated as "the same site".
	 *
	 * @param string $url
	 * @param array  $info Handshake response from the destination.
	 *
	 * @return bool
	 */
	public static function is_same_site( $url, array $info ) {
		$here  = self::normalize_host( home_url() );
		$there = self::normalize_host( (string) ( $info['site_url'] ?? $url ) );

		if ( '' === $here || $here !== $there ) {
			return false;
		}

		$here_path  = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$there_path = untrailingslashit( (string) ( $info['abspath'] ?? '' ) );

		// No path reported means an older build on the far side; the address
		// matching is enough to refuse on.
		return '' === $there_path || $here_path === $there_path;
	}

	/**
	 * An address reduced to what actually identifies a site.
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	private static function normalize_host( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] );
		$host = preg_replace( '/^www\./', '', $host );

		return $host . untrailingslashit( $parts['path'] ?? '' );
	}

	/**
	 * Take a snapshot of both sites and store it for the screen to render.
	 *
	 * The comparison is the point. Either side on its own looks plausible —
	 * it is the DIFFERENCE that says a migration did not land, and holding both
	 * next to each other turns "the site looks reset" into a specific row with
	 * two different numbers in it.
	 *
	 * @return void
	 * @handles admin_post_fw_sm_inspect
	 */
	public function handle_inspect() {
		$this->guard( self::ACTION_INSPECT );

		$destination = self::get_destination();

		if ( null === $destination ) {
			$this->redirect_with_notice( __( 'Connect to a destination first.', 'fw' ) );
		}

		$sender = new FW_SM_Sender( $destination['url'], $destination['key'] );
		$remote = $sender->inspect();

		if ( is_wp_error( $remote ) ) {
			$this->redirect_with_notice(
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not inspect the destination: %s', 'fw' ),
					$remote->get_error_message()
				)
			);
		}

		// Anything the destination cannot read gets described in detail, on
		// BOTH sides, in the same pass. Knowing a value is unreadable narrows
		// nothing on its own; the pair of descriptions names the mechanism —
		// truncated, re-encoded, slashed again, or simply never replaced.
		$suspect = array_slice( (array) ( $remote['unreadable_eg'] ?? [] ), 0, 20 );
		$forensic = [];

		if ( ! empty( $suspect ) ) {
			$there = $sender->describe_options( $suspect );

			foreach ( $suspect as $name ) {
				$forensic[ $name ] = [
					'source' => FW_SM_Receiver::describe_option( $name ),
					'dest'   => is_wp_error( $there ) ? [] : ( $there['options'][ $name ] ?? [] ),
				];
			}
		}

		update_option(
			self::INSPECT_OPTION,
			[
				'at'     => time(),
				'url'    => $destination['url'],
				'source' => FW_SM_Receiver::site_snapshot(),
				'dest'   => $remote,
				'forensic' => $forensic,
			],
			false
		);

		$this->redirect_with_notice( __( 'Compared this site with the destination.', 'fw' ), 'success' );
	}

	/**
	 * Measure the link to the destination, without migrating anything.
	 *
	 * Sends payloads of increasing size and times each round trip, separating
	 * the destination's own processing from the wire. Four exchanges, a few
	 * seconds, and it answers the question that a progress bar cannot: is a
	 * slow migration the network, the far end, or this extension?
	 *
	 * @return void
	 * @handles admin_post_fw_sm_diagnose
	 */
	public function handle_diagnose() {
		$this->guard( self::ACTION_DIAGNOSE );

		$destination = self::get_destination();

		if ( null === $destination ) {
			$this->redirect_with_notice( __( 'Connect to a destination first.', 'fw' ) );
		}

		$sender = new FW_SM_Sender( $destination['url'], $destination['key'] );
		$sender->with_limits( (int) ( $destination['info']['post_max_size'] ?? 0 ) );

		$results = [];

		// A ladder, not one measurement. Latency dominates a small payload and
		// bandwidth dominates a large one; only the shape across several sizes
		// distinguishes "every request costs 2 seconds regardless" from "the
		// link is slow per byte", and those have completely different fixes.
		foreach ( [ 0, 65536, 524288, 2097152, 8388608 ] as $size ) {
			$r = $sender->ping( $size, 0 === $size );

			if ( is_wp_error( $r ) ) {
				$results[] = [ 'size' => $size, 'error' => $r->get_error_message() ];
				break;
			}

			$results[] = $r + [ 'size' => $size ];
		}

		// The ladder does not only report — it LEARNS. The largest payload that
		// came back cleanly becomes the ceiling for real batches.
		//
		// This matters more than it sounds: a destination can advertise a
		// generous post_max_size and still die on a payload well below it,
		// because what actually runs out is memory while PHP is parsing the
		// request — before any of this extension's code is reached, so there is
		// no error to catch and the source sees only a bare 500. Measuring what
		// the far end genuinely survives is the only reliable way to size a
		// request.
		$safe = 0;

		foreach ( $results as $row ) {
			if ( empty( $row['error'] ) && (int) $row['size'] > $safe ) {
				$safe = (int) $row['size'];
			}
		}

		$failed_at = 0;

		foreach ( $results as $row ) {
			if ( ! empty( $row['error'] ) ) {
				$failed_at = (int) $row['size'];
				break;
			}
		}

		// Then the question the size ladder cannot answer: is the link full, or
		// merely idle between round trips?
		//
		// The ladder above measures one connection, and one connection over a
		// long round trip is capped by its window rather than by the link. The
		// only way to tell the two apart is to open several at once and see
		// whether the total moves any faster. If it does, the transfer is
		// latency-bound and concurrency is the fix; if it does not, the link
		// really is the ceiling and nothing in this extension can beat it.
		$dst_version = '';

		foreach ( $results as $row ) {
			if ( ! empty( $row['version'] ) ) {
				$dst_version = (string) $row['version'];
				break;
			}
		}

		$parallel = [];
		$unit     = min( 524288, max( 65536, (int) ( $safe / 2 ) ) );

		foreach ( [ 1, 4, 8 ] as $streams ) {
			$r = $sender->ping_parallel( $unit, $streams );

			if ( is_wp_error( $r ) ) {
				$parallel[] = [ 'streams' => $streams, 'error' => $r->get_error_message() ];
				break;
			}

			$parallel[] = $r;
		}

		update_option(
			self::DIAGNOSTIC_OPTION,
			[
				'at'        => time(),
				'url'       => $destination['url'],
				'results'   => $results,
				'safe'      => $safe,
				'failed_at' => $failed_at,
				'parallel'  => $parallel,
				'unit'      => $unit,
				// Stamped so a stale panel is obvious. Without this, a result
				// produced by an older build looks exactly like a current one,
				// and the only way to tell them apart is to notice which
				// sections are missing.
				'version'   => $this->manifest->get_version(),
				'dst_version' => $dst_version,
			],
			false
		);

		if ( $failed_at > 0 ) {
			$this->redirect_with_notice(
				sprintf(
					/* translators: 1: failing size, 2: largest working size. */
					__( 'The destination could not handle a %1$s payload, so requests will be kept at or below %2$s. Raising memory_limit on the destination to %3$s or more would allow larger, faster transfers.', 'fw' ),
					size_format( $failed_at ),
					size_format( $safe ),
					size_format( self::RECOMMENDED_MEMORY )
				),
				'success'
			);
		}

		$this->redirect_with_notice( __( 'Diagnostics complete.', 'fw' ), 'success' );
	}

	/**
	 * Clear an incoming migration this site is holding.
	 *
	 * The escape hatch for a source that died mid-migration without telling us.
	 * Discarding staging tables cannot damage this site — they are not live data
	 * until finalize renames them into place.
	 *
	 * @return void
	 * @handles admin_post_fw_sm_clear_incoming
	 */
	public function handle_clear_incoming() {
		$this->guard( self::ACTION_CLEAR_IN );

		FW_SM_Receiver::clear_incoming();

		$this->redirect_with_notice(
			__( 'Cleared. This site can receive a migration again.', 'fw' ),
			'success',
			'destination'
		);
	}

	/**
	 * Progress endpoint for the page's poller.
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
				'label'     => FW_SM_Stage::label( $stage_data['stage'] ),
				'processed' => ! empty( $stage_data['processed'] ),
				'active'    => empty( $stage_data['processed'] ) && ! empty( $stage_data['initialized'] ),
				'bytes'     => (int) $stage_data['total']['processed_bytes'],
				'target'    => (int) $stage_data['total']['target_bytes'],
			];
		}

		$elapsed = max( 0, time() - (int) ( $state['started_at'] ?? time() ) );

		// A rate, not an estimate. Transfer speed swings by an order of
		// magnitude between stages — a compressible SQL batch and a directory
		// of JPEGs are nothing alike — so a countdown would mostly be wrong.
		// Elapsed time and current throughput are both simply true.
		$rate = $elapsed > 0 ? $processed / $elapsed : 0;

		wp_send_json_success(
			[
				'status'      => $state['status'],
				'initialized' => ! empty( $state['initialized'] ),
				'percent'     => min( 100, (int) round( ( $processed / $target ) * 100 ) ),
				'processed'   => size_format( $processed ),
				'target'      => size_format( $target ),
				'elapsed'     => $elapsed,
				'rate'        => $rate > 0 ? size_format( $rate ) . '/s' : '',
				'error'       => $state['error'],
				'stages'      => $stages,
				'log'         => array_map(
					static function ( $entry ) {
						return $entry['message'];
					},
					array_slice( (array) $state['log'], -12 )
				),
				'report'      => self::support_report( $state ),
			]
		);
	}

	/**
	 * Everything someone would need to diagnose a failed migration, as text.
	 *
	 * A user reporting "it stopped" cannot usually read their server's error
	 * log, and the useful facts are spread across two machines. Putting them in
	 * one copyable block turns a support thread that takes a week into one that
	 * takes a reply.
	 *
	 * @param array $state
	 *
	 * @return string
	 */
	public static function support_report( array $state ) {
		$destination = self::get_destination();
		$info        = (array) ( $destination['info'] ?? [] );

		$lines = [];

		$lines[] = '=== UnysonPlus Site Migration ===';
		$lines[] = 'Status:      ' . ( $state['status'] ?? 'unknown' );

		if ( ! empty( $state['error'] ) ) {
			$lines[] = 'Error:       ' . $state['error'];
		}

		$lines[] = 'Mode:        ' . ( $state['options']['mode'] ?? 'single' );
		$lines[] = 'Quick:       ' . ( ! empty( $state['options']['quick'] ) ? 'yes' : 'no' );
		$lines[] = 'Started:     ' . gmdate( 'Y-m-d H:i:s', (int) ( $state['started_at'] ?? 0 ) ) . ' UTC';
		$lines[] = 'Elapsed:     ' . max( 0, time() - (int) ( $state['started_at'] ?? time() ) ) . 's';
		$lines[] = 'Progress:    ' . size_format( (int) $state['total']['processed_bytes'] )
		           . ' of ' . size_format( max( 1, (int) $state['total']['target_bytes'] ) );
		$lines[] = '';

		$lines[] = '--- Source ---';
		$lines[] = 'URL:         ' . untrailingslashit( home_url() );
		$lines[] = 'WordPress:   ' . get_bloginfo( 'version' ) . ( is_multisite() ? ' (multisite)' : '' );
		$lines[] = 'PHP:         ' . PHP_VERSION;
		$lines[] = 'memory_limit ' . ini_get( 'memory_limit' )
		           . '  post_max_size ' . ini_get( 'post_max_size' )
		           . '  max_execution_time ' . ini_get( 'max_execution_time' );
		$lines[] = '';

		$lines[] = '--- Destination ---';
		$lines[] = 'URL:         ' . ( $destination['url'] ?? '(not connected)' );
		$lines[] = 'WordPress:   ' . ( $info['wp_version'] ?? '?' )
		           . ( ! empty( $info['multisite'] ) ? ' (multisite)' : '' );
		$lines[] = 'PHP:         ' . ( $info['php_version'] ?? '?' );
		$lines[] = 'Extension:   ' . ( $info['plugin_version'] ?? '?' )
		           . '  protocol ' . ( $info['protocol'] ?? '?' );
		$dest_memory = (int) ( $info['memory_limit'] ?? 0 );

		// Flagged in the report itself, because this is the first thing worth
		// checking when someone sends one in about a slow or failing migration.
		$lines[] = 'memory_limit ' . size_format( $dest_memory )
		           . ( $dest_memory > 0 && $dest_memory < self::RECOMMENDED_MEMORY
		               ? '  (below the recommended ' . size_format( self::RECOMMENDED_MEMORY ) . ')'
		               : '' )
		           . '  post_max_size ' . size_format( (int) ( $info['post_max_size'] ?? 0 ) )
		           . '  max_execution_time ' . ( $info['max_execution'] ?? '?' );
		$lines[] = 'max_allowed_packet ' . size_format( (int) ( $info['max_packet'] ?? 0 ) );
		$lines[] = '';

		// The comparison, when one has been taken. This is the part that
		// answers "it migrated but the site looks wrong": both sides of the
		// same measurements, so a difference is visible rather than inferred.
		$cmp = get_option( self::INSPECT_OPTION, null );

		if ( is_array( $cmp ) && ! empty( $cmp['source'] ) && ! empty( $cmp['dest'] ) ) {
			$a = (array) $cmp['source'];
			$b = (array) $cmp['dest'];

			$lines[] = '--- Comparison (' . human_time_diff( (int) ( $cmp['at'] ?? time() ) ) . ' ago) ---';
			$lines[] = sprintf( '%-26s %-34s %s', '', 'source', 'destination' );

			foreach ( [
				'active theme'   => 'stylesheet',
				'parent theme'   => 'template',
				'options'        => 'options_total',
				'unreadable'     => 'options_unreadable',
				'extensions'     => 'active_extensions',
				'ext version'    => 'extension',
			] as $label => $key ) {
				$lines[] = sprintf( '%-26s %-34s %s', $label, (string) ( $a[ $key ] ?? '?' ), (string) ( $b[ $key ] ?? '?' ) );
			}

			$lines[] = sprintf(
				'%-26s %-34s %s',
				'parent theme files',
				empty( $a['template_dir_exists'] ) ? 'MISSING' : (string) (int) ( $a['template_files'] ?? 0 ),
				empty( $b['template_dir_exists'] ) ? 'MISSING' : (string) (int) ( $b['template_files'] ?? 0 )
			);

			$ta = (array) ( $a['theme_settings'] ?? [] );
			$tb = (array) ( $b['theme_settings'] ?? [] );

			$lines[] = sprintf( '%-26s %-34s %s', 'theme id', (string) ( $ta['theme_id'] ?? '?' ), (string) ( $tb['theme_id'] ?? '?' ) );
			$lines[] = sprintf(
				'%-26s %-34s %s',
				'settings readable',
				empty( $ta['readable'] ) ? 'NO' : size_format( (int) ( $ta['bytes'] ?? 0 ) ),
				empty( $tb['readable'] ) ? 'NO' : size_format( (int) ( $tb['bytes'] ?? 0 ) )
			);

			foreach ( array_unique( array_merge( array_keys( (array) ( $ta['keys'] ?? [] ) ), array_keys( (array) ( $tb['keys'] ?? [] ) ) ) ) as $key ) {
				$lines[] = sprintf(
					'%-26s %-34s %s',
					'  ' . $key,
					isset( $ta['keys'][ $key ] ) ? size_format( (int) $ta['keys'][ $key ] ) : 'absent',
					isset( $tb['keys'][ $key ] ) ? size_format( (int) $tb['keys'][ $key ] ) : 'absent'
				);
			}

			if ( ! empty( $b['unreadable_eg'] ) ) {
				$lines[] = 'unreadable on destination: ' . implode( ', ', (array) $b['unreadable_eg'] );
			}

			$lines[] = '';
		}

		$lines[] = '--- Stages ---';

		foreach ( (array) $state['stages'] as $stage ) {
			$lines[] = sprintf(
				'%-22s %-10s %s of %s',
				FW_SM_Stage::label( $stage['stage'] ),
				! empty( $stage['processed'] ) ? 'done' : ( ! empty( $stage['initialized'] ) ? 'running' : 'waiting' ),
				size_format( (int) $stage['total']['processed_bytes'] ),
				size_format( max( 1, (int) $stage['total']['target_bytes'] ) )
			);
		}

		$lines[] = '';
		$lines[] = '--- Log ---';

		foreach ( (array) $state['log'] as $entry ) {
			$lines[] = gmdate( 'H:i:s', (int) $entry['at'] ) . '  ' . $entry['message'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Capability + nonce + platform check, in one place so no handler can
	 * accidentally omit one.
	 *
	 * @param string $action
	 *
	 * @return void
	 */
	private function guard( $action ) {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to migrate this site.', 'fw' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * @param string $message
	 * @param string $type
	 * @param string $tab Tab to return to, so an action taken on the Destination
	 *                    tab does not bounce the user over to Source.
	 *
	 * @return void
	 */
	private function redirect_with_notice( $message, $type = 'error', $tab = '' ) {
		set_transient( 'fw_sm_notice', [ 'message' => $message, 'type' => $type ], 5 * MINUTE_IN_SECONDS );

		$url = self::get_page_url();

		if ( '' !== $tab ) {
			$url = add_query_arg( 'tab', $tab, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
