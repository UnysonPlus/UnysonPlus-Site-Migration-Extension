<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = [];

$manifest['name']        = __( 'Site Migration', 'fw' );
$manifest['slug']        = 'unysonplus-site-migration';
$manifest['description'] = __(
	'Moves a whole WordPress site to another install. The destination shows a connection key, you paste it into the source, and the source pushes everything — database, uploads, themes and plugins — in resumable background slices that survive PHP timeouts and a closed browser. URLs are rewritten with a serialization-aware replacer, and the destination only swaps the new data in once the migration finishes.',
	'fw'
);

$manifest['version']    = '1.0.26';
$manifest['display']    = true;
$manifest['standalone'] = true;
$manifest['thumbnail']  = 'thumbnail.svg';

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Site-Migration-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-Site-Migration-Extension';
$manifest['github_branch'] = 'master';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';

/**
 * Changelog
 * -----------------------------------------------------------------------------
 * 1.0.26 - Automatic leftover-staged-file detection, before and after every
 *          migration. A replacement is written beside its live file as a
 *          .fwsm-new and only renamed into place at finalize; a run that is
 *          cancelled or fails before finalize leaves those behind, and since
 *          swap_staged_files only promotes the current run's list, they were
 *          never cleaned up — so they accumulated across attempts and silently
 *          hid that a file's update never went live (WordPress keeps running
 *          the .php beside the .fwsm-new). The destination now sweeps the whole
 *          content tree for orphaned .fwsm-new/.fwsm-part at the START of every
 *          migration, so each begins from a clean slate, and re-scans at the
 *          END, reporting in the log whether any replacement was left
 *          un-promoted. Both are automatic; there is nothing to click.
 *
 * 1.0.13 - Quick migration, a connection test, and a side-by-side comparison
 *          with the destination.
 *
 *          Quick migration sends only the files the destination does not
 *          already hold, byte for byte, with the database as an optional
 *          whole. It exists for pushing a local site to a live one
 *          repeatedly, where almost nothing has changed and re-sending a
 *          themes folder is the entire cost.
 *
 *          "Test connection speed" sends payloads of increasing size and
 *          times each round trip against the destination's own reported
 *          processing time, so a slow migration can be attributed to the
 *          link, the far end, or this code rather than guessed at. It also
 *          measures whether several connections move more than one, and
 *          the file stage opens exactly as many as that measurement
 *          justified — a long round trip leaves a single connection idle
 *          waiting for acknowledgements, and no increase in request size
 *          gets past it.
 *
 *          "Compare with destination" runs the same snapshot on both sites
 *          and shows them side by side: theme, theme id, which Theme
 *          Settings keys exist and whether they are readable, options that
 *          no longer unserialize, and any table whose row count differs.
 *          A migration can succeed by every measure it already takes and
 *          still leave a site showing defaults, and the difference between
 *          the two sides is the only thing that says so.

 * 1.0.0 - Initial release. Migrates a whole site to another WordPress install
 *         over HTTP. The destination displays a connection key; the user pastes
 *         it into the source; the source pushes everything. There is no stage
 *         selection and no archive file — a migration moves the site, and
 *         choosing pieces is what the Backups extension is for.
 *
 *         The job is expressed as an ordered list of stages that enqueue jobs
 *         into a custom table, processed a slice at a time by a background
 *         runner that respawns itself over a loopback request and is watched by
 *         a cron healthcheck. A PHP timeout costs one slice rather than the
 *         migration, and closing the browser costs nothing. Progress is
 *         denominated in bytes across every stage, so one progress bar honestly
 *         covers a 400 MB uploads folder and a 12 MB table alike.
 *
 *         Requests between sites are signed with HMAC-SHA256 over every field,
 *         sorted so both sides build the same input, and verified with
 *         hash_equals() so the key cannot be recovered by timing the comparison.
 *         A timestamp inside the signed payload bounds replay to a fifteen
 *         minute window. Receiving endpoints are registered nopriv because the
 *         source has no session on the destination; the signature is the only
 *         thing that authorises them, so every handler verifies before acting.
 *
 *         Nothing is written over live data. SQL loads into _fwsm_-prefixed
 *         staging tables and is renamed into place only by the finalize call, so
 *         an interrupted or abandoned migration leaves the destination exactly
 *         as it was. Incoming file paths are resolved against their stage root
 *         and refused — not sanitized — if they escape it.
 *
 *         Search and replace is serialization-aware: values are unserialized,
 *         walked and re-serialized so length prefixes stay correct, with the
 *         same treatment for JSON and a JSON-escaped variant of every pair. A
 *         plain SQL REPLACE() silently destroys page-builder and widget data.
 *         Serialized objects are refused, and refusing to parse means returning
 *         the value untouched rather than falling through to a string replace.
 *
 *         Destination values that must survive are carved out and restored: the
 *         active plugin list, the site address, and the prefix-dependent user
 *         capability keys that would otherwise lock every user out when source
 *         and destination table prefixes differ.
 *
 *         Networks are supported in three shapes, and the difference between
 *         them is almost entirely about which tables travel and what they are
 *         called on arrival. A whole network replaces a whole network, carrying
 *         the blog register and network options and repairing every site's
 *         recorded domain and path afterwards. One site can be promoted out of
 *         a network onto a single install, which renames its wp_<id>_ tables
 *         down to the bare prefix and moves its uploads out of sites/<id>/. And
 *         a single site can be folded into an existing network, which creates
 *         the receiving site, renames tables up into its blog prefix, moves
 *         uploads the other way and grants the network administrators a role on
 *         it. Users travel when promoting a site out (a site nobody can log into
 *         is not a migration) and stay behind when folding one in (merging user
 *         rows into a network's shared table would collide on IDs).
 *
 *         Turning a single site into a network, or a network into a single site,
 *         is refused. Both would require rewriting wp-config.php on the
 *         destination, and a migration tool that edits the destination's
 *         wp-config is one that can leave it unbootable.
 */
