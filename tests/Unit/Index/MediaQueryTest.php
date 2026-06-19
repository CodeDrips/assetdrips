<?php
/**
 * MediaQuery SQL-builder unit tests.
 *
 * These guard the pure seam of the faceted query API: criteria object in,
 * a (where_sql, where_args) pair out, with zero WordPress and zero DB.
 * A minimal wpdb stub is injected so esc_like behaves predictably.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Index;

use AssetDrips\Index\MediaQuery;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for MediaQuery::build_where() and order helpers.
 */
final class MediaQueryTest extends TestCase {

	/**
	 * Minimal wpdb stub with predictable esc_like and prepare behaviour.
	 *
	 * The esc_like stub escapes %, _, and \ (the three wildcard chars wpdb::esc_like escapes).
	 * prepare() is NOT called in build_where() — it is the caller's (MediaIndex)
	 * responsibility. The stub is only needed for esc_like.
	 *
	 * @return object
	 */
	private function wpdb_stub(): object {
		return new class() {
			/**
			 * Stub esc_like: escape %, _ and \ to match wpdb behaviour.
			 *
			 * @param string $text Input text.
			 * @return string
			 */
			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}
		};
	}

	// -------------------------------------------------------------------------
	// Empty criteria
	// -------------------------------------------------------------------------

	/**
	 * With no criteria set, build_where() returns an empty where clause and no args.
	 *
	 * @return void
	 */
	public function test_empty_criteria_returns_empty_where(): void {
		$q    = new MediaQuery();
		$wpdb = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertSame( '', $where );
		$this->assertSame( array(), $args );
	}

	// -------------------------------------------------------------------------
	// Subtype facet
	// -------------------------------------------------------------------------

	/**
	 * Subtype='jpeg' produces a mime_subtype = %s fragment with 'jpeg' in args.
	 *
	 * @return void
	 */
	public function test_subtype_produces_correct_fragment(): void {
		$q          = new MediaQuery();
		$q->subtype = 'jpeg';
		$wpdb       = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'mime_subtype = %s', $where );
		$this->assertContains( 'jpeg', $args );
	}

	// -------------------------------------------------------------------------
	// used / is_used facet
	// -------------------------------------------------------------------------

	/**
	 * Maps used='unused' to is_used = 0 with int 0 in args.
	 *
	 * @return void
	 */
	public function test_used_unused_produces_is_used_zero(): void {
		$q       = new MediaQuery();
		$q->used = 'unused';
		$wpdb    = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'is_used = %d', $where );
		$this->assertContains( 0, $args );
	}

	/**
	 * Maps used='used' to is_used = 1 with int 1 in args.
	 *
	 * @return void
	 */
	public function test_used_used_produces_is_used_one(): void {
		$q       = new MediaQuery();
		$q->used = 'used';
		$wpdb    = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'is_used = %d', $where );
		$this->assertContains( 1, $args );
	}

	// -------------------------------------------------------------------------
	// missing_alt facet
	// -------------------------------------------------------------------------

	/**
	 * Maps missing_alt=true to has_alt = 0 with int 0 in args.
	 *
	 * @return void
	 */
	public function test_missing_alt_produces_has_alt_zero(): void {
		$q              = new MediaQuery();
		$q->missing_alt = true;
		$wpdb           = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'has_alt = %d', $where );
		$this->assertContains( 0, $args );
	}

	// -------------------------------------------------------------------------
	// Numeric range facets
	// -------------------------------------------------------------------------

	/**
	 * Produces filesize >= %d with int arg for size_min.
	 *
	 * @return void
	 */
	public function test_size_min_produces_filesize_gte(): void {
		$q           = new MediaQuery();
		$q->size_min = 1024;
		$wpdb        = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'filesize >= %d', $where );
		$this->assertContains( 1024, $args );
	}

	/**
	 * Produces filesize <= %d with int arg for size_max.
	 *
	 * @return void
	 */
	public function test_size_max_produces_filesize_lte(): void {
		$q           = new MediaQuery();
		$q->size_max = 5242880;
		$wpdb        = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'filesize <= %d', $where );
		$this->assertContains( 5242880, $args );
	}

	/**
	 * Produces width >= %d / width <= %d for width_min/width_max.
	 *
	 * @return void
	 */
	public function test_width_range_produces_correct_fragments(): void {
		$q            = new MediaQuery();
		$q->width_min = 100;
		$q->width_max = 2000;
		$wpdb         = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'width >= %d', $where );
		$this->assertStringContainsString( 'width <= %d', $where );
		$this->assertContains( 100, $args );
		$this->assertContains( 2000, $args );
	}

	/**
	 * Produces height >= %d / height <= %d for height_min/height_max.
	 *
	 * @return void
	 */
	public function test_height_range_produces_correct_fragments(): void {
		$q             = new MediaQuery();
		$q->height_min = 50;
		$q->height_max = 1080;
		$wpdb          = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'height >= %d', $where );
		$this->assertStringContainsString( 'height <= %d', $where );
		$this->assertContains( 50, $args );
		$this->assertContains( 1080, $args );
	}

	// -------------------------------------------------------------------------
	// Date range facets
	// -------------------------------------------------------------------------

	/**
	 * Produces uploaded_at >= %s for date_from.
	 *
	 * @return void
	 */
	public function test_date_from_produces_uploaded_at_gte(): void {
		$q            = new MediaQuery();
		$q->date_from = '2026-01-01';
		$wpdb         = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'uploaded_at >= %s', $where );
		$this->assertContains( '2026-01-01', $args );
	}

	/**
	 * Produces uploaded_at <= %s for date_to.
	 *
	 * @return void
	 */
	public function test_date_to_produces_uploaded_at_lte(): void {
		$q          = new MediaQuery();
		$q->date_to = '2026-12-31';
		$wpdb       = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'uploaded_at <= %s', $where );
		// A bare Y-m-d is extended to end-of-day so same-day uploads are included (WR-01).
		$this->assertContains( '2026-12-31 23:59:59', $args );
	}

	// -------------------------------------------------------------------------
	// Uploader facet
	// -------------------------------------------------------------------------

	/**
	 * Maps uploader=3 to uploaded_by = %d with int 3.
	 *
	 * @return void
	 */
	public function test_uploader_produces_uploaded_by_fragment(): void {
		$q           = new MediaQuery();
		$q->uploader = 3;
		$wpdb        = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'uploaded_by = %d', $where );
		$this->assertContains( 3, $args );
	}

	// -------------------------------------------------------------------------
	// Orientation facet (whitelist)
	// -------------------------------------------------------------------------

	/**
	 * Accepts orientation='landscape' from the allowed list and produces a fragment.
	 *
	 * @return void
	 */
	public function test_valid_orientation_produces_fragment(): void {
		$q              = new MediaQuery();
		$q->orientation = 'landscape';
		$wpdb           = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'orientation = %s', $where );
		$this->assertContains( 'landscape', $args );
	}

	/**
	 * Invalid orientation is dropped — no SQL fragment or arg emitted.
	 *
	 * @return void
	 */
	public function test_invalid_orientation_is_dropped(): void {
		$q              = new MediaQuery();
		$q->orientation = 'DROP TABLE--';
		$wpdb           = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertSame( '', $where );
		$this->assertSame( array(), $args );
	}

	// -------------------------------------------------------------------------
	// Type (main MIME) facet
	// -------------------------------------------------------------------------

	/**
	 * Maps type='image' to mime LIKE %s with 'image/%' in args.
	 *
	 * @return void
	 */
	public function test_type_image_produces_mime_like_fragment(): void {
		$q       = new MediaQuery();
		$q->type = 'image';
		$wpdb    = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'mime LIKE %s', $where );
		$this->assertContains( 'image/%', $args );
	}

	/**
	 * Invalid type (e.g. 'DROP') is dropped — no SQL fragment emitted.
	 *
	 * @return void
	 */
	public function test_invalid_type_is_dropped(): void {
		$q       = new MediaQuery();
		$q->type = 'DROP';
		$wpdb    = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertSame( '', $where );
		$this->assertSame( array(), $args );
	}

	// -------------------------------------------------------------------------
	// Search (esc_like + OR group)
	// -------------------------------------------------------------------------

	/**
	 * Escapes the search term via esc_like before wrapping in %…%.
	 *
	 * The stub esc_like uses addcslashes for %, _, \ — verify % in input becomes
	 * \% in the SQL literal, ensuring LIKE wildcards from the term are escaped.
	 *
	 * @return void
	 */
	public function test_search_term_is_esc_like_escaped(): void {
		$q         = new MediaQuery();
		$q->search = 'a%b_c';
		$wpdb      = $this->wpdb_stub();

		[ , $args ] = $q->build_where( $wpdb );

		// esc_like should have escaped % and _ — all five search-column args
		// should contain the escaped form wrapped in %.
		foreach ( $args as $arg ) {
			$this->assertStringContainsString( '\%', $arg, 'esc_like must escape % wildcard' );
			$this->assertStringContainsString( '\_', $arg, 'esc_like must escape _ wildcard' );
		}
	}

	/**
	 * Search produces LIKE %s for all five text columns, OR-combined in parens.
	 *
	 * @return void
	 */
	public function test_search_produces_or_group_across_five_columns(): void {
		$q         = new MediaQuery();
		$q->search = 'sunset';
		$wpdb      = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		// Must be parenthesised.
		$this->assertStringStartsWith( '(', $where );
		$this->assertStringEndsWith( ')', $where );

		// Must contain OR.
		$this->assertStringContainsString( ' OR ', $where );

		// All five columns must appear.
		foreach ( array( 'filename', 'title', 'alt', 'caption', 'description' ) as $col ) {
			$this->assertStringContainsString( $col . ' LIKE %s', $where );
		}

		// Five args (one per column), all contain the search term wrapped in %.
		$this->assertCount( 5, $args );
		foreach ( $args as $arg ) {
			$this->assertStringStartsWith( '%', $arg );
			$this->assertStringEndsWith( '%', $arg );
		}
	}

	/**
	 * Search OR-group AND-joins with facet predicates (parenthesised).
	 *
	 * @return void
	 */
	public function test_search_and_joins_with_facets(): void {
		$q          = new MediaQuery();
		$q->search  = 'photo';
		$q->subtype = 'jpeg';
		$wpdb       = $this->wpdb_stub();

		[ $where, ] = $q->build_where( $wpdb );

		// Both the search group and the facet must appear in the WHERE string.
		$this->assertStringContainsString( 'mime_subtype = %s', $where );
		$this->assertStringContainsString( '(', $where );
		$this->assertStringContainsString( 'LIKE %s', $where );
	}

	// -------------------------------------------------------------------------
	// Orderby / order whitelist (order_by_clause)
	// -------------------------------------------------------------------------

	/**
	 * Valid orderby 'filesize' / 'asc' are passed through unchanged.
	 *
	 * @return void
	 */
	public function test_valid_orderby_and_order_are_used(): void {
		$q          = new MediaQuery();
		$q->orderby = 'filesize';
		$q->order   = 'asc';

		$clause = $q->order_by_clause();

		$this->assertSame( 'filesize ASC', $clause );
	}

	/**
	 * Invalid orderby falls back to 'uploaded_at'.
	 *
	 * @return void
	 */
	public function test_invalid_orderby_falls_back_to_uploaded_at(): void {
		$q          = new MediaQuery();
		$q->orderby = 'DROP TABLE--';
		$q->order   = 'desc';

		$clause = $q->order_by_clause();

		$this->assertStringStartsWith( 'uploaded_at', $clause );
	}

	/**
	 * Invalid order falls back to 'DESC'.
	 *
	 * @return void
	 */
	public function test_invalid_order_falls_back_to_desc(): void {
		$q          = new MediaQuery();
		$q->orderby = 'filename';
		$q->order   = 'UNION SELECT--';

		$clause = $q->order_by_clause();

		$this->assertStringEndsWith( 'DESC', $clause );
	}

	// -------------------------------------------------------------------------
	// Multiple facets AND-joined
	// -------------------------------------------------------------------------

	/**
	 * Multiple facets produce AND-joined predicates in the WHERE clause.
	 *
	 * @return void
	 */
	public function test_multiple_facets_are_and_joined(): void {
		$q              = new MediaQuery();
		$q->subtype     = 'png';
		$q->used        = 'unused';
		$q->missing_alt = true;
		$q->size_min    = 2048;
		$wpdb           = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		// All four predicates must be in the WHERE.
		$this->assertStringContainsString( 'mime_subtype = %s', $where );
		$this->assertStringContainsString( 'is_used = %d', $where );
		$this->assertStringContainsString( 'has_alt = %d', $where );
		$this->assertStringContainsString( 'filesize >= %d', $where );

		// AND joins must be present.
		$this->assertStringContainsString( ' AND ', $where );

		// Args must include all four values.
		$this->assertContains( 'png', $args );
		$this->assertContains( 0, $args );   // Expect the unused sentinel value zero.
		$this->assertContains( 2048, $args );
	}

	// -------------------------------------------------------------------------
	// folder / folder_id facet (D-02)
	// -------------------------------------------------------------------------

	/**
	 * A positive integer string folder ID produces folder_id = %d and the int in args.
	 *
	 * @return void
	 */
	public function test_folder_specific_id_produces_folder_id_eq_fragment(): void {
		$q         = new MediaQuery();
		$q->folder = '42';
		$wpdb      = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'folder_id = %d', $where );
		$this->assertContains( 42, $args );
	}

	/**
	 * The 'uncategorized' sentinel produces folder_id IS NULL with no extra arg.
	 *
	 * IS NULL takes no placeholder — args must not contain a folder value.
	 *
	 * @return void
	 */
	public function test_folder_uncategorized_produces_is_null_fragment(): void {
		$q         = new MediaQuery();
		$q->folder = 'uncategorized';
		$wpdb      = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'folder_id IS NULL', $where );
		$this->assertSame( array(), $args );
	}

	/**
	 * Empty (default) folder produces no folder_id predicate at all.
	 *
	 * @return void
	 */
	public function test_folder_absent_produces_no_predicate(): void {
		$q    = new MediaQuery();
		$wpdb = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertSame( '', $where );
		$this->assertSame( array(), $args );
	}

	/**
	 * Folder combined with type produces AND-joined predicates.
	 *
	 * Both folder_id = %d and the mime LIKE fragment must appear, AND-joined.
	 *
	 * @return void
	 */
	public function test_folder_combined_with_type_produces_and_joined_where(): void {
		$q         = new MediaQuery();
		$q->folder = '5';
		$q->type   = 'image';
		$wpdb      = $this->wpdb_stub();

		[ $where, $args ] = $q->build_where( $wpdb );

		$this->assertStringContainsString( 'folder_id = %d', $where );
		$this->assertStringContainsString( 'mime LIKE %s', $where );
		$this->assertStringContainsString( ' AND ', $where );
		$this->assertContains( 5, $args );
	}

	/**
	 * Zero and negative folder IDs are silently dropped (T-02-01).
	 *
	 * Non-positive values must not emit any folder_id predicate or arg.
	 *
	 * @return void
	 */
	public function test_folder_zero_or_negative_dropped(): void {
		$wpdb = $this->wpdb_stub();

		// Zero.
		$q                          = new MediaQuery();
		$q->folder                  = '0';
		[ $where_zero, $args_zero ] = $q->build_where( $wpdb );
		$this->assertStringNotContainsString( 'folder_id', $where_zero );
		$this->assertSame( array(), $args_zero );

		// Negative.
		$q2                       = new MediaQuery();
		$q2->folder               = '-3';
		[ $where_neg, $args_neg ] = $q2->build_where( $wpdb );
		$this->assertStringNotContainsString( 'folder_id', $where_neg );
		$this->assertSame( array(), $args_neg );
	}

	// -------------------------------------------------------------------------
	// used_on (stored, wired by MediaIndex)
	// -------------------------------------------------------------------------

	/**
	 * Stores used_on on the MediaQuery object as a value > 0 when set.
	 *
	 * MediaIndex reads this property to build the JOIN; build_where() does not
	 * produce a predicate for it (the join is in the impure wrapper).
	 *
	 * @return void
	 */
	public function test_used_on_is_stored(): void {
		$q          = new MediaQuery();
		$q->used_on = 42;

		$this->assertSame( 42, $q->used_on );
	}

	// -------------------------------------------------------------------------
	// Tag predicate — source-inspection (TAG-03 / D-01)
	// -------------------------------------------------------------------------

	/**
	 * Return the source-file contents for MediaQuery.
	 *
	 * @return string
	 */
	private function media_query_source(): string {
		$ref = new \ReflectionClass( MediaQuery::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	/**
	 * Return the source-file contents for MediaIndex.
	 *
	 * @return string
	 */
	private function media_index_source(): string {
		$ref = new \ReflectionClass( \AssetDrips\Index\MediaIndex::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	/**
	 * MediaQuery exposes a public int $tag field defaulting to 0 (TAG-03, D-01).
	 *
	 * @return void
	 */
	public function test_media_query_has_tag_field(): void {
		$source = $this->media_query_source();
		if ( ! str_contains( $source, 'public int $tag' ) ) {
			$this->markTestIncomplete( 'source lands in Task 2' );
		}
		$this->assertStringContainsString(
			'public int $tag',
			$source,
			'MediaQuery must declare public int $tag (TAG-03, D-01)'
		);
	}

	/**
	 * The tag predicate uses an IN-subquery over wp_term_relationships, not a direct column compare.
	 *
	 * @return void
	 */
	public function test_tag_predicate_uses_in_subquery(): void {
		$source = $this->media_index_source();
		if ( ! str_contains( $source, 'term_relationships' ) || ! str_contains( $source, 'IN (' ) ) {
			$this->markTestIncomplete( 'source lands in Task 3' );
		}
		$this->assertStringContainsString(
			'term_relationships',
			$source,
			'MediaIndex must reference term_relationships for the tag IN-subquery (TAG-03, D-01)'
		);
		$this->assertStringContainsString(
			'IN (',
			$source,
			'MediaIndex tag predicate must use IN ( subquery, not a direct column compare (TAG-03, D-01)'
		);
	}

	/**
	 * The tag predicate scopes the JOIN to assetdrips_tag taxonomy (multisite safety).
	 *
	 * @return void
	 */
	public function test_tag_predicate_scopes_taxonomy(): void {
		$source = $this->media_index_source();
		if ( ! str_contains( $source, 'tt.taxonomy = %s' ) || ! str_contains( $source, 'tt.term_id = %d' ) ) {
			$this->markTestIncomplete( 'source lands in Task 3' );
		}
		$this->assertStringContainsString(
			'tt.taxonomy = %s',
			$source,
			'MediaIndex tag predicate must scope JOIN with tt.taxonomy = %s (multisite-safe, T-06-07)'
		);
		$this->assertStringContainsString(
			'tt.term_id = %d',
			$source,
			'MediaIndex tag predicate must bind term_id via tt.term_id = %d (T-06-01)'
		);
	}

	/**
	 * The tag predicate is added to $where_parts (CR-01 — never concatenated to SQL string).
	 *
	 * @return void
	 */
	public function test_tag_predicate_added_to_where_parts(): void {
		$source = $this->media_index_source();
		if ( ! str_contains( $source, '$where_parts[] = $tag_pred' ) ) {
			$this->markTestIncomplete( 'source lands in Task 3' );
		}
		$this->assertStringContainsString(
			'$where_parts[] = $tag_pred',
			$source,
			'MediaIndex must push $tag_pred to $where_parts[] (CR-01, T-06-08) — never concatenate to $data_sql'
		);
	}
}
