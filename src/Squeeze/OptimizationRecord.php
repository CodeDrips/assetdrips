<?php
/**
 * Optimization record value object.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Squeeze;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable view of one row in the assetdrips_squeeze table.
 *
 * Mirrors the RecoveryRecord pattern: status constants, private typed properties,
 * one public getter per property, and JSON decoding of array fields (ops_completed,
 * sizes_audit) in the constructor so callers always receive PHP arrays.
 *
 * This class is the stable Phase-8 value-object contract that Phase 9+ classes
 * depend on. Do not add mutable state.
 */
final class OptimizationRecord {

	/**
	 * Status: optimization has been queued but not yet started.
	 */
	public const PENDING = 'pending';

	/**
	 * Status: optimization is currently running.
	 */
	public const RUNNING = 'running';

	/**
	 * Status: optimization completed successfully.
	 */
	public const COMPLETE = 'complete';

	/**
	 * Status: optimization failed (error_message carries the reason).
	 */
	public const FAILED = 'failed';

	/**
	 * Status: original file has been restored from backup; row is historical.
	 */
	public const RESTORED = 'restored';

	/**
	 * Row primary key.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * WordPress attachment post ID.
	 *
	 * @var int
	 */
	private int $attachment_id;

	/**
	 * Row status (one of the status constants).
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Original file size in bytes before any optimization.
	 *
	 * @var int
	 */
	private int $original_bytes;

	/**
	 * Optimized file size in bytes (recompressed original; 0 if not recompressed).
	 *
	 * @var int
	 */
	private int $optimized_bytes;

	/**
	 * WebP alternate file size in bytes (0 if not generated).
	 *
	 * @var int
	 */
	private int $webp_bytes;

	/**
	 * AVIF alternate file size in bytes (0 if not generated).
	 *
	 * @var int
	 */
	private int $avif_bytes;

	/**
	 * List of completed operation keys (e.g. ['recompress', 'webp']).
	 * Decoded from JSON in the constructor; defaults to [].
	 *
	 * @var list<string>
	 */
	private array $ops_completed;

	/**
	 * Sizes audit map: { size_name => { expected, found, missing } }.
	 * Decoded from JSON in the constructor; defaults to {}.
	 *
	 * @var array<string, mixed>
	 */
	private array $sizes_audit;

	/**
	 * UTC datetime of the most recent optimization run, or null.
	 *
	 * @var string|null
	 */
	private ?string $last_optimized_at;

	/**
	 * Human-readable error message from the last failed run, or null.
	 *
	 * @var string|null
	 */
	private ?string $error_message;

	/**
	 * Construct from explicit typed parameters.
	 *
	 * $ops_completed and $sizes_audit are accepted as JSON strings (as stored in
	 * the database) and decoded to arrays in the constructor.
	 *
	 * @param int         $id                Row primary key.
	 * @param int         $attachment_id     Attachment post ID.
	 * @param string      $status            Row status (use status constants).
	 * @param int         $original_bytes    Original file size in bytes.
	 * @param int         $optimized_bytes   Optimized file size in bytes.
	 * @param int         $webp_bytes        WebP alternate size in bytes.
	 * @param int         $avif_bytes        AVIF alternate size in bytes.
	 * @param string      $ops_completed_json JSON array of completed operation keys (default '[]').
	 * @param string      $sizes_audit_json   JSON object of sizes audit data (default '{}').
	 * @param string|null $last_optimized_at  UTC datetime of last optimization, or null.
	 * @param string|null $error_message      Error message from the last failed run, or null.
	 */
	public function __construct(
		int $id,
		int $attachment_id,
		string $status,
		int $original_bytes,
		int $optimized_bytes,
		int $webp_bytes,
		int $avif_bytes,
		string $ops_completed_json = '[]',
		string $sizes_audit_json = '{}',
		?string $last_optimized_at = null,
		?string $error_message = null
	) {
		$this->id                = $id;
		$this->attachment_id     = $attachment_id;
		$this->status            = $status;
		$this->original_bytes    = $original_bytes;
		$this->optimized_bytes   = $optimized_bytes;
		$this->webp_bytes        = $webp_bytes;
		$this->avif_bytes        = $avif_bytes;
		$this->last_optimized_at = $last_optimized_at;
		$this->error_message     = $error_message;

		// Decode ops_completed JSON and enforce the list<string> contract: discard
		// non-string elements (integers, objects, etc.) and re-index to a 0-based list.
		// This guards against associative arrays, mixed-type arrays, or garbage rows
		// from corrupted storage that would break downstream in_array/iteration code.
		$decoded_ops         = json_decode( $ops_completed_json, true );
		$this->ops_completed = is_array( $decoded_ops )
			? array_values( array_filter( $decoded_ops, 'is_string' ) )
			: array();

		$decoded_audit     = json_decode( $sizes_audit_json, true );
		$this->sizes_audit = is_array( $decoded_audit ) ? $decoded_audit : array();
	}

	/**
	 * Row primary key.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * WordPress attachment post ID.
	 *
	 * @return int
	 */
	public function attachment_id(): int {
		return $this->attachment_id;
	}

	/**
	 * Row status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Original file size in bytes.
	 *
	 * @return int
	 */
	public function original_bytes(): int {
		return $this->original_bytes;
	}

	/**
	 * Optimized file size in bytes.
	 *
	 * @return int
	 */
	public function optimized_bytes(): int {
		return $this->optimized_bytes;
	}

	/**
	 * WebP alternate file size in bytes.
	 *
	 * @return int
	 */
	public function webp_bytes(): int {
		return $this->webp_bytes;
	}

	/**
	 * AVIF alternate file size in bytes.
	 *
	 * @return int
	 */
	public function avif_bytes(): int {
		return $this->avif_bytes;
	}

	/**
	 * List of completed operation keys (e.g. ['recompress', 'webp']).
	 *
	 * @return list<string>
	 */
	public function ops_completed(): array {
		return $this->ops_completed;
	}

	/**
	 * Sizes audit map.
	 *
	 * @return array<string, mixed>
	 */
	public function sizes_audit(): array {
		return $this->sizes_audit;
	}

	/**
	 * UTC datetime of the most recent optimization run, or null.
	 *
	 * @return string|null
	 */
	public function last_optimized_at(): ?string {
		return $this->last_optimized_at;
	}

	/**
	 * Error message from the last failed run, or null.
	 *
	 * @return string|null
	 */
	public function error_message(): ?string {
		return $this->error_message;
	}

	/**
	 * Whether this record's status column is COMPLETE.
	 *
	 * This is a STATUS CHECK ONLY — it does NOT guarantee that a backup row
	 * exists or that the original file can be restored. Restorability depends
	 * on whether a corresponding assetdrips_squeeze_backups row is present:
	 *  - WebP/AVIF alternate-generation ops are additive (no backup), so a
	 *    COMPLETE record for those ops has nothing to restore.
	 *  - A FAILED record that partially ran a recompress op may have a backup.
	 *
	 * Phase 10 MUST NOT use this method as an authoritative restore gate.
	 * Consult assetdrips_squeeze_backups for actual restorability.
	 *
	 * @return bool True when status === COMPLETE; does not imply backup exists.
	 */
	public function is_complete(): bool {
		return self::COMPLETE === $this->status;
	}
}
