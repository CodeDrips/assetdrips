<?php
/**
 * Real-time structural-lane freshness hooks for the media index.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the WordPress attachment-lifecycle hooks that keep the
 * STRUCTURAL lane of the assetdrips_media index fresh within the same request
 * (CON-structural-hooks). Upload, metadata generation, title edits (classic,
 * list-table AND REST/Gutenberg), alt-text changes, and deletion each fire a
 * cheap single-row upsert or delete — never a usage recompute.
 *
 * Every handler is the impure wrapper of the project's pure-seam convention: it
 * resolves raw, WordPress-supplied values (casting to int/string per ASVS V5),
 * delegates derivation to the pure {@see MediaRow::from_attachment()}, then
 * writes through {@see MediaIndex}. Handlers NEVER compute usage, fold in a
 * scanner, or write usage_count/is_used/usage_synced_at/folder_id/content_hash
 * (CON-two-lane-freshness) — those columns belong to the usage lane alone.
 */
final class IndexHooks {

	/**
	 * The alt-text meta key the alt handlers act on.
	 */
	private const ALT_META_KEY = '_wp_attachment_image_alt';

	/**
	 * The attachment metadata meta key.
	 */
	private const METADATA = '_wp_attachment_metadata';

	/**
	 * The attached-file meta key.
	 */
	private const ATTACHED_FILE = '_wp_attached_file';

	/**
	 * Re-entry guard for on_update().
	 *
	 * Prevents infinite recursion when wp_update_post() fires attachment_updated
	 * and edit_attachment in a tight loop during bulk operations (T-07-dos-recursion /
	 * STATE.md Phase 7 blocker). A try/finally in on_update() guarantees the flag is
	 * always reset even when an exception is thrown.
	 *
	 * @var bool
	 */
	private static bool $in_update = false;

	/**
	 * Register every structural-lane hook.
	 *
	 * The hook-to-handler contract is fixed by CON-structural-hooks:
	 *  - add_attachment                  → on_add (base row on upload)
	 *  - wp_generate_attachment_metadata → on_meta (FILTER: fill dims/filesize,
	 *                                       return $meta UNCHANGED)
	 *  - attachment_updated              → on_update (title, classic/list-table)
	 *  - edit_attachment                 → on_update (title, REST/Gutenberg — the
	 *                                       path that does not reliably fire
	 *                                       attachment_updated; Open Q2 resolved)
	 *  - added_post_meta / updated_post_meta → on_alt (alt + has_alt)
	 *  - deleted_post_meta               → on_alt_deleted (alt='' / has_alt=0)
	 *  - delete_attachment               → on_delete (row removed; fires before
	 *                                       the attachment is deleted)
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_attachment', array( $this, 'on_add' ), 10, 1 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_meta' ), 10, 3 );
		add_action( 'attachment_updated', array( $this, 'on_update' ), 10, 1 );
		add_action( 'edit_attachment', array( $this, 'on_update' ), 10, 1 );
		add_action( 'added_post_meta', array( $this, 'on_alt' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'on_alt' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'on_alt_deleted' ), 10, 4 );
		add_action( 'delete_attachment', array( $this, 'on_delete' ), 10, 1 );
	}

	/**
	 * Upsert a base structural row when an attachment is created.
	 *
	 * Dimensions and filesize are not yet available at this point (sub-sizes are
	 * generated later), so they derive to 0/'' here and are filled by
	 * {@see self::on_meta()} once wp_generate_attachment_metadata runs.
	 *
	 * @param int $post_id Newly inserted attachment post ID.
	 * @return void
	 */
	public function on_add( int $post_id ): void {
		$this->index()->upsert_structural( $this->derive_row( $post_id, array() ) );
	}

	/**
	 * Observe wp_generate_attachment_metadata to fill width/height/filesize.
	 *
	 * This is a FILTER, not an action: it MUST return $meta unchanged. Hooking it
	 * is purely to observe the freshly generated metadata (where dimensions and,
	 * from WP 6.0, filesize live) and refresh the structural row with them.
	 *
	 * @param mixed  $meta    Generated attachment metadata (array when present).
	 * @param int    $id      Attachment post ID.
	 * @param string $context Generation context ('create' | 'update').
	 * @return mixed The $meta argument, unchanged.
	 */
	public function on_meta( $meta, $id, $context = '' ) {
		unset( $context );

		$meta_array = is_array( $meta ) ? $meta : array();

		$this->index()->upsert_structural( $this->derive_row( (int) $id, $meta_array ) );

		return $meta;
	}

	/**
	 * Refresh the title when an attachment is edited.
	 *
	 * Registered on BOTH attachment_updated (classic / list-table edits) AND
	 * edit_attachment (REST / Gutenberg edits, which do not reliably fire
	 * attachment_updated). Double-firing is safe: the structural upsert is an
	 * idempotent ON DUPLICATE KEY UPDATE keyed on attachment_id. edit_attachment
	 * is attachment-scoped, so no post-type guard is required.
	 *
	 * @param int $post_id Edited attachment post ID.
	 * @return void
	 */
	public function on_update( int $post_id ): void {
		if ( self::$in_update ) {
			return;
		}
		self::$in_update = true;
		try {
			$this->index()->upsert_structural( $this->derive_row( $post_id, array() ) );
		} finally {
			self::$in_update = false;
		}
	}

