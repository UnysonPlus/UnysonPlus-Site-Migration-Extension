<?php
// describe_option(): the measures must actually distinguish the causes of
// corruption from each other, otherwise the panel is just noise.
define( 'ARRAY_A', 'ARRAY_A' );
$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; printf( "  FAIL  %s (got %s, want %s)\n", $label, var_export( $got, true ), var_export( $want, true ) ); }
}

$DB = new mysqli( '127.0.0.1', 'root', '', 'unysonplus' );
if ( $DB->connect_errno ) { fwrite( STDERR, "no db\n" ); exit( 1 ); }
$DB->set_charset( 'utf8mb4' );

// A tiny scratch table stands in for wp_options so each corruption can be
// stored and described exactly as the real thing would be.
$DB->query( 'DROP TABLE IF EXISTS `_fwsm_fx`' );
$DB->query( 'CREATE TABLE `_fwsm_fx` (option_name VARCHAR(191) PRIMARY KEY, option_value LONGTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );

class WPDB {
	public $options = '_fwsm_fx';
	private $db;
	public function __construct( $db ) { $this->db = $db; }
	public function prepare( $sql, ...$a ) {
		foreach ( $a as $v ) {
			$sql = preg_replace( '/%s/', "'" . $this->db->real_escape_string( (string) $v ) . "'", $sql, 1 );
		}
		return $sql;
	}
	public function get_var( $sql ) {
		$r = $this->db->query( $sql );
		if ( ! $r ) { return null; }
		$row = $r->fetch_row();
		return $row ? $row[0] : null;
	}
}
$GLOBALS['wpdb'] = new WPDB( $DB );

$src = file_get_contents( 'd:/Web Dev/unysonplus/framework/extensions/site-migration/includes/class-fw-sm-receiver.php' );
$sig = 'public static function describe_option( $name ) {';
$start = strpos( $src, $sig );
if ( false === $start ) { fwrite( STDERR, "missing describe_option\n" ); exit( 1 ); }
$end = strpos( $src, "\n\t}", $start ) + 4;
eval( 'class R { ' . substr( $src, $start, $end - $start ) . ' }' );

function store( $db, $name, $value ) {
	$db->query( "REPLACE INTO `_fwsm_fx` VALUES ('" . $db->real_escape_string( $name ) . "', '" . $db->real_escape_string( $value ) . "')" );
}

// A realistic value: an array of strings with quotes, like a settings blob.
$good = serialize( [
	'html'  => '<a class="brand" href="https://example.com/">Home</a>',
	'note'  => "line one\nline two",
	'plain' => 'nothing special',
] );

store( $DB, 'good', $good );
store( $DB, 'slashed', addslashes( $good ) );
store( $DB, 'truncated', substr( $good, 0, strlen( $good ) - 30 ) );
store( $DB, 'reencoded', mb_convert_encoding( serialize( [ 'x' => 'caf' . chr( 0xC3 ) . chr( 0xA9 ) . ' — dash' ] ), 'UTF-8', 'ISO-8859-1' ) );
store( $DB, 'different', serialize( [ 'html' => 'a completely different value entirely here ok' ] ) );

echo "-- a healthy value --\n";
$g = R::describe_option( 'good' );
check( 'present', $g['present'], true );
check( 'readable', $g['readable'], true );
check( 'no break point', $g['fails_at'], -1 );

echo "\n-- escaped one time too many --\n";
$s = R::describe_option( 'slashed' );
check( 'unreadable', $s['readable'], false );
check( 'more backslashes than the original', $s['backslash'] > $g['backslash'], true );
check( 'larger than the original', $s['bytes'] > $g['bytes'], true );
check( 'the break point is located', $s['fails_at'] >= 0, true );

echo "\n-- truncated --\n";
$t = R::describe_option( 'truncated' );
check( 'unreadable', $t['readable'], false );
check( 'smaller than the original', $t['bytes'] < $g['bytes'], true );

echo "\n-- re-encoded --\n";
$r = R::describe_option( 'reencoded' );
check( 'unreadable', $r['readable'], false );
check( 'carries non-ASCII bytes', $r['non_ascii'] > 0, true );

echo "\n-- simply a different value --\n";
$d = R::describe_option( 'different' );
check( 'readable, so not corruption at all', $d['readable'], true );
check( 'but a different hash', $d['sha1'] === $g['sha1'], false );

echo "\n-- an option that is not there --\n";
$m = R::describe_option( 'nope' );
check( 'reported as absent', $m['present'], false );

echo "\n-- against the real theme settings --\n";
$real = $DB->query( "SELECT option_value FROM wp_options WHERE option_name='fw_theme_settings_options:unysonplus'" )->fetch_assoc()['option_value'];
store( $DB, 'real', $real );
$a = R::describe_option( 'real' );
check( 'the real value is readable here', $a['readable'], true );
printf( "  bytes=%s quotes=%s backslashes=%s newlines=%s non-ascii=%s\n",
	number_format( $a['bytes'] ), number_format( $a['quotes'] ), $a['backslash'], $a['newlines'], $a['non_ascii'] );

// And the same value slashed once, which is what a destination showing ~+15 KB
// would look like.
store( $DB, 'real_slashed', addslashes( $real ) );
$b = R::describe_option( 'real_slashed' );
printf( "  slashed once: bytes=%s (+%s) readable=%s\n",
	number_format( $b['bytes'] ), number_format( $b['bytes'] - $a['bytes'] ), $b['readable'] ? 'yes' : 'no' );

$DB->query( 'DROP TABLE IF EXISTS `_fwsm_fx`' );

echo "\n========================================\n";
echo "  $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
