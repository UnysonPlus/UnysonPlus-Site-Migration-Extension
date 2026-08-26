<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Site Migration screen.
 *
 * Two states: a form when nothing is running, and a progress panel when
 * something is. Native WordPress chrome throughout — nav-tabs and postboxes,
 * not a bespoke UI.
 *
 * @var array|null                     $state The current migration, if any.
 * @var FW_Extension_Site_Migration    $this
 */

$running  = null !== $state && 'running' === $state['status'];
$finished = null !== $state && 'running' !== $state['status'];
$notice   = get_transient( 'fw_sm_notice' );

if ( $notice ) {
	delete_transient( 'fw_sm_notice' );
}
?>
<div class="wrap fw-ext-site-migration">

	<h1><?php esc_html_e( 'Site Migration', 'fw' ); ?></h1>

	<p class="description" style="max-width:44em;margin:.6em 0 1.4em">
		<?php
		esc_html_e(
			'Package this site — database and files — into a single archive you can download and restore onto another WordPress install. The job runs in the background in resumable slices, so you can close this tab and come back to it.',
			'fw'
		);
		?>
	</p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['fw_sm_error'] ) && 'no-stages' === $_GET['fw_sm_error'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Choose at least one thing to include before starting.', 'fw' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! FW_Extension_Site_Migration::is_supported() ) : ?>
		<div class="notice notice-warning">
			<p><?php echo esc_html( FW_Extension_Site_Migration::unsupported_reason() ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! class_exists( 'ZipArchive' ) ) : ?>
		<div class="notice notice-error">
			<p>
				<?php
				esc_html_e(
					'The PHP zip extension is not enabled on this server, so archives cannot be created. Ask your host to enable ext-zip.',
					'fw'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $running ) : ?>

		<div class="metabox-holder">
			<div class="postbox">
				<div class="postbox-header">
					<h2 class="hndle"><span><?php esc_html_e( 'Migration in progress', 'fw' ); ?></span></h2>
				</div>
				<div class="inside">

					<div id="fw-sm-progress-wrap" style="margin:.6em 0 1em">
						<div style="background:#f0f0f1;border-radius:3px;height:22px;overflow:hidden">
							<div id="fw-sm-bar"
							     style="background:#2271b1;height:100%;width:0;transition:width .4s ease"></div>
						</div>
						<p style="margin:.6em 0 0">
							<strong id="fw-sm-percent">0%</strong>
							<span id="fw-sm-bytes" class="description"></span>
						</p>
					</div>

					<p id="fw-sm-phase" class="description">
						<?php esc_html_e( 'Working out how much there is to copy…', 'fw' ); ?>
					</p>

					<ul id="fw-sm-log"
					    style="margin:1em 0 0;padding:.8em 1em;background:#f6f7f7;border-radius:3px;max-height:180px;overflow:auto;font-family:Consolas,Monaco,monospace;font-size:12px"></ul>

					<p style="margin-top:1.2em">
						<a href="<?php echo esc_url(
							wp_nonce_url(
								admin_url( 'admin-post.php?action=' . FW_Extension_Site_Migration::ACTION_CANCEL ),
								FW_Extension_Site_Migration::ACTION_CANCEL
							)
						); ?>" class="button button-secondary">
							<?php esc_html_e( 'Cancel migration', 'fw' ); ?>
						</a>
					</p>

				</div>
			</div>
		</div>

		<script>
		( function () {
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( FW_Extension_Site_Migration::ACTION_STATUS ) ); ?>;
			var bar     = document.getElementById( 'fw-sm-bar' );
			var percent = document.getElementById( 'fw-sm-percent' );
			var bytes   = document.getElementById( 'fw-sm-bytes' );
			var phase   = document.getElementById( 'fw-sm-phase' );
			var log     = document.getElementById( 'fw-sm-log' );

			function poll() {
				var url = ajaxUrl
					+ '?action=<?php echo esc_js( FW_Extension_Site_Migration::ACTION_STATUS ); ?>'
					+ '&_wpnonce=' + encodeURIComponent( nonce );

				fetch( url, { credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( ! res || ! res.success ) { return; }

						var d = res.data;

						// A finished migration means the panel is stale — reload
						// so the completed state renders server-side.
						if ( d.status !== 'running' ) {
							window.location.reload();
							return;
						}

						bar.style.width = d.percent + '%';
						percent.textContent = d.percent + '%';
						bytes.textContent = ' — ' + d.processed + ' of ' + d.target;

						phase.textContent = d.initialized
							? <?php echo wp_json_encode( __( 'Copying…', 'fw' ) ); ?>
							: <?php echo wp_json_encode( __( 'Working out how much there is to copy…', 'fw' ) ); ?>;

						log.innerHTML = '';
						( d.log || [] ).forEach( function ( line ) {
							var li = document.createElement( 'li' );
							li.textContent = line;
							log.appendChild( li );
						} );

						setTimeout( poll, 3000 );
					} )
					.catch( function () {
						// A failed poll is not a failed migration — the runner is
						// a separate process. Back off and try again.
						setTimeout( poll, 8000 );
					} );
			}

			poll();
		}() );
		</script>

	<?php elseif ( $finished ) : ?>

		<?php
		$is_complete = 'complete' === $state['status'];
		$archive     = ! empty( $state['options']['archive_path'] )
			? basename( $state['options']['archive_path'] )
			: '';
		$exists      = '' !== $archive && file_exists( $state['options']['archive_path'] );
		?>

		<div class="metabox-holder">
			<div class="postbox">
				<div class="postbox-header">
					<h2 class="hndle">
						<span>
							<?php
							echo esc_html(
								$is_complete
									? __( 'Migration complete', 'fw' )
									: __( 'Migration stopped', 'fw' )
							);
							?>
						</span>
					</h2>
				</div>
				<div class="inside">

					<?php if ( $is_complete && $exists ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: archive file size. */
								esc_html__( 'Your archive is ready — %s.', 'fw' ),
								esc_html( size_format( filesize( $state['options']['archive_path'] ) ) )
							);
							?>
						</p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url(
								wp_nonce_url(
									admin_url(
										'admin-post.php?action=' . FW_Extension_Site_Migration::ACTION_DOWNLOAD
										. '&file=' . rawurlencode( $archive )
									),
									FW_Extension_Site_Migration::ACTION_DOWNLOAD
								)
							); ?>">
								<?php esc_html_e( 'Download archive', 'fw' ); ?>
							</a>
						</p>
						<p class="description" style="max-width:44em">
							<?php
							esc_html_e(
								'Keep this file somewhere private. It contains your whole database and every uploaded file, so anyone who has it has your site.',
								'fw'
							);
							?>
						</p>
					<?php elseif ( ! $is_complete ) : ?>
						<p><?php echo esc_html( $state['error'] ? $state['error'] : __( 'The migration did not finish.', 'fw' ) ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $state['log'] ) ) : ?>
						<ul style="margin:1em 0 0;padding:.8em 1em;background:#f6f7f7;border-radius:3px;max-height:200px;overflow:auto;font-family:Consolas,Monaco,monospace;font-size:12px">
							<?php foreach ( array_slice( $state['log'], -20 ) as $entry ) : ?>
								<li><?php echo esc_html( $entry['message'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<p style="margin-top:1.2em">
						<a href="<?php echo esc_url(
							wp_nonce_url(
								admin_url( 'admin-post.php?action=' . FW_Extension_Site_Migration::ACTION_DISMISS ),
								FW_Extension_Site_Migration::ACTION_DISMISS
							)
						); ?>" class="button">
							<?php esc_html_e( 'Start another migration', 'fw' ); ?>
						</a>
					</p>

				</div>
			</div>
		</div>

	<?php elseif ( ! FW_Extension_Site_Migration::is_supported() ) : ?>

		<?php // Form deliberately withheld: see is_supported(). ?>

	<?php else : ?>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) && 'import' === $_GET['tab'] ? 'import' : 'export';
		?>

		<h2 class="nav-tab-wrapper" style="margin:.4em 0 1.4em">
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'export', FW_Extension_Site_Migration::get_page_url() ) ); ?>"
			   class="nav-tab<?php echo 'export' === $tab ? ' nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Export', 'fw' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'import', FW_Extension_Site_Migration::get_page_url() ) ); ?>"
			   class="nav-tab<?php echo 'import' === $tab ? ' nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Import', 'fw' ); ?>
			</a>
		</h2>

		<?php if ( 'export' === $tab ) : ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( FW_Extension_Site_Migration::ACTION_START ); ?>">
			<?php wp_nonce_field( FW_Extension_Site_Migration::ACTION_START ); ?>

			<div class="metabox-holder">

				<div class="postbox">
					<div class="postbox-header">
						<h2 class="hndle"><span><?php esc_html_e( 'What to include', 'fw' ); ?></span></h2>
					</div>
					<div class="inside">
						<?php foreach ( FW_SM_Stage::selectable() as $stage ) : ?>
							<p style="margin:.4em 0">
								<label>
									<input type="checkbox" name="stages[]"
									       value="<?php echo esc_attr( $stage ); ?>" checked>
									<?php echo esc_html( FW_SM_Stage::label( $stage ) ); ?>
								</label>
							</p>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="postbox">
					<div class="postbox-header">
						<h2 class="hndle"><span><?php esc_html_e( 'Destination URL', 'fw' ); ?></span></h2>
					</div>
					<div class="inside">
						<p>
							<input type="url" class="regular-text" name="target_url"
							       placeholder="https://example.com"
							       value="">
						</p>
						<p class="description" style="max-width:44em">
							<?php
							esc_html_e(
								'Where this archive is going. URLs are rewritten as the archive is built — serialized and JSON data included — so setting it here is the reliable way to move a site to a new address. Leave it empty only if the destination has the same URL as this site.',
								'fw'
							);
							?>
						</p>
					</div>
				</div>

			</div>

			<?php submit_button( __( 'Start export', 'fw' ), 'primary', 'submit', true ); ?>
		</form>

		<?php else : ?>

		<div class="notice notice-warning inline" style="margin:0 0 1.2em">
			<p style="max-width:44em">
				<strong><?php esc_html_e( 'Importing replaces this site.', 'fw' ); ?></strong>
				<?php
				esc_html_e(
					'Every table in the archive replaces the matching table here, and archived files overwrite the ones on disk. Your login and the settings for this extension are preserved, and the swap only happens once the whole archive has loaded — so a failed import leaves this site untouched — but a successful one is not reversible. Take a backup first.',
					'fw'
				);
				?>
			</p>
		</div>

		<form method="post" enctype="multipart/form-data"
		      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( FW_Extension_Site_Migration::ACTION_IMPORT ); ?>">
			<?php wp_nonce_field( FW_Extension_Site_Migration::ACTION_IMPORT ); ?>

			<div class="metabox-holder">
				<div class="postbox">
					<div class="postbox-header">
						<h2 class="hndle"><span><?php esc_html_e( 'Archive to import', 'fw' ); ?></span></h2>
					</div>
					<div class="inside">
						<p>
							<input type="file" name="archive" accept=".zip,application/zip" required>
						</p>
						<p class="description" style="max-width:44em">
							<?php
							printf(
								/* translators: %s: formatted maximum upload size. */
								esc_html__(
									'A .zip produced by this extension. The manifest is checked before anything is touched. This server accepts uploads up to %s — for a larger archive, place the file in the migration folder and import it from there.',
									'fw'
								),
								esc_html( size_format( wp_max_upload_size() ) )
							);
							?>
						</p>
						<p class="description" style="max-width:44em">
							<?php
							esc_html_e(
								'URLs are rewritten when an archive is built, not when it is restored. If the archive was exported without the address of this site as its destination, its content will still point at the old one.',
								'fw'
							);
							?>
						</p>
					</div>
				</div>
			</div>

			<?php submit_button( __( 'Import archive', 'fw' ), 'primary', 'submit', true ); ?>
		</form>

		<?php endif; ?>

	<?php endif; ?>

</div>
