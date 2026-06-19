<?php
/**
 * Taxonomy registration for the AssetDrips Sort module.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Sort;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the assetdrips_folder (hierarchical) and assetdrips_tag (flat)
 * taxonomies on the init hook.
 *
 * Both taxonomies are scoped to attachment post-type only. The
 * update_count_callback is set to _update_generic_term_count so that
 * unattached attachments (post_status = 'inherit', post_parent = 0) produce
 * accurate non-zero term counts. The default _update_post_term_count callback
 * only counts attachments whose parent post is published — producing zero
 * counts for the entire Media Library use case (D-04).
 *
 * Only the folder Find facet ships this phase; assetdrips_tag is registered now
 * but show_ui => false keeps it invisible until Phase 6 (D-05).
 *
 * Must be wired in Plugin::boot() OUTSIDE the is_admin() block so REST-API
 * term assignment (Phase 5) fires on every request context.
 */
final class TaxonomyRegistrar {

	/**
	 * Hook register_taxonomies onto the init action.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_taxonomies' ), 10 );
	}

	/**
	 * Register both AssetDrips taxonomies.
	 *
	 * Called on the init hook. Do NOT call directly at construction time — the
	 * taxonomy must be registered on init to avoid _doing_it_wrong notices.
	 *
	 * @return void
	 */
	public function register_taxonomies(): void {
		// Virtual folder hierarchy — assetdrips_folder.
		// hierarchical: true mirrors WordPress's built-in category taxonomy.
		// update_count_callback: _update_generic_term_count counts ALL objects in
		// wp_term_relationships regardless of post_status — required for unattached
		// Media Library items (post_status = 'inherit', post_parent = 0).
		register_taxonomy(
			'assetdrips_folder',
			array( 'attachment' ),
			array(
				'hierarchical'          => true,
				'public'                => false,
				'show_ui'               => false,
				'show_in_rest'          => true,
				'rewrite'               => false,
				'show_admin_column'     => false,
				'update_count_callback' => '_update_generic_term_count',
				'labels'                => array(
					'name'          => 'Folders',
					'singular_name' => 'Folder',
				),
			)
		);

		// Flat attachment tags — assetdrips_tag.
		// Registered now but ships no UI this phase (D-05); show_ui => false.
		register_taxonomy(
			'assetdrips_tag',
			array( 'attachment' ),
			array(
				'hierarchical'          => false,
				'public'                => false,
				'show_ui'               => false,
				'show_in_rest'          => true,
				'rewrite'               => false,
				'show_admin_column'     => false,
				'update_count_callback' => '_update_generic_term_count',
				'labels'                => array(
					'name'          => 'Tags',
					'singular_name' => 'Tag',
				),
			)
		);
	}
}
