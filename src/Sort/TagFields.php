<?php
/**
 * Attachment panel tag assignment — attachment_fields_to_edit /
 * attachment_fields_to_save filters.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Sort;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the tag assignment text input in the media modal and Edit Media
 * screen (attachment_fields_to_edit) and persists the selection via
 * wp_set_object_terms (attachment_fields_to_save).
 *
 * Covers both surfaces with one filter pair (D-03):
 *   - Media modal (Backbone): wp_ajax_save_attachment_compat applies
 *     attachment_fields_to_save.
 *   - Edit Media (post.php): edit_post() applies attachment_fields_to_save.
 *
 * append=false in wp_set_object_terms replaces the full tag set on every save,
 * so removing a name from the field removes that tag (D-04, Success Criteria 1+2).
 *
 * NOTE: Do NOT rely on get_attachment_taxonomies() / automatic taxonomy
 * processing in wp_ajax_save_attachment_compat for show_ui:false taxonomies —
 * Pitfall 3 from 05-RESEARCH.md. We always call wp_set_object_terms manually
 * here in attachment_fields_to_save.
 *
 * Register inside is_admin() in Plugin::boot() — these filters only fire on
 * admin screens.
 */
final class TagFields {

	/**
	 * Register the attachment field filters.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_tag_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_tag_field' ), 10, 2 );
	}

	/**
	 * Inject the tag assignment text input into the attachment edit form.
	 *
	 * Pre-fills with the attachment's current tag names joined by ', ' (comma-space).
	 * Inline autocomplete JS queries /wp/v2/assetdrips_tag?search={q} and renders a
	 * suggestion listbox (ARIA combobox pattern, progressive enhancement).
	 *
	 * The <input> name MUST be attachments[{$post->ID}][assetdrips_tags] so that
	 * $_REQUEST['attachments'][$id]['assetdrips_tags'] is populated for both the
	 * AJAX modal save and the attachment_fields_to_save filter (D-03).
	 *
	 * Output escaping: esc_attr() on all attribute values (T-06-03).
	 * aria-label hardcoded literal — always valid regardless of JS state.
	 *
	 * @param array<string, mixed> $form_fields Existing attachment form fields.
	 * @param \WP_Post             $post        Attachment post.
	 * @return array<string, mixed>
	 */
	public function add_tag_field( array $form_fields, \WP_Post $post ): array {
		// Resolve current tags by name for comma-separated display (D-04).
		$current_terms = wp_get_object_terms(
			$post->ID,
			'assetdrips_tag',
			array( 'fields' => 'names' )
		);
		$current_value = ( ! is_wp_error( $current_terms ) && ! empty( $current_terms ) )
			? implode( ', ', $current_terms )
			: '';

		$input_id   = 'attachments-' . (int) $post->ID . '-assetdrips_tags';
		$input_name = 'attachments[' . (int) $post->ID . '][assetdrips_tags]';

		// Build the text input. aria-label is hardcoded (always valid with or without JS).
		// aria-autocomplete, aria-owns, aria-expanded are also hardcoded here; they are
		// valid even without JS (the listbox is present but hidden).
		$html = '<input type="text"'
			. ' name="' . esc_attr( $input_name ) . '"'
			. ' id="' . esc_attr( $input_id ) . '"'
			. ' value="' . esc_attr( $current_value ) . '"'
			. ' placeholder="Enter tags, comma-separated&hellip;"'
			. ' class="widefat"'
			. ' autocomplete="off"'
			. ' aria-label="Tags (comma-separated)"'
			. ' aria-autocomplete="list"'
			. ' aria-owns="ad-tag-suggestions-' . (int) $post->ID . '"'
			. ' aria-expanded="false" />';

		// Suggestion listbox — populated by inline JS; hidden without JS (display:none).
		$html .= '<ul id="ad-tag-suggestions-' . (int) $post->ID . '"'
			. ' role="listbox"'
			. ' aria-label="Tag suggestions"'
			. ' class="ad-tag-suggestions"'
			. ' style="display:none;position:absolute;z-index:9999;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 2px 6px rgba(0,0,0,.15);max-height:240px;overflow-y:auto;min-width:200px;width:100%;margin:0;padding:0;list-style:none;">'
			. '</ul>';

		// Inline autocomplete JS — zero-dependency, progressive enhancement.
		// Pitfall 4 (numeric tag name treated as term_id by wp_set_object_terms): WP
		// inspects whether the value is numeric (is_numeric) and treats it as a term_id
		// if so. This is known WP behavior; purely numeric tag names (e.g. "2024") may
		// resolve to a term_id rather than creating a new term named "2024". This is
		// uncommon for attachment tags and is documented but not mitigated (per Plan 01
		// Task 2 action note — accept WP default behavior).
		$nonce = wp_create_nonce( 'wp_rest' );
		$html .= $this->build_autocomplete_script( (int) $post->ID, $nonce );

		$form_fields['assetdrips_tags'] = array(
			'label' => 'Tags',
			'input' => 'html',
			'html'  => $html,
		);

		return $form_fields;
	}

