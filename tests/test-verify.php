<?php
// A migration must not finish if a file it received is no longer on disk.
//
// Three live sites went down with a new plugin file requiring an include that
// was absent — and every time, the migration had reported success. The
// received list said the include had landed; nothing ever checked it was
// still there when the rest went live.
$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; printf( "  FAIL  %s (got %s, want %s)\n", $label, var_export( $got, true ), var_export( $want, true ) ); }
}

$root = sys_get_temp_dir() . '/fwsm-verify-' . getmypid();
$manifest = "$root/manifest.txt";

function rrm( $d ) {
	if ( ! is_dir( $d ) ) { return; }
	foreach ( array_diff( scandir( $d ), [ '.', '..' ] ) as $e ) { $p = "$d/$e"; is_dir( $p ) ? rrm( $p ) : unlink( $p ); }
	rmdir( $d );
}
function put( $p, $c = 'x' ) { @mkdir( dirname( $p ), 0777, true ); file_put_contents( $p, $c ); }

rrm( $root );
@mkdir( $root, 0777, true );

// Stand-ins for the collaborators, so the REAL verify_received() runs.
class FW_SM_Stage { public static function destination_dir( $s, $b = 0 ) { return $GLOBALS['root'] . '/plugins'; } }

$src = file_get_contents( 'd:/Web Dev/unysonplus/framework/extensions/site-migration/includes/class-fw-sm-receiver.php' );
$sig = 'private function verify_received( $session ) {';
$start = strpos( $src, $sig );
if ( false === $start ) { fwrite( STDERR, "verify_received not found\n" ); exit( 1 ); }
$end = strpos( $src, "\n\t}", $start ) + 4;
$body = str_replace( 'private function verify_received(', 'public function verify_received(', substr( $src, $start, $end - $start ) );

eval( 'class R {
	const STAGED_SUFFIX = ".fwsm-new";
	public static function prunable_stages() { return [ "plugins" ]; }
	public static function manifest_path( $s, $stage ) { return $GLOBALS["manifest"]; }
	// Like the real one: an escaping path is refused with null.
	public static function safe_target( $root, $rel ) { return false !== strpos( $rel, ".." ) ? null : $root . "/" . $rel; }
	' . $body . '
}' );

$r = new R();
$session = [ 'migration_id' => 'test' ];

echo "-- the exact failure: a main file arrived, its include did not --\n";
put( "$root/plugins/unysonplus/framework/extensions/asset-optimizer/class-fw-extension-asset-optimizer.php" );
// The include was recorded as received, then vanished.
file_put_contents( $manifest, implode( "\n", [
	'unysonplus/framework/extensions/asset-optimizer/class-fw-extension-asset-optimizer.php',
	'unysonplus/framework/extensions/asset-optimizer/includes/class-fw-ao-minifier.php',
] ) . "\n" );

$missing = $r->verify_received( $session );
check( 'the absent include is reported', $missing, [ 'plugins/unysonplus/framework/extensions/asset-optimizer/includes/class-fw-ao-minifier.php' ] );

echo "\n-- once it is actually there, nothing is missing --\n";
put( "$root/plugins/unysonplus/framework/extensions/asset-optimizer/includes/class-fw-ao-minifier.php" );
check( 'clean', $r->verify_received( $session ), [] );

echo "\n-- a staged replacement counts as present --\n";
// The live copy is old; the new one waits beside it as .fwsm-new.
unlink( "$root/plugins/unysonplus/framework/extensions/asset-optimizer/includes/class-fw-ao-minifier.php" );
put( "$root/plugins/unysonplus/framework/extensions/asset-optimizer/includes/class-fw-ao-minifier.php.fwsm-new" );
check( 'a .fwsm-new satisfies the check', $r->verify_received( $session ), [] );

echo "\n-- a file that escapes the root is ignored, not reported as missing --\n";
file_put_contents( $manifest, "../../etc/passwd\n", FILE_APPEND );
// The real safe_target() returns null for an escape and verify_received()
// skips it — it is neither present nor missing, it is refused.
check( 'an escaping path is skipped', $r->verify_received( $session ), [] );

echo "\n-- an empty or missing manifest means nothing to verify --\n";
unlink( $manifest );
check( 'no manifest, no complaint', $r->verify_received( $session ), [] );

echo "\n-- the report is bounded --\n";
$lines = [];
for ( $i = 0; $i < 500; $i++ ) { $lines[] = "gone/$i.php"; }
file_put_contents( $manifest, implode( "\n", $lines ) . "\n" );
$m = $r->verify_received( $session );
check( 'capped at 200 entries', count( $m ), 200 );

rrm( $root );

echo "\n========================================\n";
echo "  $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
