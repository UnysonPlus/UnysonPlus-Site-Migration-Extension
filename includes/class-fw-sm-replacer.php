<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Serialization-aware search and replace.
 *
 * This is the class that makes a migration safe, and the one place where being
 * clever is a liability. A plain SQL REPLACE() over wp_postmeta corrupts every
 * serialized value it touches, because PHP records each string's byte length in
 * the serialized form:
 *
 *     s:19:"https://example.com"   ->  s:19:"https://a-longer-domain.test"
 *                                          ^ still says 19, so unserialize() fails
 *
 * WordPress stores page-builder trees, widget instances, theme mods and most
 * plugin settings as serialized arrays, so getting this wrong does not produce a
 * few wrong URLs — it produces a site whose builder content silently disappears.
 *
 * The fix is to never treat a value as a string until you have established that
 * it is not something structured: unserialize it, walk it, replace inside the
 * leaves, and re-serialize so the lengths are recomputed. Same for JSON.
 */
class FW_SM_Replacer {

	/**
	 * @var array[] Each: [ 'search' => string, 'replace' => string, 'ci' => bool ].
	 */
	private $pairs = [];

	/**
	 * How many replacements have actually been made, for the report.
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * Guards against infinite recursion on self-referencing structures.
	 *
	 * @var int
	 */
	private $depth = 0;

	/**
	 * Deepest structure we will walk before giving up and leaving a value alone.
	 */
	const MAX_DEPTH = 64;

	/**
	 * @param array[] $pairs Each: [ 'search' => string, 'replace' => string, 'ci' => bool ].
	 */
	public function __construct( array $pairs = [] ) {
		foreach ( $pairs as $pair ) {
			$this->add_pair(
				$pair['search'] ?? '',
				$pair['replace'] ?? '',
				! empty( $pair['ci'] )
			);
		}
	}

	/**
	 * Add a replacement pair, plus the JSON-escaped variant of it.
	 *
	 * The escaped variant matters more than it looks. A block editor or a page
	 * builder that stores JSON inside a post column writes forward slashes
	 * escaped — "https:\/\/example.com" — so a pair that only knows the plain
	 * form silently misses every URL held that way. Registering both costs one
	 * extra str_replace per value and closes the gap.
	 *
	 * @param string $search
	 * @param string $replace
	 * @param bool   $case_insensitive
	 *
	 * @return void
	 */
	/**
	 * Characters a root-relative URL can legitimately follow.
	 *
	 * Quotes and an equals sign cover HTML attributes, whitespace and a comma
	 * cover srcset and CSS lists, the parenthesis covers url(), and the
	 * brackets cover JSON and markup boundaries.
	 */
	const DELIMITERS = '[\x22\x27\s=(\[,;>]';
	
	public function add_pair( $search, $replace, $case_insensitive = false ) {
		$search  = (string) $search;
		$replace = (string) $replace;

		if ( '' === $search || $search === $replace ) {
			return;
		}

		$this->pairs[] = [
			'search'  => $search,
			'replace' => $replace,
			'ci'      => (bool) $case_insensitive,
		];

		$escaped_search  = str_replace( '/', '\\/', $search );
		$escaped_replace = str_replace( '/', '\\/', $replace );

		if ( $escaped_search !== $search ) {
			$this->pairs[] = [
				'search'  => $escaped_search,
				'replace' => $escaped_replace,
				'ci'      => (bool) $case_insensitive,
			];
		}
	}