	/**
	 * Persist the tag selection via wp_set_object_terms.
	 *
	 * Manually calls wp_set_object_terms because assetdrips_tag has show_ui:false
	 * and may not pass get_attachment_taxonomies() filtering in
	 * wp_ajax_save_attachment_compat (Pitfall 3 — 05-RESEARCH.md).
	 *
	 * append=false (D-04): replaces the full tag set. Saving a subset removes
	 * the omitted tags. Saving empty clears all tags (Success Criterion 2).
	 * Saving with names auto-creates any missing terms (D-05, TAG-01).
	 *
	 * Security (T-06-02): sanitize_text_field + wp_unslash on raw value;
	 * names passed to wp_set_object_terms which WP sanitizes via sanitize_title()
	 * internally; no raw SQL.
	 *
	 * @param array<string, mixed> $post       Partial wp_posts data for the attachment.
	 * @param array<string, mixed> $attachment POST data from the attachment fields.
	 * @return array<string, mixed>
	 */
	public function save_tag_field( array $post, array $attachment ): array {
		if ( ! isset( $attachment['assetdrips_tags'] ) ) {
			return $post;
		}

		$attachment_id = (int) $post['ID'];
		$raw_val       = sanitize_text_field( wp_unslash( $attachment['assetdrips_tags'] ) );

		if ( '' === $raw_val ) {
			// Clear all tags — full-set replace with empty (D-04, D-05).
			wp_set_object_terms( $attachment_id, array(), 'assetdrips_tag', false );
		} else {
			// Split on comma, trim whitespace, discard empty strings, deduplicate.
			// Uses names (not IDs) so wp_set_object_terms auto-creates missing terms (D-05).
			$names = array_values(
				array_unique(
					array_filter(
						array_map( 'trim', explode( ',', $raw_val ) )
					)
				)
			);
			if ( ! empty( $names ) ) {
				wp_set_object_terms( $attachment_id, $names, 'assetdrips_tag', false );
			} else {
				wp_set_object_terms( $attachment_id, array(), 'assetdrips_tag', false );
			}
		}

		return $post;
	}

