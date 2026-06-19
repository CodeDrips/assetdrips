<?php
/**
 * Evidence store: attachment ID to its usage hits.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Accumulates {@see UsageHit}s keyed by attachment ID.
 *
 * Every scanner writes into one shared map for a scan. The scorer then reads it:
 * a single hit for an attachment means USED. Hits are grouped per attachment so
 * the evidence trail can be shown and stored.
 */
final class UsageMap {

	/**
	 * Hits grouped by attachment ID.
	 *
	 * @var array<int, UsageHit[]>
	 */
	private array $hits = array();

	/**
	 * Record one hit.
	 *
	 * @param UsageHit $hit Evidence record.
	 * @return void
	 */
	public function add( UsageHit $hit ): void {
		$this->hits[ $hit->attachment_id() ][] = $hit;
	}

	/**
	 * Record many hits.
	 *
	 * @param UsageHit[] $hits Evidence records.
	 * @return void
	 */
	public function add_many( array $hits ): void {
		foreach ( $hits as $hit ) {
			$this->add( $hit );
		}
	}

	/**
	 * Fold another map's hits into this one.
	 *
	 * @param UsageMap $other Map to merge in.
	 * @return void
	 */
	public function merge( UsageMap $other ): void {
		foreach ( $other->hits as $id => $hits ) {
			foreach ( $hits as $hit ) {
				$this->hits[ $id ][] = $hit;
			}
		}
	}

	/**
	 * Whether an attachment has any evidence of use.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public function is_used( int $attachment_id ): bool {
		return ! empty( $this->hits[ $attachment_id ] );
	}

	/**
	 * Hits recorded for one attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return UsageHit[]
	 */
	public function hits_for( int $attachment_id ): array {
		return $this->hits[ $attachment_id ] ?? array();
	}

	/**
	 * Number of hits recorded for one attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return int
	 */
	public function count_for( int $attachment_id ): int {
		return count( $this->hits[ $attachment_id ] ?? array() );
	}

	/**
	 * All attachment IDs that have at least one hit.
	 *
	 * @return int[]
	 */
	public function used_ids(): array {
		return array_keys( $this->hits );
	}

	/**
	 * Evidence for one attachment as plain arrays, for JSON storage.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function evidence_for( int $attachment_id ): array {
		return array_map(
			static fn( UsageHit $hit ): array => $hit->to_array(),
			$this->hits_for( $attachment_id )
		);
	}
}
