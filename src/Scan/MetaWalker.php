<?php
/**
 * Recursively walks meta/option values to find attachment references.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Scan;

use AssetDrips\Inventory\ReferenceIndex;
use AssetDrips\Usage\UsageHit;
use AssetDrips\Usage\UsageMap;

defined( 'ABSPATH' ) || exit;

/**
 * Traverses an unserialised meta or option value and records usage hits.
 *
 * The coding rule is absolute: never string-match serialised data. Callers
 * unserialise first; this walker then descends the real structure. On each
 * string leaf it resolves URL/path references; on numeric leaves it records an
 * ID reference only when the surrounding key looks media-related, so arbitrary
 * numbers do not become false "used" verdicts.
 *
 * Pure and WordPress-free, so the matching logic is unit-testable.
 */
final class MetaWalker {

	/**
	 * Key fragments that mark a value as likely holding a media reference.
	 */
	private const IMAGE_KEY_PATTERN = '/(image|img|thumb|logo|icon|photo|picture|gallery|media|avatar|banner|background|header|hero|slide|cover|attachment|gravatar|favicon)/i';

	/**
	 * Reference resolver.
	 *
	 * @var ReferenceIndex
	 */
	private ReferenceIndex $index;

	/**
	 * Construct with a resolver.
	 *
	 * @param ReferenceIndex $index Reference resolver.
	 */
	public function __construct( ReferenceIndex $index ) {
		$this->index = $index;
	}

	/**
	 * Walk a value, appending hits to the map.
	 *
	 * @param mixed    $value      Unserialised value (scalar, array, or object).
	 * @param string   $context    Human-readable locator for evidence.
	 * @param string   $source     Scanner source identifier.
	 * @param UsageMap $into        Shared evidence store.
	 * @param bool     $image_scope Whether an ancestor key looked media-related.
	 * @return void
	 */
	public function walk( $value, string $context, string $source, UsageMap $into, bool $image_scope = false ): void {
		if ( is_string( $value ) ) {
			$this->walk_string( $value, $context, $source, $into, $image_scope );
			return;
		}

		if ( is_int( $value ) ) {
			if ( $image_scope ) {
				$this->id_hit( $value, $context, $source, $into );
			}
			return;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$child_scope = $image_scope || $this->looks_like_image_key( (string) $key );
				$this->walk( $child, $context . '.' . $key, $source, $into, $child_scope );
			}
			return;
		}

		if ( is_object( $value ) ) {
			$this->walk( get_object_vars( $value ), $context, $source, $into, $image_scope );
		}
	}

	/**
	 * Whether a key name suggests it holds a media reference.
	 *
	 * @param string $key Key name.
	 * @return bool
	 */
	public function looks_like_image_key( string $key ): bool {
		return 1 === preg_match( self::IMAGE_KEY_PATTERN, $key );
	}

	/**
	 * Handle a string leaf: URL/path references, plus ID-bearing values when in
	 * a media-related key scope.
	 *
	 * @param string   $value       String leaf.
	 * @param string   $context     Evidence locator.
	 * @param string   $source      Scanner source identifier.
	 * @param UsageMap $into         Shared evidence store.
	 * @param bool     $image_scope Whether the key scope looked media-related.
	 * @return void
	 */
	private function walk_string( string $value, string $context, string $source, UsageMap $into, bool $image_scope ): void {
		foreach ( $this->index->find_in_text( $value ) as $id => $token ) {
			$into->add( new UsageHit( $id, $source, $context, UsageHit::MATCH_URL, (string) $token ) );
		}

		// Embedded media markup (wp-image-{id}, gallery/caption shortcodes) inside
		// rich-text values such as wysiwyg or HTML meta.
		foreach ( $this->index->ids_in_text( $value ) as $id ) {
			$into->add( new UsageHit( $id, $source, $context, UsageHit::MATCH_ID, (string) $id ) );
		}

		if ( $image_scope && 1 === preg_match( '/^\s*[\d,\s]+\s*$/', $value ) ) {
			$parts = preg_split( '/[,\s]+/', trim( $value ) );
			foreach ( ( false === $parts ? array() : $parts ) as $part ) {
				if ( '' !== $part && ctype_digit( $part ) ) {
					$this->id_hit( (int) $part, $context, $source, $into );
				}
			}
		}
	}

	/**
	 * Record an ID hit when the ID is a known attachment.
	 *
	 * @param int      $id      Candidate attachment ID.
	 * @param string   $context Evidence locator.
	 * @param string   $source  Scanner source identifier.
	 * @param UsageMap $into     Shared evidence store.
	 * @return void
	 */
	private function id_hit( int $id, string $context, string $source, UsageMap $into ): void {
		if ( $id > 0 && $this->index->is_attachment( $id ) ) {
			$into->add( new UsageHit( $id, $source, $context, UsageHit::MATCH_ID, (string) $id ) );
		}
	}
}
