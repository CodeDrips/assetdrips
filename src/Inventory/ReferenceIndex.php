<?php
/**
 * Resolves references to attachment IDs, variant-aware.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Inventory;

defined( 'ABSPATH' ) || exit;

/**
 * The reverse index from reference tokens to attachment IDs.
 *
 * Built once per scan from the {@see AttachmentCatalogue}: every attachment's
 * variant relative paths are registered, so a reference to ANY size, the
 * original, or a backup resolves to the parent attachment. This is where the
 * prime directive lives — a missed variant here is a false "unused" verdict.
 *
 * Lookups are exact on the uploads-relative path, with a lowercase fallback to
 * survive filesystem case differences (the conservative, mark-as-used direction).
 */
final class ReferenceIndex {

	/**
	 * Uploads-relative path to attachment ID.
	 *
	 * @var array<string, int>
	 */
	private array $relative_to_id = array();

	/**
	 * Lowercased uploads-relative path to attachment ID (case fallback).
	 *
	 * @var array<string, int>
	 */
	private array $relative_lc_to_id = array();

	/**
	 * Known attachment IDs.
	 *
	 * @var array<int, true>
	 */
	private array $ids = array();

	/**
	 * Uploads prefixes used for extraction and SQL candidate filtering.
	 *
	 * @var string[]
	 */
	private array $prefixes;

	/**
	 * Extractor bound to the same prefixes.
	 *
	 * @var ReferenceExtractor
	 */
	private ReferenceExtractor $extractor;

	/**
	 * Construct an empty index for a set of uploads prefixes.
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
		$this->prefixes  = array_keys( $clean );
		$this->extractor = new ReferenceExtractor( $this->prefixes );
	}

	/**
	 * Derive the uploads prefixes to anchor on from an uploads location.
	 *
	 * Returns the URL path component (covers every URL shape and, since the
	 * filesystem path contains it, most absolute paths too) plus the filesystem
	 * base directory (covers uploads relocated outside wp-content).
	 *
	 * @param string $base_url Uploads base URL.
	 * @param string $base_dir Uploads base directory.
	 * @return string[]
	 */
	public static function prefixes_from_uploads( string $base_url, string $base_dir ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure string computation; keeps the index unit-testable without WordPress.
		$url_path = (string) parse_url( rtrim( $base_url, '/' ), PHP_URL_PATH );

		return array(
			rtrim( $url_path, '/' ),
			rtrim( $base_dir, '/' ),
		);
	}

	/**
	 * Register an attachment's match keys into the index.
	 *
	 * @param MatchKeys $keys Variant-aware keys for one attachment.
	 * @return void
	 */
	public function register( MatchKeys $keys ): void {
		$id               = $keys->id();
		$this->ids[ $id ] = true;

		foreach ( $keys->relative_paths() as $relative ) {
			$this->relative_to_id[ $relative ]                  = $id;
			$this->relative_lc_to_id[ strtolower( $relative ) ] = $id;
		}
	}

	/**
	 * Whether an ID is a known attachment.
	 *
	 * @param int $id Candidate ID.
	 * @return bool
	 */
	public function is_attachment( int $id ): bool {
		return isset( $this->ids[ $id ] );
	}

	/**
	 * Resolve a single reference value to an attachment ID.
	 *
	 * @param string $value URL, path, or relative path.
	 * @return int|null Attachment ID, or null if unresolved.
	 */
	public function match( string $value ): ?int {
		$relative = $this->extractor->normalize( $value );
		if ( null === $relative || '' === $relative ) {
			return null;
		}

		if ( isset( $this->relative_to_id[ $relative ] ) ) {
			return $this->relative_to_id[ $relative ];
		}

		return $this->relative_lc_to_id[ strtolower( $relative ) ] ?? null;
	}

	/**
	 * Find every attachment referenced by URL or path within free text.
	 *
	 * @param string $text Arbitrary text.
	 * @return array<int, string> Attachment ID to the matched token (evidence).
	 */
	public function find_in_text( string $text ): array {
		$out = array();
		foreach ( $this->extractor->relative_candidates( $text ) as $candidate ) {
			$id = $this->match( $candidate );
			if ( null !== $id ) {
				$out[ $id ] = $candidate;
			}
		}

		return $out;
	}

	/**
	 * Find every attachment referenced by ID within content-bearing text.
	 *
	 * @param string $text Arbitrary text.
	 * @return int[] Attachment IDs.
	 */
	public function ids_in_text( string $text ): array {
		$out = array();
		foreach ( ReferenceExtractor::id_candidates( $text ) as $id ) {
			if ( $this->is_attachment( $id ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * The uploads prefixes, for building SQL candidate filters.
	 *
	 * @return string[]
	 */
	public function prefixes(): array {
		return $this->prefixes;
	}

	/**
	 * Number of registered attachments.
	 *
	 * @return int
	 */
	public function size(): int {
		return count( $this->ids );
	}
}
