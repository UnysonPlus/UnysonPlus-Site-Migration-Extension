<?php
// site_snapshot(): the diagnostic both sides run. Exercised against the real
// local database so the shape is proven on real data, not a fixture.
$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; printf( "  FAIL  %s (got %s, want %s)\n", $label, var_export( $got, true ), var_export( $want, true ) ); }
}

$DB = new mysqli( '127.0.0.1', 'root', '', 'unysonplus' );
if ( $DB->connect_errno ) { fwrite( STDERR, "no db\n" ); exit( 1 ); }
$DB->set_charset( 'utf8mb4' );

// A $wpdb stand-in with the handful of methods the snapshot uses.
class WPDB {
	public $options = 'wp_options';
	public $prefix  = 'wp_';
	private $db;
	public function __construct( $db ) { $this->db = $db; }
	public function get_results( $sql, $mode = null ) {
		$r = $this->db->query( $sql );
		$out = [];
		while ( $row = $r->fetch_assoc() ) { $out[] = $row; }
		return $out;
	}
	public function get_col( $sql ) {
		$r = $this->db->query( $sql );
		$out = [];
		while ( $row = $r->fetch_row() ) { $out[] = $row[0]; }
		return $out;
	}
	public function get_var( $sql ) {
		$r = $this->db->query( $sql );
		$row = $r->fetch_row();
		return $row ? $row[0] : null;
	}
	public function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }
}
$GLOBALS['wpdb'] = new WPDB( $DB );

define( 'FW', true );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ABSPATH', 'D:/xampp/htdocs/unysonplus/' );

function home_url( $p = '' ) { return 'http://localhost/unysonplus' . $p; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function wp_normalize_path( $p ) { return strtr( (string) $p, [ chr( 92 ) => '/' ] ); }
function get_theme_root() { return 'D:/xampp/htdocs/unysonplus/wp-content/themes'; }
function get_bloginfo( $x ) { return '7.1'; }
function get_option( $name, $default = false ) {
	global $wpdb;
	$row = $wpdb->get_results( "SELECT option_value FROM wp_options WHERE option_name='" . addslashes( $name ) . "'" );
	if ( ! $row ) { return $default; }
	$v = $row[0]['option_value'];
	$un = @unserialize( $v );
	return false === $un && 'b:0;' !== $v ? $v : $un;
}
function is_serialized( $data, $strict = true ) {
	if ( ! is_string( $data ) ) { return false; }
	$data = trim( $data );
	if ( 'N;' === $data ) { return true; }
	if ( strlen( $data ) < 4 || ':' !== $data[1] ) { return false; }
	return (bool) preg_match( '/^[aOsbdiC]:/', $data );
}
function maybe_serialize( $v ) { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v; }
function fw() { return null; }

// Only the two static methods are needed; pull them out so the receiver's
// WordPress-heavy dependencies stay out of the way.
$src = file_get_contents( 'd:/Web Dev/unysonplus/framework/extensions/site-migration/includes/class-fw-sm-receiver.php' );
$code = '';
foreach ( [
	'public static function site_snapshot() {',
	'private static function count_files( $dir ) {',
	'public static function theme_settings_report() {',
] as $sig ) {
	$start = strpos( $src, $sig );
	if ( false === $start ) { fwrite( STDERR, "missing: $sig\n" ); exit( 1 ); }
	$end = strpos( $src, "\n\t}", $start ) + 4;
	$code .= substr( $src, $start, $end - $start ) . "\n";
}
// extension_version() is trivial and pulls in fw_ext(); stub it.
$code .= 'public static function extension_version() { return "1.0.11"; }' . "\n";
eval( 'class R { ' . $code . ' }' );

$snap = R::site_snapshot();

echo "-- the snapshot describes this site --\n";
check( 'address', $snap['site_url'], 'http://localhost/unysonplus' );
check( 'active theme read from options', $snap['stylesheet'], 'unysonplus-website-child' );
check( 'parent theme read from options', $snap['template'], 'unysonplus-theme' );
check( 'parent theme directory found', $snap['template_dir_exists'], true );
check( 'parent theme manifest found', $snap['template_manifest'], true );
check( 'child theme has no manifest of its own', $snap['stylesheet_manifest'], false );

echo "\n-- it counts what it should --\n";
check( 'parent theme has files', $snap['template_files'] > 100, true );
check( 'options counted', $snap['options_total'] > 100, true );
// Real data, so the count is whatever it is; what matters is that the
// field is populated and names what it found. This source really does carry
// one option that no longer unserializes.
check( 'unreadable options are counted', is_int( $snap['options_unreadable'] ), true );
if ( $snap['options_unreadable'] > 0 ) {
	printf( "  found unreadable: %s
", implode( ', ', $snap['unreadable_eg'] ) );
}
check( 'and named when present', $snap['options_unreadable'] === count( $snap['unreadable_eg'] ) || count( $snap['unreadable_eg'] ) === 5, true );
check( 'tables listed', count( $snap['tables'] ) >= 12, true );
check( 'wp_options row count present', isset( $snap['tables']['wp_options'] ), true );

echo "\n-- the theme settings report is the discriminating part --\n";
$ts = $snap['theme_settings'];
check( 'every settings key is listed', isset( $ts['keys']['fw_theme_settings_options:unysonplus'] ), true );
printf( "  keys found: %s\n", implode( ', ', array_keys( $ts['keys'] ) ) );
printf( "  bytes: %s\n", number_format( $ts['keys']['fw_theme_settings_options:unysonplus'] ?? 0 ) );
check( 'the real settings are ~128 KB', $ts['keys']['fw_theme_settings_options:unysonplus'] > 100000, true );

echo "\n========================================\n";
echo "  $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