	/**
	 * Refresh alt + has_alt when the alt-text meta is added or updated.
	 *
	 * Guards on the meta key (Pitfall 4) so unrelated post-meta writes are
	 * ignored. Re-derives the full structural row so has_alt and alt stay
	 * consistent.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $object_id  Attachment post ID.
	 * @param string $meta_key   Meta key being written.
	 * @param mixed  $meta_value New meta value (unused; read fresh on derive).
	 * @return void
	 */
	public function on_alt( $meta_id, $object_id, $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );

		if ( self::ALT_META_KEY !== $meta_key ) {
			return;
		}

		$this->index()->upsert_structural( $this->derive_row( (int) $object_id, array() ) );
	}

	/**
	 * Reset alt + has_alt when the alt-text meta is deleted.
	 *
	 * Same key guard as {@see self::on_alt()}. With the alt meta gone, the
	 * re-derived row carries alt='' and has_alt=0 (Pitfall 4 — deleted_post_meta).
	 *
	 * @param int    $meta_ids   Meta row ID(s) (unused).
	 * @param int    $object_id  Attachment post ID.
	 * @param string $meta_key   Meta key being deleted.
	 * @param mixed  $meta_value Deleted meta value (unused).
	 * @return void
	 */
	public function on_alt_deleted( $meta_ids, $object_id, $meta_key, $meta_value ): void {
		unset( $meta_ids, $meta_value );

		if ( self::ALT_META_KEY !== $meta_key ) {
			return;
		}

		$this->index()->upsert_structural( $this->derive_row( (int) $object_id, array() ) );
	}

	/**
	 * Delete the index row when an attachment is deleted.
	 *
	 * The delete_attachment hook fires BEFORE the attachment is removed, so the
	 * row is still readable, but only the attachment_id is needed to drop it.
	 *
	 * @param int $post_id Attachment post ID being deleted.
	 * @return void
	 */
	public function on_delete( int $post_id ): void {
		$this->index()->delete( $post_id );
	}

	/**
	 * Derive the structural row for one attachment, resolving impure inputs.
	 *
	 * Fetches the post and the meta MediaRow needs, resolves the filesize
	 * fallback (Pitfall 1) preferring freshly generated $meta, then delegates to
	 * the pure {@see MediaRow}. Reads alt from meta each time so the alt handlers
	 * need not pass a value through. Computes no usage.
	 *
	 * @param int                  $id   Attachment post ID.
	 * @param array<string, mixed> $meta Freshly generated metadata, or empty to read stored meta.
	 * @return array<string, int|string> Structural columns.
	 */
	private function derive_row( int $id, array $meta ): array {
		$post = get_post( $id );

		$title       = ( $post instanceof \WP_Post ) ? (string) $post->post_title : '';
		$caption     = ( $post instanceof \WP_Post ) ? (string) $post->post_excerpt : '';
		$description = ( $post instanceof \WP_Post ) ? (string) $post->post_content : '';
		$mime        = ( $post instanceof \WP_Post ) ? (string) $post->post_mime_type : '';
		$uploaded_by = ( $post instanceof \WP_Post ) ? (int) $post->post_author : 0;
		$uploaded_at = ( $post instanceof \WP_Post && '' !== (string) $post->post_date )
			? (string) $post->post_date
			: current_time( 'mysql' );

		if ( array() === $meta ) {
			$stored = get_post_meta( $id, self::METADATA, true );
			$meta   = is_array( $stored ) ? $stored : array();
		}

		$attached = get_post_meta( $id, self::ATTACHED_FILE, true );
		$attached = is_string( $attached ) ? $attached : '';
		$filename = '' !== $attached ? basename( $attached ) : '';

		$alt = get_post_meta( $id, self::ALT_META_KEY, true );
		$alt = is_string( $alt ) ? $alt : '';

		$filesize = $this->resolve_filesize( $id, $meta );

		return MediaRow::from_attachment(
			$id,
			$filename,
			$title,
			$alt,
			$caption,
			$description,
			$mime,
			$meta,
			$filesize,
			$uploaded_by,
			$uploaded_at,
			current_time( 'mysql' )
		);
	}

	/**
	 * Resolve the file size, preferring metadata and falling back to the file.
	 *
	 * WordPress only reliably carries 'filesize' in attachment metadata from WP
	 * 6.0 and not for every path (Pitfall 1), so fall back to the original file
	 * on disk when the meta value is absent or zero.
	 *
	 * @param int                  $id   Attachment post ID.
	 * @param array<string, mixed> $meta Attachment metadata.
	 * @return int File size in bytes (0 when unknown).
	 */
	private function resolve_filesize( int $id, array $meta ): int {
		if ( isset( $meta['filesize'] ) && (int) $meta['filesize'] > 0 ) {
			return (int) $meta['filesize'];
		}

		$path = get_attached_file( $id );
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			return (int) filesize( $path );
		}

		return 0;
	}

	/**
	 * The index data-access seam, resolved from the live environment.
	 *
	 * @return MediaIndex
	 */
	private function index(): MediaIndex {
		return MediaIndex::from_wordpress();
	}
}
