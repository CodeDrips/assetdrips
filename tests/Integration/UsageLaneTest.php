<?php
/**
 * Usage-lane fill and lane-isolation integration test.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Integration;

use AssetDrips\Db\Schema;
use AssetDrips\Index\IndexBuilder;
use AssetDrips\Index\MediaIndex;
use AssetDrips\Usage\UsageHit;
use AssetDrips\Usage\UsageMap;
use WP_UnitTestCase;

/**
 * Verifies the usage lane fills from a UsageMap, that a never-synced row keeps a
 * NULL usage_synced_at, and that the two freshness lanes never clobber each other.
 */
final class UsageLaneTest extends WP_UnitTestCase {

	/**
	 * Install the media table fresh for every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
	}

	/**
	 * A backfilled-but-never-synced row has usage 0 and usage_synced_at NULL.
	 *
	 * @return void
	 */
	public function test_backfilled_row_has_null_usage_synced_at(): void {
		$id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/never-synced.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		IndexBuilder::from_wordpress()->backfill();

		$row = $this->row( $id );
		$this->assertSame( '0', $row['usage_count'] );
		$this->assertSame( '0', $row['is_used'] );
		$this->assertNull( $row['usage_synced_at'], 'Never-synced rows must keep usage_synced_at NULL.' );
	}

	/**
	 * The usage lane fills usage_count / is_used / usage_synced_at from the map.
	 *
	 * @return void
	 */
	public function test_usage_lane_fills_from_usage_map(): void {
		$used   = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/used.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$unused = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/unused.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		IndexBuilder::from_wordpress()->backfill();

		$map = new UsageMap();
		$map->add( new UsageHit( $used, 'content', 'post #1', UsageHit::MATCH_URL, 'used.jpg' ) );
		$map->add( new UsageHit( $used, 'postmeta', '_thumbnail_id', UsageHit::MATCH_ID, (string) $used ) );

		IndexBuilder::from_wordpress()->sync_usage( $map );

		$used_row = $this->row( $used );
		$this->assertSame( '2', $used_row['usage_count'] );
		$this->assertSame( '1', $used_row['is_used'] );
		$this->assertNotNull( $used_row['usage_synced_at'], 'Synced rows must stamp usage_synced_at.' );

		$unused_row = $this->row( $unused );
		$this->assertSame( '0', $unused_row['usage_count'] );
		$this->assertSame( '0', $unused_row['is_used'] );
	}

	/**
	 * A usage update leaves the structural columns untouched.
	 *
	 * @return void
	 */
	public function test_usage_update_does_not_clobber_structural(): void {
		$id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/lane-a.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Lane A',
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', 'Original alt text' );

		IndexBuilder::from_wordpress()->backfill();

		$before = $this->row( $id );

		MediaIndex::from_wordpress()->update_usage( $id, 7, true );

		$after = $this->row( $id );
		$this->assertSame( $before['filename'], $after['filename'], 'Usage update must not change filename.' );
		$this->assertSame( $before['title'], $after['title'], 'Usage update must not change title.' );
		$this->assertSame( $before['alt'], $after['alt'], 'Usage update must not change alt.' );
		$this->assertSame( '7', $after['usage_count'] );
		$this->assertSame( '1', $after['is_used'] );
	}

	/**
	 * A structural upsert leaves the usage columns untouched.
	 *
	 * @return void
	 */
	public function test_structural_upsert_does_not_clobber_usage(): void {
		$id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/lane-b.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		IndexBuilder::from_wordpress()->backfill();

		// Seed the usage lane.
		MediaIndex::from_wordpress()->update_usage( $id, 5, true );
		$synced = $this->row( $id )['usage_synced_at'];

		// Re-run backfill: a structural upsert over the same attachment.
		IndexBuilder::from_wordpress()->backfill();

		$after = $this->row( $id );
		$this->assertSame( '5', $after['usage_count'], 'Structural upsert must not reset usage_count.' );
		$this->assertSame( '1', $after['is_used'], 'Structural upsert must not reset is_used.' );
		$this->assertSame( $synced, $after['usage_synced_at'], 'Structural upsert must not clear usage_synced_at.' );
	}

	/**
	 * Fetch one media row as an associative array.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<string, string|null>
	 */
	private function row( int $attachment_id ): array {
		global $wpdb;

		$table = Schema::media_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a Schema constant (never input); the value is bound via prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE attachment_id = %d", $attachment_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (array) $row;
	}
}
