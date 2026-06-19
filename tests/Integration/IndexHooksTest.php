<?php
/**
 * Structural-lane hook integration test.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Integration;

use AssetDrips\Db\Schema;
use AssetDrips\Index\IndexHooks;
use AssetDrips\Index\MediaIndex;
use WP_UnitTestCase;

/**
 * Verifies the real-time structural lane (IDX-03): upload, metadata generation,
 * alt add/change/remove, title edits (classic AND REST/Gutenberg paths) and
 * deletion all reflect in the index within the same request — no cron, no scan.
 */
final class IndexHooksTest extends WP_UnitTestCase {

	/**
	 * Install the media table and register the live hooks for every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
		( new IndexHooks() )->register();
	}

	/**
	 * Uploading an attachment inserts a base index row, and the metadata filter
	 * fills width/height/filesize within the same request (IDX-03).
	 *
	 * @return void
	 */
	public function test_upload_creates_row(): void {
		$id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/upload.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Uploaded Image',
			)
		);

		// add_attachment fires on create; assert the base row exists with the
		// structural fields populated.
		$row = $this->row( $id );
		$this->assertNotSame( array(), $row, 'Upload must insert an index row.' );
		$this->assertSame( 'upload.jpg', $row['filename'] );
		$this->assertSame( 'image/jpeg', $row['mime'] );
		$this->assertSame( 'jpeg', $row['mime_subtype'] );
		$this->assertSame( 'Uploaded Image', $row['title'] );

		// Simulate WordPress generating sub-size metadata: the filter must fill
		// dimensions/filesize and return $meta unchanged.
		$meta = array(
			'width'    => 800,
			'height'   => 400,
			'filesize' => 123456,
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Simulating WordPress core firing its own metadata filter to exercise on_meta.
		$returned = apply_filters( 'wp_generate_attachment_metadata', $meta, $id, 'create' );

		$this->assertSame( $meta, $returned, 'on_meta must return $meta unchanged.' );

		$row = $this->row( $id );
		$this->assertSame( '800', $row['width'] );
		$this->assertSame( '400', $row['height'] );
		$this->assertSame( '123456', $row['filesize'] );
		$this->assertSame( 'landscape', $row['orientation'] );
	}

	/**
	 * Setting, changing and removing the alt meta refreshes alt + has_alt across
	 * all three meta hooks (added/updated/deleted — Pitfall 4).
	 *
	 * @return void
	 */
	public function test_alt_edit_refreshes(): void {
		$id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/alt.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		// First-time set fires added_post_meta.
		add_post_meta( $id, '_wp_attachment_image_alt', 'First alt', true );
		$row = $this->row( $id );
		$this->assertSame( 'First alt', $row['alt'] );
		$this->assertSame( '1', $row['has_alt'] );

		// Change fires updated_post_meta.
		update_post_meta( $id, '_wp_attachment_image_alt', 'Second alt' );
		$row = $this->row( $id );
		$this->assertSame( 'Second alt', $row['alt'] );
		$this->assertSame( '1', $row['has_alt'] );

		// Removal fires deleted_post_meta.
		delete_post_meta( $id, '_wp_attachment_image_alt' );
		$row = $this->row( $id );
		$this->assertSame( '', $row['alt'] );
		$this->assertSame( '0', $row['has_alt'] );
	}

	/**
	 * A non-alt meta write must NOT touch the index row (key guard).
	 *
	 * @return void
	 */
	public function test_unrelated_meta_does_not_refresh(): void {
		$id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/guard.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', 'Keep me' );

		// An unrelated meta change must leave the alt intact (guarded handler).
		update_post_meta( $id, '_some_other_meta', 'irrelevant' );

		$row = $this->row( $id );
		$this->assertSame( 'Keep me', $row['alt'], 'Unrelated meta must not change alt.' );
		$this->assertSame( '1', $row['has_alt'] );
	}

	/**
	 * Editing the title via the classic path (attachment_updated) refreshes the
	 * index title within the request.
	 *
	 * @return void
	 */
	public function test_title_edit_refreshes(): void {
		$id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/title.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Before',
			)
		);

		// wp_update_post on an existing attachment fires attachment_updated.
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'After Classic',
			)
		);

		$this->assertSame( 'After Classic', $this->row( $id )['title'] );
	}

	/**
	 * Editing the title via a REST/Gutenberg-style path that fires
	 * edit_attachment (without relying on attachment_updated) refreshes the index
	 * title — proving Open Q2 coverage.
	 *
	 * @return void
	 */
	public function test_title_edit_rest_style_refreshes(): void {
		$id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/rest.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Before REST',
			)
		);

		// Persist a new title directly, then fire edit_attachment as the REST /
		// Gutenberg attachment-update path does — without attachment_updated.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test arranges a REST-style edit that bypasses wp_update_post's attachment_updated.
		$wpdb->update( $wpdb->posts, array( 'post_title' => 'After REST' ), array( 'ID' => $id ) );
		clean_post_cache( $id );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Simulating WordPress core's REST/Gutenberg edit_attachment firing to exercise on_update.
		do_action( 'edit_attachment', $id );

		$this->assertSame(
			'After REST',
			$this->row( $id )['title'],
			'edit_attachment must refresh the title for REST/Gutenberg edits.'
		);
	}

	/**
	 * Deleting an attachment removes its index row within the request.
	 *
	 * @return void
	 */
	public function test_delete_removes_row(): void {
		$id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/delete.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$this->assertNotSame( array(), $this->row( $id ), 'Row should exist before deletion.' );

		wp_delete_attachment( $id, true );

		$this->assertSame( array(), $this->row( $id ), 'Index row must be gone after deletion.' );
		$this->assertNotContains( $id, MediaIndex::from_wordpress()->indexed_ids() );
	}

	/**
	 * Fetch one media row as an associative array, or an empty array when absent.
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

		return null === $row ? array() : (array) $row;
	}
}
