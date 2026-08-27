<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Connection identity and request signing.
 *
 * A migration is one site writing into another site's database. The only thing
 * standing between "convenient" and "catastrophic" is this class, so it is
 * deliberately small and deliberately boring.
 *
 * The model: the DESTINATION generates a secret and displays it alongside its
 * URL as a single connection string. The user pastes that into the SOURCE. From
 * then on, every request the source makes is signed with that secret, and the
 * destination verifies the signature before doing anything at all.
 *
 * Why a shared secret rather than a login: the source is a server, not a person.
 * It has no session on the destination and cannot have one. The requests
 * therefore arrive logged out and must carry their own proof, which is what a
 * signature is.
 *
 * Three choices worth stating, because each is a place the obvious version is
 * weaker:
 *
 *   - HMAC-SHA256, not SHA-1. SHA-1 is not broken for HMAC today, but there is
 *     no reason to build something new on it.
 *   - hash_equals(), not ===. String comparison short-circuits on the first
 *     differing byte, which leaks the secret one byte at a time to anyone
 *     willing to measure. hash_equals() is constant-time.
 *   - A timestamp inside the signed payload, so a captured request cannot be
 *     replayed indefinitely.
 */
class FW_SM_Connection {

	const KEY_OPTION      = 'fw_sm_secret_key';
	const KEY_SET_OPTION  = 'fw_sm_secret_key_set_at';

	/**
	 * How far apart the two servers' clocks may be before a signed request is
	 * refused as too old. Generous, because shared hosting clocks drift, but far
	 * short of "forever".
	 */
	const MAX_CLOCK_SKEW = 900; // 15 minutes

	/**
	 * Fields larger than this are signed by hash rather than by value.
	 *
	 * Small enough that the payload-carrying fields always take the cheap path,
	 * large enough that ordinary fields are signed directly and the signature
	 * stays trivially auditable.
	 */
	const HASH_FIELD_OVER = 65536; // 64 KB

	/**
	 * This site's secret, generated on first read.
	 *
	 * @return string
	 */
	public static function get_key() {
		$key = get_option( self::KEY_OPTION, '' );

		if ( ! is_string( $key ) || strlen( $key ) < 40 ) {
			$key = self::reset_key();
		}

		return $key;
	}

	/**
	 * Generate a new secret, invalidating every existing connection to this site.
	 *
	 * @return string The new key.
	 */
	public static function reset_key() {
		$key = wp_generate_password( 64, false );

		update_option( self::KEY_OPTION, $key, false );
		update_option( self::KEY_SET_OPTION, time(), false );

		return $key;
	}

	/**
	 * When the current key was generated.
	 *
	 * @return int Unix timestamp, 0 if never.
	 */
	public static function key_set_at() {
		return (int) get_option( self::KEY_SET_OPTION, 0 );
	}

	/**
	 * The string a user copies from the destination into the source.
	 *
	 * URL and secret separated by a space — one field to copy, one field to
	 * paste, and no chance of pasting half of it.
	 *
	 * @return string
	 */
	public static function connection_string() {
		return untrailingslashit( home_url() ) . ' ' . self::get_key();
	}

