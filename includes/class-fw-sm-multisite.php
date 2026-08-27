<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Everything the migration needs to know about networks.
 *
 * A multisite install is not "a site with extra tables". It is several sites
 * sharing one users table, one uploads tree and one set of network records, and
 * every one of those shared things has to be handled deliberately or a migration
 * produces a network that looks fine until somebody tries to log in.
 *
 * Four shapes are supported, and the differences between them are almost
 * entirely about which tables travel and what they are called when they arrive:
 *
 *   single    → single     the ordinary case; nothing here applies
 *   network   → network    everything, including the network records
 *   subsite   → single     one site promoted out of a network
 *   single    → subsite    a site folded into an existing network
 *
 * What is deliberately NOT supported: turning a single site into a network, or
 * a network into a single site. Both require rewriting wp-config.php on the
 * destination, and a migration tool that edits the destination's wp-config is a
 * migration tool that can render it unbootable.
 */
class FW_SM_Multisite {

	const MODE_SINGLE           = 'single';
	const MODE_NETWORK          = 'network';
	const MODE_SUBSITE_TO_SINGLE = 'subsite_to_single';
	const MODE_SINGLE_TO_SUBSITE = 'single_to_subsite';

	/**
	 * Tables that belong to the network itself rather than to any one site.
	 *
	 * These travel only in a whole-network migration. Sending them in any other
	 * mode would overwrite the destination network's own site register with the
	 * source's, which is how you end up with a network whose blog records point
	 * at sites that do not exist.
	 *
	 * @return string[] Unprefixed names.
	 */
	public static function network_table_names() {
		return [ 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log' ];
	}

	/**
	 * Tables shared by every site on a network.
	 *
	 * @return string[] Unprefixed names.
	 */
	public static function shared_table_names() {
		return [ 'users', 'usermeta' ];
	}

	/**
	 * The sites on this network, for the source-side picker.
	 *
	 * @return array[] Each: [ 'id', 'name', 'url', 'path' ].
	 */
	public static function list_sites() {
		if ( ! is_multisite() ) {
			return [];
		}

		$sites = get_sites( [ 'number' => 500, 'orderby' => 'id' ] );
		$list  = [];

		foreach ( (array) $sites as $site ) {
			$blog_id = (int) $site->blog_id;

			switch_to_blog( $blog_id );
			$name = get_bloginfo( 'name' );
			$url  = untrailingslashit( home_url() );
			restore_current_blog();

			$list[] = [
				'id'   => $blog_id,
				'name' => $name,
				'url'  => $url,
				'path' => $site->path,
			];
		}

		return $list;
	}

	/**
	 * The value the site picker uses for "everything".
	 *
	 * A sentinel rather than a real blog id, so the picker can be one list —
	 * every site, then the whole network — instead of a radio group and a
	 * dropdown that have to agree with each other.
	 */
	const SCOPE_ALL = 'all';

	/**
	 * The table prefix a given blog uses.
	 *
	 * Blog 1 is the odd one out: its tables carry the bare prefix, not `wp_1_`.
	 *
	 * @param string $base_prefix
	 * @param int    $blog_id
	 *
	 * @return string
	 */
	public static function blog_prefix( $base_prefix, $blog_id ) {
		$blog_id = (int) $blog_id;

		return ( $blog_id <= 1 ) ? $base_prefix : $base_prefix . $blog_id . '_';
	}

	/**
	 * Is this table name one of a subsite's (i.e. `wp_<digits>_something`)?
	 *
	 * @param string $table
	 * @param string $base_prefix
	 *
	 * @return int|false The blog id, or false.
	 */
	public static function table_blog_id( $table, $base_prefix ) {
		$pattern = '/^' . preg_quote( $base_prefix, '/' ) . '(\d+)_/';

		return preg_match( $pattern, $table, $m ) ? (int) $m[1] : false;
	}

	/**
	 * Decide the migration mode from what both ends are.
	 *
	 * @param bool     $source_is_network
	 * @param bool     $dest_is_network
	 * @param string   $scope 'network' or 'subsite' when the source is a network.
	 *
	 * @return string|WP_Error
	 */
	public static function resolve_mode( $source_is_network, $dest_is_network, $scope = 'network' ) {
		if ( ! $source_is_network && ! $dest_is_network ) {
			return self::MODE_SINGLE;
		}

		if ( $source_is_network && $dest_is_network ) {
			// A whole network can only replace a whole network. Pushing one
			// subsite INTO a network is supported as single→subsite from the
			// subsite's point of view, so it is offered as its own scope.
			return 'subsite' === $scope ? self::MODE_SINGLE_TO_SUBSITE : self::MODE_NETWORK;
		}

		if ( $source_is_network && ! $dest_is_network ) {
			if ( 'network' === $scope ) {
				return new WP_Error(
					'fw_sm_network_to_single',
					__( 'A whole network cannot be migrated onto a single site. Choose one site from this network instead, or migrate to a destination that is itself a network.', 'fw' )
				);
			}

			return self::MODE_SUBSITE_TO_SINGLE;
		}

		// Single source, network destination: arrive as a new subsite.
		return self::MODE_SINGLE_TO_SUBSITE;
	}

	/**
	 * Human description of a mode, for the confirmation UI.
	 *
	 * @param string $mode
	 *
	 * @return string
	 */
	public static function describe_mode( $mode ) {
		switch ( $mode ) {
			case self::MODE_NETWORK:
				return __( 'The whole network — every site on it — replaces the destination network.', 'fw' );
			case self::MODE_SUBSITE_TO_SINGLE:
				return __( 'One site from this network becomes the destination’s entire site.', 'fw' );
			case self::MODE_SINGLE_TO_SUBSITE:
				return __( 'This site is added to the destination network as a site of its own.', 'fw' );
			default:
				return __( 'This site replaces the destination site.', 'fw' );
		}
	}

	/**
	 * Which tables travel, for a given mode.
	 *
	 * @param string $mode
	 * @param string $base_prefix    Source base prefix.
	 * @param int    $source_blog_id Relevant for subsite modes.
	 *
	 * @return callable A filter: fn( string $table ): bool
	 */
	public static function table_filter( $mode, $base_prefix, $source_blog_id = 1 ) {
		$network = array_map(
			static function ( $n ) use ( $base_prefix ) {
				return $base_prefix . $n;
			},
			self::network_table_names()
		);

		$shared = array_map(
			static function ( $n ) use ( $base_prefix ) {
				return $base_prefix . $n;
			},
			self::shared_table_names()
		);

		switch ( $mode ) {
			case self::MODE_NETWORK:
				// Everything with the base prefix, which on a network means the
				// network records, the shared tables, and every subsite.
				return static function ( $table ) {
					return true;
				};

			case self::MODE_SUBSITE_TO_SINGLE:
				$blog_prefix = self::blog_prefix( $base_prefix, $source_blog_id );

				return static function ( $table ) use ( $blog_prefix, $base_prefix, $shared, $network, $source_blog_id ) {
					// Network records never travel out of a network.
					if ( in_array( $table, $network, true ) ) {
						return false;
					}

					// Users come along — a site with no users cannot be logged into.
					if ( in_array( $table, $shared, true ) ) {
						return true;
					}

					if ( $source_blog_id > 1 ) {
						return 0 === strpos( $table, $blog_prefix );
					}

					// Blog 1 owns the bare-prefixed tables, so exclude anything
					// that belongs to another blog.
					return false === self::table_blog_id( $table, $base_prefix );
				};

			case self::MODE_SINGLE_TO_SUBSITE:
				// The source may be a standalone site OR one site of a network,
				// and those own completely different tables. Reading the base
				// prefix in the second case picks up the network's MAIN site
				// instead of the one that was chosen — which does not fail, it
				// quietly migrates the wrong site's content.
				$blog_prefix = self::blog_prefix( $base_prefix, $source_blog_id );

				return static function ( $table ) use ( $base_prefix, $blog_prefix, $shared, $network, $source_blog_id ) {
					if ( in_array( $table, $network, true ) ) {
						return false;
					}

					// The destination network already has its own users table and
					// merging user rows into it would collide on IDs. Users are
					// handled separately, by granting the destination's existing
					// administrator a role on the new site.
					if ( in_array( $table, $shared, true ) ) {
						return false;
					}

					if ( $source_blog_id > 1 ) {
						return 0 === strpos( $table, $blog_prefix );
					}

					return false === self::table_blog_id( $table, $base_prefix );
				};

			default:
				return static function ( $table ) use ( $base_prefix ) {
					// A single site should not carry stray subsite tables left
					// behind by a network that was torn down.
					return false === self::table_blog_id( $table, $base_prefix );
				};
		}
	}

	/**
	 * What a table is called when it arrives on the destination.
	 *
	 * This is where each mode actually differs. The source rewrites the name as
	 * it writes the SQL, so the destination's staging and swap logic never has
	 * to know which mode is running.
	 *
	 * @param string $table
	 * @param array  $ctx  mode, source_prefix, dest_prefix, source_blog_id, dest_blog_id
	 *
	 * @return string
	 */
	public static function map_table( $table, array $ctx ) {
		$mode        = $ctx['mode'] ?? self::MODE_SINGLE;
		$src_prefix  = $ctx['source_prefix'] ?? '';
		$dest_prefix = $ctx['dest_prefix'] ?? $src_prefix;
		$src_blog    = (int) ( $ctx['source_blog_id'] ?? 1 );
		$dest_blog   = (int) ( $ctx['dest_blog_id'] ?? 1 );

		if ( '' === $src_prefix || 0 !== strpos( $table, $src_prefix ) ) {
			return $table;
		}

		$bare = substr( $table, strlen( $src_prefix ) );

		switch ( $mode ) {
			case self::MODE_SUBSITE_TO_SINGLE:
				// wp_7_posts -> wp_posts (and wp_users stays wp_users).
				if ( $src_blog > 1 ) {
					$bare = preg_replace( '/^' . $src_blog . '_/', '', $bare, 1 );
				}

				return $dest_prefix . $bare;

			case self::MODE_SINGLE_TO_SUBSITE:
				// wp_posts -> wp_9_posts on the destination network.
				//
				// When the source is itself a subsite the name already carries a
				// blog segment, and that has to come off first: wp_7_posts must
				// become wp_9_posts, not wp_9_7_posts.
				if ( $src_blog > 1 ) {
					$bare = preg_replace( '/^' . $src_blog . '_/', '', $bare, 1 );
				}

				return self::blog_prefix( $dest_prefix, $dest_blog ) . $bare;

			case self::MODE_NETWORK:
			default:
				// Identity apart from a prefix change, which also has to keep a
				// subsite's blog segment intact: wp_7_posts -> newpfx_7_posts.
				return $dest_prefix . $bare;
		}
	}

	/**
	 * Where a stage's files sit for a particular blog.
	 *
	 * Subsite uploads live under `uploads/sites/<id>/`. Only uploads are
	 * per-site; themes and plugins are network-wide and shared.
	 *
	 * @param string $stage
	 * @param int    $blog_id
	 *
	 * @return string|null
	 */
	public static function subsite_uploads_dir( $blog_id ) {
		$blog_id = (int) $blog_id;

		if ( ! is_multisite() ) {
			return null;
		}

		switch_to_blog( $blog_id );
		$dir = wp_upload_dir();
		restore_current_blog();

		$path = $dir['basedir'] ?? '';

		return $path && is_dir( $path ) ? untrailingslashit( wp_normalize_path( $path ) ) : null;
	}

	/**
	 * Extra replacement pairs a mode needs beyond the plain URL and path swap.
	 *
	 * Promoting a subsite moves its uploads from `/uploads/sites/7/` to
	 * `/uploads/`, and every attachment URL and every serialized reference to
	 * that path has to follow. Folding a site into a network does the reverse.
	 *
	 * @param string $mode
	 * @param int    $source_blog_id
	 * @param int    $dest_blog_id
	 *
	 * @return array[] Each: [ 'search', 'replace', 'ci' ].
	 */
	public static function extra_replace_pairs( $mode, $source_blog_id, $dest_blog_id ) {
		$pairs = [];

		if ( self::MODE_SUBSITE_TO_SINGLE === $mode && (int) $source_blog_id > 1 ) {
			$pairs[] = [
				'search'  => '/uploads/sites/' . (int) $source_blog_id . '/',
				'replace' => '/uploads/',
				'ci'      => false,
			];
			// Networks created before WP 3.5 used blogs.dir; some very old
			// installs still carry these paths in meta.
			$pairs[] = [
				'search'  => '/blogs.dir/' . (int) $source_blog_id . '/files/',
				'replace' => '/uploads/',
				'ci'      => false,
			];
		}

		if ( self::MODE_SINGLE_TO_SUBSITE === $mode && (int) $dest_blog_id > 1 ) {
			$pairs[] = [
				'search'  => '/uploads/',
				'replace' => '/uploads/sites/' . (int) $dest_blog_id . '/',
				'ci'      => false,
			];
		}

		return $pairs;
	}

	/**
	 * Create a site on this network to receive an incoming migration.
	 *
	 * Runs on the DESTINATION.
	 *
	 * @param string $path_or_subdomain Requested slug.
	 * @param string $title
	 *
	 * @return array|WP_Error [ 'blog_id', 'url' ]
	 */
	public static function create_receiving_site( $path_or_subdomain, $title ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'fw_sm_not_network',
				__( 'This site is not a network, so it cannot create a site to receive a migration.', 'fw' )
			);
		}

