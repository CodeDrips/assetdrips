<?php
/**
 * Pure extraction of attachment references from text and values.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Inventory;

defined( 'ABSPATH' ) || exit;

/**
 * Finds candidate attachment references in arbitrary strings.
 *
 * Two kinds of reference:
 *  - uploads paths, anchored on the uploads prefix so every URL shape (absolute,
 *    protocol-relative, root-relative) and absolute filesystem paths all reduce
 *    to the same uploads-relative path token;
 *  - attachment IDs, drawn only from contexts that genuinely denote a media item
 *    (block attributes, the wp-image-{id} class, gallery/caption shortcodes), so
 *    arbitrary numbers in content are not mistaken for references.
 *
 * This class is pure: it does no resolution and touches no WordPress globals, so
 * it is fully unit-testable. {@see ReferenceIndex} turns its output into IDs.
 */
final class ReferenceExtractor {

	/**
	 * Uploads prefixes to anchor path extraction on, no trailing slash.
	 *
	 * @var string[]
	 */
	private array $prefixes;

	/**
	 * Characters that terminate a URL or path token in HTML, CSS, or JSON.
	 */
	private const DELIMITERS = "\\s\"'<>)\\]}\\\\?#,;";

	/**
	 * Construct with the uploads prefixes to anchor on.
	 *
	 * @param string[] $prefixes Uploads URL-path and/or filesystem prefixes.
	 */
	public function __construct( array $prefixes ) {
		$clean = array();
		foreach ( $prefixes as $prefix ) {
			$prefix = rtrim( (string) $prefix, '/' );
			if ( '' !== $prefix ) {
				$clean[ $prefix ] = true;
			}
		}
		$this->prefixes = array_keys( $clean );
	}

	/**
	 * Extract uploads-relative path candidates from free text.
	 *
	 * Any reference under the uploads directory — in any URL or path form —
	 * contains an uploads prefix followed by the relative path. We capture the
	 * trailing path and URL-decode it.
	 *
	 * @param string $text Arbitrary text (post content, CSS, a meta string).
	 * @return string[] Unique uploads-relative path candidates.
	 */
	public function relative_candidates( string $text ): array {
		if ( '' === $text || array() === $this->prefixes ) {
			return array();
		}

		$found = array();
		foreach ( $this->prefixes as $prefix ) {
			$pattern = '~' . preg_quote( $prefix, '~' ) . '/([^' . self::DELIMITERS . ']+)~';
			if ( preg_match_all( $pattern, $text, $matches ) ) {
				foreach ( $matches[1] as $candidate ) {
					$found[ $this->clean_candidate( $candidate ) ] = true;
				}
			}
		}

		unset( $found[''] );

		return array_keys( $found );
	}

	/**
	 * Reduce a single value (URL, path, or relative path) to an uploads-relative
	 * path token, or null when it is not under the uploads directory and is not
	 * already a bare relative path.
	 *
	 * Resolution against the index decides whether the token is real; this only
	 * normalises shape.
	 *
	 * @param string $value Candidate reference.
	 * @return string|null Uploads-relative path, or null.
	 */
	public function normalize( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		foreach ( $this->prefixes as $prefix ) {
			$needle = $prefix . '/';
			$pos    = strpos( $value, $needle );
			if ( false !== $pos ) {
				$tail = substr( $value, $pos + strlen( $needle ) );
				return $this->clean_candidate( $tail );
			}
		}

		// Not under a known prefix. If it looks like a scheme/host URL it is some
		// other host's asset; otherwise treat it as a bare uploads-relative path
		// and let the index gate whether it actually exists.
		if ( false !== strpos( $value, '://' ) || str_starts_with( $value, '//' ) ) {
			return null;
		}

		return $this->clean_candidate( ltrim( $value, '/' ) );
	}

	/**
	 * Extract attachment ID candidates from content-bearing text.
	 *
	 * @param string $text Arbitrary text (typically post content).
	 * @return int[] Unique candidate attachment IDs.
	 */
	public static function id_candidates( string $text ): array {
		if ( '' === $text ) {
			return array();
		}

		$ids = array();

		// The wp-image-{id} class on classic and block images.
		if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		// Block attributes holding a single media ID (id, mediaId).
		if ( preg_match_all( '/"(?:id|mediaId)"\s*:\s*(\d+)/', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		// Gallery block attribute holding an array of IDs.
		if ( preg_match_all( '/"ids"\s*:\s*\[([\d,\s]+)\]/', $text, $m ) ) {
			foreach ( $m[1] as $raw ) {
				foreach ( self::split_int_list( $raw ) as $id ) {
					$ids[ $id ] = true;
				}
			}
		}

		// Classic gallery shortcode with an ids attribute.
		if ( preg_match_all( '/\[gallery[^\]]*\bids=["\']?([\d,\s]+)/i', $text, $m ) ) {
			foreach ( $m[1] as $raw ) {
				foreach ( self::split_int_list( $raw ) as $id ) {
					$ids[ $id ] = true;
				}
			}
		}

		// Caption shortcode referencing an attachment id.
		if ( preg_match_all( '/\[caption[^\]]*\bid=["\']?attachment_(\d+)/i', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		unset( $ids[0] );

		return array_keys( $ids );
	}

	/**
	 * Trim a captured path candidate: drop any query/fragment and URL-decode.
	 *
	 * @param string $candidate Raw captured path.
	 * @return string
	 */
	private function clean_candidate( string $candidate ): string {
		foreach ( array( '?', '#' ) as $cut ) {
			$pos = strpos( $candidate, $cut );
			if ( false !== $pos ) {
				$candidate = substr( $candidate, 0, $pos );
			}
		}

		return rawurldecode( $candidate );
	}

	/**
	 * Parse a comma/space separated sequence of integers.
	 *
	 * @param string $raw Raw sequence.
	 * @return int[]
	 */
	private static function split_int_list( string $raw ): array {
		$ids   = array();
		$parts = preg_split( '/[,\s]+/', trim( $raw ) );
		if ( false === $parts ) {
			return $ids;
		}

		foreach ( $parts as $part ) {
			if ( '' !== $part && ctype_digit( $part ) ) {
				$ids[] = (int) $part;
			}
		}

		return $ids;
	}
}