	/**
	 * Split a pasted connection string back into its parts.
	 *
	 * Tolerant of what people actually paste: extra whitespace, a trailing
	 * slash, a newline in the middle from a wrapped copy.
	 *
	 * @param string $input
	 *
	 * @return array|WP_Error [ 'url' => string, 'key' => string ]
	 */
	public static function parse( $input ) {
		$input = trim( (string) $input );

		if ( '' === $input ) {
			return new WP_Error(
				'fw_sm_connection_empty',
				__( 'Paste the connection information from the destination site first.', 'fw' )
			);
		}

		$parts = preg_split( '/\s+/', $input );
		$parts = array_values( array_filter( array_map( 'trim', (array) $parts ) ) );

		if ( count( $parts ) < 2 ) {
			return new WP_Error(
				'fw_sm_connection_malformed',
				__( 'That does not look like connection information. Copy the whole line from the destination site — it is a URL followed by a key.', 'fw' )
			);
		}

		$url = untrailingslashit( esc_url_raw( $parts[0] ) );
		$key = $parts[1];

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'fw_sm_connection_bad_url',
				__( 'The destination address in that connection information is not a valid URL.', 'fw' )
			);
		}

		if ( strlen( $key ) < 40 ) {
			return new WP_Error(
				'fw_sm_connection_bad_key',
				__( 'The key in that connection information looks incomplete. Copy the whole line again.', 'fw' )
			);
		}

		return [ 'url' => $url, 'key' => $key ];
	}

	/**
	 * Sign a request payload.
	 *
	 * The signature covers every field, sorted by key so both sides build the
	 * same string regardless of array order. Booleans are normalised first —
	 * PHP would otherwise stringify true as '1' and false as '', and the two
	 * sides can disagree about which they were handed.
	 *
	 * @param array  $data
	 * @param string $key
	 *
	 * @return string
	 */
	public static function sign( array $data, $key ) {
		unset( $data['sig'] );

		ksort( $data );

		// Built incrementally, and large values are committed to by their hash
		// rather than by their contents.
		//
		// The obvious implementation — array_map() then implode() — copies the
		// whole payload twice. For a migration that carries megabytes in a
		// single field, those copies are the largest allocation in the request,
		// and on a destination with a modest memory_limit they are what pushes
		// it over: PHP dies while signing, before any of this code's own
		// safeguards can report anything, so the source sees a bare 500.
		//
		// Hashing a large field first is not a weakening. SHA-256 commits to
		// the content, so signing H(content) proves the same thing as signing
		// content — an attacker who could substitute a different body would
		// need a preimage for the hash. It just costs 32 bytes instead of
		// however many megabytes the field holds.
		$parts = [];

		foreach ( $data as $value ) {
			$value = self::normalize( $value );

			$parts[] = strlen( $value ) > self::HASH_FIELD_OVER
				? 'h:' . hash( 'sha256', $value )
				: $value;

			unset( $value );
		}

		return base64_encode( hash_hmac( 'sha256', implode( '|', $parts ), $key, true ) );
	}

	/**
	 * Verify a signed payload.
	 *
	 * @param array  $data
	 * @param string $key
	 *
	 * @return true|WP_Error
	 */
	public static function verify( array $data, $key ) {
		if ( empty( $data['sig'] ) || ! is_string( $data['sig'] ) ) {
			return new WP_Error(
				'fw_sm_unsigned',
				__( 'The request was not signed.', 'fw' )
			);
		}

		$given = $data['sig'];

		unset( $data['sig'] );

		$expected = self::sign( $data, $key );

		// Constant-time. A plain === here would leak the key by timing.
		if ( ! hash_equals( $expected, $given ) ) {
			return new WP_Error(
				'fw_sm_bad_signature',
				__( 'The connection information is wrong or has been reset on the destination site. Copy it again from there.', 'fw' )
			);
		}

		// A signature is proof of origin, not of freshness. Without this a
		// captured request could be replayed against the destination forever.
		$timestamp = isset( $data['timestamp'] ) ? (int) $data['timestamp'] : 0;

		if ( $timestamp <= 0 || abs( time() - $timestamp ) > self::MAX_CLOCK_SKEW ) {
			return new WP_Error(
				'fw_sm_stale_request',
				__( 'The request was rejected as too old. Check that the clocks on both servers are roughly correct, then try again.', 'fw' )
			);
		}

		return true;
	}

	/**
	 * Build a signed payload ready to POST.
	 *
	 * @param array  $data
	 * @param string $key
	 *
	 * @return array
	 */
	public static function prepare( array $data, $key ) {
		$data['timestamp'] = time();
		$data['sig']       = self::sign( $data, $key );

		return $data;
	}

	/**
	 * Flatten a value for signing.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	private static function normalize( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return (string) wp_json_encode( $value );
		}

		return (string) $value;
	}

	/**
	 * Forget everything about this site's identity as a destination.
	 *
	 * @return void
	 */
	public static function delete() {
		delete_option( self::KEY_OPTION );
		delete_option( self::KEY_SET_OPTION );
	}
}
