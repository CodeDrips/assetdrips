<?php
/**
 * Attachment panel folder assignment — attachment_fields_to_edit /
 * attachment_fields_to_save filters.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Sort;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the folder assignment <select> in the media modal and Edit Media
 * screen (attachment_fields_to_edit) and persists the selection via
 * wp_set_object_terms (attachment_fields_to_save).
 *
 * Covers both surfaces with one filter pair (D-09):
 *   - Media modal (Backbone): wp_ajax_save_attachment_compat applies
 *     attachment_fields_to_save and then calls wp_set_object_terms.
 *   - Edit Media (post.php): edit_post() applies attachment_fields_to_save.
 *
 * wp_set_object_terms fires set_object_terms → SortHooks::on_set_object_terms
 * → instant folder_id UPDATE in assetdrips_media (FOLDER-03, Success Criterion 5).
 *
 * NOTE: Do NOT rely on get_attachment_taxonomies() / automatic taxonomy
 * processing in wp_ajax_save_attachment_compat for show_ui:false taxonomies —
 * Pitfall 3 from 05-RESEARCH.md. We always call wp_set_object_terms manually
 * here in attachment_fields_to_save.
 *
 * Register inside is_admin() in Plugin::boot() — these filters only fire on
 * admin screens. Plugin wiring is intentionally deferred to Plan 03.
 */
final class FolderFields {

	/**
	 * Register the attachment field filters.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_folder_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_folder_field' ), 10, 2 );
	}

	/**
	 * Inject the folder assignment <select> into the attachment edit form.
	 *
	 * Uses input => 'html' with a flat indented <select> whose depth is computed
	 * by walking the ->parent ancestor chain (same algorithm as FindScreen lines
	 * 1066-1079; $depth < 100 cap guards against circular-parent corruption).
	 *
	 * The <select> name MUST be attachments[{$post->ID}][assetdrips_folder] so
	 * that $_REQUEST['attachments'][$id]['assetdrips_folder'] is populated for
	 * both the AJAX modal save and the attachment_fields_to_save filter (D-10).
	 *
	 * Output escaping: esc_html() on names; esc_attr() on term_id and attribute
	 * values (T-05-04).
	 *
	 * @param array<string, mixed> $form_fields Existing attachment form fields.
	 * @param \WP_Post             $post        Attachment post.
	 * @return array<string, mixed>
	 */
	public function add_folder_field( array $form_fields, \WP_Post $post ): array {
		// Resolve the current folder assignment (lowest term_id — D-04).
		// orderby=term_id + order=ASC mirrors SortHooks::on_set_object_terms so the
		// pre-selected option always matches the persisted folder_id value.
		$current_terms = wp_get_object_terms(
			$post->ID,
			'assetdrips_folder',
			array(
				'fields'  => 'ids',
				'orderby' => 'term_id',
				'order'   => 'ASC',
			)
		);
		$current_id    = ( ! is_wp_error( $current_terms ) && ! empty( $current_terms ) )
			? (string) reset( $current_terms )
			: '';

		// Build the flat indented <select> — same depth-walk as FindScreen lines 1066-1079.
		$html  = '<select name="attachments[' . (int) $post->ID . '][assetdrips_folder]"'
				. ' id="attachments-' . (int) $post->ID . '-assetdrips_folder">';
		$html .= '<option value=""' . selected( '', $current_id, false ) . '>— Uncategorized —</option>';

		$folder_terms = get_terms(
			array(
				'taxonomy'   => 'assetdrips_folder',
				'hide_empty' => false,
				'orderby'    => 'id',
				'order'      => 'ASC',
			)
		);

		if ( ! is_wp_error( $folder_terms ) && is_array( $folder_terms ) ) {
			foreach ( $folder_terms as $term ) {
				$depth = 0;
				$p     = $term->parent;
				// Cap the ancestor walk at 100 levels — guards against circular parent
				// references (only reachable via direct DB corruption; real trees are shallow).
				while ( $p > 0 && $depth < 100 ) {
					++$depth;
					$parent_term = get_term( $p, 'assetdrips_folder' );
					$p           = ( $parent_term instanceof \WP_Term ) ? (int) $parent_term->parent : 0;
				}
				$indent = str_repeat( '&nbsp;&nbsp;&nbsp;', $depth );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Name is escaped via esc_html(); &nbsp; indent is a safe literal.
				$html .= '<option value="' . esc_attr( (string) $term->term_id ) . '"'
						. selected( (string) $term->term_id, $current_id, false ) . '>'
						. $indent . esc_html( $term->name ) . '</option>';
			}
		}

		$html .= '</select>';

		$form_fields['assetdrips_folder'] = array(
			'label' => 'Folder',
			'input' => 'html',
			'html'  => $html,
		);

		return $form_fields;
	}

	/**
	 * Persist the folder selection via wp_set_object_terms.
	 *
	 * Manually calls wp_set_object_terms because assetdrips_folder has
	 * show_ui:false and may not pass get_attachment_taxonomies() filtering
	 * in wp_ajax_save_attachment_compat (Pitfall 3 — 05-RESEARCH.md).
	 *
	 * wp_set_object_terms fires set_object_terms → SortHooks::on_set_object_terms
	 * → instant folder_id UPDATE in assetdrips_media (FOLDER-03).
	 *
	 * Security (T-05-02): sanitize_text_field strips tags/extra whitespace; absint
	 * ensures the value is a non-negative integer; > 0 guard prevents assignment
	 * of term_id 0 (which would be a no-op but is explicitly blocked for clarity).
	 * Empty string clears the assignment (Uncategorized). Invalid values (negative,
	 * non-numeric after absint) are silently dropped — the existing term assignment
	 * is unchanged.
	 *
	 * @param array<string, mixed> $post       Partial wp_posts data for the attachment.
	 * @param array<string, mixed> $attachment POST data from the attachment fields.
	 * @return array<string, mixed>
	 */
	public function save_folder_field( array $post, array $attachment ): array {
		if ( ! isset( $attachment['assetdrips_folder'] ) ) {
			return $post;
		}

		$attachment_id = (int) $post['ID'];
		$raw_val       = sanitize_text_field( $attachment['assetdrips_folder'] );

		if ( '' === $raw_val ) {
			// Clear assignment — revert to Uncategorized (SortHooks NULLs folder_id).
			wp_set_object_terms( $attachment_id, array(), 'assetdrips_folder', false );
		} else {
			$term_id = absint( $raw_val );
			if ( $term_id > 0 ) {
				wp_set_object_terms( $attachment_id, array( $term_id ), 'assetdrips_folder', false );
			}
		}
		// wp_set_object_terms fires set_object_terms -> SortHooks::on_set_object_terms -> folder_id UPDATE.

		return $post;
	}
}
