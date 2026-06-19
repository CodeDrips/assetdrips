<?php
/**
 * Real-time folder-lane freshness hooks for the media index.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Sort;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the WordPress term-relationship hook that keeps the
 * FOLDER lane of the assetdrips_media index fresh within the same request
 * (D-03). When an attachment is assigned to a folder term, the lowest
 * term_id wins (D-04, single-folder membership); when all folder terms are
 * removed, folder_id is set to NULL (Uncategorized sentinel).
 *
 * Mirrors the proven {@see \AssetDrips\Index\IndexHooks} impure-wrapper ->
 * guard -> private-write pattern. Non-folder taxonomies (assetdrips_tag, and
 * any other taxonomy) are ignored via an early-return guard so this hook fires
 * cheaply on every set_object_terms call without incurring a DB write.
 *
 * Must be registered for EVERY request context: REST-API term assignment (and
 * CLI) are not is_admin(). Wired in {@see \AssetDrips\Plugin::boot()} outside
 * the is_admin() block.
 */
final class SortHooks {

	/**
	 * Register the term-relationship hook.
	 *
	 * The set_object_terms hook fires on every taxonomy term assignment. Registered
	 * with 6 args to receive all parameters (object_id, terms, tt_ids, taxonomy,
	 * append, old_tt_ids).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'set_object_terms', array( $this, 'on_set_object_terms' ), 10, 6 );
		// Safety-net NULL reversion for any wp_delete_term caller (CLI, external plugins) — D-08.
		add_action( 'pre_delete_term', array( $this, 'on_pre_delete_term' ), 10, 2 );
	}

	/**
	 * Sync folder_id when folder term assignments change.
	 *
	 * Guards on taxonomy first — non-folder taxonomies return immediately
	 * without any DB access (Pitfall 2: $tt_ids are term_taxonomy_ids, not
	 * term_ids — never index them directly). Resolves the canonical term_id(s)
	 * via wp_get_object_terms ordered ASC so the lowest term_id is always
	 * picked (D-04). Delegates the single-row UPDATE to update_folder_id().
	 *
	 * @param int    $object_id   Attachment post ID.
	 * @param mixed  $terms       Terms passed to wp_set_object_terms (unused; canonical set queried fresh).
	 * @param array  $tt_ids      Term taxonomy IDs — NOT term_ids; never used as folder_id (Pitfall 2).
	 * @param string $taxonomy    Taxonomy slug.
	 * @param bool   $append      Whether terms were appended or replaced (unused).
	 * @param array  $old_tt_ids  Previous term taxonomy IDs (unused).
	 * @return void
	 */
	public function on_set_object_terms( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		unset( $terms, $tt_ids, $append, $old_tt_ids );

		if ( 'assetdrips_folder' !== $taxonomy ) {
			return;
		}

		$ids = wp_get_object_terms(
			$object_id,
			'assetdrips_folder',
			array(
				'fields'  => 'ids',
				'orderby' => 'term_id',
				'order'   => 'ASC',
			)
		);

		// D-04: lowest term_id wins; no folder terms -> NULL (Uncategorized).
		$folder_id = ( ! is_wp_error( $ids ) && ! empty( $ids ) ) ? (int) reset( $ids ) : null;

		$this->update_folder_id( $object_id, $folder_id );
	}

	/**
	 * NULL-out folder_id in the media index before WP removes term_relationships.
	 *
	 * Fires on the pre_delete_term hook — the earliest point in the deletion
	 * sequence, before any DB modifications. This is the safety net for any
	 * wp_delete_term caller (CLI, external plugin). The FolderScreen delete
	 * handler also reparents children and removes the assetdrips_folders row,
	 * but this hook ensures folder_id is always reverted even when wp_delete_term
	 * is called externally.
	 *
	 * Non-folder taxonomies return immediately without any DB access.
	 *
	 * @param int    $term_id  Term being deleted.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function on_pre_delete_term( int $term_id, string $taxonomy ): void {
		if ( 'assetdrips_folder' !== $taxonomy ) {
			return;
		}
		global $wpdb;
		$table = \AssetDrips\Db\Schema::media_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single-column UPDATE; table name is a Schema constant (never input); term_id bound via prepare() %d.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET folder_id = NULL WHERE folder_id = %d",
				$term_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Write a single-row UPDATE of folder_id for one attachment.
	 *
	 * The table name comes exclusively from Schema::media_table() (a constant,
	 * never user input — threat T-04-06). folder_id and attachment_id are bound
	 * via wpdb::prepare() %d. The NULL path uses a hardcoded literal (not a
	 * placeholder) because prepare() cannot bind SQL keywords.
	 *
	 * @param int      $attachment_id Attachment post ID.
	 * @param int|null $folder_id     Resolved term_id, or null for Uncategorized.
	 * @return void
	 */
	private function update_folder_id( int $attachment_id, ?int $folder_id ): void {
		global $wpdb;

		$table = \AssetDrips\Db\Schema::media_table();

		if ( null === $folder_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single-row folder_id UPDATE; table name is a Schema constant (never input) and attachment_id is bound via prepare(). NULL is a hardcoded literal, not user input.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET folder_id = NULL WHERE attachment_id = %d",
					$attachment_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single-row folder_id UPDATE; table name is a Schema constant (never input); folder_id and attachment_id are bound via prepare() %d.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET folder_id = %d WHERE attachment_id = %d",
					$folder_id,
					$attachment_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
