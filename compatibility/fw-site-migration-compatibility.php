<?php
/*
Plugin Name: UnysonPlus Site Migration Compatibility
Description: Keeps other plugins and the active theme out of the way during a migration request only. Installed and removed by the Site Migration extension.
Author: UnysonPlus
Version: 1.0.0
*/

defined( 'ABSPATH' ) || exit;

/**
 * Why this exists.
 *
 * A migration slice is the most expensive request the site will ever serve, and
 * it is served with every other plugin loaded: a page builder, a security plugin
 * rewriting requests, a caching layer buffering output, an analytics plugin
 * opening its own connections. Any one of them can push the slice over the
 * memory limit; a caching layer can serve a stale response to the loopback
 * request and stall the chain outright.
 *
 * So for the duration of a migration request — and ONLY those requests — the
 * active plugin list is trimmed to a whitelist and the theme is pointed at a
 * stub. Normal page loads are completely untouched, which is what makes this
 * safe to leave installed.
 *
 * This file runs as a must-use plugin, which is to say before the plugins it
 * needs to filter. It cannot call anything from the extension, because nothing
 * is loaded yet — hence the deliberately self-contained checks below.
 */

if ( ! empty( $GLOBALS['fw_sm_compat_active'] ) ) {
	return;
}

/**
 * Is the current request one of ours?
 *
 * Kept narrow on purpose. Getting a false positive here means a real page load
 * renders with half its plugins missing, which is far worse than a migration
 * slice running with the full stack loaded.
 *
 * @return bool
 */
function fw_sm_compat_is_migration_request() {
	// The background runner's loopback endpoint.
	if ( isset( $_GET['action'] ) && 'fw_sm_run' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return true;
	}

	// WP-Cron firing the healthcheck.
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		$args = isset( $_GET['fw_sm'] ) ? sanitize_text_field( wp_unslash( $_GET['fw_sm'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'healthcheck' === $args ) {
			return true;
		}
	}

	return false;
}

if ( ! fw_sm_compat_is_migration_request() ) {
	return;
}

$GLOBALS['fw_sm_compat_active'] = true;

/**
 * The plugins that must stay loaded.
 *
 * UnysonPlus itself, obviously — the migration lives inside it. Anything else a
 * site genuinely needs can be added through the option below, which the
 * extension writes and this file reads without loading any framework code.
 *
 * @return string[]
 */
function fw_sm_compat_whitelist() {
	$whitelist = [
		'unysonplus/unysonplus.php',
	];

	$extra = get_option( 'fw_sm_compat_whitelist', [] );

	if ( is_array( $extra ) ) {
		$whitelist = array_merge( $whitelist, array_map( 'strval', $extra ) );
	}

	return $whitelist;
}

/**
 * Trim the active plugin list to the whitelist.
 *
 * @param mixed $plugins
 *
 * @return array
 */
function fw_sm_compat_filter_plugins( $plugins ) {
	if ( ! is_array( $plugins ) ) {
		return $plugins;
	}

	$whitelist = fw_sm_compat_whitelist();

	return array_values(
		array_filter(
			$plugins,
			static function ( $plugin ) use ( $whitelist ) {
				foreach ( $whitelist as $allowed ) {
					if ( $plugin === $allowed || false !== strpos( $plugin, $allowed ) ) {
						return true;
					}
				}

				return false;
			}
		)
	);
}

add_filter( 'option_active_plugins', 'fw_sm_compat_filter_plugins' );

/**
 * The network-activated equivalent, which is keyed by plugin file.
 *
 * @param mixed $plugins
 *
 * @return array
 */
function fw_sm_compat_filter_site_plugins( $plugins ) {
	if ( ! is_array( $plugins ) ) {
		return $plugins;
	}

	$whitelist = fw_sm_compat_whitelist();
	$kept      = [];

	foreach ( $plugins as $plugin => $time ) {
		foreach ( $whitelist as $allowed ) {
			if ( $plugin === $allowed || false !== strpos( $plugin, $allowed ) ) {
				$kept[ $plugin ] = $time;
				break;
			}
		}
	}

	return $kept;
}

add_filter( 'site_option_active_sitewide_plugins', 'fw_sm_compat_filter_site_plugins' );

/**
 * Point the theme at the bundled stub.
 *
 * A theme's functions.php can do anything at all — register post types, open
 * connections, enqueue a megabyte of code. None of it is wanted here, and a
 * migration request renders no front end, so a stub with an empty functions.php
 * is strictly better.
 *
 * @param string $dir
 *
 * @return string
 */
function fw_sm_compat_stub_theme( $dir ) {
	$stub = __DIR__ . '/stub-theme';

	return is_dir( $stub ) ? $stub : $dir;
}

add_filter( 'stylesheet_directory', 'fw_sm_compat_stub_theme' );
add_filter( 'template_directory', 'fw_sm_compat_stub_theme' );
