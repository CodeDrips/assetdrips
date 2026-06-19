<?php
/**
 * Admin Folders screen — folder management UI.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Admin;

use AssetDrips\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The AssetDrips Folders admin screen.
 *
 * A dedicated submenu page providing a server-rendered nested <ul> tree of
 * virtual folders, AJAX CRUD (create/rename/delete) with no-JS admin_post
 * fallbacks, quick-link deep-links into the Phase 4 Find folder facet, and an
 * inline progressive-enhancement script. Zero third-party JS/CSS dependencies.
 *
 * D-07 delete sequence: reparent direct children to deleted folder's parent →
 * remove assetdrips_folders sort_weight row → wp_delete_term (pre_delete_term
 * hook in SortHooks NULLs folder_id before WP removes term_relationships).
 */
final class FolderScreen {

	/**
	 * Page slug. Public so other screens can deep-link into Folders.
	 */
	public const SLUG = 'assetdrips-folders';

	/**
	 * Capability required to view and act.
	 */
	private const CAP = 'manage_options';

	/**
	 * Nonce action for AJAX requests. Distinct from FindScreen (Pitfall 7).
	 */
	private const AJAX_NONCE = 'assetdrips_folders_ajax';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'wp_ajax_assetdrips_folder_create', array( $this, 'ajax_folder_create' ) );
		add_action( 'wp_ajax_assetdrips_folder_rename', array( $this, 'ajax_folder_rename' ) );
		add_action( 'wp_ajax_assetdrips_folder_delete', array( $this, 'ajax_folder_delete' ) );
		add_action( 'admin_post_assetdrips_folder_create', array( $this, 'handle_folder_create' ) );
		add_action( 'admin_post_assetdrips_folder_rename', array( $this, 'handle_folder_rename' ) );
		add_action( 'admin_post_assetdrips_folder_delete', array( $this, 'handle_folder_delete' ) );
	}

	/**
	 * Add the Folders submenu under the AssetDrips top-level menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			Dashboard::SLUG,
			'Folders — Media Organization',
			'Folders',
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the Folders screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		echo '<div class="wrap assetdrips-wrap">';
		$this->print_styles();
		$this->print_header();
		$this->print_notice();
		$this->print_quick_links();
		$this->print_create_form();
		echo '<div class="ad-folder-tree-wrap">';
		echo '<ul class="ad-folder-tree" id="ad-folder-tree">';
		$this->print_folder_tree();
		echo '</ul>';
		echo '</div><!-- .ad-folder-tree-wrap -->';
		$this->print_folder_script();
		echo '</div><!-- .wrap -->';
	}

	/**
	 * Print the folder tree nodes (public so AJAX handlers can buffer it).
	 *
	 * Fetches all terms, loads sort_weights in one query, builds a
	 * parent-keyed lookup, and renders recursively from parent 0.
	 *
	 * @return void
	 */
	public function print_folder_tree(): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'assetdrips_folder',
				'hide_empty' => false,
				'orderby'    => 'id',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			echo '<div class="ad-empty ad-folder-empty">';
			echo '<span class="dashicons dashicons-category" style="font-size:40px;color:#ccc;"></span>';
			echo '<p><strong>No folders yet.</strong></p>';
			echo '<p>Create your first folder above to start organizing your media library.</p>';
			echo '</div>';
			return;
		}

		// Load sort_weights for all terms in one query.
		global $wpdb;
		$folders_table = Schema::folders_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single admin-page read; no user input; table name is Schema constant (never user input).
		$weight_rows = $wpdb->get_results(
			"SELECT term_id, sort_weight FROM {$folders_table}",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$sort_weights = array();
		if ( is_array( $weight_rows ) ) {
			foreach ( $weight_rows as $row ) {
				$sort_weights[ (int) $row['term_id'] ] = (int) $row['sort_weight'];
			}
		}

		// Build a parent-keyed lookup (direct children only).
		$children_by_parent = array();
		foreach ( (array) $terms as $term ) {
			if ( ! ( $term instanceof \WP_Term ) ) {
				continue;
			}
			$parent = (int) $term->parent;
			if ( ! isset( $children_by_parent[ $parent ] ) ) {
				$children_by_parent[ $parent ] = array();
			}
			$children_by_parent[ $parent ][] = $term;
		}

		// Render from the root (parent = 0).
		$this->render_tree_node( $children_by_parent, 0, $sort_weights, 0 );
	}

	/**
	 * Recursively render one level of the folder tree.
	 *
	 * @param array<int, array<\WP_Term>> $children_by_parent Map of parent_id to direct children.
	 * @param int                         $term_parent        Current parent term_id (0 = root).
	 * @param array<int, int>             $sort_weights       Map of term_id to sort_weight.
	 * @param int                         $depth              Current recursion depth (cap: 100).
	 * @return void
	 */
	private function render_tree_node(
		array $children_by_parent,
		int $term_parent,
		array $sort_weights,
		int $depth
	): void {
		if ( $depth >= 100 || ! isset( $children_by_parent[ $term_parent ] ) ) {
			return;
		}

		$children = $children_by_parent[ $term_parent ];

		// Sort by sort_weight ASC then name ASC.
		usort(
			$children,
			function ( \WP_Term $a, \WP_Term $b ) use ( $sort_weights ): int {
				$wa = $sort_weights[ $a->term_id ] ?? 0;
				$wb = $sort_weights[ $b->term_id ] ?? 0;
				if ( $wa !== $wb ) {
					return $wa <=> $wb;
				}
				return strcmp( $a->name, $b->name );
			}
		);

		$find_base = add_query_arg( 'page', FindScreen::SLUG, admin_url( 'admin.php' ) );
		$post_url  = esc_url( admin_url( 'admin-post.php' ) );

		foreach ( $children as $term ) {
			$term_id     = (int) $term->term_id;
			$term_name   = $term->name;
			$file_count  = (int) $term->count;
			$direct_subs = isset( $children_by_parent[ $term_id ] ) ? count( $children_by_parent[ $term_id ] ) : 0;
			$view_url    = esc_url( add_query_arg( 'folder', $term_id, $find_base ) );

			echo '<li class="ad-folder-node" data-term-id="' . esc_attr( (string) $term_id ) . '" data-name="' . esc_attr( $term_name ) . '" data-files="' . esc_attr( (string) $file_count ) . '" data-subs="' . esc_attr( (string) $direct_subs ) . '">';
			echo '<span class="ad-folder-row">';
			echo '<span class="dashicons dashicons-category ad-folder-icon"></span>';
			echo '<span class="ad-folder-name">' . esc_html( $term_name ) . '</span>';
			echo '<span class="ad-folder-count">' . esc_html( (string) $file_count ) . '</span>';
			echo '<span class="ad-folder-actions">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $view_url already escaped via esc_url().
			echo '<a href="' . $view_url . '" class="button button-small">View files</a> ';
			echo '<button type="button" class="button button-small ad-folder-rename-btn" data-term-id="' . esc_attr( (string) $term_id ) . '">Rename</button> ';
			// Delete form wraps the Delete button so admin_post_assetdrips_folder_delete
			// is reachable without JavaScript (D-04 no-JS fallback). JS intercepts the
			// form's submit event to run confirm() + AJAX instead of a full page reload.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post_url already escaped via esc_url().
			echo '<form class="ad-folder-delete-form" method="post" action="' . $post_url . '" style="display:inline;">';
			echo '<input type="hidden" name="action" value="assetdrips_folder_delete">';
			echo '<input type="hidden" name="term_id" value="' . esc_attr( (string) $term_id ) . '">';
			wp_nonce_field( 'assetdrips_folder_delete' );
			echo '<button type="submit" class="button button-small ad-folder-delete-btn" data-term-id="' . esc_attr( (string) $term_id ) . '" data-name="' . esc_attr( $term_name ) . '" data-files="' . esc_attr( (string) $file_count ) . '" data-subs="' . esc_attr( (string) $direct_subs ) . '">Delete</button>';
			echo '</form>';
			echo '</span><!-- .ad-folder-actions -->';
			echo '</span><!-- .ad-folder-row -->';

			// Inline rename form (hidden by default; toggled by JS).
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post_url already escaped via esc_url().
			echo '<form class="ad-folder-rename-form" style="display:none;" method="post" action="' . $post_url . '">';
			echo '<input type="hidden" name="action" value="assetdrips_folder_rename">';
			echo '<input type="hidden" name="term_id" value="' . esc_attr( (string) $term_id ) . '">';
			wp_nonce_field( 'assetdrips_folder_rename' );
			echo '<input type="text" name="name" value="' . esc_attr( $term_name ) . '" class="ad-folder-rename-input" required maxlength="200">';
			echo '<button type="submit" class="button button-primary button-small">Rename Folder</button> ';
			echo '<button type="button" class="button button-small ad-folder-rename-cancel">Keep Original</button>';
			echo '</form>';

			// Nested children.
			if ( isset( $children_by_parent[ $term_id ] ) ) {
				echo '<ul class="ad-folder-tree">';
				$this->render_tree_node( $children_by_parent, $term_id, $sort_weights, $depth + 1 );
				echo '</ul>';
			}

			echo '</li>';
		}
	}

	/**
	 * Print the quick-links bar (All Files + Uncategorized deep-links).
	 *
	 * Deep-links: Find page URL + &folder= (empty, All Files) and &folder=uncategorized.
	 *
	 * @return void
	 */
	private function print_quick_links(): void {
		global $wpdb;

		$find_base = add_query_arg( 'page', FindScreen::SLUG, admin_url( 'admin.php' ) );

		// Total attachments via WP API.
		$counts      = wp_count_attachments();
		$total_count = 0;
		if ( is_object( $counts ) ) {
			foreach ( (array) $counts as $mime_count ) {
				$total_count += (int) $mime_count;
			}
		}

		// Uncategorized count: attachments with folder_id IS NULL (literal, zero-arg, mirrors D-02 sentinel).
		$media_table = Schema::media_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single admin-page read; no user input; table name is Schema constant; IS NULL is a literal (zero bound args).
		$null_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$media_table} WHERE folder_id IS NULL"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// All Files: &folder= (empty string) — shows all attachments regardless of folder assignment.
		$all_url = esc_url( $find_base . '&folder=' );
		// Uncategorized: &folder=uncategorized — shows only attachments with folder_id IS NULL.
		$uncategorized_url = esc_url( $find_base . '&folder=uncategorized' );

		echo '<div class="ad-folder-quicklinks">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $all_url and $uncategorized_url already escaped via esc_url().
		echo '<a href="' . $all_url . '" class="button">All Files (' . esc_html( (string) $total_count ) . ')</a> ';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $uncategorized_url already escaped via esc_url().
		echo '<a href="' . $uncategorized_url . '" class="button">Uncategorized (' . esc_html( (string) $null_count ) . ')</a>';
		echo '</div><!-- .ad-folder-quicklinks -->';
	}

	/**
	 * Print the create-folder form.
	 *
	 * @return void
	 */
	private function print_create_form(): void {
		$post_url = esc_url( admin_url( 'admin-post.php' ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $post_url already escaped via esc_url().
		echo '<form class="ad-folder-create-form" method="post" action="' . $post_url . '" id="ad-folder-create">';
		echo '<input type="hidden" name="action" value="assetdrips_folder_create">';
		wp_nonce_field( 'assetdrips_folder_create' );
		echo '<div class="ad-folder-create-row">';
		echo '<input type="text" name="name" placeholder="New folder name" class="regular-text" required maxlength="200" id="ad-new-folder-name">';
		echo '<label for="ad-new-folder-parent">Under:&nbsp;';
		echo '<select name="parent_id" id="ad-new-folder-parent">';
		echo '<option value="0">— Top level —</option>';

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
				while ( $p > 0 && $depth < 100 ) {
					++$depth;
					$parent_term = get_term( $p, 'assetdrips_folder' );
					$p           = ( $parent_term instanceof \WP_Term ) ? (int) $parent_term->parent : 0;
				}
				$indent = str_repeat( '&nbsp;&nbsp;&nbsp;', $depth );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Name is escaped via esc_html(); &nbsp; indent is a safe literal.
				echo '<option value="' . esc_attr( (string) $term->term_id ) . '">' . $indent . esc_html( $term->name ) . '</option>';
			}
		}

		echo '</select>';
		echo '</label>';
		echo '<button type="submit" class="button button-primary">Create Folder</button>';
		echo '</div><!-- .ad-folder-create-row -->';
		echo '</form>';
	}

	/**
	 * AJAX handler: create a folder.
	 *
	 * Security (T-05-07): capability check first, nonce second.
	 *
	 * @return void
	 */
	public function ajax_folder_create(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
		$name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$parent_id = isset( $_POST['parent_id'] ) ? absint( wp_unslash( $_POST['parent_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => 'Folder name cannot be empty.' ), 400 );
		}

		$result = wp_insert_term( $name, 'assetdrips_folder', array( 'parent' => $parent_id ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		// Upsert default sort_weight row (Pitfall 2). ON DUPLICATE KEY is a no-op
		// for retried AJAX creates where the term landed but a later step previously
		// failed — prevents silent INSERT failure on the primary key.
		global $wpdb;
		$folders_table = Schema::folders_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-table upsert; write op, no caching needed; table name is Schema constant (never user input); term_id bound via %d.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$folders_table} (term_id, sort_weight) VALUES (%d, 0) ON DUPLICATE KEY UPDATE sort_weight = sort_weight",
				(int) $result['term_id']
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		ob_start();
		$this->print_folder_tree();
		$tree_html = (string) ob_get_clean();

		wp_send_json_success( array( 'tree_html' => $tree_html ) );
	}

	/**
	 * AJAX handler: rename a folder.
	 *
	 * Security (T-05-07): capability check first, nonce second.
	 *
	 * @return void
	 */
	public function ajax_folder_rename(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $term_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid folder.' ), 400 );
		}

		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => 'Folder name cannot be empty.' ), 400 );
		}

		$result = wp_update_term( $term_id, 'assetdrips_folder', array( 'name' => $name ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		ob_start();
		$this->print_folder_tree();
		$tree_html = (string) ob_get_clean();

		wp_send_json_success( array( 'tree_html' => $tree_html ) );
	}

	/**
	 * AJAX handler: delete a folder (D-07 sequence).
	 *
	 * Security (T-05-07): capability check first, nonce second.
	 * Sequence: reparent direct children → remove sort_weight row →
	 * wp_delete_term (pre_delete_term hook NULLs folder_id).
	 *
	 * @return void
	 */
	public function ajax_folder_delete(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $term_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid folder.' ), 400 );
		}

		$term = get_term( $term_id, 'assetdrips_folder' );
		if ( ! $term instanceof \WP_Term ) {
			wp_send_json_error( array( 'message' => 'Folder not found.' ), 404 );
		}

		// Step 1: Get direct child terms only (NOT get_term_children — returns all descendants, Pitfall 6).
		$children = get_terms(
			array(
				'taxonomy'   => 'assetdrips_folder',
				'parent'     => $term_id,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( is_wp_error( $children ) ) {
			$children = array();
		}

		// Step 2: Reparent direct children to the deleted folder's parent (Pitfall 6).
		$new_parent = (int) $term->parent;
		foreach ( (array) $children as $child_id ) {
			wp_update_term( (int) $child_id, 'assetdrips_folder', array( 'parent' => $new_parent ) );
		}

		// Step 3: Remove sort-weight row from plugin table (Pitfall 2).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-table DELETE; no WP API wrapper; term_id bound via %d format; write operation, no caching needed.
		$wpdb->delete(
			Schema::folders_table(),
			array( 'term_id' => $term_id ),
			array( '%d' )
		);

		// Step 4: Delete term — pre_delete_term hook in SortHooks fires here,
		// NULLing folder_id in assetdrips_media before WP removes term_relationships.
		wp_delete_term( $term_id, 'assetdrips_folder' );

		ob_start();
		$this->print_folder_tree();
		$tree_html = (string) ob_get_clean();

		wp_send_json_success( array( 'tree_html' => $tree_html ) );
	}

	/**
	 * Admin_post handler: create folder (no-JS fallback).
	 *
	 * @return void
	 */
	public function handle_folder_create(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'assetdrips_folder_create' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() above.
		$name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$parent_id = isset( $_POST['parent_id'] ) ? absint( wp_unslash( $_POST['parent_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $name ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => self::SLUG,
						'folder_error' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$result = wp_insert_term( $name, 'assetdrips_folder', array( 'parent' => $parent_id ) );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => self::SLUG,
						'folder_error' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Upsert default sort_weight row (Pitfall 2). ON DUPLICATE KEY is a no-op
		// for retried no-JS creates where the term landed but a later step previously
		// failed — prevents silent INSERT failure on the primary key.
		global $wpdb;
		$folders_table = Schema::folders_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-table upsert; write op, no caching needed; table name is Schema constant (never user input); term_id bound via %d.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$folders_table} (term_id, sort_weight) VALUES (%d, 0) ON DUPLICATE KEY UPDATE sort_weight = sort_weight",
				(int) $result['term_id']
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::SLUG,
					'folder_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Admin_post handler: rename folder (no-JS fallback).
	 *
	 * @return void
	 */
	public function handle_folder_rename(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'assetdrips_folder_rename' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() above.
		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $term_id <= 0 || '' === $name ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => self::SLUG,
						'folder_error' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$result = wp_update_term( $term_id, 'assetdrips_folder', array( 'name' => $name ) );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => self::SLUG,
						'folder_error' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::SLUG,
					'folder_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Admin_post handler: delete folder (no-JS fallback, D-07 sequence).
	 *
	 * @return void
	 */
	public function handle_folder_delete(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'assetdrips_folder_delete' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() above.
		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $term_id <= 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => self::SLUG,
						'folder_error' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$term = get_term( $term_id, 'assetdrips_folder' );
		if ( ! $term instanceof \WP_Term ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => self::SLUG,
						'folder_error' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Step 1: Get direct child terms only (NOT get_term_children, Pitfall 6).
		$children = get_terms(
			array(
				'taxonomy'   => 'assetdrips_folder',
				'parent'     => $term_id,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( is_wp_error( $children ) ) {
			$children = array();
		}

		// Step 2: Reparent direct children to the deleted folder's parent.
		$new_parent = (int) $term->parent;
		foreach ( (array) $children as $child_id ) {
			wp_update_term( (int) $child_id, 'assetdrips_folder', array( 'parent' => $new_parent ) );
		}

		// Step 3: Remove sort-weight row (Pitfall 2).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-table DELETE; no WP API wrapper; term_id bound via %d format; write operation, no caching needed.
		$wpdb->delete(
			Schema::folders_table(),
			array( 'term_id' => $term_id ),
			array( '%d' )
		);

		// Step 4: Delete term — pre_delete_term hook fires, NULLing folder_id.
		wp_delete_term( $term_id, 'assetdrips_folder' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::SLUG,
					'folder_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Print the inline progressive-enhancement script for CRUD interactions.
	 *
	 * Mirrors FindScreen::print_find_script() structure.
	 * - Intercepts the create form submit → fetch admin-ajax.php.
	 * - Wires rename-btn to reveal inline form (one node at a time).
	 * - Wires delete-btn to window.confirm() + fetch admin-ajax.php.
	 *
	 * @return void
	 */
	private function print_folder_script(): void {
		$nonce = esc_js( wp_create_nonce( self::AJAX_NONCE ) );
		$ajax  = esc_js( admin_url( 'admin-ajax.php' ) );

		$script = <<<JS
(function(){
    var nonce = '{$nonce}';
    var ajax  = '{$ajax}';
    var tree  = document.getElementById('ad-folder-tree');
    var createForm = document.getElementById('ad-folder-create');

    function setLoading(loading) {
        var wrap = document.querySelector('.ad-folder-tree-wrap');
        if (!wrap) { return; }
        wrap.style.pointerEvents = loading ? 'none' : '';
        if (tree) { tree.setAttribute('aria-busy', loading ? 'true' : 'false'); }
        var btns = wrap.querySelectorAll('button');
        btns.forEach(function(b){ b.disabled = loading; });
        if (createForm) {
            createForm.querySelectorAll('button').forEach(function(b){ b.disabled = loading; });
        }
    }

    function showError(msg) {
        var existing = document.querySelector('.ad-folder-notice');
        if (existing) { existing.parentNode.removeChild(existing); }
        var notice = document.createElement('div');
        notice.className = 'notice notice-error is-dismissible ad-folder-notice';
        notice.style.margin = '8px 0';
        var p = document.createElement('p');
        p.textContent = msg;
        notice.appendChild(p);
        var treeWrap = document.querySelector('.ad-folder-tree-wrap');
        if (treeWrap) { treeWrap.parentNode.insertBefore(notice, treeWrap); }
    }

    function clearError() {
        var existing = document.querySelector('.ad-folder-notice');
        if (existing) { existing.parentNode.removeChild(existing); }
    }

    function refreshTree(html) {
        if (tree) { tree.innerHTML = html; }
        bindTree();
    }

    function doRequest(body, onSuccess) {
        setLoading(true);
        fetch(ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(function(r){ return r.json(); })
        .then(function(res){
            setLoading(false);
            if (res && res.success && res.data && res.data.tree_html !== undefined) {
                clearError();
                refreshTree(res.data.tree_html);
                if (onSuccess) { onSuccess(); }
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : 'Something went wrong. Please try again.';
                showError(msg);
            }
        })
        .catch(function(){
            setLoading(false);
            showError('Something went wrong. Please try again.');
        });
    }

    // Create form interception.
    if (createForm) {
        createForm.addEventListener('submit', function(e){
            e.preventDefault();
            var nameInput = document.getElementById('ad-new-folder-name');
            var parentSel = document.getElementById('ad-new-folder-parent');
            var name = nameInput ? nameInput.value.trim() : '';
            var parentId = parentSel ? parentSel.value : '0';
            if (!name) { showError('Folder name cannot be empty.'); return; }
            var body = 'action=assetdrips_folder_create&nonce=' + encodeURIComponent(nonce)
                + '&name=' + encodeURIComponent(name)
                + '&parent_id=' + encodeURIComponent(parentId);
            doRequest(body, function(){ if (nameInput) { nameInput.value = ''; } });
        });
    }

    function bindTree() {
        tree = document.getElementById('ad-folder-tree');
        if (!tree) { return; }

        // Rename buttons — one node at a time.
        tree.querySelectorAll('.ad-folder-rename-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                // Cancel any other open rename form first.
                tree.querySelectorAll('.ad-folder-rename-form').forEach(function(f){
                    f.style.display = 'none';
                    var li = f.closest('.ad-folder-node');
                    if (li) {
                        var nameSpan = li.querySelector('.ad-folder-name');
                        var actions  = li.querySelector('.ad-folder-actions');
                        if (nameSpan) { nameSpan.style.display = ''; }
                        if (actions)  { actions.style.display  = ''; }
                    }
                });
                var li = btn.closest('.ad-folder-node');
                if (!li) { return; }
                var form     = li.querySelector('.ad-folder-rename-form');
                var nameSpan = li.querySelector('.ad-folder-name');
                var actions  = li.querySelector('.ad-folder-actions');
                if (form)     { form.style.display     = ''; }
                if (nameSpan) { nameSpan.style.display = 'none'; }
                if (actions)  { actions.style.display  = 'none'; }
                var input = form ? form.querySelector('.ad-folder-rename-input') : null;
                if (input)    { input.focus(); }
            });
        });

        // Rename cancel buttons.
        tree.querySelectorAll('.ad-folder-rename-cancel').forEach(function(btn){
            btn.addEventListener('click', function(){
                var li = btn.closest('.ad-folder-node');
                if (!li) { return; }
                var form     = li.querySelector('.ad-folder-rename-form');
                var nameSpan = li.querySelector('.ad-folder-name');
                var actions  = li.querySelector('.ad-folder-actions');
                if (form)     { form.style.display     = 'none'; }
                if (nameSpan) { nameSpan.style.display = ''; }
                if (actions)  { actions.style.display  = ''; }
            });
        });

        // Rename form submit.
        tree.querySelectorAll('.ad-folder-rename-form').forEach(function(form){
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var tidInput  = form.querySelector('input[name="term_id"]');
                var nameInput = form.querySelector('.ad-folder-rename-input');
                var termId    = tidInput  ? tidInput.value  : '';
                var name      = nameInput ? nameInput.value.trim() : '';
                if (!name) { showError('Folder name cannot be empty.'); return; }
                var body = 'action=assetdrips_folder_rename&nonce=' + encodeURIComponent(nonce)
                    + '&term_id=' + encodeURIComponent(termId)
                    + '&name='    + encodeURIComponent(name);
                doRequest(body);
            });
        });

        // Delete forms — intercept submit so both JS and no-JS paths work (D-04).
        // JS: preventDefault + confirm() + AJAX. No-JS: form submits normally to admin-post.php.
        tree.querySelectorAll('.ad-folder-delete-form').forEach(function(form){
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var btn   = form.querySelector('.ad-folder-delete-btn');
                var label = btn ? (btn.dataset.name  || 'this folder') : 'this folder';
                var files = btn ? parseInt(btn.dataset.files || '0', 10) : 0;
                var subs  = btn ? parseInt(btn.dataset.subs  || '0', 10) : 0;
                var msg   = 'Delete "' + label + '"?\n';
                if (files > 0) { msg += files + ' file(s) will revert to Uncategorized.\n'; }
                if (subs  > 0) { msg += subs  + ' subfolder(s) will be moved up one level.\n'; }
                if (!window.confirm(msg)) { return; }
                var termId = btn ? (btn.dataset.termId || btn.getAttribute('data-term-id') || '') : '';
                var body = 'action=assetdrips_folder_delete&nonce=' + encodeURIComponent(nonce)
                    + '&term_id=' + encodeURIComponent(termId);
                doRequest(body);
            });
        });
    }

    // Initial bind.
    bindTree();

})();
JS;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static script body; dynamic values (nonce, ajax) escaped with esc_js() above.
		echo '<script>' . $script . '</script>';
	}

	/**
	 * Print an action notice from the redirect, if present.
	 *
	 * @return void
	 */
	private function print_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display of a post-redirect message; no state change.
		if ( isset( $_GET['folder_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Folder saved.</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display of a post-redirect message; no state change.
		if ( isset( $_GET['folder_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>Could not save folder. Please try again.</p></div>';
		}
	}

	/**
	 * Print the page header.
	 *
	 * @return void
	 */
	private function print_header(): void {
		$home = add_query_arg( 'page', Dashboard::SLUG, admin_url( 'admin.php' ) );
		echo '<a class="ad-crumb" href="' . esc_url( $home ) . '">&larr; AssetDrips</a>';
		echo '<div class="assetdrips-head">';
		echo '<h1>Folders</h1>';
		echo '<span class="ad-mod" style="background:#080808;">Manage</span>';
		echo '</div>';
		echo '<p class="assetdrips-tag">Create and manage virtual folders. Files are never moved.</p>';
	}

	/**
	 * Print the scoped brand styles for the Folders screen.
	 *
	 * Shared utility classes are copied verbatim from FindScreen (standalone
	 * rendering). New ad-folder-* classes follow the UI-SPEC CSS Class Inventory.
	 *
	 * @return void
	 */
	private function print_styles(): void {
		echo '<style>
			/* --- Shared utility classes (verbatim from FindScreen) --- */
			.assetdrips-wrap{--ad-orange:#FF4200;--ad-black:#080808;color:var(--ad-black);}
			.ad-crumb{display:inline-block;margin:6px 0 2px;color:#777;text-decoration:none;font-size:13px;font-weight:600;}
			.ad-crumb:hover{color:var(--ad-orange);}
			.assetdrips-head{display:flex;align-items:baseline;gap:12px;margin:4px 0 4px;}
			.assetdrips-head h1{font-size:28px;font-weight:700;margin:0;color:var(--ad-black);}
			.assetdrips-head .ad-mod{background:var(--ad-orange);color:#fff;border-radius:999px;padding:2px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;}
			.assetdrips-tag{color:#555;margin:0 0 18px;max-width:680px;font-size:14px;}
			.ad-empty{padding:28px;text-align:center;color:#777;background:#fff;border-radius:12px;}
			.ad-mod{border-radius:999px;padding:2px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#fff;}
			.ad-crumb{display:inline-block;margin:6px 0 2px;color:#777;text-decoration:none;font-size:13px;font-weight:700;}
			/* --- New ad-folder-* classes (UI-SPEC CSS Class Inventory) --- */
			.ad-folder-quicklinks{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px;}
			.ad-folder-create-form{margin:0 0 20px;padding:12px 16px;background:#fff;border:1px solid #ececec;border-radius:8px;}
			.ad-folder-create-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px;}
			.ad-folder-create-row .button-primary{background:var(--ad-orange);border-color:var(--ad-orange);border-radius:999px;padding:4px 20px;}
			.ad-folder-tree-wrap{background:#fff;border:1px solid #ececec;border-radius:8px;padding:8px 0;}
			.ad-folder-tree{list-style:none;margin:0;padding:0;}
			.ad-folder-tree .ad-folder-tree{padding-left:20px;}
			.ad-folder-node{border-bottom:1px solid #ececec;}
			.ad-folder-node:last-child{border-bottom:none;}
			.ad-folder-node.is-active{border-left:4px solid var(--ad-orange);}
			.ad-folder-node.is-active .ad-folder-name{color:var(--ad-orange);}
			.ad-folder-row{display:flex;align-items:center;gap:8px;padding:8px 12px;min-height:2em;background:#fff;}
			.ad-folder-row:hover{background:#f3f3f3;}
			.ad-folder-icon{color:#aaa;flex-shrink:0;}
			.ad-folder-name{flex:1;font-size:14px;font-weight:400;color:var(--ad-black);}
			.ad-folder-count{display:inline-block;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:700;background:#f1f1f1;color:#666;flex-shrink:0;}
			.ad-folder-actions{display:flex;flex-wrap:wrap;gap:4px;flex-shrink:0;}
			.ad-folder-rename-form{padding:8px 12px;background:#f9f9f9;border-top:1px solid #ececec;display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
			.ad-folder-rename-input{height:28px;min-width:200px;}
			.ad-folder-rename-input:focus{outline:2px solid var(--ad-orange);}
			.ad-folder-empty{padding:28px;text-align:center;color:#777;background:#fff;border-radius:12px;}
			.ad-folder-notice{margin:8px 0;}
		</style>';
	}
}
