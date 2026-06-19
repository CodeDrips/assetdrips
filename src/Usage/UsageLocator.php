<?php
/**
 * Pure parser: scanner context string → {host_type, host_id}.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a scanner context string into a host type and ID.
 *
 * Every scanner encodes a structured context string that names the host
 * (post, term) where the attachment reference was found. This pure parser
 * extracts {host_type, host_id} from each format without touching the DB
 * or any WordPress globals — it is the unit-testable seam that feeds the
 * usage-locations table.
 *
 * Context formats handled (from RESEARCH §3):
 *
 *   post:{ID}:post_content          → host_type='post',  host_id={ID}
 *   postmeta:{post_id}:{key}        → host_type='post',  host_id={post_id}
 *   woo:product:{post_id}:gallery   → host_type='post',  host_id={post_id}
 *   woo:product:{post_id}:downloads → host_type='post',  host_id={post_id}
 *   woo:{label}:{ID}:featured       → host_type='post',  host_id={ID}
 *   acf:post:{post_id}:{meta_key}   → host_type='post',  host_id={post_id}
 *   acf:term:{term_id}:{meta_key}   → host_type='term',  host_id={term_id}
 *   acf:option:{name}               → null (not post/term-bound)
 *   option:{name}                   → null (not post/term-bound)
 *   termmeta:{term_id}:{key}        → host_type='term',  host_id={term_id}
 *
 * Any unrecognised or malformed format returns null.
 */
final class UsageLocator {

	/**
	 * Parse a scanner context string into a host type and ID.
	 *
	 * Returns null for context strings that are not post/term-bound
	 * (e.g. 'option:*' patterns) or for any malformed input.
	 *
	 * @param string $context Raw context string from UsageHit::context().
	 * @return array{host_type: string, host_id: int}|null
	 */
	public static function parse( string $context ): ?array {
		if ( '' === $context ) {
			return null;
		}

		$parts = explode( ':', $context );

		switch ( $parts[0] ) {
			case 'post':
				// post:{ID}:{field} — needs at least 3 parts.
				if ( count( $parts ) < 3 ) {
					return null;
				}
				return self::post_host( $parts[1] );

			case 'postmeta':
				// Host post id is the second segment.
				if ( count( $parts ) < 3 ) {
					return null;
				}
				return self::post_host( $parts[1] );

			case 'woo':
				return self::parse_woo( $parts );

			case 'acf':
				return self::parse_acf( $parts );

			case 'option':
				// option:{name} — not post/term-bound.
				return null;

			case 'termmeta':
				// Host term id is the second segment.
				if ( count( $parts ) < 3 ) {
					return null;
				}
				return self::term_host( $parts[1] );

			default:
				return null;
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Parse a woo: context string.
	 *
	 * Two accepted shapes:
	 *   woo:product:{post_id}:{gallery|downloads}  → post host
	 *   woo:{label}:{ID}:featured                  → post host
	 *
	 * @param string[] $parts Exploded context parts.
	 * @return array{host_type: string, host_id: int}|null
	 */
	private static function parse_woo( array $parts ): ?array {
		// Need at least 4 parts: woo + sub-type + id + field.
		if ( count( $parts ) < 4 ) {
			return null;
		}

		if ( 'product' === $parts[1] ) {
			// Product gallery or downloads — host id is the third segment.
			return self::post_host( $parts[2] );
		}

		// Featured image — host id is the third segment.
		if ( 'featured' === $parts[3] ) {
			return self::post_host( $parts[2] );
		}

		return null;
	}

	/**
	 * Parse an acf: context string.
	 *
	 * Three accepted sub-types:
	 *   acf:post:{post_id}:{meta_key}   → post host
	 *   acf:term:{term_id}:{meta_key}   → term host
	 *   acf:option:{name}               → null
	 *
	 * @param string[] $parts Exploded context parts.
	 * @return array{host_type: string, host_id: int}|null
	 */
	private static function parse_acf( array $parts ): ?array {
		if ( count( $parts ) < 2 ) {
			return null;
		}

		switch ( $parts[1] ) {
			case 'post':
				// ACF post field — host id is the third segment.
				if ( count( $parts ) < 4 ) {
					return null;
				}
				return self::post_host( $parts[2] );

			case 'term':
				// ACF term field — host id is the third segment.
				if ( count( $parts ) < 4 ) {
					return null;
				}
				return self::term_host( $parts[2] );

			case 'option':
				// acf:option:{name} — not post/term-bound.
				return null;

			default:
				return null;
		}
	}

	/**
	 * Build a post host result, validating the raw ID string.
	 *
	 * Returns null if the raw value is not a positive integer.
	 *
	 * @param string $raw_id Raw ID string from the context parts.
	 * @return array{host_type: string, host_id: int}|null
	 */
	private static function post_host( string $raw_id ): ?array {
		$id = self::positive_int( $raw_id );
		if ( null === $id ) {
			return null;
		}
		return array(
			'host_type' => 'post',
			'host_id'   => $id,
		);
	}

	/**
	 * Build a term host result, validating the raw ID string.
	 *
	 * Returns null if the raw value is not a positive integer.
	 *
	 * @param string $raw_id Raw ID string from the context parts.
	 * @return array{host_type: string, host_id: int}|null
	 */
	private static function term_host( string $raw_id ): ?array {
		$id = self::positive_int( $raw_id );
		if ( null === $id ) {
			return null;
		}
		return array(
			'host_type' => 'term',
			'host_id'   => $id,
		);
	}

	/**
	 * Cast a string to a positive integer, or return null.
	 *
	 * Only pure digit strings that evaluate to > 0 are accepted. Strings like
	 * "0", "-1", "1.5", or non-numeric values all return null.
	 *
	 * @param string $value Raw string value.
	 * @return int|null Positive integer or null.
	 */
	private static function positive_int( string $value ): ?int {
		if ( ! ctype_digit( $value ) ) {
			return null;
		}
		$int = (int) $value;
		if ( $int <= 0 ) {
			return null;
		}
		return $int;
	}
}
