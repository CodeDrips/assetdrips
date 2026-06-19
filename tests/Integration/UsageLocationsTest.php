<?php
/**
 * UsageLocations read/write seam integration tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Integration;

use AssetDrips\Db\Schema;
use AssetDrips\Usage\UsageHit;
use AssetDrips\Usage\UsageLocations;
use AssetDrips\Usage\UsageMap;
use WP_UnitTestCase;

/**
 * Verifies that UsageLocations populates correctly from a UsageMap and that
 * for_host() / for_attachment() return the expected sets.
 */
final class UsageLocationsTest extends WP_UnitTestCase {

	/**
	 * Install the schema fresh for every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
	}

	/**
	 * Inserting rows via replace_for_attachment increases count_rows.
	 *
	 * @return void
	 */
	public function test_replace_for_attachment_inserts_rows(): void {
		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/loc-a.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$locations = UsageLocations::from_wordpress();

		$locations->replace_for_attachment(
			$attachment_id,
			array(
				array(
					'host_type' => 'post',
					'host_id'   => 1,
					'source'    => 'content',
					'context'   => 'post:1:post_content',
				),
			)
		);

		$this->assertSame( 1, $locations->count_rows() );
	}

	/**
	 * Running replace_for_attachment twice produces exactly one set of rows.
	 *
	 * @return void
	 */
	public function test_replace_for_attachment_is_idempotent(): void {
		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/loc-idem.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$locations = UsageLocations::from_wordpress();

		$row = array(
			'host_type' => 'post',
			'host_id'   => 10,
			'source'    => 'content',
			'context'   => 'post:10:post_content',
		);

		$locations->replace_for_attachment( $attachment_id, array( $row ) );
		$locations->replace_for_attachment( $attachment_id, array( $row ) );

		// Should still be exactly 1 row, not 2.
		$this->assertSame( 1, $locations->count_rows() );
	}

	/**
	 * Querying a post host returns only the attachment IDs on that host.
	 *
	 * @return void
	 */
	public function test_for_host_returns_correct_attachment_ids(): void {
		$att_a = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/host-a.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$att_b = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/host-b.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$post_id  = 42;
		$other_id = 99;

		$locations = UsageLocations::from_wordpress();

		// att_a is on post 42.
		$locations->replace_for_attachment(
			$att_a,
			array(
				array(
					'host_type' => 'post',
					'host_id'   => $post_id,
					'source'    => 'content',
					'context'   => "post:{$post_id}:post_content",
				),
			)
		);

		// att_b is on post 99.
		$locations->replace_for_attachment(
			$att_b,
			array(
				array(
					'host_type' => 'post',
					'host_id'   => $other_id,
					'source'    => 'content',
					'context'   => "post:{$other_id}:post_content",
				),
			)
		);

		$ids_for_42 = $locations->for_host( 'post', $post_id );
		$this->assertCount( 1, $ids_for_42 );
		$this->assertContains( $att_a, $ids_for_42 );
		$this->assertNotContains( $att_b, $ids_for_42 );
	}

	/**
	 * Multiple hits for the same attachment on the same host return a single distinct ID.
	 *
	 * @return void
	 */
	public function test_for_host_returns_distinct_ids(): void {
		$att_a = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/distinct-a.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$post_id   = 55;
		$locations = UsageLocations::from_wordpress();

		// Two different source hits on the same host.
		$locations->replace_for_attachment(
			$att_a,
			array(
				array(
					'host_type' => 'post',
					'host_id'   => $post_id,
					'source'    => 'content',
					'context'   => "post:{$post_id}:post_content",
				),
				array(
					'host_type' => 'post',
					'host_id'   => $post_id,
					'source'    => 'postmeta',
					'context'   => "postmeta:{$post_id}:_thumbnail_id",
				),
			)
		);

		$ids = $locations->for_host( 'post', $post_id );
		$this->assertCount( 1, $ids );
		$this->assertContains( $att_a, $ids );
	}

	/**
	 * All location rows for one attachment are returned by for_attachment.
	 *
	 * @return void
	 */
	public function test_for_attachment_returns_correct_locations(): void {
		$att = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/for-att.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$locations = UsageLocations::from_wordpress();

		$locations->replace_for_attachment(
			$att,
			array(
				array(
					'host_type' => 'post',
					'host_id'   => 7,
					'source'    => 'content',
					'context'   => 'post:7:post_content',
				),
				array(
					'host_type' => 'post',
					'host_id'   => 8,
					'source'    => 'postmeta',
					'context'   => 'postmeta:8:_thumbnail_id',
				),
			)
		);

		$rows = $locations->for_attachment( $att );
		$this->assertCount( 2, $rows );

		$contexts = array_column( $rows, 'context' );
		$this->assertContains( 'post:7:post_content', $contexts );
		$this->assertContains( 'postmeta:8:_thumbnail_id', $contexts );
	}

	/**
	 * Populating from a UsageMap inserts location rows and returns the processed count.
	 *
	 * @return void
	 */
	public function test_populate_from_usage_inserts_locations(): void {
		$att_a = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/pop-a.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$att_b = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/pop-b.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$post_id = 20;

		$map = new UsageMap();
		$map->add( new UsageHit( $att_a, 'content', "post:{$post_id}:post_content", UsageHit::MATCH_URL, 'pop-a.jpg' ) );
		$map->add( new UsageHit( $att_b, 'postmeta', "postmeta:{$post_id}:_thumbnail_id", UsageHit::MATCH_ID, (string) $att_b ) );
		// Option hit — should be skipped (not post/term-bound).
		$map->add( new UsageHit( $att_a, 'option', 'option:custom_logo', UsageHit::MATCH_ID, (string) $att_a ) );

		$locations = UsageLocations::from_wordpress();
		$processed = $locations->populate_from_usage( $map );

		// Both attachments processed.
		$this->assertSame( 2, $processed );

		// for_host('post', 20) returns both attachment IDs.
		$ids = $locations->for_host( 'post', $post_id );
		$this->assertContains( $att_a, $ids );
		$this->assertContains( $att_b, $ids );
	}

	/**
	 * Context strings that do not resolve to a host are silently skipped by populate_from_usage.
	 *
	 * @return void
	 */
	public function test_populate_from_usage_skips_unresolvable_contexts(): void {
		$att = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/skip.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$map = new UsageMap();
		// Only option hits — none resolve to a post/term host.
		$map->add( new UsageHit( $att, 'option', 'option:site_logo', UsageHit::MATCH_ID, (string) $att ) );
		$map->add( new UsageHit( $att, 'option', 'acf:option:header_bg', UsageHit::MATCH_ID, (string) $att ) );

		$locations = UsageLocations::from_wordpress();
		$locations->populate_from_usage( $map );

		// No location rows inserted for option-only hits.
		$this->assertSame( 0, $locations->count_rows() );
	}

	/**
	 * Querying a host with no location rows returns an empty array.
	 *
	 * @return void
	 */
	public function test_for_host_returns_empty_array_when_no_matches(): void {
		$this->assertSame( array(), UsageLocations::from_wordpress()->for_host( 'post', 9999 ) );
	}
}
