<?php
// A whole-network migration must carry every subsite's uploads.
//
// Blog 1's uploads root physically contains every other site's media under
// sites/<id>/. The scanner excludes */sites/* so a SINGLE-site migration cannot
// drag the whole network along. But a whole-NETWORK migration is meant to carry
// exactly that, and blog 1 stays the source blog (id 1) — so the exclusion has
// to be lifted for it, or every subsite arrives with its images missing.
$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; printf( "  FAIL  %s (got %s, want %s)\n", $label, var_export( $got, true ), var_export( $want, true ) ); }
}

const MODE_SINGLE  = 'single';
const MODE_NETWORK = 'network';

// The decision the runner makes for the UPLOADS stage, lifted verbatim.
function carry_all_sites( $source_blog_id, $mode ) {
	return (int) $source_blog_id > 1 || MODE_NETWORK === $mode;
}

// What survives in the exclude list after the decision is applied.
function uploads_excludes( $source_blog_id, $mode ) {
	$excludes = [ '*/sites/*', '*/backup*/*', '*/cache/*' ];
	if ( carry_all_sites( $source_blog_id, $mode ) ) {
		$excludes = array_values( array_filter( $excludes, static function ( $p ) { return '*/sites/*' !== $p; } ) );
	}
	return $excludes;
}

echo "-- when the sites/ exclusion is lifted --\n";
check( 'a whole-network migration lifts it', carry_all_sites( 1, MODE_NETWORK ), true );
check( 'a subsite migration lifts it (root is already sites/<id>)', carry_all_sites( 15, MODE_SINGLE ), true );
check( 'a plain single-site migration keeps it', carry_all_sites( 1, MODE_SINGLE ), false );
check( 'blog id 0 on a network still lifts it', carry_all_sites( 0, MODE_NETWORK ), true );

echo "\n-- the exclude list that reaches the scanner --\n";
check( 'network: sites/ is NOT excluded, so subsite media travels',
	in_array( '*/sites/*', uploads_excludes( 1, MODE_NETWORK ), true ), false );
check( 'network: cache/backup are still excluded',
	in_array( '*/cache/*', uploads_excludes( 1, MODE_NETWORK ), true ), true );
check( 'single site: sites/ stays excluded so a network is not dragged in',
	in_array( '*/sites/*', uploads_excludes( 1, MODE_SINGLE ), true ), true );

echo "\n-- the concrete case that was reported --\n";
// pinky-bites is blog 15; its logo lives under sites/15/. On a network push it
// must not match any surviving exclude pattern.
$relative = '/sites/15/2026/07/pinky-bites-logo.png';
$excluded = false;
foreach ( uploads_excludes( 1, MODE_NETWORK ) as $pattern ) {
	if ( fnmatch( $pattern, $relative ) ) { $excluded = true; break; }
}
check( 'the missing subsite logo is now included on a network migration', $excluded, false );

echo "\n========================================\n";
echo "  $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
