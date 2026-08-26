<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = [];

$manifest['name']        = __( 'Site Migration', 'fw' );
$manifest['slug']        = 'unysonplus-site-migration';
$manifest['description'] = __(
	'Packages a WordPress site — database and files — into a single portable archive, and restores one onto another install. The whole job runs in the background in resumable slices, so it survives PHP timeouts and a closed browser, and URLs are rewritten with a serialization-aware replacer that leaves serialized and JSON data intact.',
	'fw'
);

$manifest['version']    = '1.0.0';
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
 * 1.0.0 - Initial release. Exports a site to a portable .zip archive and imports
 *         one back, with the whole job expressed as an ordered list of stages
 *         (database, uploads, themes, plugins, mu-plugins, other files) that are
 *         enqueued as individual jobs and processed a slice at a time by a
 *         self-respawning background runner, so neither a PHP timeout nor a
 *         closed browser ends a migration. Progress is denominated in bytes
 *         across every stage, so one honest progress bar covers a 400 MB uploads
 *         folder and a 12 MB table alike.
 *
 *         Database export walks tables by primary key rather than LIMIT/OFFSET so
 *         a multi-million-row table resumes exactly where it stopped without the
 *         deep-offset penalty, and the importer loads rows into temporary tables
 *         that are only renamed into place once the whole import has succeeded —
 *         an interrupted import therefore leaves the destination site intact and
 *         serving. Foreign-key constraints are stripped from CREATE statements
 *         and re-applied as deferred ALTERs at the end, so table order is
 *         irrelevant.
 *
 *         Search and replace is recursive and serialization-aware: values are
 *         unserialized, walked, and re-serialized so length prefixes stay
 *         correct, with the same treatment for JSON payloads and a JSON-escaped
 *         variant of every pair so URLs embedded in escaped-slash JSON are caught
 *         too. This is the step a plain SQL REPLACE() gets wrong and is why
 *         hand-rolled migrations corrupt page-builder and widget data.
 *
 *         Destination options that must survive an import are carved out and
 *         restored afterwards — this extension's own settings, the active plugin
 *         list, and the prefix-dependent user capability keys that would
 *         otherwise lock every user out when source and destination table
 *         prefixes differ.
 *
 *         Ships an optional compatibility mu-plugin that trims the active plugin
 *         list to a whitelist and swaps in a stub theme for the duration of a
 *         migration request, so a page builder, security plugin or caching layer
 *         cannot interfere with, or blow the memory budget of, a long-running
 *         slice.
 *
 *         Import unpacks an archive in resumable slices before touching
 *         anything, validating every entry path as it goes: absolute paths and
 *         any name that escapes the working directory once normalized are
 *         refused rather than sanitized, because a name that needed sanitizing
 *         was never a name to trust. Entries are streamed to disk rather than
 *         read whole, so an archive containing a file larger than the memory
 *         limit does not end the import.
 *
 *         Multisite is refused outright for now, and deliberately. Table
 *         discovery matches on the site prefix, and because esc_like() renders
 *         the underscore literal rather than as a wildcard, that pattern also
 *         matches every subsite's tables — so on a network the database stage
 *         would collect the whole network while the uploads stage collected a
 *         single subsite, and the archive would describe no site that exists.
 *         The required capability is raised to manage_network_options on
 *         multisite regardless, so the gate cannot be walked around by a site
 *         administrator who holds export.
 */
