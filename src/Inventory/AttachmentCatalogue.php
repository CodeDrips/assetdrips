<?php
/**
 * Attachment catalogue: builds variant-aware match keys for every attachment.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Inventory;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 0 inventory.
 *
 * Catalogues every attachment and precomputes its {@see MatchKeys}: the full set
 * of URLs and paths that — for ANY generated size, the original pre-scaled file,
 * or a backup of an edited image — must mark the attachment used.
 *
 * The variant-derivation logic ({@see build_keys()}) is pure: it takes the raw
 * meta values and the uploads base, and touches no WordPress globals. That keeps
 * the correctness-critical part unit-testable against fixtures without a DB. The
 * DB-backed helpers are thin wrappers that fetch meta and delegate to it.
 */
final class AttachmentCatalogue {

	/**
	 * Postmeta keys read per attachment.
	 */
	private const ATTACHED_FILE = '_wp_attached_file';
	private const METADATA      = '_wp_attachment_metadata';
	private const BACKUP_SIZES  = '_wp_attachment_backup_sizes';

	/**
	 * WordPress database handle. Null in pure unit tests that only exercise
	 * build_keys().
	 *
	 * @var \wpdb|null
	 */
	private ?\wpdb $wpdb;

	/**
	 * Uploads base directory, no trailing slash.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Uploads base URL, no trailing slash.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Path component of the base URL (root-relative prefix), no trailing slash.
	 *
	 * @var string
	 */
	private string $url_path;

	/**
	 * Construct with an explicit uploads base, for testability.
	 *
	 * @param \wpdb|null $wpdb     Database handle (null for pure unit use).
	 * @param string     $base_dir Uploads base directory.
	 * @param string     $base_url Uploads base URL.
	 */
	public function __construct( ?\wpdb $wpdb, string $base_dir, string $base_url ) {
		$this->wpdb     = $wpdb;
		$this->base_dir = rtrim( $base_dir, '/' );
		$this->base_url = rtrim( $base_url, '/' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure string computation; avoids a WordPress dependency so the catalogue stays unit-testable.
		$path           = (string) parse_url( $this->base_url, PHP_URL_PATH );
		$this->url_path = rtrim( $path, '/' );
	}

	/**
	 * Construct from the live WordPress environment.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		global $wpdb;

		$uploads = wp_get_upload_dir();

		return new self( $wpdb, (string) $uploads['basedir'], (string) $uploads['baseurl'] );
	}

	/**
	 * Build match keys from raw meta values. Pure — no WordPress dependency.
	 *
	 * @param int                  $id            Attachment post ID.
	 * @param string               $attached_file Value of _wp_attached_file.
	 * @param array<string, mixed> $metadata      Unserialised _wp_attachment_metadata.
	 * @param array<string, mixed> $backup_sizes  Unserialised _wp_attachment_backup_sizes.
	 * @return MatchKeys
	 */
	public function build_keys( int $id, string $attached_file, array $metadata, array $backup_sizes = array() ): MatchKeys {
		$relatives = $this->collect_relative_paths( $attached_file, $metadata, $backup_sizes );

		$urls  = array();
		$paths = array();

		foreach ( $relatives as $rel ) {
			$urls[] = $this->base_url . '/' . $rel;
			if ( '' !== $this->url_path ) {
				$urls[] = $this->url_path . '/' . $rel;
			}
			$paths[] = $this->base_dir . '/' . $rel;
		}

		return new MatchKeys( $id, $urls, $paths, $relatives );
	}

	/**
	 * Build match keys for one attachment by ID, fetching its meta.
	 *
	 * @param int $id Attachment post ID.
	 * @return MatchKeys|null Null when the post has no attached file or metadata.
	 */
	public function keys_for( int $id ): ?MatchKeys {
		$attached = get_post_meta( $id, self::ATTACHED_FILE, true );
		$attached = is_string( $attached ) ? $attached : '';

		$metadata = get_post_meta( $id, self::METADATA, true );
		$metadata = is_array( $metadata ) ? $metadata : array();

		$backup = get_post_meta( $id, self::BACKUP_SIZES, true );
		$backup = is_array( $backup ) ? $backup : array();

		if ( '' === $attached && array() === $metadata ) {
			return null;
		}

		return $this->build_keys( $id, $attached, $metadata, $backup );
	}

	/**
	 * Iterate every attachment in resumable, memory-safe batches.
	 *
	 * Uses keyset pagination (ID greater than a cursor) rather than OFFSET so a
	 * scan can resume from the returned cursor without drift if rows change.
	 * The consumer receives a list of {@see MatchKeys} for each batch.
	 *
	 * @param int                         $batch_size     Rows per batch.
	 * @param callable(MatchKeys[]): void $consumer       Receives each batch.
	 * @param int                         $start_after_id Resume cursor; 0 from the start.
	 * @return int The highest attachment ID processed (next resume cursor).
	 *
	 * @throws \RuntimeException When constructed without a database handle.
	 */
	public function each_batch( int $batch_size, callable $consumer, int $start_after_id = 0 ): int {
		$this->require_wpdb();
		$wpdb = $this->wpdb;

		$batch_size = max( 1, $batch_size );
		$last_id    = $start_after_id;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A full-library scan is inherently direct; caching stale rows would defeat the purpose.
			$ids     = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
					$last_id,
					$batch_size
				)
			);
			$ids     = array_map( 'intval', (array) $ids );
			$fetched = count( $ids );