		$slug = sanitize_title( $path_or_subdomain );

		if ( '' === $slug ) {
			return new WP_Error(
				'fw_sm_bad_slug',
				__( 'The requested site address is not usable.', 'fw' )
			);
		}

		$network = get_network();

		if ( is_subdomain_install() ) {
			$domain = $slug . '.' . preg_replace( '|^www\.|', '', $network->domain );
			$path   = $network->path;
		} else {
			$domain = $network->domain;
			$path   = trailingslashit( $network->path ) . $slug . '/';
			$path   = preg_replace( '#/+#', '/', $path );
		}

		$existing = get_blog_id_from_url( $domain, $path );

		if ( $existing ) {
			// Reusing an existing site is a legitimate choice — the migration
			// replaces its contents — but it must be deliberate, so it is the
			// caller's decision rather than a silent overwrite here.
			return [ 'blog_id' => (int) $existing, 'url' => set_url_scheme( 'http://' . $domain . $path ), 'existed' => true ];
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			$supers = get_super_admins();
			$user   = ! empty( $supers ) ? get_user_by( 'login', $supers[0] ) : null;
			$user_id = $user ? $user->ID : 1;
		}

		$blog_id = wpmu_create_blog( $domain, $path, $title, $user_id, [ 'public' => 1 ], $network->id );