	/**
	 * Build the inline autocomplete <script> for the tag text input.
	 *
	 * Pattern: ARIA combobox with listbox, debounced REST fetch, keyboard navigation.
	 * Zero-dependency: no external libraries, no assets/js/, no bundler.
	 * Progressive enhancement: the <input> is fully functional without JS.
	 *
	 * Security (T-06-04): X-WP-Nonce header on all REST fetches; suggestion list
	 * items rendered via textContent (not innerHTML) — browser-level XSS escape.
	 * The nonce string is PHP-echoed as a JS string literal, esc_js()-escaped.
	 *
	 * REST endpoint: /wp/v2/assetdrips_tag?search={q}&per_page=10&context=view
	 * Accessible to logged-in admins/editors; view context returns true regardless
	 * of public:false on the taxonomy (RESEARCH.md Pattern 4, confirmed).
	 *
	 * @param int    $post_id Attachment ID (used to scope element IDs to this field).
	 * @param string $nonce   wp_create_nonce('wp_rest') value for X-WP-Nonce header.
	 * @return string Inline <script> HTML string.
	 */
	private function build_autocomplete_script( int $post_id, string $nonce ): string {
		$safe_nonce   = esc_js( $nonce );
		$safe_post_id = (int) $post_id;
		// rest_url() yields the site's full REST root (e.g. https://site/wp-json/wp/v2/assetdrips_tag);
		// a bare '/wp/v2/...' path 404s because it omits the /wp-json/ (or ?rest_route=) prefix (CR-01).
		$rest_base = rest_url( 'wp/v2/assetdrips_tag' );

		return '<script>
(function() {
	var inputId   = ' . wp_json_encode( 'attachments-' . $safe_post_id . '-assetdrips_tags' ) . ';
	var listId    = ' . wp_json_encode( 'ad-tag-suggestions-' . $safe_post_id ) . ';
	var itemIdPfx = ' . wp_json_encode( 'ad-tag-suggestion-' . $safe_post_id . '-' ) . ';
	var nonce     = ' . wp_json_encode( $safe_nonce ) . ';
	var restBase  = ' . wp_json_encode( $rest_base ) . ';

	var input     = document.getElementById( inputId );
	var list      = document.getElementById( listId );
	if ( ! input || ! list ) { return; }

	var timer     = null;
	var activeIdx = -1;
	var items     = [];

	function hideList() {
		list.style.display = \'none\';
		list.innerHTML     = \'\';
		items              = [];
		activeIdx          = -1;
		input.setAttribute( \'aria-expanded\', \'false\' );
		input.setAttribute( \'aria-activedescendant\', \'\' );
	}

	function setActive( idx ) {
		items.forEach( function( li, i ) {
			var isActive = ( i === idx );
			li.classList.toggle( \'ad-tag-suggestion--active\', isActive );
			li.setAttribute( \'aria-selected\', isActive ? \'true\' : \'false\' );
			if ( isActive ) {
				li.style.background = \'#2271b1\';
				li.style.color      = \'#ffffff\';
				input.setAttribute( \'aria-activedescendant\', li.id );
			} else {
				li.style.background = \'\';
				li.style.color      = \'\';
			}
		} );
		activeIdx = idx;
	}

	function commitSuggestion( name ) {
		// Case-insensitive dedupe: do not insert if name already in input.
		var parts    = input.value.split( \',\' );
		var existing = parts.map( function( p ) { return p.trim().toLowerCase(); } );
		if ( existing.indexOf( name.toLowerCase() ) !== -1 ) {
			hideList();
			input.focus();
			return;
		}
		// Replace the last token with the selected name, append ", " for next entry.
		parts[ parts.length - 1 ] = \' \' + name;
		input.value = parts.join( \',\' ) + \', \';
		// Move cursor to end.
		var len = input.value.length;
		input.setSelectionRange( len, len );
		hideList();
		input.setAttribute( \'aria-expanded\', \'false\' );
		input.focus();
	}

	function renderSuggestions( terms ) {
		input.setAttribute( \'aria-busy\', \'false\' );
		list.innerHTML = \'\';
		items          = [];
		activeIdx      = -1;

		if ( ! terms || terms.length === 0 ) {
			hideList();
			return;
		}

		terms.forEach( function( term, idx ) {
			var li = document.createElement( \'li\' );
			li.id  = itemIdPfx + idx;
			li.setAttribute( \'role\', \'option\' );
			li.setAttribute( \'aria-selected\', \'false\' );
			li.className   = \'ad-tag-suggestion\';
			li.style.cssText = \'padding:4px 8px;min-height:32px;cursor:pointer;line-height:1.6;\';

			// Render name as plain text (not innerHTML) — browser-level XSS escape (T-06-03).
			var nameNode = document.createTextNode( term.name );
			li.appendChild( nameNode );

			if ( term.count > 0 ) {
				var countSpan = document.createElement( \'span\' );
				countSpan.className   = \'ad-tag-count\';
				countSpan.style.color = \'#787c82\';
				countSpan.textContent = \' (\' + term.count + \')\';
				li.appendChild( countSpan );
			}

			li.addEventListener( \'mousedown\', function( e ) {
				// mousedown fires before blur; prevent blur from closing the list first.
				e.preventDefault();
				commitSuggestion( term.name );
			} );

			list.appendChild( li );
			items.push( li );
		} );

		list.style.display = \'block\';
		input.setAttribute( \'aria-expanded\', \'true\' );
	}

	function fetchSuggestions( q ) {
		input.setAttribute( \'aria-busy\', \'true\' );
		// rest_url() may already carry a query string (?rest_route=) on plain-permalink sites.
		var sep = ( restBase.indexOf( \'?\' ) === -1 ) ? \'?\' : \'&\';
		var url = restBase + sep + \'search=\' + encodeURIComponent( q ) + \'&per_page=10&context=view\';
		fetch( url, {
			headers: {
				\'X-WP-Nonce\': nonce
			}
		} )
		.then( function( r ) {
			if ( ! r.ok ) {
				// 4xx/5xx: degrade silently (T-06-04 fallback; A1).
				input.setAttribute( \'aria-busy\', \'false\' );
				hideList();
				return null;
			}
			return r.json();
		} )
		.then( function( terms ) {
			if ( terms ) {
				renderSuggestions( terms );
			}
		} )
		.catch( function() {
			// Network error: degrade silently to plain text input.
			input.setAttribute( \'aria-busy\', \'false\' );
			hideList();
		} );
	}

	// Debounced input handler — 300ms (UI-SPEC).
	input.addEventListener( \'input\', function() {
		clearTimeout( timer );
		var q = input.value.split( \',\' ).pop().trim();
		if ( q.length < 2 ) {
			hideList();
			return;
		}
		timer = setTimeout( function() {
			fetchSuggestions( q );
		}, 300 );
	} );

	// Keyboard navigation.
	input.addEventListener( \'keydown\', function( e ) {
		if ( list.style.display === \'none\' ) { return; }
		if ( e.key === \'ArrowDown\' ) {
			e.preventDefault();
			setActive( items.length ? ( activeIdx + 1 ) % items.length : -1 );
		} else if ( e.key === \'ArrowUp\' ) {
			e.preventDefault();
			setActive( items.length ? ( activeIdx - 1 + items.length ) % items.length : -1 );
		} else if ( e.key === \'Enter\' ) {
			if ( activeIdx >= 0 && items[ activeIdx ] ) {
				e.preventDefault();
				var activeName = items[ activeIdx ].childNodes[0].textContent;
				commitSuggestion( activeName );
			}
		} else if ( e.key === \'Escape\' ) {
			hideList();
			input.setAttribute( \'aria-activedescendant\', \'\' );
		} else if ( e.key === \'Tab\' ) {
			// Close list on Tab but allow normal tab navigation.
			hideList();
		}
	} );

	// Close on outside click (mousedown fires before blur).
	document.addEventListener( \'mousedown\', function( e ) {
		if ( e.target !== input && e.target !== list && ! list.contains( e.target ) ) {
			hideList();
		}
	} );

	// Close on blur if the new focus target is outside input/list.
	input.addEventListener( \'blur\', function() {
		// Small timeout to allow mousedown on a list item to fire first.
		setTimeout( function() {
			if ( document.activeElement !== input && ! list.contains( document.activeElement ) ) {
				hideList();
			}
		}, 100 );
	} );
}());
</script>';
	}
}