	/**
	 * Add a pair that only matches a URL path where a URL actually starts.
	 *
	 * Root-relative URLs — src="/subdir/wp-content/..." — carry no scheme and
	 * no host, so the ordinary URL pair never sees them and the source's
	 * subdirectory survives into a destination that has none.
	 *
	 * A plain string pair cannot fix that safely in either direction:
	 *
	 *   - Subdirectory to root, '/subdir/wp-content' => '/wp-content' would
	 *     also rewrite somebody else's absolute URL that happens to contain
	 *     that path.
	 *   - Root to subdirectory, '/wp-content' => '/subdir/wp-content' would hit
	 *     the absolute URLs the URL pair has ALREADY rewritten, turning
	 *     /subdir/wp-content into /subdir/subdir/wp-content. Pairs apply in
	 *     sequence, so each one sees the previous one's output.
	 *
	 * What separates the two cases is the character in front. A root-relative
	 * URL always begins right after a delimiter — a quote, an equals sign,
	 * whitespace, a bracket, an opening parenthesis in CSS url(). An absolute
	 * URL has the host there instead. Matching that delimiter and putting it
	 * back is precise enough to be safe both ways.
	 *
	 * @param string $from Path prefix as it appears now, e.g. 'subdir' or ''.
	 * @param string $to   Path prefix it should become.
	 *
	 * @return void
	 */
	public function add_root_relative_pair( $from, $to ) {
		$from = trim( (string) $from, '/' );
		$to   = trim( (string) $to, '/' );

		if ( $from === $to ) {
			return;
		}

		$from = '' === $from ? '' : '/' . $from;
		$to   = '' === $to ? '' : '/' . $to;

		// Anchored on WordPress's own directories rather than on the path
		// alone: '/blog' => '' would rewrite any text starting with /blog,
		// while '/blog/wp-content' is unambiguously this site's.
		$dirs = [ 'wp-content', 'wp-includes', 'wp-admin', 'wp-json' ];

		// A JSON-encoded column writes its slashes escaped, so both forms are
		// registered for the same reason add_pair() registers both.
		$escaped_slash = chr( 92 ) . '/';

		foreach ( $dirs as $dir ) {
			$plain  = $from . '/' . $dir;
			$become = $to . '/' . $dir;

			$forms = [
				[ $plain, $become ],
				[
					str_replace( '/', $escaped_slash, $plain ),
					str_replace( '/', $escaped_slash, $become ),
				],
			];

			foreach ( $forms as $form ) {
				$this->pairs[] = [
					'regex'   => true,
					'search'  => '/(^|' . self::DELIMITERS . ')' . preg_quote( $form[0], '/' ) . '/',
					// $1 puts the delimiter back. Any dollar sign in the
					// replacement itself has to be escaped or preg_replace
					// would read it as another backreference.
					'replace' => '$1' . str_replace( '$', chr( 92 ) . '$', $form[1] ),
					'ci'      => false,
				];
			}
		}
	}

	/**
	 * Build the standard pairs for moving a site from one URL and path to another.
	 *
	 * Derives what it can rather than asking the user for it: the URL swap, the
	 * absolute-path swap, and — when only the scheme differs — a protocol-only
	 * pair, which is the common case of moving a site behind HTTPS.
	 *
	 * @param string $from_url  Source site URL, no trailing slash.
	 * @param string $to_url    Destination site URL, no trailing slash.
	 * @param string $from_path Source ABSPATH.
	 * @param string $to_path   Destination ABSPATH.
	 *
	 * @return self
	 */
	public static function for_site_move( $from_url, $to_url, $from_path = '', $to_path = '' ) {
		$replacer = new self();

		$from_url = untrailingslashit( trim( (string) $from_url ) );
		$to_url   = untrailingslashit( trim( (string) $to_url ) );

		if ( '' !== $from_url && $from_url !== $to_url ) {
			// Domains are not case sensitive, and enough sites have mixed-case
			// URLs stored in meta to make this worth doing case-insensitively.
			$replacer->add_pair( $from_url, $to_url, true );

			// Protocol-relative form: //example.com stays valid on both schemes
			// and is common in older themes.
			$from_bare = preg_replace( '#^https?://#i', '//', $from_url );
			$to_bare   = preg_replace( '#^https?://#i', '//', $to_url );

			if ( $from_bare !== $to_bare ) {
				$replacer->add_pair( $from_bare, $to_bare, true );
			}
		}

		$from_path = '' !== $from_path ? untrailingslashit( wp_normalize_path( $from_path ) ) : '';
		$to_path   = '' !== $to_path ? untrailingslashit( wp_normalize_path( $to_path ) ) : '';

		// Root-relative URLs carry no host, so the pair above never sees them.
		// A site moving between a subdirectory and a document root has to have
		// its /subdir prefix added or removed on those separately, or every
		// src="/subdir/wp-content/..." in the content points at nothing.
		$replacer->add_root_relative_pair(
			(string) wp_parse_url( $from_url, PHP_URL_PATH ),
			(string) wp_parse_url( $to_url, PHP_URL_PATH )
		);

		if ( '' !== $from_path && $from_path !== $to_path ) {
			// Paths ARE case sensitive on the filesystems that matter here.
			$replacer->add_pair( $from_path, $to_path, false );
		}

		return $replacer;
	}

	/**
	 * @return bool Whether there is anything to do at all.
	 */
	public function has_pairs() {
		return ! empty( $this->pairs );
	}

	/**
	 * @return int Replacements made so far.
	 */
	public function get_count() {
		return $this->count;
	}

