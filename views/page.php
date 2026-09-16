<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Site Migration screen.
 *
 * Two tabs, because this site can play either role in a migration:
 *
 *   Source      — this site is being migrated somewhere else.
 *   Destination — this site is receiving a migration from somewhere else.
 *
 * The Source tab carries the whole job lifecycle: not connected, connected and
 * ready, running, finished. Each state shows exactly one thing to do next.
 *
 * @var array|null $state       Current migration, if any.
 * @var array|null $destination Connected destination, if any.
 */

$running  = null !== $state && 'running' === $state['status'];
$finished = null !== $state && 'running' !== $state['status'];
$notice   = get_transient( 'fw_sm_notice' );

if ( $notice ) {
	delete_transient( 'fw_sm_notice' );
}

// A migration in progress belongs on the Source tab, so land there regardless
// of which tab the user was last looking at.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$requested = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
$tab       = ( $running || $finished ) ? 'source' : ( 'destination' === $requested ? 'destination' : 'source' );

$post_url   = admin_url( 'admin-post.php' );
$is_network = FW_Extension_Site_Migration::is_network();
$dest_info  = (array) ( $destination['info'] ?? [] );
$dest_is_nw = ! empty( $dest_info['multisite'] );

// Allowed but not created: WP_ALLOW_MULTISITE is set on the destination, yet the
// network was never actually built. It looks like a network to a user reading
// wp-config and is emphatically not one to WordPress.
$dest_ms_half = ! $dest_is_nw && ! empty( $dest_info['ms_allowed'] );

/**
 * A form that posts one action with its nonce.
 *
 * Every control on this page is a POST. None of these actions are safe to
 * trigger from a link someone else can put in front of an administrator.
 */
$action_form = static function ( $action, $label, $class = 'button', $confirm = '' ) use ( $post_url ) {
	?>
	<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline-block;margin:0">
		<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
		<?php wp_nonce_field( $action ); ?>
		<button type="submit" class="<?php echo esc_attr( $class ); ?>"
			<?php if ( $confirm ) : ?>
				onclick="return confirm( <?php echo esc_attr( wp_json_encode( $confirm ) ); ?> )"
			<?php endif; ?>
		><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};

