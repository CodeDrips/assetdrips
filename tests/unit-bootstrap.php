<?php
/**
 * Bootstrap for pure unit tests.
 *
 * These tests exercise logic that has no WordPress dependency (e.g. the
 * variant-key derivation in AttachmentCatalogue::build_keys). They run without a
 * database or the WP test suite. ABSPATH is defined so the plugin source files'
 * `defined( 'ABSPATH' ) || exit;` guards do not terminate the process on load.
 *
 * WP function stubs are declared here for the subset of WP API used by the
 * classes under test. Stubs that need stateful in-memory behaviour (e.g.
 * get_user_meta / update_user_meta) are backed by a global registry so
 * round-trip tests can verify read-back without a database.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Stubbing the WordPress core constant so guarded source files load under unit tests.
define( 'ABSPATH', sys_get_temp_dir() . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// Minimal WordPress function stubs (unit-test scope only).
// These stubs intentionally replicate WP function names without the plugin
// prefix so the plugin source files under test resolve them correctly.
// The phpcs:disable block covers all stub declarations in this section.
// ---------------------------------------------------------------------------

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP function stubs for unit-test bootstrapping; names must match WP API exactly.

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * No-op stub for WordPress add_action().
	 *
	 * @param string   $hook_name Hook name.
	 * @param callable $callback  Callback.
	 * @return void
	 */
	function add_action( string $hook_name, callable $callback ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; params intentionally unused.
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Stub for WordPress is_admin(). Defaults to false (front-end); a test may set
	 * $GLOBALS['__ad_test_is_admin'] = true to simulate a wp-admin request.
	 *
	 * @return bool
	 */
	function is_admin(): bool {
		return ! empty( $GLOBALS['__ad_test_is_admin'] );
	}
}

