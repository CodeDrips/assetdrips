<?php
/**
 * A quarantine recovery record.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Quarantine;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable view of one row in the quarantine table.
 *
 * Holds everything needed to undo a quarantine exactly: the snapshot of the
 * attachment's wp_posts row and postmeta, and the from/to map of every file that
 * was moved. The review screen and CLI read these to list and restore.
 */
final class RecoveryRecord {

	/**
	 * Status: file moved and DB rows snapshotted; fully restorable.
	 */
	public const QUARANTINED = 'quarantined';

	/**
	 * Status: moved back and DB rows re-inserted.
	 */
	public const RESTORED = 'restored';

	/**
	 * Status: permanently deleted; no longer restorable.
	 */
	public const PURGED = 'purged';

	/**
	 * Recovery record ID.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Attachment post ID.
	 *
	 * @var int
	 */
	private int $attachment_id;

	/**
	 * Record status (one of the status constants).
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Snapshot: { post: array, postmeta: array[] }.
	 *
	 * @var array<string, mixed>
	 */
	private array $post_snapshot;

	/**
	 * File move map: list of { from, to }.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $file_paths;

	/**
	 * Construct a recovery record.
	 *
	 * @param int                               $id            Record ID.
	 * @param int                               $attachment_id Attachment post ID.
	 * @param string                            $status        Record status.
	 * @param array<string, mixed>              $post_snapshot DB snapshot.
	 * @param array<int, array<string, string>> $file_paths    File move map.
	 */
	public function __construct( int $id, int $attachment_id, string $status, array $post_snapshot, array $file_paths ) {
		$this->id            = $id;
		$this->attachment_id = $attachment_id;
		$this->status        = $status;
		$this->post_snapshot = $post_snapshot;
		$this->file_paths    = $file_paths;
	}

	/**
	 * Recovery record ID.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Attachment post ID.
	 *
	 * @return int
	 */
	public function attachment_id(): int {
		return $this->attachment_id;
	}

	/**
	 * Record status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Whether the record is still restorable.
	 *
	 * @return bool
	 */
	public function is_restorable(): bool {
		return self::QUARANTINED === $this->status;
	}

	/**
	 * Snapshot of the attachment's wp_posts row.
	 *
	 * @return array<string, mixed>
	 */
	public function post_row(): array {
		$post = $this->post_snapshot['post'] ?? array();

		return is_array( $post ) ? $post : array();
	}

	/**
	 * Snapshot of the attachment's postmeta rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function postmeta_rows(): array {
		$meta = $this->post_snapshot['postmeta'] ?? array();

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * File move map: list of { from, to }.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function file_paths(): array {
		return $this->file_paths;
	}
}
