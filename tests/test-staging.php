<?php
// Files must not go live until the whole migration has.
//
// The failure this prevents, exactly as it happened: framework/bootstrap.php
// arrived and requires framework/includes/icon-palette.php; the migration then
// stopped before that include was sent. The destination was left executing a
// new bootstrap against an old tree and fataled on every request.
$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; printf( "  FAIL  %s (got %s, want %s)\n", $label, var_export( $got, true ), var_export( $want, true ) ); }
}

$root = sys_get_temp_dir() . '/fwsm-staging-' . getmypid();

function rrm( $d ) {
	if ( ! is_dir( $d ) ) { return; }
	foreach ( array_diff( scandir( $d ), [ '.', '..' ] ) as $e ) {
		$p = "$d/$e";
		is_dir( $p ) ? rrm( $p ) : unlink( $p );
	}
	rmdir( $d );
}
function put( $p, $c ) { @mkdir( dirname( $p ), 0777, true ); file_put_contents( $p, $c ); }

// The two helpers under test, lifted from the receiver.
$src = file_get_contents( 'd:/Web Dev/unysonplus/framework/extensions/site-migration/includes/class-fw-sm-receiver.php' );
$start = strpos( $src, 'private static function landing_path( $target, $stage ) {' );
$end   = strpos( $src, "\n\t}", $start ) + 4;
eval( 'class R { const STAGED_SUFFIX = ".fwsm-new"; public static function prunable_stages() { return [ "themes", "plugins", "muplugins" ]; } public static function landing( $t, $stage = "plugins" ) { return self::landing_path( $t, $stage ); } ' . substr( $src, $start, $end - $start ) . ' }' );

// A destination running the OLD plugin.
rrm( $root );
put( "$root/plugin/framework/bootstrap.php", 'old bootstrap, requires nothing' );
put( "$root/plugin/framework/includes/existing.php", 'old' );

echo "-- a replacement lands beside the live file, not over it --\n";
$target  = "$root/plugin/framework/bootstrap.php";
$landing = R::landing( $target );
check( 'a replacement is staged', $landing, $target . '.fwsm-new' );
check( 'the live file is untouched so far', file_get_contents( $target ), 'old bootstrap, requires nothing' );

echo "\n-- a file the destination does not have lands directly --\n";
$new_target  = "$root/plugin/framework/includes/icon-palette.php";
$new_landing = R::landing( $new_target, 'plugins' );
check( 'a new code file is staged', $new_landing, $new_target . '.fwsm-new' );

echo "\n-- the migration is interrupted right here --\n";
// bootstrap.php's replacement is staged; icon-palette.php has NOT arrived.
put( $landing, 'new bootstrap, requires includes/icon-palette.php' );

check( 'the live bootstrap is still the old one', file_get_contents( $target ), 'old bootstrap, requires nothing' );
check( 'so it does not require a file that is absent', file_exists( $new_target ), false );
// That pairing is the whole point: old bootstrap + no icon-palette = a working
// site. New bootstrap + no icon-palette = the fatal error that was reported.
check( 'the destination is therefore self-consistent', file_get_contents( $target ) === 'old bootstrap, requires nothing' && ! file_exists( $new_target ), true );

echo "\n-- now let it finish instead --\n";
put( $new_landing, 'the include' ); // new file arrives, STAGED as .fwsm-new
// The swap, as swap_staged_files() performs it.
$staged_list = [ $landing, $new_landing ]; // both the replacement AND the new include
$swapped = 0;
foreach ( $staged_list as $st ) {
	$t = substr( $st, 0, -strlen( '.fwsm-new' ) );
	if ( rename( $st, $t ) ) { $swapped++; }
}
check( 'both staged files went live', $swapped, 2 );
check( 'bootstrap is the new one', file_get_contents( $target ), 'new bootstrap, requires includes/icon-palette.php' );
check( 'and its include is present', file_exists( $new_target ), true );
check( 'no staging file is left behind', file_exists( $landing ), false );

echo "\n-- an abandoned migration leaves no clutter --\n";
put( "$root/plugin/framework/other.php", 'old' );
$abandoned = "$root/plugin/framework/other.php.fwsm-new";
put( $abandoned, 'never went live' );
$gone = 0;
foreach ( [ $abandoned ] as $st ) { if ( is_file( $st ) && unlink( $st ) ) { $gone++; } }
check( 'the staged copy is discarded', $gone, 1 );
check( 'the live file survives untouched', file_get_contents( "$root/plugin/framework/other.php" ), 'old' );

echo "\n-- the prune must not mistake a pending replacement for an orphan --\n";
$suffix = '.fwsm-new';
check( 'a .fwsm-new file is recognised', $suffix === substr( 'bootstrap.php.fwsm-new', -strlen( $suffix ) ), true );
check( 'an ordinary file is not', $suffix === substr( 'bootstrap.php', -strlen( $suffix ) ), false );

echo "\n-- the automatic sweep finds every leftover staged file, anywhere --\n";
// The real recursive walker, lifted from the receiver.
$s2  = strpos( $src, 'private function scan_staged_dir( $dir, $delete, array &$examples, $depth ) {' );
$e2  = strpos( $src, "\n\t}", $s2 ) + 4;
eval( 'class Sweeper { const STAGED_SUFFIX = ".fwsm-new"; public function sweep( $dir, $delete, array &$ex ) { return $this->scan_staged_dir( $dir, $delete, $ex, 0 ); } ' . str_replace( 'private function scan_staged_dir', 'public function scan_staged_dir', substr( $src, $s2, $e2 - $s2 ) ) . ' }' );

rrm( $root );
// A tree with live files plus leftovers scattered at several depths.
put( "$root/themes/child/view.php", 'live' );
put( "$root/themes/child/view.php.fwsm-new", 'staged replacement' );
put( "$root/themes/child/nested/deep/tpl.php.fwsm-new", 'staged, deep' );
put( "$root/plugins/acme/main.php", 'live' );
put( "$root/plugins/acme/main.php.fwsm-part", 'half-transferred' );
put( "$root/uploads/2026/image.jpg", 'a normal upload, must survive' );

$sw = new Sweeper();

$ex    = [];
$found = $sw->sweep( $root, false, $ex ); // count-only (the "after" check)
check( 'count mode finds all three leftovers', $found, 3 );
check( 'count mode deletes nothing', file_exists( "$root/themes/child/view.php.fwsm-new" ), true );
check( 'count mode collects examples', count( $ex ) > 0, true );

$ex   = [];
$gone = $sw->sweep( $root, true, $ex ); // clean (the "before" sweep)
check( 'clean mode removes all three', $gone, 3 );
check( 'the .fwsm-new next to a live file is gone', file_exists( "$root/themes/child/view.php.fwsm-new" ), false );
check( 'the deep .fwsm-new is gone', file_exists( "$root/themes/child/nested/deep/tpl.php.fwsm-new" ), false );
check( 'the .fwsm-part is gone', file_exists( "$root/plugins/acme/main.php.fwsm-part" ), false );
check( 'every LIVE file survives the sweep', file_get_contents( "$root/themes/child/view.php" ), 'live' );
check( 'the plugin live file survives', file_get_contents( "$root/plugins/acme/main.php" ), 'live' );
check( 'the upload survives', file_get_contents( "$root/uploads/2026/image.jpg" ), 'a normal upload, must survive' );

$ex    = [];
$after = $sw->sweep( $root, false, $ex ); // a swept tree is clean
check( 'nothing remains after the sweep', $after, 0 );

rrm( $root );

echo "\n========================================\n";
echo "  $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
