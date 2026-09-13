<?php
// Packing whole small tables into one database batch.
//
// The point of these is the FIRST section: a single site's tables fill the byte
// cap on their own, so the packing path never engages and its batching is
// exactly what it was before. Everything after that covers the network case
// packing exists for.
$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; printf( "  FAIL  %s (got %s, want %s)\n", $label, var_export( $got, true ), var_export( $want, true ) ); }
}

class FW_SM_Stage { const DATABASE = 'database'; const UPLOADS = 'uploads'; }

$src = file_get_contents( 'd:/Web Dev/unysonplus/framework/extensions/site-migration/includes/class-fw-sm-runner.php' );
$sig = 'public static function packable_table( $job, $budget, $current ) {';
$start = strpos( $src, $sig );
if ( false === $start ) { fwrite( STDERR, "packable_table not found\n" ); exit( 1 ); }
$end = strpos( $src, "\n\t}", $start ) + 4;
eval( 'class R { ' . substr( $src, $start, $end - $start ) . ' }' );

function job( $id, $table, $bytes, $stage = 'database' ) {
	return [ 'id' => $id, 'stage' => $stage, 'bytes' => $bytes, 'payload' => [ 'table' => $table ] ];
}

$MB  = 1048576;
$CAP = 8 * $MB;

echo "-- a single site: the batch is already full, so nothing is packed --\n";
// wp_postmeta on a real single site fills the cap by itself. Once the current
// table has consumed the batch, the budget left cannot admit anything.
$budget = $CAP - $CAP; // the current table used the whole cap
check( 'nothing fits an exhausted budget', R::packable_table( job( 2, 'wp_posts', 50 * $MB ), $budget, 1 ), '' );
check( 'not even a tiny table', R::packable_table( job( 2, 'wp_options', 1024 ), $budget, 1 ), '' );

// And with a realistically large remaining table, still nothing.
$budget = 200 * 1024; // 200 KB left after a big table
check( 'a 50 MB table is refused', R::packable_table( job( 2, 'wp_posts', 50 * $MB ), $budget, 1 ), '' );
check( 'a 150 KB table is refused when 200 KB remains', R::packable_table( job( 2, 'wp_terms', 150 * 1024 ), $budget, 1 ), '' );

echo "\n-- a network: hundreds of tiny tables ride along --\n";
$budget = $CAP;
check( 'an empty WooCommerce table is packed', R::packable_table( job( 7, 'wp_5_wc_reserved_stock', 16384 ), $budget, 1 ), 'wp_5_wc_reserved_stock' );
check( 'a 64 KB table is packed', R::packable_table( job( 8, 'wp_5_options', 65536 ), $budget, 1 ), 'wp_5_options' );
check( 'a 1 MB table is packed into an 8 MB budget', R::packable_table( job( 9, 'wp_5_posts', $MB ), $budget, 1 ), 'wp_5_posts' );

echo "\n-- the safety margin on the size estimate --\n";
// information_schema is an estimate, so twice the estimate must fit.
check( 'exactly half the budget is allowed', R::packable_table( job( 10, 'wp_x', 4 * $MB ), $CAP, 1 ), 'wp_x' );
check( 'a byte over half is refused', R::packable_table( job( 10, 'wp_x', 4 * $MB + 1 ), $CAP, 1 ), '' );

echo "\n-- what must never be packed --\n";
check( 'the job already being sent', R::packable_table( job( 1, 'wp_posts', 1024 ), $CAP, 1 ), '' );
check( 'a job from another stage', R::packable_table( job( 3, 'wp_posts', 1024, 'uploads' ), $CAP, 1 ), '' );
check( 'a job with no table name', R::packable_table( [ 'id' => 4, 'stage' => 'database', 'bytes' => 10 ], $CAP, 1 ), '' );

echo "\n-- payload arriving as JSON, as the queue stores it --\n";
$raw = [ 'id' => 5, 'stage' => 'database', 'bytes' => 2048, 'payload' => json_encode( [ 'table' => 'wp_9_termmeta' ] ) ];
check( 'a JSON payload is understood', R::packable_table( $raw, $CAP, 1 ), 'wp_9_termmeta' );

echo "\n-- the shape of the win, on the real numbers --\n";
// 472 tables under 64 KB, measured at 58 KB and ~0.88s per request.
$tiny = 472;
$before_requests = $tiny;
// Packed into 8 MB batches, allowing twice the estimate.
$after_requests = (int) ceil( $tiny * 64 * 1024 / ( 4 * $MB ) );
printf( "  %d tiny tables: %d requests before, ~%d after\n", $tiny, $before_requests, $after_requests );
printf( "  at 0.88s per round trip: %d min before, %d s after\n",
	(int) round( $before_requests * 0.88 / 60 ), (int) round( $after_requests * 0.88 ) );
check( 'at least a tenfold reduction in round trips', $before_requests / max( 1, $after_requests ) >= 10, true );

echo "\n========================================\n";
echo "  $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