			if ( 0 === $fetched ) {
				break;
			}

			$consumer( $this->keys_for_ids( $ids ) );

			$last_id = (int) end( $ids );
		} while ( $fetched === $batch_size );

		return $last_id;
	}

	/**
	 * Build match keys for a set of IDs with bulk meta fetching.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return MatchKeys[] In ascending ID order; IDs with no file/meta are skipped.
	 */
	private function keys_for_ids( array $ids ): array {
		$this->require_wpdb();
		$wpdb = $this->wpdb;

		if ( array() === $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// meta_key list is a fixed literal; only the IDs are interpolated as %d.
		$sql = "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			WHERE post_id IN ( {$placeholders} )
			AND meta_key IN ( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' )";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders is a list of %d built from the ID count and the IDs are passed to prepare(); a full-library scan is inherently direct and uncached.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );

		$by_id = array();
		foreach ( (array) $rows as $row ) {
			$by_id[ (int) $row['post_id'] ][ $row['meta_key'] ] = $row['meta_value'];
		}

		$keys = array();
		foreach ( $ids as $id ) {
			$meta = $by_id[ $id ] ?? array();

			$attached = isset( $meta[ self::ATTACHED_FILE ] ) ? (string) $meta[ self::ATTACHED_FILE ] : '';
			$metadata = isset( $meta[ self::METADATA ] ) ? maybe_unserialize( $meta[ self::METADATA ] ) : array();
			$backup   = isset( $meta[ self::BACKUP_SIZES ] ) ? maybe_unserialize( $meta[ self::BACKUP_SIZES ] ) : array();

			$metadata = is_array( $metadata ) ? $metadata : array();
			$backup   = is_array( $backup ) ? $backup : array();

			if ( '' === $attached && array() === $metadata ) {
				continue;
			}

			$keys[] = $this->build_keys( $id, $attached, $metadata, $backup );
		}

		return $keys;
	}

	/**
	 * Collect the deduplicated set of uploads-relative paths for an attachment:
	 * the main file, the metadata file, the original pre-scaled image, every
	 * generated sub-size, and every backup (pre-edit) size.
	 *
	 * @param string               $attached_file Value of _wp_attached_file.
	 * @param array<string, mixed> $metadata      Unserialised attachment metadata.
	 * @param array<string, mixed> $backup_sizes  Unserialised backup sizes.
	 * @return string[] Uploads-relative paths, no leading slash.
	 */
	private function collect_relative_paths( string $attached_file, array $metadata, array $backup_sizes ): array {
		$attached_file = ltrim( $attached_file, '/' );

		// Insertion order is preserved by the associative set, keeping output stable.
		$set = array();

		if ( '' !== $attached_file ) {
			$set[ $attached_file ] = true;
		}

		$meta_file = ( isset( $metadata['file'] ) && is_string( $metadata['file'] ) ) ? ltrim( $metadata['file'], '/' ) : '';
		if ( '' !== $meta_file ) {
			$set[ $meta_file ] = true;
		}

		// Directory that variant basenames live in.
		$dir = $this->dir_of( '' !== $meta_file ? $meta_file : $attached_file );

		// Original, pre-scaled image (present when WP created a -scaled version).
		if ( isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && '' !== $metadata['original_image'] ) {
			$set[ $this->join_variant( $dir, $metadata['original_image'] ) ] = true;
		}

		foreach ( $this->size_files( $metadata ) as $file ) {
			$set[ $this->join_variant( $dir, $file ) ] = true;
		}

		foreach ( $this->size_files( array( 'sizes' => $backup_sizes ) ) as $file ) {
			$set[ $this->join_variant( $dir, $file ) ] = true;
		}

		return array_keys( $set );
	}

	/**
	 * Extract the 'file' basenames from a metadata 'sizes' map, defensively.
	 *
	 * @param array<string, mixed> $metadata Metadata containing a 'sizes' map.
	 * @return string[]
	 */
	private function size_files( array $metadata ): array {
		if ( ! isset( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return array();
		}

		$files = array();
		foreach ( $metadata['sizes'] as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) && '' !== $size['file'] ) {
				$files[] = $size['file'];
			}
		}

		return $files;
	}

	/**
	 * Directory portion of a relative path, or '' for a top-level file.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	private function dir_of( string $relative ): string {
		$dir = dirname( $relative );

		return ( '.' === $dir || '' === $dir || '/' === $dir ) ? '' : $dir;
	}

	/**
	 * Join a directory and a variant filename. A filename that already carries
	 * its own path is treated as uploads-relative and used as-is.
	 *
	 * @param string $dir  Directory (may be empty).
	 * @param string $file Variant filename or relative path.
	 * @return string
	 */
	private function join_variant( string $dir, string $file ): string {
		$file = ltrim( $file, '/' );

		if ( str_contains( $file, '/' ) ) {
			return $file;
		}

		return '' === $dir ? $file : $dir . '/' . $file;
	}

	/**
	 * Guard the DB-backed methods against pure-unit construction.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When constructed without a database handle.
	 */
	private function require_wpdb(): void {
		if ( null === $this->wpdb ) {
			throw new \RuntimeException( 'AttachmentCatalogue requires a database handle for this operation.' );
		}
	}
}
