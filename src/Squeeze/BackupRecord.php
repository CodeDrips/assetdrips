<?php
/**
 * A squeeze backup record.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Squeeze;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable view of one row in the assetdrips_squeeze_backups table.
 *
 * Holds everything needed to identify, verify, and restore a backup of an
 * attachment's original file. BackupManager creates these by hydrating DB rows;
 * SqueezeEngine reads them to check backup state before encoding.
 */
final class BackupRecord {

	/**
	 * Status: file copied and record inserted; backup is available for restore.
	 */
	public const ACTIVE = 'active';

	/**
	 * Status: backup renamed back over the original; attachment is restored.
	 */
	public const RESTORED = 'restored';

	/**
	 * Status: backup file deleted; no longer restorable.
	 */
	public const PURGED = 'purged';

	/**
	 * Backup record ID.
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
	 * Operation that triggered the backup ('recompress' or 'resize').
	 *
	 * @var string
	 */
	private string $op;

	/**
	 * Absolute path to the original file.
	 *
	 * @var string
	 */
	private string $original_path;

	/**
	 * Absolute path to the backup copy.
	 *
	 * @var string
	 */
	private string $backup_path;

	/**
	 * Filesize of the original at backup time, in bytes.
	 *
	 * @var int
	 */
	private int $original_bytes;

	/**
	 * Record status (one of the status constants).
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * MySQL datetime when the backup was created.
	 *
	 * @var string
	 */
	private string $backed_up_at;

	/**
	 * MySQL datetime when the backup was restored, or null if not yet restored.
	 *
	 * @var string|null
	 */
	private ?string $restored_at;

	/**
	 * Construct a backup record.
	 *
	 * @param int         $id             Record ID.
	 * @param int         $attachment_id  Attachment post ID.
	 * @param string      $op             Operation: 'recompress' or 'resize'.
	 * @param string      $original_path  Absolute path to the original file.
	 * @param string      $backup_path    Absolute path to the backup copy.
	 * @param int         $original_bytes Filesize of original at backup time.
	 * @param string      $status         Record status.
	 * @param string      $backed_up_at   MySQL datetime when backed up.
	 * @param string|null $restored_at    MySQL datetime when restored, or null.
	 */
	public function __construct(
		int $id,
		int $attachment_id,
		string $op,
		string $original_path,
		string $backup_path,
		int $original_bytes,
		string $status,
		string $backed_up_at,
		?string $restored_at
	) {
		$this->id             = $id;
		$this->attachment_id  = $attachment_id;
		$this->op             = $op;
		$this->original_path  = $original_path;
		$this->backup_path    = $backup_path;
		$this->original_bytes = $original_bytes;
		$this->status         = $status;
		$this->backed_up_at   = $backed_up_at;
		$this->restored_at    = $restored_at;
	}

	/**
	 * Backup record ID.
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
	 * Operation that triggered the backup.
	 *
	 * @return string
	 */
	public function op(): string {
		return $this->op;
	}

	/**
	 * Absolute path to the original file.
	 *
	 * @return string
	 */
	public function original_path(): string {
		return $this->original_path;
	}

	/**
	 * Absolute path to the backup copy.
	 *
	 * @return string
	 */
	public function backup_path(): string {
		return $this->backup_path;
	}

	/**
	 * Filesize of the original at backup time, in bytes.
	 *
	 * @return int
	 */
	public function original_bytes(): int {
		return $this->original_bytes;
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
	 * MySQL datetime when the backup was created.
	 *
	 * @return string
	 */
	public function backed_up_at(): string {
		return $this->backed_up_at;
	}

	/**
	 * MySQL datetime when the backup was restored, or null if not restored yet.
	 *
	 * @return string|null
	 */
	public function restored_at(): ?string {
		return $this->restored_at;
	}

	/**
	 * Whether the backup is still active (available for restore).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return self::ACTIVE === $this->status;
	}
}
