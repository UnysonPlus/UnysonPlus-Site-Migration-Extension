<?php
// A file the destination could not write must be retried, and must never let
// the prune delete the copy already sitting there.
//
// This is the failure that took a live site down: a bundled file failed to
// write, its queue job was cleared anyway because the source counted files
// PACKED rather than files WRITTEN, it was therefore absent from the received
// list, and the prune read that absence as "stale" and deleted the
// destination's existing good copy. The plugin then fataled on a missing
// require.
$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; printf( "  FAIL  %s (got %s, want %s)\n", $label, var_export( $got, true ), var_export( $want, true ) ); }
}

// --- the source's completion rule, as the runner now applies it ---
function jobs_to_clear( array $group, array $ids, array $result ) {
	$done  = (int) ( $result['sent_files'] ?? count( $group ) );
	$lost  = array_flip( (array) ( $result['skipped_paths'] ?? [] ) );
	$clear = [];

	for ( $j = 0; $j < $done; $j++ ) {
		if ( isset( $lost[ $group[ $j ]['path'] ?? '' ] ) ) {
			continue;
		}
		$clear[] = $ids[ $j ];
	}

	return $clear;
}

$group = [
	[ 'path' => 'unysonplus/framework/extensions/backups/class-fw-extension-backups.php' ],
	[ 'path' => 'unysonplus/framework/extensions/backups/includes/module/class--fw-ext-backups-module.php' ],
	[ 'path' => 'unysonplus/framework/extensions/backups/manifest.php' ],
];
$ids = [ 101, 102, 103 ];

echo "-- every file lands --\n";
check(
	'all three jobs are cleared',
	jobs_to_clear( $group, $ids, [ 'sent_files' => 3, 'written' => 3, 'skipped' => 0, 'skipped_paths' => [] ] ),
	[ 101, 102, 103 ]
);

echo "\n-- the exact file that took the site down fails to write --\n";
$result = [
	'sent_files'    => 3,
	'written'       => 2,
	'skipped'       => 1,
	'skipped_paths' => [ 'unysonplus/framework/extensions/backups/includes/module/class--fw-ext-backups-module.php' ],
];
check( 'its job is NOT cleared, so it will be retried', jobs_to_clear( $group, $ids, $result ), [ 101, 103 ] );

// The old rule, for contrast: it cleared everything and lost the file.
$old_clear = array_slice( $ids, 0, (int) $result['sent_files'] );
check( 'the old rule cleared it and lost the file', $old_clear, [ 101, 102, 103 ] );

echo "\n-- fewer packed than offered, and one of those skipped --\n";
$result = [ 'sent_files' => 2, 'written' => 1, 'skipped' => 1, 'skipped_paths' => [ $group[0]['path'] ] ];
check( 'only the written one is cleared', jobs_to_clear( $group, $ids, $result ), [ 102 ] );
check( 'the unpacked third is untouched', in_array( 103, jobs_to_clear( $group, $ids, $result ), true ), false );

echo "\n-- an older destination that reports no skipped_paths --\n";
// It cannot name them, so nothing is treated as lost; behaviour matches before.
check(
	'falls back to clearing what was packed',
	jobs_to_clear( $group, $ids, [ 'sent_files' => 3, 'written' => 3, 'skipped' => 0 ] ),
	[ 101, 102, 103 ]
);

// --- the prune guard, as the receiver now applies it ---
function may_prune( array $session ) {
	if ( ! empty( $session['quick'] ) ) { return false; }
	if ( ! empty( $session['skipped_files'] ) ) { return false; }
	return true;
}

echo "\n-- when the prune may run at all --\n";
check( 'a clean full migration prunes', may_prune( [ 'quick' => false ] ), true );
check( 'a quick migration never prunes', may_prune( [ 'quick' => true ] ), false );
check( 'one skipped file stops the prune', may_prune( [ 'quick' => false, 'skipped_files' => 1 ] ), false );
check( 'and so does a skip in a quick run', may_prune( [ 'quick' => true, 'skipped_files' => 4 ] ), false );

echo "\n-- the capacity response depends on the stage --\n";
// batch_ceiling sizes SQL only; a file stage must give back concurrency instead.
function capacity_response( $stage, $streams ) {
	if ( 'database' !== $stage && $streams > 1 ) {
		return [ 'shrink' => 'streams', 'to' => max( 1, (int) floor( $streams / 2 ) ) ];
	}
	return [ 'shrink' => 'batch' ];
}
check( 'a database failure shrinks the batch', capacity_response( 'database', 8 ), [ 'shrink' => 'batch' ] );
check( 'a plugins failure halves the streams', capacity_response( 'plugins', 8 ), [ 'shrink' => 'streams', 'to' => 4 ] );
check( 'and again', capacity_response( 'plugins', 4 ), [ 'shrink' => 'streams', 'to' => 2 ] );
check( 'at one stream it falls back to the batch', capacity_response( 'plugins', 1 ), [ 'shrink' => 'batch' ] );

echo "\n========================================\n";
echo "  $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
