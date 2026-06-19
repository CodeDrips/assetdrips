<?php
/**
 * Pure derivation seam for a single media index row.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Derives the structural columns of one {@see \AssetDrips\Db\Schema} media row
 * from raw attachment inputs.
 *
 * This is the unit-testable seam of the index, mirroring the project convention
 * of {@see \AssetDrips\Inventory\AttachmentCatalogue::build_keys()} and
 * {@see \AssetDrips\Scan\ScanService::result_row()}: pure logic, zero DB, zero
 * WordPress globals. The impure work — resolving the filesize fallback, fetching
 * meta — happens in the hook/builder wrappers, which then delegate here.
 *
 * Only structural-lane columns are emitted. The usage lane (usage_count,
 * is_used, usage_synced_at) and the nullable, later-phase columns (folder_id,
 * content_hash) are deliberately omitted so their DDL DEFAULTs hold and the
 * structural writer never clobbers the usage lane.
 */
final class MediaRow {

	/**
	 * Derive the structural index columns from raw inputs. Pure.
	 *
	 * @param int                  $attachment_id Attachment post ID.
	 * @param string               $filename      Base filename (no path).
	 * @param string               $title         Attachment title.
	 * @param string               $alt           Alt text (may be empty).
	 * @param string               $caption       Caption (post_excerpt; may be empty).
	 * @param string               $description   Description (post_content; may be empty).
	 * @param string               $mime          Full MIME type, e.g. image/png.
	 * @param array<string, mixed> $meta          Unserialised _wp_attachment_metadata.
	 * @param int                  $filesize      Resolved file size in bytes.
	 * @param int                  $uploaded_by   Author user ID.
	 * @param string               $uploaded_at   Upload datetime (MySQL format).
	 * @param string               $indexed_at    Index-write datetime (MySQL format).
	 * @return array<string, int|string> Structural columns only.
	 */
	public static function from_attachment(
		int $attachment_id,
		string $filename,
		string $title,
		string $alt,
		string $caption,
		string $description,
		string $mime,
		array $meta,
		int $filesize,
		int $uploaded_by,
		string $uploaded_at,
		string $indexed_at
	): array {
		$width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;

		list( , $mime_subtype ) = self::split_mime( $mime );

		return array(
			'attachment_id' => $attachment_id,
			'filename'      => $filename,
			'title'         => $title,
			'alt'           => $alt,
			'caption'       => $caption,
			'description'   => $description,
			'mime'          => $mime,
			'mime_subtype'  => $mime_subtype,
			'width'         => $width,
			'height'        => $height,
			'orientation'   => self::orientation( $width, $height ),
			'filesize'      => $filesize,
			'has_alt'       => '' !== trim( $alt ) ? 1 : 0,
			'uploaded_by'   => $uploaded_by,
			'uploaded_at'   => $uploaded_at,
			'indexed_at'    => $indexed_at,
		);
	}

	/**
	 * Classify orientation from dimensions. Pure.
	 *
	 * Returns '' when either dimension is unknown (0) so the facet stays honest
	 * rather than guessing.
	 *
	 * @param int $w Width in pixels.
	 * @param int $h Height in pixels.
	 * @return string '' | 'landscape' | 'portrait' | 'square'.
	 */
	public static function orientation( int $w, int $h ): string {
		if ( 0 === $w || 0 === $h ) {
			return '';
		}

		if ( $w > $h ) {
			return 'landscape';
		}

		if ( $w < $h ) {
			return 'portrait';
		}

		return 'square';
	}

	/**
	 * Split a MIME type into its main type and subtype. Pure.
	 *
	 * For example 'image/png' => array( 'image', 'png' ). A malformed value with
	 * no slash yields an empty subtype.
	 *
	 * @param string $mime Full MIME type.
	 * @return array{0: string, 1: string} [ main, subtype ].
	 */
	public static function split_mime( string $mime ): array {
		$slash = strpos( $mime, '/' );

		if ( false === $slash ) {
			return array( $mime, '' );
		}

		return array(
			substr( $mime, 0, $slash ),
			substr( $mime, $slash + 1 ),
		);
	}
}