if ( ! function_exists( 'is_feed' ) ) {
	/**
	 * Stub for WordPress is_feed(). Defaults to false; a test may set
	 * $GLOBALS['__ad_test_is_feed'] = true to simulate an RSS/Atom feed request.
	 *
	 * @return bool
	 */
	function is_feed(): bool {
		return ! empty( $GLOBALS['__ad_test_is_feed'] );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Returns user ID 1 in unit tests.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return 1;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Trims the input string.
	 *
	 * @param string $str Input string.
	 * @return string
	 */
	function sanitize_text_field( string $str ): string {
		return trim( $str );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Returns the absolute integer value of the input.
	 *
	 * @param mixed $maybeint Input value.
	 * @return int
	 */
	function absint( $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Strips slashes from a string or passes non-string values through.
	 *
	 * @param mixed $value Input value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Stub for wp_create_nonce() — returns a deterministic test string.
	 *
	 * @param string $action Nonce action.
	 * @return string
	 */
	function wp_create_nonce( string $action ): string {
		return 'test_nonce_' . md5( $action );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Stub for number_format_i18n() — delegates to PHP number_format().
	 *
	 * @param float $number   Number to format.
	 * @param int   $decimals Decimal places.
	 * @return string
	 */
	function number_format_i18n( float $number, int $decimals = 0 ): string {
		return number_format( $number, $decimals );
	}
}

if ( ! function_exists( 'get_users' ) ) {
	/**
	 * Stub for get_users() — returns an empty array (no users in unit tests).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<int, mixed>
	 */
	function get_users( array $args = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
		return array();
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	/**
	 * Stub for get_terms() — returns an empty array (no taxonomy terms in unit tests).
	 *
	 * @param array<string, mixed>|string $args Query arguments or taxonomy name.
	 * @return array<int, mixed>
	 */
	function get_terms( $args = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
		return array();
	}
}

if ( ! function_exists( 'selected' ) ) {
	/**
	 * Stub for WordPress selected() — outputs or returns ' selected="selected"' when
	 * the two values match.
	 *
	 * @param mixed $selected One of the two values to compare.
	 * @param mixed $current  The other value to compare.
	 * @param bool  $echo     Whether to echo or return the result.
	 * @return string The ' selected="selected"' string when values match, '' otherwise.
	 */
	function selected( $selected, $current = true, bool $echo = true ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
		$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
		if ( $echo ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Stub; value is a fixed constant string.
		}
		return $result;
	}
}

// In-memory registry backing the get_user_meta / update_user_meta stubs.
$GLOBALS['_assetdrips_user_meta_stub'] = array();

if ( ! function_exists( 'get_user_meta' ) ) {
	/**
	 * Returns meta from the in-memory stub registry.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key.
	 * @param bool   $single   Ignored in the stub.
	 * @return mixed
	 */
	function get_user_meta( int $user_id, string $meta_key, bool $single = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; $single not needed.
		return $GLOBALS['_assetdrips_user_meta_stub'][ $user_id ][ $meta_key ] ?? '';
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	/**
	 * Writes meta to the in-memory stub registry.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value to store.
	 * @return bool
	 */
	function update_user_meta( int $user_id, string $meta_key, $meta_value ): bool {
		$GLOBALS['_assetdrips_user_meta_stub'][ $user_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Returns the current time as a MySQL datetime string.
	 * When $gmt is truthy, returns UTC; otherwise returns local time.
	 *
	 * @param string $type Ignored in stub — always returns datetime format.
	 * @param bool   $gmt  Ignored in stub — always returns UTC datetime.
	 * @return string
	 */
	function current_time( string $type, $gmt = false ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; params intentionally unused.
		return gmdate( 'Y-m-d H:i:s' );
	}
}

// In-memory registry backing the get_option / update_option / delete_option stubs.
$GLOBALS['_assetdrips_options_stub'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Returns the option value from the in-memory stub registry.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value when option is not set.
	 * @return mixed
	 */
	function get_option( string $option, $default = false ) {
		return $GLOBALS['_assetdrips_options_stub'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Writes the option value to the in-memory stub registry.
	 *
	 * @param string     $option   Option name.
	 * @param mixed      $value    Option value.
	 * @param mixed|null $autoload Ignored in the stub.
	 * @return bool
	 */
	function update_option( string $option, $value, $autoload = null ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; $autoload not needed.
		$GLOBALS['_assetdrips_options_stub'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Removes the option from the in-memory stub registry.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( string $option ): bool {
		unset( $GLOBALS['_assetdrips_options_stub'][ $option ] );
		return true;
	}
}

// In-memory registry backing the get_transient / set_transient / delete_transient stubs.
$GLOBALS['_assetdrips_transient_stub'] = array();

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Returns the transient value from the in-memory stub registry.
	 * Returns false (not null) on miss — mirroring WP's get_transient() contract.
	 *
	 * @param string $transient Transient name.
	 * @return mixed
	 */
	function get_transient( string $transient ) {
		return $GLOBALS['_assetdrips_transient_stub'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Writes the transient value to the in-memory stub registry.
	 *
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Value to store.
	 * @param int    $expiration Ignored in the stub.
	 * @return bool
	 */
	function set_transient( string $transient, $value, int $expiration = 0 ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; $expiration not needed.
		$GLOBALS['_assetdrips_transient_stub'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Removes the transient from the in-memory stub registry.
	 *
	 * @param string $transient Transient name.
	 * @return bool
	 */
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['_assetdrips_transient_stub'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	/**
	 * Returns a unique temporary file path in the system temp directory.
	 *
	 * @param string $filename Optional base filename (ignored in stub).
	 * @param string $dir      Optional directory (ignored in stub; always uses sys_get_temp_dir).
	 * @return string
	 */
	function wp_tempnam( string $filename = '', string $dir = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; params intentionally unused.
		return tempnam( sys_get_temp_dir(), 'squeeze-probe-' );
	}
}

// ---------------------------------------------------------------------------
// Phase 9 stubs: WP functions injected by BackupManagerTest, SqueezeEngineTest,
// BulkActionsSqueezeTest, and SqueezeScreenBackupTest.
// Each stub is guarded by function_exists so production code can still load these
// symbols first under integration tests.
// In-memory registries follow the same $GLOBALS['_assetdrips_*_stub'] convention
// used above for options, transients, and user-meta.
// ---------------------------------------------------------------------------

// In-memory registries for Phase 9 stubs.
$GLOBALS['_assetdrips_attached_file_stub']    = array();
$GLOBALS['_assetdrips_post_mime_stub']        = array();
$GLOBALS['_assetdrips_attachment_meta_stub']  = array();
$GLOBALS['_assetdrips_image_subsizes_stub']   = array();
$GLOBALS['_assetdrips_disk_free_space_stub']  = PHP_INT_MAX;
$GLOBALS['_assetdrips_current_user_can_stub'] = true;

// In-memory registries for Phase 13 WP image-subsize stubs.
$GLOBALS['_assetdrips_update_subsizes_stub']     = array(); // wp_update_image_subsizes
$GLOBALS['_assetdrips_missing_subsizes_stub']    = array(); // wp_get_missing_image_subsizes
$GLOBALS['_assetdrips_registered_subsizes_stub'] = array(); // wp_get_registered_image_subsizes

if ( ! function_exists( 'wp_update_image_subsizes' ) ) {
	/**
	 * Returns a configurable sizes array or WP_Error from the in-memory registry.
	 *
	 * Seed via: $GLOBALS['_assetdrips_update_subsizes_stub'][$id] = ['sizes' => [...]];
	 * Seed a WP_Error to exercise the error path:
	 *   $GLOBALS['_assetdrips_update_subsizes_stub'][$id] = new WP_Error('no_file', '...');
	 * Default: ['sizes' => []].
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	function wp_update_image_subsizes( int $attachment_id ) {
		return $GLOBALS['_assetdrips_update_subsizes_stub'][ $attachment_id ]
			?? array( 'sizes' => array() );
	}
}

if ( ! function_exists( 'wp_get_missing_image_subsizes' ) ) {
	/**
	 * Returns a configurable array of missing image subsizes from the in-memory registry.
	 *
	 * Seed via: $GLOBALS['_assetdrips_missing_subsizes_stub'][$id] = ['thumbnail' => [...]];
	 * Default: empty array (no missing sizes).
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<string, mixed>
	 */
	function wp_get_missing_image_subsizes( int $attachment_id ): array {
		return $GLOBALS['_assetdrips_missing_subsizes_stub'][ $attachment_id ] ?? array();
	}
}

if ( ! function_exists( 'wp_get_registered_image_subsizes' ) ) {
	/**
	 * Returns a configurable array of all registered image subsizes from the in-memory registry.
	 *
	 * Seed via: $GLOBALS['_assetdrips_registered_subsizes_stub'] = ['thumbnail' => [...]];
	 * Default: empty array.
	 *
	 * @return array<string, mixed>
	 */
	function wp_get_registered_image_subsizes(): array {
		return $GLOBALS['_assetdrips_registered_subsizes_stub'] ?? array();
	}
}

if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	/**
	 * Returns a minimal upload-dir array pointing at sys_get_temp_dir().
	 *
	 * @return array<string, mixed>
	 */
	function wp_get_upload_dir(): array {
		return array( 'basedir' => sys_get_temp_dir() );
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * Creates a directory recursively; returns true if it already exists.
	 *
	 * @param string $dir Directory path to create.
	 * @return bool
	 */
	function wp_mkdir_p( string $dir ): bool {
		if ( is_dir( $dir ) ) {
			return true;
		}
		return mkdir( $dir, 0755, true );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	/**
	 * Deletes a file from the filesystem (best-effort).
	 *
	 * @param string $path Absolute path to the file.
	 * @return void
	 */
	function wp_delete_file( string $path ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort deletion; file may not exist.
		@unlink( $path );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Returns the string unchanged (safe in unit context — no HTML encoding needed).
	 *
	 * @param string $s Input string.
	 * @return string
	 */
	function esc_html( string $s ): string {
		return $s;
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	/**
	 * Returns the absolute path for an attachment from the in-memory registry.
	 *
	 * Seed via: $GLOBALS['_assetdrips_attached_file_stub'][$id] = '/path/to/file.jpg';
	 *
	 * @param int $id Attachment post ID.
	 * @return string|false
	 */
	function get_attached_file( int $id ) {
		return $GLOBALS['_assetdrips_attached_file_stub'][ $id ] ?? false;
	}
}

if ( ! function_exists( 'get_post_mime_type' ) ) {
	/**
	 * Returns the MIME type for an attachment from the in-memory registry.
	 *
	 * Seed via: $GLOBALS['_assetdrips_post_mime_stub'][$id] = 'image/png';
	 * Default: 'image/jpeg'.
	 *
	 * @param int $id Attachment post ID.
	 * @return string
	 */
	function get_post_mime_type( int $id ): string {
		return $GLOBALS['_assetdrips_post_mime_stub'][ $id ] ?? 'image/jpeg';
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	/**
	 * Returns attachment metadata from the in-memory registry.
	 *
	 * Seed via: $GLOBALS['_assetdrips_attachment_meta_stub'][$id] = [...];
	 *
	 * @param int $id Attachment post ID.
	 * @return array<string, mixed>|false
	 */
	function wp_get_attachment_metadata( int $id ) {
		return $GLOBALS['_assetdrips_attachment_meta_stub'][ $id ] ?? false;
	}
}

if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
	/**
	 * Writes attachment metadata to the in-memory registry and returns true.
	 *
	 * @param int                  $id   Attachment post ID.
	 * @param array<string, mixed> $meta Metadata to store.
	 * @return bool
	 */
	function wp_update_attachment_metadata( int $id, array $meta ): bool {
		$GLOBALS['_assetdrips_attachment_meta_stub'][ $id ] = $meta;
		return true;
	}
}

if ( ! function_exists( 'wp_create_image_subsizes' ) ) {
	/**
	 * Returns a configurable sizes array from the in-memory registry.
	 *
	 * Seed via: $GLOBALS['_assetdrips_image_subsizes_stub'][$id] = ['thumbnail' => [...]];
	 * Default: empty array.
	 *
	 * Matches the real WordPress signature (since WP 5.3):
	 *   wp_create_image_subsizes( string $file, int $attachment_id ): array
	 *
	 * @param string $file          Absolute path to the image file.
	 * @param int    $attachment_id Attachment post ID.
	 * @return array<string, mixed>
	 */
	function wp_create_image_subsizes( string $file, int $attachment_id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Stub; $file accepted to match real WP signature.
		return $GLOBALS['_assetdrips_image_subsizes_stub'][ $attachment_id ] ?? array();
	}
}

if ( ! function_exists( 'wp_get_image_editor' ) ) {
	/**
	 * Stub for wp_get_image_editor() — returns a WP_Error in unit tests.
	 *
	 * Unit tests that need a functional editor inject a mock $editor_factory directly
	 * into the SqueezeEngine constructor. This stub exists so SqueezeEngine::from_wordpress()
	 * (called lazily from BackupManager::squeeze_engine()) does not fatal when no live
	 * WP image stack is loaded.
	 *
	 * @param string $path   Absolute path to the image file (unused in unit context).
	 * @param array  $args   Additional arguments (unused in unit context).
	 * @return \WP_Error
	 */
	function wp_get_image_editor( string $path, array $args = array() ): \WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
		return new \WP_Error( 'no_editor', 'No image editor available in unit test context.' );
	}
}

if ( ! function_exists( 'clearstatcache' ) ) {
	/**
	 * No-op stub; PHP's clearstatcache() is a built-in that may already exist.
	 *
	 * @param bool   $clear_realpath_cache Ignored.
	 * @param string $filename             Ignored.
	 * @return void
	 */
	function clearstatcache( bool $clear_realpath_cache = false, string $filename = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; params intentionally unused.
	}
}

if ( ! function_exists( 'disk_free_space' ) ) {
	/**
	 * Returns a configurable disk-free-space value.
	 *
	 * Seed via: $GLOBALS['_assetdrips_disk_free_space_stub'] = 1024;
	 * Default: PHP_INT_MAX (plenty of space).
	 *
	 * @param string $dir Directory path (ignored in stub).
	 * @return int|float
	 */
	function disk_free_space( string $dir ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub; param intentionally unused.
		return $GLOBALS['_assetdrips_disk_free_space_stub'] ?? PHP_INT_MAX;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Returns a global-flag-controlled boolean (default: true).
	 *
	 * Seed via: $GLOBALS['_assetdrips_current_user_can_stub'] = false;
	 *
	 * @param string $cap  Capability to check (ignored in stub).
	 * @param mixed  ...$args Additional args (ignored).
	 * @return bool
	 */
	function current_user_can( string $cap, ...$args ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; params intentionally unused.
		return (bool) ( $GLOBALS['_assetdrips_current_user_can_stub'] ?? true );
	}
}

// SqueezeScreen render() stubs — required by SqueezeScreenBackupTest.
// All stubs use function_exists guards to avoid redeclaration errors.

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Appends a query string argument to a URL.
	 *
	 * @param string|array<string, string> $key   Query key or associative array of key→value pairs.
	 * @param string                       $value Value (ignored when $key is an array).
	 * @param string                       $url   Base URL.
	 * @return string
	 */
	function add_query_arg( $key, string $value = '', string $url = '' ): string {
		if ( is_array( $key ) ) {
			return $url . '?' . http_build_query( $key );
		}
		return $url . '?' . urlencode( $key ) . '=' . urlencode( $value );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Returns a stub admin URL.
	 *
	 * @param string $path Path relative to wp-admin.
	 * @return string
	 */
	function admin_url( string $path = '' ): string {
		return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_get_referer' ) ) {
	/**
	 * Returns the referer from a settable stub global, or false when unset.
	 *
	 * Seed via: $GLOBALS['_assetdrips_wp_get_referer_stub'] = 'http://.../admin.php';
	 *
	 * @return string|false
	 */
	function wp_get_referer() {
		return $GLOBALS['_assetdrips_wp_get_referer_stub'] ?? false;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Returns the URL unchanged (safe in unit context).
	 *
	 * @param string $url URL to escape.
	 * @return string
	 */
	function esc_url( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Returns the string unchanged (safe in unit context).
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'checked' ) ) {
	/**
	 * Returns ' checked="checked"' when the two values are equal.
	 *
	 * @param mixed $helper  Value to compare.
	 * @param mixed $current Current value.
	 * @param bool  $echo    Whether to echo (ignored in stub — always returns).
	 * @return string
	 */
	function checked( $helper, $current, bool $echo = true ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
		// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- Mirrors WP checked() behavior.
		$result = ( $helper == $current ) ? ' checked="checked"' : '';
		return $result;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Echoes a nonce hidden input field (stub).
	 *
	 * @param string $action Nonce action.
	 * @param string $name   Nonce field name.
	 * @param bool   $referer Whether to include the referrer field.
	 * @param bool   $display Whether to echo (ignored in stub).
	 * @return string
	 */
	function wp_nonce_field( string $action = '', string $name = '_wpnonce', bool $referer = true, bool $display = true ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
		$field = '<input type="hidden" name="' . $name . '" value="stub-nonce" />';
		if ( $display ) {
			echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Stub; safe constant HTML.
		}
		return $field;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	/**
	 * No-op stub for WordPress add_submenu_page().
	 *
	 * @param string   $parent_slug Parent menu slug.
	 * @param string   $page_title  Page title.
	 * @param string   $menu_title  Menu title.
	 * @param string   $capability  Capability required.
	 * @param string   $menu_slug   Menu slug.
	 * @param callable $callback    Render callback.
	 * @param int      $position    Menu position.
	 * @return string|false
	 */
	function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, ?callable $callback = null, int $position = 0 ): string|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
		return '';
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Returns null (no post exists in unit context).
	 *
	 * @param int|null $post      Post ID.
	 * @param string   $output    Output format.
	 * @param string   $filter    Filter type.
	 * @return null
	 */
	function get_post( int|null $post = null, string $output = 'OBJECT', string $filter = 'raw' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
		return null;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * Returns null (no user exists in unit context).
	 *
	 * @param int $user_id User ID.
	 * @return null
	 */
	function get_userdata( int $user_id ): ?object { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
		return null;
	}
}

// ---------------------------------------------------------------------------
// Handler stubs: wp_safe_redirect, check_admin_referer, wp_die.
// These are needed by admin-post handler unit tests (e.g. SqueezeScreenPurgeTest).
// wp_safe_redirect throws RedirectException carrying the location so the
// handler's subsequent `exit` is never reached, letting the test assert the URL.
// ---------------------------------------------------------------------------

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test-only helper; name uses plugin prefix to avoid collisions.
if ( ! class_exists( 'AssetDripsTestRedirectException' ) ) {
	/**
	 * Thrown by the wp_safe_redirect stub so handler tests can catch redirects.
	 */
	class AssetDripsTestRedirectException extends \RuntimeException {
		/** @var string */
		public readonly string $location;

		/**
		 * @param string $location Redirect URL.
		 */
		public function __construct( string $location ) {
			$this->location = $location;
			parent::__construct( 'Redirect: ' . $location );
		}
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/**
	 * Throws AssetDripsTestRedirectException so handler tests can catch the redirect.
	 *
	 * @param string $location Redirect URL.
	 * @param int    $status   HTTP status code (ignored in stub).
	 * @param string $x_redirect_by X-Redirect-By header value (ignored in stub).
	 * @return never
	 */
	function wp_safe_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; params intentionally unused.
		throw new \AssetDripsTestRedirectException( $location );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	/**
	 * No-op stub — always passes the nonce check in unit tests.
	 *
	 * @param mixed  $action    Nonce action.
	 * @param string $query_arg Nonce query arg name.
	 * @return int
	 */
	function check_admin_referer( $action = -1, string $query_arg = '_wpnonce' ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
		return 1;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Throws a RuntimeException so wp_die() calls are testable.
	 *
	 * @param string $message Message.
	 * @param string $title   Title (ignored in stub).
	 * @param mixed  $args    Additional args (ignored).
	 * @return never
	 */
	function wp_die( string $message = '', string $title = '', $args = array() ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
		throw new \RuntimeException( 'wp_die: ' . $message );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * No-op stub for WordPress add_filter().
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority (ignored in stub).
	 * @param int      $accepted_args Number of accepted args (ignored in stub).
	 * @return void
	 */
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; params intentionally unused.
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * No-op stub for WordPress remove_filter().
	 *
	 * @param string   $hook_name Hook name.
	 * @param callable $callback  Callback to remove.
	 * @param int      $priority  Priority (ignored in stub).
	 * @return bool
	 */
	function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; params intentionally unused.
		return true;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Returns the text unchanged (translation + escaping stub for unit tests).
	 *
	 * @param string $text   Text to translate and escape.
	 * @param string $domain Text domain (ignored in stub).
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; $domain not needed.
		return $text;
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Returns the HTML-attribute-escaped text (translation + escaping stub for unit tests).
	 *
	 * @param string $text   Text to translate and escape.
	 * @param string $domain Text domain (ignored in stub).
	 * @return string
	 */
	function esc_attr__( string $text, string $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; $domain not needed.
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	/**
	 * Returns null by default (no admin screen loaded in unit tests).
	 *
	 * NextGenColumn::print_styles() guards on this; returning null causes it to
	 * skip the style block, which is the correct behaviour in unit test context.
	 *
	 * @return object|null
	 */
	function get_current_screen(): ?object {
		return null;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Returns a configurable boolean from the in-memory stub registry.
	 *
	 * Default: false (no event scheduled).
	 * Seed via: $GLOBALS['_assetdrips_next_scheduled_stub'] = true;
	 *
	 * @param string            $hook Hook name.
	 * @param array<int, mixed> $args Cron args (ignored in stub).
	 * @return bool|int
	 */
	function wp_next_scheduled( string $hook, array $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub; params intentionally unused.
		return $GLOBALS['_assetdrips_next_scheduled_stub'] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Records a scheduled event into the in-memory stub registry.
	 *
	 * Inspect via: $GLOBALS['_assetdrips_scheduled_events'].
	 *
	 * @param int               $timestamp Unix timestamp.
	 * @param string            $hook      Hook name.
	 * @param array<int, mixed> $args      Cron args.
	 * @return bool
	 */
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		if ( ! isset( $GLOBALS['_assetdrips_scheduled_events'] ) ) {
			$GLOBALS['_assetdrips_scheduled_events'] = array();
		}
		$GLOBALS['_assetdrips_scheduled_events'][] = array(
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
		);
		return true;
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// WP_Error class stub — required by CapabilityProbeTest and CapabilityProbe (is_wp_error check).
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Stubbing the WordPress core class so the probe under test can call is_wp_error() without a live WP load.
if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub for unit tests.
	 */
	class WP_Error { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace
		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct(
			public readonly string $code = '',
			public readonly string $message = ''
		) {}

		/**
		 * Return the primary error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Returns true when the value is a WP_Error instance.
	 *
	 * @param mixed $thing Value to check.
	 * @return bool
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

// WEEK_IN_SECONDS constant (604800) — used by CapabilityProbe transient TTL.
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Stubbing the WordPress core constant.
	define( 'WEEK_IN_SECONDS', 604800 );
}

// ARRAY_A constant — used by wpdb::get_row() / get_results() to request associative arrays.
if ( ! defined( 'ARRAY_A' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Stubbing the WordPress core constant.
	define( 'ARRAY_A', 'ARRAY_A' );
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.WP.GlobalVariablesOverride.Prohibited -- $GLOBALS['wpdb'] stub required by any unit test that instantiates MediaIndex and calls a method invoking Schema::media_table(); overriding the global is intentional in the unit harness (no real WordPress is loaded).
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class() {
		/**
		 * Table prefix read by Schema::media_table() via $wpdb->prefix.
		 *
		 * @var string
		 */
		public string $prefix = 'wp_';

		/**
		 * Stub for wpdb::prepare() — returns the SQL string as-is (no binding in unit tests).
		 *
		 * @param string $sql  SQL template.
		 * @param mixed  ...$args Bound values.
		 * @return string
		 */
		public function prepare( string $sql, mixed ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
			return $sql;
		}

		/**
		 * Stub for wpdb::query() — no-op; returns 1 to indicate success.
		 *
		 * @param string $sql SQL to execute.
		 * @return int|false
		 */
		public function query( string $sql ): int|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return 1;
		}

		/**
		 * Stub for wpdb::get_var() — returns null (no DB in unit tests).
		 *
		 * @param string $sql Prepared SQL.
		 * @return mixed
		 */
		public function get_var( string $sql ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return null;
		}

		/**
		 * Stub for wpdb::get_row() — returns next_get_row if set, otherwise null.
		 *
		 * Seed via: $GLOBALS['wpdb']->next_get_row = ['has_webp' => 1, ...];
		 *
		 * @param string $sql    Prepared SQL.
		 * @param string $output Output format constant (e.g. ARRAY_A).
		 * @return mixed
		 */
		public mixed $next_get_row  = null;

		/**
		 * Queue of values to return from successive get_row() calls.
		 *
		 * When non-empty, each get_row() call pops and returns the front entry.
		 * When empty, falls back to the consume-once $next_get_row pattern.
		 * Allows tests that trigger multiple get_row() calls (e.g. get_flags() +
		 * get() in render_column) to seed both return values.
		 *
		 * @var array<int, mixed>
		 */
		public array $get_row_queue = array();

		public function get_row( string $sql, string $output = '' ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
			// Queue takes priority when populated (allows multiple get_row() calls per test).
			if ( ! empty( $this->get_row_queue ) ) {
				return array_shift( $this->get_row_queue );
			}
			$row                = $this->next_get_row;
			$this->next_get_row = null; // consume once.
			return $row;
		}

		/**
		 * Stub for wpdb::get_col() — returns an empty array (no DB in unit tests).
		 *
		 * @param string $sql Prepared SQL.
		 * @return array<int, mixed>
		 */
		public function get_col( string $sql ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
			return array();
		}

		/**
		 * Stub for wpdb::get_results() — returns an empty array (no DB in unit tests).
		 *
		 * Used by OptimizationIndex::get_biggest_unoptimized() and any other method
		 * that fetches a multi-row result set. Return empty array = zero rows.
		 *
		 * @param string $sql    Prepared SQL.
		 * @param string $output Output format constant (e.g. ARRAY_A).
		 * @return array<int, mixed>
		 */
		public function get_results( string $sql, string $output = '' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
			return array();
		}
	};
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.WP.GlobalVariablesOverride.Prohibited