		if ( is_wp_error( $blog_id ) ) {
			return $blog_id;
		}

		return [
			'blog_id' => (int) $blog_id,
			'url'     => set_url_scheme( 'http://' . $domain . $path ),
			'existed' => false,
		];
	}

	/**
	 * Repair the network's own records after a whole-network migration.
	 *
	 * The blog register, the network record and the network options all carry
	 * the SOURCE's domain and path. Until they are rewritten, the destination
	 * network is describing sites that do not exist at those addresses — which
	 * looks exactly like a broken network.
	 *
	 * Runs on the DESTINATION, after the swap.
	 *
	 * @param string $source_url
	 * @param string $dest_url MUST be the destination's own URL as captured
	 *                         before the swap — see the caller.
	 *
	 * @return array What was changed, so a migration can report it and a failure
	 *               to rewrite is visible rather than silent.
	 */
	public static function repair_network_records( $source_url, $dest_url ) {
		global $wpdb;

		$report = [
			'source_url'   => $source_url,
			'dest_url'     => $dest_url,
			'domains'      => 0,
			'paths'        => 0,
			'path_skipped' => false,
		];

		if ( ! is_multisite() ) {
			return $report;
		}

		$src = wp_parse_url( $source_url );
		$dst = wp_parse_url( $dest_url );

		$src_host = $src['host'] ?? '';
		$dst_host = $dst['host'] ?? '';
		$src_path = trailingslashit( $src['path'] ?? '/' );
		$dst_path = trailingslashit( $dst['path'] ?? '/' );

		if ( '' === $src_host || '' === $dst_host ) {
			return $report;
		}

		// The blog register: every site's domain, and the path prefix that the
		// network sits under.
		$report['domains'] = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->blogs} SET domain = %s WHERE domain = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$dst_host,
				$src_host
			)
		);

		if ( $src_path !== $dst_path ) {
			$report['paths'] = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->blogs} SET path = CONCAT(%s, SUBSTRING(path, %d)) WHERE path LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$dst_path,
					strlen( $src_path ) + 1,
					$wpdb->esc_like( $src_path ) . '%'
				)
			);
		} else {
			$report['path_skipped'] = true;
		}

		// Each subsite also stores its own address. The replacer rewrites those
		// during export, but only when the source URL it was given matches what
		// is actually in the row — so they are corrected here too rather than
		// assumed.
		foreach ( $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" ) as $blog_id ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$blog_id = (int) $blog_id;
			$table   = self::blog_prefix( $wpdb->base_prefix, $blog_id ) . 'options';

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			foreach ( [ 'siteurl', 'home' ] as $key ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE `{$table}` SET option_value = REPLACE(option_value, %s, %s) WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						untrailingslashit( $source_url ),
						untrailingslashit( $dest_url ),
						$key
					)
				);
			}
		}

		// The network record itself.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->site} SET domain = %s, path = %s WHERE domain = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$dst_host,
				$dst_path,
				$src_host
			)
		);

		// Network options that hold the address.
		foreach ( [ 'siteurl', 'home' ] as $key ) {
			$value = get_network_option( null, $key );

			if ( is_string( $value ) && '' !== $value ) {
				update_network_option( null, $key, str_replace( $source_url, $dest_url, $value ) );
			}
		}

		clean_blog_cache( get_site() );
		wp_cache_flush();

		return $report;
	}

	/**
	 * Give the destination's administrator a role on a freshly received site.
	 *
	 * Users are not migrated in single→subsite mode, so without this the new
	 * site exists but nobody on the destination network can edit it.
	 *
	 * @param int $blog_id
	 *
	 * @return void
	 */
	public static function grant_admin_on_site( $blog_id ) {
		if ( ! is_multisite() ) {
			return;
		}

		$supers = get_super_admins();

		foreach ( (array) $supers as $login ) {
			$user = get_user_by( 'login', $login );

			if ( $user ) {
				add_user_to_blog( (int) $blog_id, $user->ID, 'administrator' );
			}
		}
	}
}