$tab_url = static function ( $which ) {
	return add_query_arg( 'tab', $which, FW_Extension_Site_Migration::get_page_url() );
};
?>
<div class="wrap fw-ext-site-migration">

	<h1>
		<?php esc_html_e( 'Site Migration', 'fw' ); ?>
		<span class="fw-sm-beta"
		      style="display:inline-block;vertical-align:middle;margin-left:.5em;padding:.15em .55em;border-radius:3px;background:#f0b849;color:#1d2327;font-size:12px;font-weight:600;line-height:1.6;letter-spacing:.02em;text-transform:uppercase;"
		      title="<?php esc_attr_e( 'This extension is new and still being refined — always keep a backup of the destination before you migrate.', 'fw' ); ?>">
			<?php esc_html_e( 'Beta', 'fw' ); ?>
		</span>
	</h1>

	<p class="description" style="margin:-.4em 0 1.2em;max-width:52em">
		<?php
		esc_html_e(
			'Site Migration is in beta. It works, but expect the occasional rough edge — always take a backup of the destination before migrating, and update the extension on both sites so they match.',
			'fw'
		);
		?>
	</p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo 'success' === ( $notice['type'] ?? 'error' ) ? 'success' : 'error'; ?>">
			<p><?php echo esc_html( $notice['message'] ?? '' ); ?></p>
		</div>
	<?php endif; ?>

	<h2 class="nav-tab-wrapper" style="margin:.6em 0 1.4em">
		<a href="<?php echo esc_url( $tab_url( 'source' ) ); ?>"
		   class="nav-tab<?php echo 'source' === $tab ? ' nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Source', 'fw' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_url( 'destination' ) ); ?>"
		   class="nav-tab<?php echo 'destination' === $tab ? ' nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Destination', 'fw' ); ?>
		</a>
	</h2>

	<?php if ( 'source' === $tab ) : ?>

		<p class="description" style="max-width:46em;margin:0 0 1.2em">
			<?php
			echo esc_html(
				$is_network
					? __( 'This network is the source — send the whole network, or one site from it, to another WordPress install.', 'fw' )
					: __( 'This site is the source — send it to another WordPress install.', 'fw' )
			);
			?>
		</p>

		<?php if ( $running ) : ?>

			<div class="metabox-holder">
				<div class="postbox">
					<div class="postbox-header">
						<h2 class="hndle"><span><?php esc_html_e( 'Migrating', 'fw' ); ?></span></h2>
					</div>
					<div class="inside">

						<p class="description" style="margin:.2em 0 1em">
							<?php
							printf(
								/* translators: %s: destination site URL. */
								esc_html__( 'Sending this site to %s. You can close this tab — the migration keeps running.', 'fw' ),
								'<strong>' . esc_html( $destination['url'] ?? '' ) . '</strong>'
							);
							?>
						</p>

						<div style="background:#f0f0f1;border-radius:3px;height:24px;overflow:hidden">
							<div id="fw-sm-bar" style="background:var(--fw-accent, #3858e9);height:100%;width:0;transition:width .4s ease"></div>
						</div>
						<p style="margin:.6em 0 1.2em">
							<strong id="fw-sm-percent">0%</strong>
							<span id="fw-sm-bytes" class="description"></span>
							<span id="fw-sm-clock" class="description" style="float:right;font-variant-numeric:tabular-nums"></span>
						</p>

						<ul id="fw-sm-stages" style="margin:0 0 1.2em;padding:0;list-style:none"></ul>

						<details>
							<summary style="cursor:pointer" class="description"><?php esc_html_e( 'Details', 'fw' ); ?></summary>
							<ul id="fw-sm-log"
							    style="margin:.6em 0 0;padding:.8em 1em;background:#f6f7f7;border-radius:3px;max-height:180px;overflow:auto;font-family:Consolas,Monaco,monospace;font-size:12px"></ul>
							<p style="margin:.6em 0 0">
								<button type="button" class="button button-small" id="fw-sm-copy-report">
									<?php esc_html_e( 'Copy diagnostic report', 'fw' ); ?>
								</button>
								<span class="description">
									<?php esc_html_e( 'Everything needed to diagnose a problem — both sites, their PHP limits, and the full log.', 'fw' ); ?>
								</span>
							</p>
						</details>

						<p style="margin:1.4em 0 0">
							<?php
							$action_form(
								FW_Extension_Site_Migration::ACTION_CANCEL,
								__( 'Cancel migration', 'fw' ),
								'button',
								__( 'Cancel this migration? The destination site will be left untouched.', 'fw' )
							);
							?>
						</p>

					</div>
				</div>
			</div>

			<script>
			( function () {
				var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var nonce    = <?php echo wp_json_encode( wp_create_nonce( FW_Extension_Site_Migration::ACTION_STATUS ) ); ?>;
				var bar      = document.getElementById( 'fw-sm-bar' );
				var percent  = document.getElementById( 'fw-sm-percent' );
				var bytes    = document.getElementById( 'fw-sm-bytes' );
				var stages   = document.getElementById( 'fw-sm-stages' );
				var log      = document.getElementById( 'fw-sm-log' );
				var scanning = <?php echo wp_json_encode( ' — ' . __( 'working out how much there is to send…', 'fw' ) ); ?>;
				var clock    = document.getElementById( 'fw-sm-clock' );
				var copyBtn  = document.getElementById( 'fw-sm-copy-report' );
				var report   = '';
				var elapsed  = 0;

				function hms( total ) {
					var h = Math.floor( total / 3600 );
					var m = Math.floor( ( total % 3600 ) / 60 );
					var s = total % 60;
					return ( h > 0 ? h + 'h ' : '' ) + ( ( h > 0 || m > 0 ) ? m + 'm ' : '' ) + s + 's';
				}

				// Ticks locally between polls, so the clock moves every second
				// rather than jumping every three.
				setInterval( function () {
					elapsed++;
					if ( clock && clock.dataset.rate !== undefined ) {
						clock.textContent = hms( elapsed ) + ( clock.dataset.rate ? '  ·  ' + clock.dataset.rate : '' );
					}
				}, 1000 );

				if ( copyBtn ) {
					copyBtn.addEventListener( 'click', function () {
						if ( ! report ) { return; }
						if ( navigator.clipboard ) {
							navigator.clipboard.writeText( report );
						} else {
							var t = document.createElement( 'textarea' );
							t.value = report;
							document.body.appendChild( t );
							t.select();
							document.execCommand( 'copy' );
							document.body.removeChild( t );
						}
						copyBtn.textContent = <?php echo wp_json_encode( __( 'Copied', 'fw' ) ); ?>;
					} );
				}

				function poll() {
					fetch(
						ajaxUrl + '?action=<?php echo esc_js( FW_Extension_Site_Migration::ACTION_STATUS ); ?>&_wpnonce=' + encodeURIComponent( nonce ),
						{ credentials: 'same-origin' }
					)
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( ! res || ! res.success ) { return; }

							var d = res.data;

							// Finished: re-render server-side rather than trying
							// to reproduce the completed state in JS.
							if ( d.status !== 'running' ) { window.location.reload(); return; }

							bar.style.width = d.percent + '%';
							percent.textContent = d.percent + '%';
							bytes.textContent = d.initialized ? ' — ' + d.processed + ' of ' + d.target : scanning;

							elapsed = d.elapsed || 0;
							report  = d.report || '';

							if ( clock ) {
								clock.dataset.rate = d.rate || '';
								clock.textContent  = hms( elapsed ) + ( d.rate ? '  ·  ' + d.rate : '' );
							}

							stages.innerHTML = '';
							( d.stages || [] ).forEach( function ( s ) {
								var li = document.createElement( 'li' );
								li.style.padding = '.25em 0';
								li.style.color = s.processed ? '#1a7f37' : ( s.active ? 'var(--fw-accent, #3858e9)' : '#787c82' );
								li.textContent = ( s.processed ? '✓' : ( s.active ? '•' : '·' ) ) + '  ' + s.label;
								stages.appendChild( li );
							} );

							log.innerHTML = '';
							( d.log || [] ).forEach( function ( line ) {
								var li = document.createElement( 'li' );
								li.textContent = line;
								log.appendChild( li );
							} );

							setTimeout( poll, 3000 );
						} )
						.catch( function () {
							// A failed poll is not a failed migration — the runner
							// is a separate process. Back off and try again.
							setTimeout( poll, 8000 );
						} );
				}

				poll();
			}() );
			</script>

		<?php elseif ( $finished ) : ?>

			<?php $ok = 'complete' === $state['status']; ?>
			<div class="metabox-holder">
				<div class="postbox">
					<div class="postbox-header">
						<h2 class="hndle">
							<span><?php echo esc_html( $ok ? __( 'Migration complete', 'fw' ) : __( 'Migration stopped', 'fw' ) ); ?></span>
						</h2>
					</div>
					<div class="inside">
						<?php if ( $ok ) : ?>
							<p>
								<?php
								printf(
									/* translators: %s: destination site URL. */
									esc_html__( 'This site has been migrated to %s.', 'fw' ),
									'<strong>' . esc_html( $destination['url'] ?? '' ) . '</strong>'
								);
								?>
							</p>
						<?php else : ?>
							<p><?php echo esc_html( $state['error'] ? $state['error'] : __( 'The migration did not finish.', 'fw' ) ); ?></p>
							<p class="description">
								<?php esc_html_e( 'The destination site was not changed — its data is only replaced once a migration finishes.', 'fw' ); ?>
							</p>
						<?php endif; ?>

						<?php if ( ! empty( $state['log'] ) ) : ?>
							<details<?php echo $ok ? '' : ' open'; ?>>
								<summary style="cursor:pointer" class="description"><?php esc_html_e( 'Details', 'fw' ); ?></summary>
								<ul style="margin:.6em 0 0;padding:.8em 1em;background:#f6f7f7;border-radius:3px;max-height:200px;overflow:auto;font-family:Consolas,Monaco,monospace;font-size:12px">
									<?php foreach ( array_slice( $state['log'], -20 ) as $entry ) : ?>
										<li><?php echo esc_html( $entry['message'] ); ?></li>
									<?php endforeach; ?>
								</ul>
							</details>
						<?php endif; ?>

						<?php
						$took = max( 0, (int) ( $state['finished_at'] ?? time() ) - (int) ( $state['started_at'] ?? time() ) );
						?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: duration, e.g. "4m 12s". */
								esc_html__( 'Took %s.', 'fw' ),
								esc_html(
									( $took >= 3600 ? floor( $took / 3600 ) . 'h ' : '' )
									. ( $took >= 60 ? floor( ( $took % 3600 ) / 60 ) . 'm ' : '' )
									. ( $took % 60 ) . 's'
								)
							);
							?>
						</p>

						<p style="margin:1.4em 0 0">
							<?php $action_form( FW_Extension_Site_Migration::ACTION_DISMISS, __( 'Done', 'fw' ), 'button button-primary' ); ?>
							&nbsp;
							<button type="button" class="button" id="fw-sm-copy-done">
								<?php esc_html_e( 'Copy diagnostic report', 'fw' ); ?>
							</button>
						</p>

						<script>
						( function () {
							var btn = document.getElementById( 'fw-sm-copy-done' );
							if ( ! btn ) { return; }
							var text = <?php echo wp_json_encode( FW_Extension_Site_Migration::support_report( $state ) ); ?>;
							btn.addEventListener( 'click', function () {
								if ( navigator.clipboard ) {
									navigator.clipboard.writeText( text );
								} else {
									var t = document.createElement( 'textarea' );
									t.value = text;
									document.body.appendChild( t );
									t.select();
									document.execCommand( 'copy' );
									document.body.removeChild( t );
								}
								btn.textContent = <?php echo wp_json_encode( __( 'Copied', 'fw' ) ); ?>;
							} );
						}() );
						</script>
					</div>
				</div>
			</div>

		<?php elseif ( null === $destination ) : ?>

			<div class="metabox-holder">
				<div class="postbox">
					<div class="postbox-header">
						<h2 class="hndle"><span><?php esc_html_e( 'Connect to a destination', 'fw' ); ?></span></h2>
					</div>
					<div class="inside">
						<p class="description">
							<?php
							esc_html_e(
								'Install this extension on the destination site, open its Site Migration screen, and copy the line from its Destination tab. Paste it below.',
								'fw'
							);
							?>
						</p>

						<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin-top:1em">
							<input type="hidden" name="action" value="<?php echo esc_attr( FW_Extension_Site_Migration::ACTION_CONNECT ); ?>">
							<?php wp_nonce_field( FW_Extension_Site_Migration::ACTION_CONNECT ); ?>
							<p>
								<textarea name="connection" rows="3" style="width:100%;display:block;font-family:Consolas,Monaco,monospace"
								          placeholder="https://destination.example.com  a1b2c3…" required></textarea>
							</p>
							<?php submit_button( __( 'Connect', 'fw' ), 'primary', 'submit', false ); ?>
						</form>
					</div>
				</div>
			</div>

		<?php else : ?>

			<?php $info = (array) ( $destination['info'] ?? [] ); ?>
			<div class="metabox-holder">
				<div class="postbox">
					<div class="postbox-header">
						<h2 class="hndle"><span><?php esc_html_e( 'Ready to migrate', 'fw' ); ?></span></h2>
					</div>
					<div class="inside">

						<table class="widefat striped" style="max-width:46em;margin:.4em 0 1.2em">
							<tbody>
								<tr>
									<td style="width:8em"><strong><?php esc_html_e( 'From', 'fw' ); ?></strong></td>
									<td><?php echo esc_html( untrailingslashit( home_url() ) ); ?></td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'To', 'fw' ); ?></strong></td>
									<td>
										<?php echo esc_html( $destination['url'] ); ?>
										<?php if ( ! empty( $info['site_name'] ) ) : ?>
											<span class="description">&mdash; <?php echo esc_html( $info['site_name'] ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<?php
								$dest_memory = (int) ( $dest_info['memory_limit'] ?? 0 );
								?>
								<?php if ( $dest_memory > 0 ) : ?>
									<tr>
										<td><strong><?php esc_html_e( 'Destination memory', 'fw' ); ?></strong></td>
										<td>
											<?php echo esc_html( size_format( $dest_memory ) ); ?>
											<?php if ( $dest_memory < FW_Extension_Site_Migration::RECOMMENDED_MEMORY ) : ?>
												<span class="description">
													&mdash;
													<?php
													printf(
														/* translators: %s: recommended memory_limit. */
														esc_html__( '%s or more is recommended for a live site. The migration will still run, shrinking each request to fit, but it will take longer.', 'fw' ),
														esc_html( size_format( FW_Extension_Site_Migration::RECOMMENDED_MEMORY ) )
													);
													?>
												</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endif; ?>

								<tr>
									<td><strong><?php esc_html_e( 'Destination is', 'fw' ); ?></strong></td>
									<td>
										<?php
										if ( $dest_is_nw ) {
											echo esc_html__( 'a multisite network', 'fw' );
										} elseif ( $dest_ms_half ) {
											echo esc_html__( 'a single site — multisite allowed but not set up', 'fw' );
										} else {
											echo esc_html__( 'a single site', 'fw' );
										}
										?>
										<span class="description">
											&mdash;
											<?php
											$checked = (int) ( $destination['checked_at'] ?? 0 );

											if ( $checked ) {
												printf(
													/* translators: %s: human-readable duration. */
													esc_html__( 'checked %s ago.', 'fw' ),
													esc_html( human_time_diff( $checked ) )
												);
											} else {
												esc_html_e( 'checked when you connected.', 'fw' );
											}
											?>
											<?php
											$action_form(
												FW_Extension_Site_Migration::ACTION_RECHECK,
												__( 'Re-check', 'fw' )
											);
											?>
										</span>
									</td>
								</tr>
							</tbody>
						</table>

						<?php
						// What this migration will actually do, given what both
						// ends are. Shown before the button, because "Migrate"
						// means something quite different in each case.
						// The picker defaults to a single site, so describe that
						// unless the only possible choice is the whole network.
						$default_scope = ( $is_network || ! $dest_is_nw ) ? 'subsite' : 'network';
						$resolved      = FW_Extension_Site_Migration::resolve_mode( $destination, $default_scope );
						?>

						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( FW_Extension_Site_Migration::ACTION_MIGRATE ); ?>">
							<?php wp_nonce_field( FW_Extension_Site_Migration::ACTION_MIGRATE ); ?>

							<?php if ( $is_network ) : ?>
								<?php
								$sites = FW_SM_Multisite::list_sites();

								// One list: every site, then the whole network last.
								// A radio group plus a dropdown meant two controls
								// that had to agree with each other, which is a
								// state you can get wrong.
								?>
								<p style="margin:.2em 0 1.2em">
									<label for="fw-sm-target" style="display:block;font-weight:600;margin-bottom:.4em">
										<?php esc_html_e( 'What to migrate', 'fw' ); ?>
									</label>
									<select name="target" id="fw-sm-target" style="min-width:34em;max-width:100%">
										<?php
										foreach ( $sites as $site ) :
											// The address this site would sensibly take on the
											// destination network: its own path if it has one,
											// falling back to its name. A network's main site
											// has no path of its own, and its name is the only
											// thing that distinguishes it.
											$path = trim( (string) ( $site['path'] ?? '' ), '/' );
											$slug = '' !== $path
												? sanitize_title( basename( $path ) )
												: sanitize_title( $site['name'] );
											?>
											<option value="<?php echo esc_attr( $site['id'] ); ?>"
												data-slug="<?php echo esc_attr( $slug ); ?>"
												<?php selected( (int) $site['id'], (int) get_current_blog_id() ); ?>>
												<?php echo esc_html( $site['name'] . ' — ' . $site['url'] ); ?>
											</option>
										<?php endforeach; ?>

										<?php if ( $dest_is_nw ) : ?>
											<option value="<?php echo esc_attr( FW_SM_Multisite::SCOPE_ALL ); ?>">
												<?php
												printf(
													/* translators: %d: number of sites on the network. */
													esc_html__( 'Entire Multi Site, all %d sites (Proceed with Caution)', 'fw' ),
													count( $sites )
												);
												?>
											</option>
										<?php endif; ?>
									</select>
								</p>

								<?php if ( $dest_ms_half ) : ?>
									<div class="notice notice-warning inline" style="margin:-.6em 0 1.2em">
										<p style="max-width:46em">
											<strong><?php esc_html_e( 'The destination is not a network yet.', 'fw' ); ?></strong>
											<?php
											esc_html_e(
												'It has WP_ALLOW_MULTISITE in wp-config.php, which only unlocks Tools → Network Setup. WordPress does not treat it as a network until that wizard has been completed and the constants it prints have been added to wp-config.php and .htaccess. Finish Network Setup there, then use Re-check above.',
												'fw'
											);
											?>
										</p>
									</div>
								<?php elseif ( ! $dest_is_nw ) : ?>
									<p class="description" style="max-width:46em;margin:-.6em 0 1.2em">
										<?php
										esc_html_e(
											'The destination is a single site, so one site can be sent to it. Migrating the entire network needs a destination that is itself a network — if you have since made it one, use Re-check above.',
											'fw'
										);
										?>
									</p>
								<?php endif; ?>
							<?php else : ?>
								<input type="hidden" name="target" value="1">
							<?php endif; ?>

							<?php
							if ( $dest_is_nw ) :
								// Whatever the dropdown starts on. Previously this was the
								// name of the site the admin screen was being viewed from,
								// which on a network is the main site — so selecting any
								// other site left the address pointing somewhere unrelated.
								$default_slug = sanitize_title( get_bloginfo( 'name' ) );

								if ( ! empty( $sites ) ) {
									foreach ( $sites as $site ) {
										if ( (int) $site['id'] !== (int) get_current_blog_id() ) {
											continue;
										}

										$path         = trim( (string) ( $site['path'] ?? '' ), '/' );
										$default_slug = '' !== $path
											? sanitize_title( basename( $path ) )
											: sanitize_title( $site['name'] );
									}
								}
								?>
								<p style="margin:.8em 0 1.2em">
									<label for="fw-sm-slug"><?php esc_html_e( 'Address on the destination network:', 'fw' ); ?></label>
									<input type="text" name="target_slug" id="fw-sm-slug" class="regular-text"
									       value="<?php echo esc_attr( $default_slug ); ?>">
									<span class="description">
										<?php
										echo esc_html(
											! empty( $dest_info['subdomain'] )
												? __( 'used as a subdomain', 'fw' )
												: __( 'used as a sub-directory', 'fw' )
										);
										?>
									</span>
								</p>
							<?php endif; ?>

							<?php if ( $dest_is_nw && ! empty( $sites ) ) : ?>
								<script>
								( function () {
									var target = document.getElementById( 'fw-sm-target' ),
									    slug   = document.getElementById( 'fw-sm-slug' );

									if ( ! target || ! slug ) {
										return;
									}

									// Only follow the dropdown until the field is edited by
									// hand. Overwriting a deliberate choice on the next
									// change would be worse than not helping at all.
									var touched = false;

									slug.addEventListener( 'input', function () {
										touched = true;
									} );

									target.addEventListener( 'change', function () {
										if ( touched ) {
											return;
										}

										var option = target.options[ target.selectedIndex ],
										    value  = option && option.getAttribute( 'data-slug' );

										if ( value ) {
											slug.value = value;
										}
									} );
								}() );
								</script>
							<?php endif; ?>

							<div id="fw-sm-full-warning" class="notice notice-warning inline" style="margin:0 0 1.2em">
								<p style="max-width:46em">
									<?php if ( is_wp_error( $resolved ) ) : ?>
										<?php echo esc_html( $resolved->get_error_message() ); ?>
									<?php else : ?>
										<strong><?php echo esc_html( FW_SM_Multisite::describe_mode( $resolved ) ); ?></strong>
										<?php
										esc_html_e(
											'Everything it replaces on the destination is replaced in full. Inside the plugins and themes it sends, files that no longer exist here are removed there too — stale files from an older version are a common cause of fatal errors after a migration. Plugins, themes and media that exist only on the destination are left alone. The database is only replaced once the migration finishes, so an interrupted one leaves the destination’s data untouched. Files are different: they are written as they arrive, so a migration that stops partway can leave the destination’s plugins or themes half-updated — and a half-updated plugin can take the site down until the migration is run again. A completed migration cannot be undone.',
											'fw'
										);
										?>
									<?php endif; ?>
								</p>
							</div>

							<?php
							// What to send. Two modes, mapped onto the existing
							// backend contract so the transfer path is untouched:
							//
							//   whole  — the full migration. `quick` is NOT
							//            submitted, so the handler runs every stage
							//            and prunes, exactly as before.
							//   choose — selective. Submits `quick=1` plus the
							//            per-stage `stages[]` and `folders[STAGE][]`
							//            the handler already understands.
							//
							// The cards are a nicer face on the old "Quick
							// migration -> Include" box; nothing server-side changed.
							$file_stages = array_values( array_filter(
								FW_SM_Stage::all(),
								static function ( $s ) { return FW_SM_Stage::FINALIZE !== $s; }
							) );
							?>

							<input type="hidden" name="quick" id="fw-sm-quick" value="">

							<?php
							// The last push to THIS destination, if there was one.
							// Shown as a recency line plus a one-click "repeat",
							// which applies the saved mode / stages / folders to the
							// form below without submitting — the user still sees
							// what it will do and presses Migrate themselves.
							$last = FW_Extension_Site_Migration::last_migration( $destination['url'] );
							?>
							<?php if ( is_array( $last ) && ! empty( $last['at'] ) ) : ?>
								<p class="fw-sm-last" style="margin:.2em 0 1.2em;color:#50575e">
									<span class="dashicons dashicons-backup" style="vertical-align:text-bottom;color:#787c82"></span>
									<?php
									printf(
										/* translators: %s: human-readable duration. */
										esc_html__( 'Last migration to this destination: %s ago.', 'fw' ),
										esc_html( human_time_diff( (int) $last['at'] ) )
									);
									?>
									<?php if ( 'choose' === ( $last['mode'] ?? 'whole' ) ) : ?>
										<button type="button" id="fw-sm-repeat" class="button button-small" style="margin-left:.4em"
										        data-profile="<?php echo esc_attr( wp_json_encode( [
											'mode'    => (string) ( $last['mode'] ?? 'whole' ),
											'target'  => (string) ( $last['target'] ?? '' ),
											'stages'  => array_values( (array) ( $last['stages'] ?? [] ) ),
											'folders' => (array) ( $last['folders'] ?? [] ),
										] ) ); ?>">
											<?php esc_html_e( 'Repeat that selection', 'fw' ); ?>
										</button>
									<?php endif; ?>
								</p>
							<?php endif; ?>
							<div class="fw-sm-what" style="margin:.2em 0 1.2em">
								<p style="font-weight:600;margin:0 0 .6em"><?php esc_html_e( 'What to send', 'fw' ); ?></p>

								<div class="fw-sm-modes" style="display:flex;gap:.6em;flex-wrap:wrap;margin:0 0 1em;max-width:46em">
									<label class="fw-sm-mode" style="flex:1 1 18em;border:1px solid #dcdcde;border-radius:4px;padding:.7em .9em;cursor:pointer;background:#fff">
										<span style="display:flex;align-items:center;gap:.5em;font-weight:600">
											<input type="radio" name="fw_sm_mode" value="whole" checked>
											<?php esc_html_e( 'Whole site', 'fw' ); ?>
										</span>
										<span class="description" style="display:block;margin:.25em 0 0 1.7em">
											<?php esc_html_e( 'Everything — database, uploads, themes, plugins and the rest.', 'fw' ); ?>
										</span>
									</label>
									<label class="fw-sm-mode" style="flex:1 1 18em;border:1px solid #dcdcde;border-radius:4px;padding:.7em .9em;cursor:pointer;background:#fff">
										<span style="display:flex;align-items:center;gap:.5em;font-weight:600">
											<input type="radio" name="fw_sm_mode" value="choose">
											<?php esc_html_e( 'Choose what to send', 'fw' ); ?>
										</span>
										<span class="description" style="display:block;margin:.25em 0 0 1.7em">
											<?php esc_html_e( 'Pick stages and folders. Only files the destination does not already have are sent.', 'fw' ); ?>
										</span>
									</label>
								</div>

								<div id="fw-sm-cards" style="display:none;max-width:46em">
									<?php
									foreach ( $file_stages as $stage ) :
										$is_db      = ( FW_SM_Stage::DATABASE === $stage );
										$default_on = ! $is_db; // Database off by default in choose mode.
										$entries    = FW_SM_Stage::is_file_stage( $stage )
											? FW_SM_Stage::top_level( $stage, (int) ( $is_network ? get_current_blog_id() : 0 ) )
											: [];
										$has_folders = ( count( $entries ) > 1 );
										?>
										<div class="fw-sm-card" data-stage="<?php echo esc_attr( $stage ); ?>"
										     style="border:1px solid #dcdcde;border-radius:4px;margin:0 0 .5em;background:#fff">
											<div style="display:flex;align-items:center;gap:.6em;padding:.6em .9em">
												<label style="font-weight:600;display:flex;align-items:center;gap:.5em;margin:0">
													<input type="checkbox" class="fw-sm-inc" name="stages[]"
													       value="<?php echo esc_attr( $stage ); ?>"
													       <?php checked( $default_on ); ?>>
													<?php echo esc_html( FW_SM_Stage::label( $stage ) ); ?>
												</label>
												<span class="fw-sm-summary description" style="margin-left:auto;text-align:right"></span>
												<?php if ( $has_folders || $is_db ) : ?>
													<button type="button" class="button-link fw-sm-toggle" aria-expanded="false"
													        style="text-decoration:none;white-space:nowrap"><?php esc_html_e( 'edit ▾', 'fw' ); ?></button>
												<?php endif; ?>
											</div>

											<?php if ( $is_db ) : ?>
												<div class="fw-sm-body" style="display:none;padding:0 .9em .8em">
													<p class="description" style="max-width:44em;margin:.2em 0 0">
														<strong><?php esc_html_e( 'Sending the database replaces the destination’s.', 'fw' ); ?></strong>
														<?php
														esc_html_e(
															'Anything the destination recorded since your last copy — orders, comments, form entries, new users — is replaced, not merged. For pushing design or code changes to a live site, leave the database off.',
															'fw'
														);
														?>
													</p>
												</div>
											<?php elseif ( $has_folders ) : ?>
												<div class="fw-sm-body fw-sm-folders" style="display:none;padding:0 .9em .8em">
													<p style="margin:.2em 0 .4em">
														<a href="#" class="fw-sm-all"><?php esc_html_e( 'all', 'fw' ); ?></a>
														&middot;
														<a href="#" class="fw-sm-none"><?php esc_html_e( 'none', 'fw' ); ?></a>
														<span class="description" style="margin-left:.6em">
															<?php
															printf(
																/* translators: %d: number of folders. */
																esc_html__( '%d items', 'fw' ),
																count( $entries )
															);
															?>
														</span>
													</p>
													<div style="max-height:12em;overflow:auto;border:1px solid #f0f0f1;padding:.4em .7em;border-radius:3px">
														<?php foreach ( $entries as $entry ) : ?>
															<label style="display:block;margin:.15em 0">
																<input type="checkbox"
																       name="folders[<?php echo esc_attr( $stage ); ?>][]"
																       value="<?php echo esc_attr( $entry['name'] ); ?>" checked>
																<?php echo esc_html( $entry['name'] ); ?>
																<?php if ( ! $entry['dir'] ) : ?>
																	<span class="description">&mdash; <?php esc_html_e( 'file', 'fw' ); ?></span>
																<?php endif; ?>
															</label>
														<?php endforeach; ?>
													</div>
												</div>
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
								</div>
							</div>

							<?php if ( $is_network && $dest_is_nw ) : ?>
								<div id="fw-sm-network-caution" class="notice notice-error inline" style="display:none;margin:0 0 1.2em">
									<p style="max-width:46em">
										<strong><?php esc_html_e( 'Caution — this migrates the entire network.', 'fw' ); ?></strong>
										<?php
										esc_html_e(
											'Every site on the destination network is replaced, along with its users, its site register and its network settings. This is a much larger and slower operation than migrating one site, and it cannot be undone once it completes. If you only need one site moved, choose it from the list instead.',
											'fw'
										);
										?>
									</p>
								</div>
							<?php endif; ?>

							<script>
							( function () {
								var quick = document.getElementById( 'fw-sm-quick' );
								var cards = document.getElementById( 'fw-sm-cards' );
								var warn  = document.getElementById( 'fw-sm-full-warning' );
								if ( ! quick || ! cards ) { return; }

								// A card's one-line summary, kept in step with its
								// own checkboxes — this is the whole point of the
								// redesign: what each stage will do, at a glance.
								function refresh( card ) {
									var inc     = card.querySelector( '.fw-sm-inc' );
									var summary = card.querySelector( '.fw-sm-summary' );
									if ( ! summary ) { return; }

									if ( inc && ! inc.checked ) {
										summary.textContent = '<?php echo esc_js( __( 'not sent', 'fw' ) ); ?>';
										summary.style.color = '#a7aaad';
										return;
									}

									summary.style.color = '';
									var boxes = card.querySelectorAll( '.fw-sm-folders input[type=checkbox]' );

									if ( ! boxes.length ) {
										summary.textContent = '<?php echo esc_js( __( 'everything', 'fw' ) ); ?>';
										return;
									}

									var on = 0;
									Array.prototype.forEach.call( boxes, function ( b ) { if ( b.checked ) { on++; } } );

									summary.textContent = ( on === boxes.length )
										? '<?php echo esc_js( __( 'everything', 'fw' ) ); ?>'
										: on + ' / ' + boxes.length + ' <?php echo esc_js( __( 'folders', 'fw' ) ); ?>';
								}

								Array.prototype.forEach.call( cards.querySelectorAll( '.fw-sm-card' ), function ( card ) {
									var body   = card.querySelector( '.fw-sm-body' );
									var toggle = card.querySelector( '.fw-sm-toggle' );
									var all    = card.querySelector( '.fw-sm-all' );
									var none   = card.querySelector( '.fw-sm-none' );

									if ( toggle && body ) {
										toggle.addEventListener( 'click', function () {
											var open = body.style.display !== 'none';
											body.style.display = open ? 'none' : '';
											toggle.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
											toggle.textContent = open
												? '<?php echo esc_js( __( 'edit ▾', 'fw' ) ); ?>'
												: '<?php echo esc_js( __( 'done ▴', 'fw' ) ); ?>';
										} );
									}

									function setAll( on ) {
										Array.prototype.forEach.call(
											card.querySelectorAll( '.fw-sm-folders input[type=checkbox]' ),
											function ( b ) { b.checked = on; }
										);
										refresh( card );
									}
									if ( all )  { all.addEventListener( 'click', function ( e ) { e.preventDefault(); setAll( true ); } ); }
									if ( none ) { none.addEventListener( 'click', function ( e ) { e.preventDefault(); setAll( false ); } ); }

									card.addEventListener( 'change', function () { refresh( card ); } );
									refresh( card );
								} );

								// Mode: "whole" is the full migration (quick empty);
								// "choose" reveals the cards and sets quick=1.
								Array.prototype.forEach.call(
									document.querySelectorAll( 'input[name=fw_sm_mode]' ),
									function ( r ) {
										r.addEventListener( 'change', function () {
											var choose = ( document.querySelector( 'input[name=fw_sm_mode]:checked' ) || {} ).value === 'choose';
											cards.style.display = choose ? '' : 'none';
											quick.value = choose ? '1' : '';
											if ( warn ) { warn.style.display = choose ? 'none' : ''; }
										} );
									}
								);

								// Repeat the last selection: apply a saved profile to the
								// form, then leave the user to press Migrate.
								var repeat = document.getElementById( 'fw-sm-repeat' );
								if ( repeat ) {
									repeat.addEventListener( 'click', function () {
										var prof;
										try { prof = JSON.parse( repeat.getAttribute( 'data-profile' ) || '{}' ); }
										catch ( e ) { return; }

										var target = document.getElementById( 'fw-sm-target' );
										if ( target && prof.target ) { target.value = prof.target; }

										var wants = ( prof.mode === 'choose' );
										var radio = document.querySelector( 'input[name=fw_sm_mode][value=' + ( wants ? 'choose' : 'whole' ) + ']' );
										if ( radio ) { radio.checked = true; radio.dispatchEvent( new Event( 'change', { bubbles: true } ) ); }

										if ( ! wants ) { return; }

										var stages = prof.stages || [];
										var folders = prof.folders || {};

										Array.prototype.forEach.call( cards.querySelectorAll( '.fw-sm-card' ), function ( card ) {
											var stage = card.getAttribute( 'data-stage' );
											var inc   = card.querySelector( '.fw-sm-inc' );
											if ( inc ) { inc.checked = ( stages.indexOf( stage ) !== -1 ); }

											// A saved folder list narrows this stage; its absence
											// means the whole stage, so leave every box checked.
											var keep = folders[ stage ];
											if ( keep && keep.length !== undefined ) {
												Array.prototype.forEach.call(
													card.querySelectorAll( '.fw-sm-folders input[type=checkbox]' ),
													function ( b ) { b.checked = ( keep.indexOf( b.value ) !== -1 ); }
												);
											}

											refresh( card );
										} );
									} );
								}
							}() );
							</script>

							<p>
								<button type="submit" class="button button-primary button-hero"
								        onclick="return confirm( <?php echo esc_attr( wp_json_encode( sprintf( __( 'Replace content on %s with this site?', 'fw' ), $destination['url'] ) ) ); ?> )">
									<?php esc_html_e( 'Migrate', 'fw' ); ?>
								</button>
							</p>

							<?php if ( $is_network && $dest_is_nw ) : ?>
								<script>
								( function () {
									var sel = document.getElementById( 'fw-sm-target' );
									var box = document.getElementById( 'fw-sm-network-caution' );
									if ( ! sel || ! box ) { return; }

									function sync() {
										box.style.display = ( sel.value === <?php echo wp_json_encode( FW_SM_Multisite::SCOPE_ALL ); ?> ) ? '' : 'none';
									}

									sel.addEventListener( 'change', sync );
									sync();
								}() );
								</script>
							<?php endif; ?>
						</form>

						<p style="margin:1em 0 0">
							<?php $action_form( FW_Extension_Site_Migration::ACTION_DISCONNECT, __( 'Disconnect', 'fw' ) ); ?>
							&nbsp;
							<?php
								$action_form(
									FW_Extension_Site_Migration::ACTION_INSPECT,
									__( 'Compare with destination', 'fw' )
								);
							?>
							&nbsp;
							<?php
							$action_form(
								FW_Extension_Site_Migration::ACTION_DIAGNOSE,
								__( 'Test connection speed', 'fw' )
							);
							?>
						</p>

						<?php $cmp = get_option( FW_Extension_Site_Migration::INSPECT_OPTION, null ); ?>

						<?php if ( is_array( $cmp ) && ! empty( $cmp['source'] ) && ! empty( $cmp['dest'] ) ) : ?>

							<?php

							$src = (array) $cmp['source'];

							$dst = (array) $cmp['dest'];



							$ts_src = (array) ( $src['theme_settings'] ?? [] );

							$ts_dst = (array) ( $dst['theme_settings'] ?? [] );



							// Rows are [ label, source, destination, difference

							// expected ]. Marking the expected ones matters: the

							// address and the install path are SUPPOSED to

							// differ, and flagging them would bury the

							// differences that are actual problems.

							$rows = [

								[ __( 'Address', 'fw' ), $src['site_url'] ?? '', $dst['site_url'] ?? '', true ],

								[ __( 'Install path', 'fw' ), $src['abspath'] ?? '', $dst['abspath'] ?? '', true ],

								[ __( 'WordPress', 'fw' ), $src['wp'] ?? '', $dst['wp'] ?? '', false ],

								[ __( 'Site Migration version', 'fw' ), $src['extension'] ?? '', $dst['extension'] ?? '', false ],

								[ __( 'Active theme', 'fw' ), $src['stylesheet'] ?? '', $dst['stylesheet'] ?? '', false ],

								[ __( 'Parent theme', 'fw' ), $src['template'] ?? '', $dst['template'] ?? '', false ],

								[

									__( 'Parent theme files', 'fw' ),

									empty( $src['template_dir_exists'] ) ? __( 'MISSING', 'fw' ) : (string) (int) ( $src['template_files'] ?? 0 ),

									empty( $dst['template_dir_exists'] ) ? __( 'MISSING', 'fw' ) : (string) (int) ( $dst['template_files'] ?? 0 ),

									false,

								],

								[

									__( 'Parent theme manifest', 'fw' ),

									empty( $src['template_manifest'] ) ? __( 'missing', 'fw' ) : __( 'present', 'fw' ),

									empty( $dst['template_manifest'] ) ? __( 'missing', 'fw' ) : __( 'present', 'fw' ),

									false,

								],

								[ __( 'Theme id used for settings', 'fw' ), $ts_src['theme_id'] ?? '', $ts_dst['theme_id'] ?? '', false ],

								[

									__( 'Theme Settings readable', 'fw' ),

									empty( $ts_src['readable'] ) ? __( 'NO', 'fw' ) : size_format( (int) ( $ts_src['bytes'] ?? 0 ) ),

									empty( $ts_dst['readable'] ) ? __( 'NO', 'fw' ) : size_format( (int) ( $ts_dst['bytes'] ?? 0 ) ),

									false,

								],

								[ __( 'Options', 'fw' ), (string) ( $src['options_total'] ?? 0 ), (string) ( $dst['options_total'] ?? 0 ), true ],

								[ __( 'Unreadable options', 'fw' ), (string) ( $src['options_unreadable'] ?? 0 ), (string) ( $dst['options_unreadable'] ?? 0 ), false ],

								[ __( 'Active extensions', 'fw' ), (string) ( $src['active_extensions'] ?? 0 ), (string) ( $dst['active_extensions'] ?? 0 ), false ],

							];

							?>



							<div style="margin-top:1.4em">

								<h4 style="margin:0 0 .4em">

									<?php esc_html_e( 'This site vs the destination', 'fw' ); ?>

									<span class="description" style="font-weight:400">

										&mdash;

										<?php

										printf(

											/* translators: %s: human time diff. */

											esc_html__( '%s ago', 'fw' ),

											esc_html( human_time_diff( (int) ( $cmp['at'] ?? time() ) ) )

										);

										?>

									</span>

								</h4>



								<table class="widefat striped" style="max-width:60em">

									<thead>

										<tr>

											<th style="width:16em"><?php esc_html_e( 'What', 'fw' ); ?></th>

											<th><?php esc_html_e( 'This site', 'fw' ); ?></th>

											<th><?php esc_html_e( 'Destination', 'fw' ); ?></th>

										</tr>

									</thead>

									<tbody>

										<?php foreach ( $rows as $row ) : ?>

											<?php $differs = ! $row[3] && (string) $row[1] !== (string) $row[2]; ?>

											<tr<?php echo $differs ? ' style="background:#fcf0f1"' : ''; ?>>

												<td>

													<strong><?php echo esc_html( $row[0] ); ?></strong>

													<?php if ( $differs ) : ?>

														<span class="description" style="color:#b32d2e">&nbsp;&larr; <?php esc_html_e( 'differs', 'fw' ); ?></span>

													<?php endif; ?>

												</td>

												<td><?php echo esc_html( $row[1] ); ?></td>

												<td><?php echo esc_html( $row[2] ); ?></td>

											</tr>

										<?php endforeach; ?>

									</tbody>

								</table>



								<?php if ( ! empty( $ts_src['keys'] ) || ! empty( $ts_dst['keys'] ) ) : ?>

									<h4 style="margin:1.2em 0 .4em"><?php esc_html_e( 'Theme Settings keys present', 'fw' ); ?></h4>

									<table class="widefat striped" style="max-width:60em">

										<thead>

											<tr>

												<th style="width:26em"><?php esc_html_e( 'Option', 'fw' ); ?></th>

												<th><?php esc_html_e( 'This site', 'fw' ); ?></th>

												<th><?php esc_html_e( 'Destination', 'fw' ); ?></th>

											</tr>

										</thead>

										<tbody>

											<?php

											$keys = array_unique(

												array_merge(

													array_keys( (array) ( $ts_src['keys'] ?? [] ) ),

													array_keys( (array) ( $ts_dst['keys'] ?? [] ) )

												)

											);

											sort( $keys );

											?>

											<?php foreach ( $keys as $key ) : ?>

												<tr>

													<td><code><?php echo esc_html( $key ); ?></code></td>

													<td>

														<?php

														echo isset( $ts_src['keys'][ $key ] )

															? esc_html( size_format( (int) $ts_src['keys'][ $key ] ) )

															: '<em>' . esc_html__( 'absent', 'fw' ) . '</em>';

														?>

													</td>

													<td>

														<?php

														echo isset( $ts_dst['keys'][ $key ] )

															? esc_html( size_format( (int) $ts_dst['keys'][ $key ] ) )

															: '<em>' . esc_html__( 'absent', 'fw' ) . '</em>';

														?>

													</td>

												</tr>

											<?php endforeach; ?>

										</tbody>

									</table>



									<p class="description" style="max-width:56em;margin-top:.6em">

										<?php

										esc_html_e(

											'Theme Settings are stored under the theme id taken from the theme manifest, falling back to "default". If the destination holds the settings under one id but resolves another, every setting reads as unset and the site shows defaults — which looks exactly like the settings never migrated.',

											'fw'

										);

										?>

									</p>

								<?php endif; ?>



								<?php

								// Row counts, compared by the table name with each

								// side's own prefix removed — the prefixes may

								// legitimately differ.

								$t_src = (array) ( $src['tables'] ?? [] );

								$t_dst = (array) ( $dst['tables'] ?? [] );

								$diff  = [];



								foreach ( $t_src as $name => $count ) {

									$bare  = substr( $name, strlen( (string) ( $src['prefix'] ?? '' ) ) );

									$there = null;



									foreach ( $t_dst as $dname => $dcount ) {

										if ( substr( $dname, strlen( (string) ( $dst['prefix'] ?? '' ) ) ) === $bare ) {

											$there = $dcount;

											break;

										}

									}



									if ( $there !== $count ) {

										$diff[] = [ $bare, $count, null === $there ? __( 'absent', 'fw' ) : $there ];

									}

								}

								?>



								<?php if ( ! empty( $diff ) ) : ?>

									<h4 style="margin:1.2em 0 .4em"><?php esc_html_e( 'Tables whose row counts differ', 'fw' ); ?></h4>

									<table class="widefat striped" style="max-width:60em">

										<thead>

											<tr>

												<th style="width:26em"><?php esc_html_e( 'Table', 'fw' ); ?></th>

												<th><?php esc_html_e( 'This site', 'fw' ); ?></th>

												<th><?php esc_html_e( 'Destination', 'fw' ); ?></th>

											</tr>

										</thead>

										<tbody>

											<?php foreach ( $diff as $d ) : ?>

												<tr>

													<td><code><?php echo esc_html( $d[0] ); ?></code></td>

													<td><?php echo esc_html( number_format( (int) $d[1] ) ); ?></td>

													<td><?php echo esc_html( is_numeric( $d[2] ) ? number_format( (int) $d[2] ) : $d[2] ); ?></td>

												</tr>

											<?php endforeach; ?>

										</tbody>

									</table>

									<p class="description" style="max-width:56em;margin-top:.6em">

										<?php

										esc_html_e(

											'Some difference is normal right after a migration — the destination keeps its own users and its own copy of this extension bookkeeping. A content table that differs is not normal.',

											'fw'

										);

										?>

									</p>

								<?php endif; ?>



							<?php if ( ! empty( $cmp['forensic'] ) ) : ?>
								<h4 style="margin:1.4em 0 .4em"><?php esc_html_e( 'Why those options cannot be read', 'fw' ); ?></h4>

								<table class="widefat striped" style="max-width:60em">
									<thead>
										<tr>
											<th style="width:14em"><?php esc_html_e( 'Option', 'fw' ); ?></th>
											<th><?php esc_html_e( 'Measure', 'fw' ); ?></th>
											<th><?php esc_html_e( 'This site', 'fw' ); ?></th>
											<th><?php esc_html_e( 'Destination', 'fw' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( (array) $cmp['forensic'] as $name => $pair ) : ?>
											<?php
											$fa = (array) ( $pair['source'] ?? [] );
											$fb = (array) ( $pair['dest'] ?? [] );

											// The measures that separate the causes from each
											// other: size and hash say whether it is the same
											// value at all; quotes and backslashes catch an
											// extra round of escaping; non-ASCII catches a
											// re-encode; readable says which side is at fault.
											$measures = [
												'bytes'     => __( 'bytes', 'fw' ),
												'sha1'      => __( 'hash', 'fw' ),
												'quotes'    => __( 'double quotes', 'fw' ),
												'backslash' => __( 'backslashes', 'fw' ),
												'newlines'  => __( 'newlines', 'fw' ),
												'non_ascii' => __( 'non-ASCII bytes', 'fw' ),
												'fails_at'  => __( 'breaks at byte', 'fw' ),
											];
											$first = true;
											?>
											<?php foreach ( $measures as $key => $label ) : ?>
												<?php
												$va = $fa[ $key ] ?? null;
												$vb = $fb[ $key ] ?? null;

												if ( null === $va && null === $vb ) {
													continue;
												}

												if ( 'sha1' === $key ) {
													$va = null === $va ? null : substr( (string) $va, 0, 12 );
													$vb = null === $vb ? null : substr( (string) $vb, 0, 12 );
												}

												$differs = (string) $va !== (string) $vb;
												?>
												<tr<?php echo $differs ? ' style="background:#fcf0f1"' : ''; ?>>
													<td>
														<?php if ( $first ) : ?>
															<code style="font-size:11px"><?php echo esc_html( $name ); ?></code>
														<?php endif; ?>
													</td>
													<td><?php echo esc_html( $label ); ?></td>
													<td><?php echo esc_html( is_numeric( $va ) ? number_format( (float) $va ) : (string) $va ); ?></td>
													<td>
														<?php
														echo empty( $fb )
															? '<em>' . esc_html__( 'not reported', 'fw' ) . '</em>'
															: esc_html( is_numeric( $vb ) ? number_format( (float) $vb ) : (string) $vb );
														?>
													</td>
												</tr>
												<?php $first = false; ?>
											<?php endforeach; ?>

											<?php if ( ! empty( $fb['context'] ) ) : ?>
												<tr>
													<td></td>
													<td><?php esc_html_e( 'around the break', 'fw' ); ?></td>
													<td colspan="2">
														<code style="font-size:11px;white-space:pre-wrap;word-break:break-all"><?php echo esc_html( $fb['context'] ); ?></code>
													</td>
												</tr>
											<?php endif; ?>
										<?php endforeach; ?>
									</tbody>
								</table>

								<p class="description" style="max-width:56em;margin-top:.6em">
									<?php
									esc_html_e(
										'Read the rows that differ. Same hash on both sides means the value arrived intact and something else is wrong. More backslashes or quotes on the destination means it was escaped an extra time. More non-ASCII bytes means it was re-encoded. Fewer bytes means it was truncated. A different hash with the same size means it is simply a different value — usually an older one that was never replaced.',
										'fw'
									);
									?>
								</p>
							<?php endif; ?>

								<?php if ( ! empty( $dst['options_unreadable'] ) ) : ?>

									<p class="description" style="max-width:56em;margin-top:.8em">

										<strong style="color:#b32d2e">

											<?php

											printf(

												/* translators: %d: number of options. */

												esc_html__( '%d option(s) on the destination no longer unserialize.', 'fw' ),

												(int) $dst['options_unreadable']

											);

											?>

										</strong>

										<?php echo esc_html( implode( ', ', (array) ( $dst['unreadable_eg'] ?? [] ) ) ); ?>

									</p>

								<?php endif; ?>

							</div>

						<?php endif; ?>

						<?php $diag = get_option( FW_Extension_Site_Migration::DIAGNOSTIC_OPTION, null ); ?>
						<?php if ( is_array( $diag ) && ! empty( $diag['results'] ) ) : ?>
							<div style="margin-top:1.4em">
								<h4 style="margin:0 0 .4em">
									<?php esc_html_e( 'Connection test', 'fw' ); ?>
									<span class="description" style="font-weight:400">
										&mdash;
										<?php
										printf(
											/* translators: 1: destination URL, 2: human time diff. */
											esc_html__( '%1$s, %2$s ago', 'fw' ),
											esc_html( $diag['url'] ?? '' ),
											esc_html( human_time_diff( (int) ( $diag['at'] ?? time() ) ) )
										);
										?>
									</span>
								</h4>

								<?php
								$here  = fw_ext( 'site-migration' )->manifest->get_version();
								$ran   = (string) ( $diag['version'] ?? '' );
								$there = (string) ( $diag['dst_version'] ?? '' );
								?>

								<p class="description" style="max-width:52em;margin:0 0 .8em">
									<?php
									printf(
										/* translators: 1: this site's version, 2: the destination's. */
										esc_html__( 'This site is running %1$s. The destination answered as %2$s.', 'fw' ),
										esc_html( $ran ? $ran : $here ),
										esc_html( $there ? $there : __( 'an unknown version', 'fw' ) )
									);
									?>

									<?php if ( $ran && $ran !== $here ) : ?>
										<br>
										<strong>
											<?php
											printf(
												/* translators: %s: current version. */
												esc_html__( 'These results were produced by version %1$s and are out of date — this site now runs %2$s. Reload with a hard refresh and run the test again.', 'fw' ),
												esc_html( $ran ),
												esc_html( $here )
											);
											?>
										</strong>
									<?php elseif ( $there && $here && version_compare( $there, $here, '<' ) ) : ?>
										<br>
										<strong>
											<?php
											esc_html_e(
												'The destination is running an older version. Fixes that apply to the receiving end — including the limits measured below — do not take effect until the plugin is updated there too.',
												'fw'
											);
											?>
										</strong>
									<?php endif; ?>
								</p>

								<table class="widefat striped" style="max-width:52em">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Payload', 'fw' ); ?></th>
											<th><?php esc_html_e( 'Round trip', 'fw' ); ?></th>
											<th><?php esc_html_e( 'Destination', 'fw' ); ?></th>
											<th><?php esc_html_e( 'Wire', 'fw' ); ?></th>
											<th><?php esc_html_e( 'Effective upload', 'fw' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $diag['results'] as $row ) : ?>
											<tr>
												<td>
													<?php
													echo esc_html(
														0 === (int) $row['size']
															? __( 'empty (latency)', 'fw' )
															: size_format( (int) $row['size'] )
													);
													?>
												</td>
												<?php if ( ! empty( $row['error'] ) ) : ?>
													<td colspan="4"><?php echo esc_html( $row['error'] ); ?></td>
												<?php else : ?>
													<td><?php echo esc_html( number_format( (int) $row['total_ms'] ) ); ?> ms</td>
													<td><?php echo esc_html( number_format( (int) $row['remote_ms'] ) ); ?> ms
														<?php if ( ! empty( $row['db_ms'] ) ) : ?>
															<span class="description">(<?php echo esc_html( (int) $row['db_ms'] ); ?> ms MySQL)</span>
														<?php endif; ?>
													</td>
													<td><?php echo esc_html( number_format( (int) $row['wire_ms'] ) ); ?> ms</td>
													<td>
														<?php
														$wire = max( 1, (int) $row['wire_ms'] );
														echo (int) $row['size'] > 0
															? esc_html( size_format( (int) $row['sent'] / ( $wire / 1000 ) ) . '/s' )
															: '&mdash;';
														?>
													</td>
												<?php endif; ?>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>

								<?php if ( ! empty( $diag['parallel'] ) ) : ?>
								<h4 style="margin:1.4em 0 .4em">
									<?php esc_html_e( 'One connection, or several?', 'fw' ); ?>
								</h4>

								<table class="widefat striped" style="max-width:52em">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Connections', 'fw' ); ?></th>
											<th><?php esc_html_e( 'Total sent', 'fw' ); ?></th>
											<th><?php esc_html_e( 'Wall time', 'fw' ); ?></th>
											<th><?php esc_html_e( 'Throughput', 'fw' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php
										$base_rate = 0;

										foreach ( $diag['parallel'] as $row ) :
											?>
											<tr>
												<td><?php echo esc_html( (int) $row['streams'] ); ?></td>
												<?php if ( ! empty( $row['error'] ) ) : ?>
													<td colspan="3"><?php echo esc_html( $row['error'] ); ?></td>
												<?php else : ?>
													<?php
													$secs = max( 0.001, (int) $row['total_ms'] / 1000 );
													$rate = (int) $row['sent'] / $secs;

													if ( 1 === (int) $row['streams'] ) {
														$base_rate = $rate;
													}
													?>
													<td><?php echo esc_html( size_format( (int) $row['sent'] ) ); ?></td>
													<td><?php echo esc_html( number_format( (int) $row['total_ms'] ) ); ?> ms</td>
													<td>
														<?php echo esc_html( size_format( $rate ) . '/s' ); ?>
														<?php if ( $base_rate > 0 && (int) $row['streams'] > 1 ) : ?>
															<span class="description">
																(<?php echo esc_html( number_format( $rate / $base_rate, 1 ) ); ?>&times;)
															</span>
														<?php endif; ?>
													</td>
												<?php endif; ?>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>

								<p class="description" style="max-width:52em;margin-top:.6em">
									<?php
									esc_html_e(
										'Each row sends the same amount per connection, so if the link were already full the throughput column would stay flat and wall time would grow in step. Throughput that climbs with the connection count means the opposite: one connection was leaving the link mostly idle waiting for round trips, and the migration can go that many times faster by sending in parallel.',
										'fw'
									);
									?>
								</p>
							<?php endif; ?>

							<?php if ( ! empty( $diag['failed_at'] ) ) : ?>
									<p class="description" style="max-width:52em;margin-top:.6em">
										<strong>
											<?php
											printf(
												/* translators: 1: failing size, 2: largest size that worked. */
												esc_html__( 'The destination could not handle a %1$s request. The largest it accepted was %2$s.', 'fw' ),
												esc_html( size_format( (int) $diag['failed_at'] ) ),
												esc_html( size_format( (int) ( $diag['safe'] ?? 0 ) ) )
											);
											?>
										</strong>
										<?php
										printf(
											/* translators: %s: recommended memory_limit. */
											esc_html__( 'That is almost always memory_limit on the destination — PHP runs out while reading the request, before any plugin code runs, so there is no error for it to report. %s or more is worth asking a host for on a live site; below that the migration still works, it is just held back by how much each request can carry.', 'fw' ),
											esc_html( size_format( FW_Extension_Site_Migration::RECOMMENDED_MEMORY ) )
										);
										?>
									</p>

									<p class="description" style="max-width:52em;margin-top:.6em">
										<?php
										printf(
											/* translators: %s: batch size. */
											esc_html__( 'Database batches will be built up to %s of SQL. That is larger than the figure above because this test sends random bytes, which do not compress, while real batches are compressed before sending — usually to around a tenth of their size.', 'fw' ),
											esc_html( size_format( FW_SM_Runner::safe_payload_size() ) )
										);
										?>
									</p>
								<?php endif; ?>

								<p class="description" style="max-width:52em;margin-top:.6em">
									<?php
									esc_html_e(
										'The empty payload measures pure latency — the cost every request pays before carrying anything. If round trips stay slow as the payload grows, the link is the limit; if the destination column dominates, the far end is; if effective upload is far below your connection, something between the two is throttling.',
										'fw'
									);
									?>
								</p>
							</div>
						<?php endif; ?>

					</div>
				</div>
			</div>

		<?php endif; ?>

	<?php else : ?>

		<p class="description" style="max-width:46em;margin:0 0 1.2em">
			<?php
			echo esc_html(
				$is_network
					? __( 'This network is the destination — receive a migration from another WordPress install.', 'fw' )
					: __( 'This site is the destination — receive a migration from another WordPress install.', 'fw' )
			);
			?>
		</p>

		<div class="metabox-holder">
			<div class="postbox">
				<div class="postbox-header">
					<h2 class="hndle"><span><?php esc_html_e( 'Connection information', 'fw' ); ?></span></h2>
				</div>
				<div class="inside">

					<?php if ( $is_network ) : ?>
						<div class="notice notice-info inline" style="margin:0 0 1.2em">
							<p style="max-width:46em">
								<?php
								esc_html_e(
									'This is a network, so it can receive either a whole network — replacing every site on it — or a single site, which is added as a new site on this network. The source chooses which.',
									'fw'
								);
								?>
							</p>
						</div>
					<?php endif; ?>

					<?php $incoming = FW_SM_Receiver::get_incoming(); ?>
					<?php if ( null !== $incoming ) : ?>
						<div class="notice notice-warning inline" style="margin:0 0 1.2em">
							<p style="max-width:46em">
								<?php
								printf(
									/* translators: 1: source site URL, 2: human-readable duration. */
									esc_html__( 'This site is holding a migration from %1$s, last active %2$s ago. While it is held, other migrations here are refused. If that source is no longer running, clear it.', 'fw' ),
									'<strong>' . esc_html( $incoming['source_url'] ?? __( 'an unknown site', 'fw' ) ) . '</strong>',
									esc_html( human_time_diff( (int) ( $incoming['seen_at'] ?? time() ) ) )
								);
								?>
							</p>
							<p>
								<?php
								$action_form(
									FW_Extension_Site_Migration::ACTION_CLEAR_IN,
									__( 'Clear it', 'fw' ),
									'button',
									__( 'Clear the incoming migration? Nothing on this site changes — only the half-received copy is discarded.', 'fw' )
								);
								?>
							</p>
						</div>
					<?php endif; ?>

					<p class="description" style="max-width:46em">
						<?php
						esc_html_e(
							'Copy the line below and paste it into the Source tab of the site you are migrating from. It is a password for this site — send it the way you would send a password.',
							'fw'
						);
						?>
					</p>

					<p>
						<input type="text" readonly id="fw-sm-key" class="large-text code"
						       style="font-family:Consolas,Monaco,monospace"
						       value="<?php echo esc_attr( FW_SM_Connection::connection_string() ); ?>"
						       onfocus="this.select()">
					</p>

					<p>
						<button type="button" class="button" id="fw-sm-copy"><?php esc_html_e( 'Copy to clipboard', 'fw' ); ?></button>
						&nbsp;
						<?php
						$action_form(
							FW_Extension_Site_Migration::ACTION_RESET_KEY,
							__( 'Reset key', 'fw' ),
							'button',
							__( 'Generate a new key? Any site already connected to this one will stop working until it is given the new connection information.', 'fw' )
						);
						?>
					</p>

					<?php $set_at = FW_SM_Connection::key_set_at(); ?>
					<?php if ( $set_at ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: human-readable time difference, e.g. "2 days". */
								esc_html__( 'This key was generated %s ago.', 'fw' ),
								esc_html( human_time_diff( $set_at ) )
							);
							?>
						</p>
					<?php endif; ?>

					<script>
					( function () {
						var btn = document.getElementById( 'fw-sm-copy' );
						var fld = document.getElementById( 'fw-sm-key' );
						if ( ! btn || ! fld ) { return; }
						btn.addEventListener( 'click', function () {
							fld.select();
							if ( navigator.clipboard ) {
								navigator.clipboard.writeText( fld.value );
							} else {
								document.execCommand( 'copy' );
							}
							btn.textContent = <?php echo wp_json_encode( __( 'Copied', 'fw' ) ); ?>;
						} );
					}() );
					</script>

				</div>
			</div>
		</div>

	<?php endif; ?>

</div>
