<?php
// A literal % in migrated data must survive as a literal %.
//
// wpdb::_real_escape() ends with add_placeholder_escape(), which replaces every
// % with a per-instance random hash. That is undone by a 'query' filter in the
// SAME process — but a migration's SQL is executed by a different WordPress,
// whose hash differs, so the placeholder is stored verbatim and every
// serialized value containing % loses its length prefix.
$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; printf( "  FAIL  %s (got %s, want %s)\n", $label, var_export( $got, true ), var_export( $want, true ) ); }
}

$DB = new mysqli( '127.0.0.1', 'root', '', 'unysonplus' );
if ( $DB->connect_errno ) { fwrite( STDERR, "no db\n" ); exit( 1 ); }
$DB->set_charset( 'utf8mb4' );

// A wpdb stand-in that reproduces the real placeholder behaviour, including a
// salt that differs per instance — which is the whole point.
class FakeWPDB {
	private $db;
	private $salt;
	public function __construct( $db ) {
		$this->db   = $db;
		$this->salt = hash( 'sha256', uniqid( (string) mt_rand(), true ) );
	}
	public function placeholder_escape() { return '{' . $this->salt . '}'; }
	public function add_placeholder_escape( $q ) { return str_replace( '%', $this->placeholder_escape(), $q ); }
	public function remove_placeholder_escape( $q ) { return str_replace( $this->placeholder_escape(), '%', $q ); }
	public function _real_escape( $d ) {
		return $this->add_placeholder_escape( $this->db->real_escape_string( (string) $d ) );
	}
}

$source = new FakeWPDB( $DB );
$dest   = new FakeWPDB( $DB ); // a different WordPress, so a different salt.

check( 'the two instances really do differ', $source->placeholder_escape() === $dest->placeholder_escape(), false );

// quote(), lifted from the exporter.
$src_file = file_get_contents( 'd:/Web Dev/unysonplus/framework/extensions/site-migration/includes/class-fw-sm-db-export.php' );
$start = strpos( $src_file, 'private function quote( $value ) {' );
$end   = strpos( $src_file, "\n\t}", $start ) + 4;
$body = substr( $src_file, $start, $end - $start );
$body = str_replace( 'private function quote(', 'public static function quote(', $body );
eval( 'class Q { public static function run( $v ) { return self::quote( $v ); } ' . $body . ' }' );

$GLOBALS['wpdb'] = $source;

echo "\n-- a value built from CSS percentages --\n";
$value = serialize( [
	'width'  => '100%',
	'css'    => '@keyframes x { 0% { opacity: 0 } 100% { opacity: 1 } }',
	'plain'  => 'no percent here',
] );

$literal = Q::run( $value );
check( 'no placeholder hash in the SQL literal', preg_match( '/\{[0-9a-f]{64}\}/', $literal ), 0 );
check( 'the placeholder is gone', substr_count( $literal, $source->placeholder_escape() ), 0 );
check( 'the percent signs survive', substr_count( $literal, '%' ), substr_count( $value, '%' ) );

echo "\n-- executed by the OTHER WordPress, as a migration does --\n";
$DB->query( 'DROP TABLE IF EXISTS `_fwsm_pct`' );
$DB->query( 'CREATE TABLE `_fwsm_pct` (k VARCHAR(64) PRIMARY KEY, v LONGTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );

// The destination applies ITS OWN remove_placeholder_escape via the query
// filter, then runs the statement.
$stmt = "INSERT INTO `_fwsm_pct` (k, v) VALUES ('a', " . $literal . ")";
$DB->query( $dest->remove_placeholder_escape( $stmt ) );

$back = $DB->query( "SELECT v FROM `_fwsm_pct` WHERE k='a'" )->fetch_assoc()['v'];

check( 'the value arrives byte-identical', $back === $value, true );
check( 'and still unserializes', unserialize( $back ), unserialize( $value ) );
// The CSS in this value has braces of its own, so the check is for the
// placeholder's actual shape: a brace-wrapped 64-character hex string.
check( 'no placeholder hash left in the data', preg_match( '/\{[0-9a-f]{64}\}/', $back ), 0 );

echo "\n-- what the bug used to do, for contrast --\n";
$broken = "'" . $source->_real_escape( $value ) . "'";
$stmt2  = "INSERT INTO `_fwsm_pct` (k, v) VALUES ('b', " . $broken . ")";
$DB->query( $dest->remove_placeholder_escape( $stmt2 ) );
$bad = $DB->query( "SELECT v FROM `_fwsm_pct` WHERE k='b'" )->fetch_assoc()['v'];

check( 'the old path grew the value', strlen( $bad ) > strlen( $value ), true );
check( 'by 65 bytes per percent sign', strlen( $bad ) - strlen( $value ), 65 * substr_count( $value, '%' ) );
check( 'and left it unreadable', false === @unserialize( $bad ), true );

$DB->query( 'DROP TABLE IF EXISTS `_fwsm_pct`' );

echo "\n========================================\n";
echo "  $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