	/**
	 * Replace inside a value of any shape, preserving its structure.
	 *
	 * @param mixed $value
	 *
	 * @return mixed The value with replacements applied.
	 */
	public function replace( $value ) {
		if ( empty( $this->pairs ) ) {
			return $value;
		}

		if ( $this->depth > self::MAX_DEPTH ) {
			return $value;
		}

		// Arrays and objects: walk in place. Anything reached this way came from
		// an unserialize() below, so re-serialization happens on the way back up.
		if ( is_array( $value ) ) {
			$this->depth++;

			$out = [];
			foreach ( $value as $key => $item ) {
				// Keys can hold paths too — a meta array keyed by directory is
				// unusual but not rare enough to ignore.
				$out[ $this->replace( $key ) ] = $this->replace( $item );
			}

			$this->depth--;

			return $out;
		}

		if ( is_object( $value ) ) {
			// An incomplete class (its definition is not loaded here) must be
			// left strictly alone: writing to it would produce an object that
			// cannot be restored on the destination.
			if ( $value instanceof __PHP_Incomplete_Class ) {
				return $value;
			}

			if ( ! $this->is_cloneable( $value ) ) {
				return $value;
			}

			$this->depth++;

			$clone = clone $value;

			foreach ( get_object_vars( $clone ) as $prop => $item ) {
				$clone->$prop = $this->replace( $item );
			}

			$this->depth--;

			return $clone;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		return $this->replace_string( $value );
	}

	/**
	 * Replace inside a string, first establishing whether it is really a string.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	private function replace_string( $value ) {
		// Serialized? Unserialize, recurse, re-serialize. Lengths are recomputed
		// by serialize(), which is the entire point of the exercise.
		if ( is_serialized( $value, true ) ) {
			$unserialized = $this->maybe_unserialize( $value );

			// Serialized data we will not or cannot parse — an object, or a
			// malformed payload. It MUST be returned untouched. Falling through
			// to a plain string replace here would rewrite the data without
			// recomputing its length prefixes, which is precisely the corruption
			// this whole class exists to prevent; refusing to parse a value is
			// not a licence to mangle it.
			if ( null === $unserialized ) {
				return $value;
			}

			$this->depth++;
			$replaced = $this->replace( $unserialized );
			$this->depth--;

			return serialize( $replaced );
		}

		// JSON? Same treatment. Guard on the first character so we are not
		// running json_decode() over every plain string in the database.
		$first = $value[0];

		if ( '{' === $first || '[' === $first ) {
			$decoded = json_decode( $value, true );

			if ( JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
				$this->depth++;
				$replaced = $this->replace( $decoded );
				$this->depth--;

				$encoded = wp_json_encode( $replaced );

				if ( false !== $encoded ) {
					return $encoded;
				}
			}
		}

		return $this->apply_pairs( $value );
	}

	/**
	 * Run every pair over a plain string.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	private function apply_pairs( $value ) {
		foreach ( $this->pairs as $pair ) {
			$before = $value;

			if ( ! empty( $pair['regex'] ) ) {
				$replaced = preg_replace( $pair['search'], $pair['replace'], $value );

				// preg_replace returns null on backtrack limits and the like.
				// Keeping the original is the only safe answer: a half-replaced
				// value is worse than an unreplaced one.
				$value = null === $replaced ? $value : $replaced;
			} else {
				$value = $pair['ci']
					? str_ireplace( $pair['search'], $pair['replace'], $value )
					: str_replace( $pair['search'], $pair['replace'], $value );
			}

			if ( $before !== $value ) {
				$this->count++;
			}
		}

		return $value;
	}

	/**
	 * Unserialize a string, but only if it really is serialized data.
	 *
	 * Deliberately refuses to instantiate objects. A migration archive is
	 * attacker-controlled input as far as this code is concerned — an archive
	 * from an untrusted source containing a serialized object of a class with a
	 * destructor is the classic PHP object-injection chain. Refusing objects
	 * here means the worst case is that an object-valued option passes through
	 * unmodified, which is a correctness inconvenience rather than a remote
	 * code execution.
	 *
	 * @param string $value
	 *
	 * @return mixed|null Null when the value is not serialized data we will touch.
	 */
	private function maybe_unserialize( $value ) {
		// is_serialized() is cheap and rejects the overwhelming majority of
		// values before we ever call unserialize().
		if ( ! is_serialized( $value, true ) ) {
			return null;
		}

		// 'O:' (object) and 'C:' (custom-serialized object) are refused outright.
		if ( preg_match( '/^[OC]:\d+:/', $value ) ) {
			return null;
		}

		$result = @unserialize( $value, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $result && 'b:0;' !== $value ) {
			return null;
		}

		// A nested object could still surface via allowed_classes => false as an
		// __PHP_Incomplete_Class. replace() leaves those alone.
		return $result;
	}

	/**
	 * @param object $object
	 *
	 * @return bool
	 */
	private function is_cloneable( $object ) {
		$reflection = new ReflectionClass( $object );

		return $reflection->isCloneable();
	}
}
