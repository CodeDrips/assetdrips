<?php
/**
 * Match keys for a single attachment.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Inventory;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable set of references that should mark one attachment as used.
 *
 * The whole point of this object is variant-awareness: a reference to ANY
 * generated size, the original pre-scaled file, or a backup of an edited image
 * must mark the parent attachment used. Missing a variant is the classic
 * false-positive that breaks a live site, so all forms are gathered up front.
 *
 * Three token families, because references in the wild take different shapes:
 *  - urls()           absolute and root-relative URLs (post content, CSS url()).
 *  - paths()          absolute filesystem paths (file-based references).
 *  - relative_paths() uploads-relative paths, e.g. "2023/05/photo.jpg"
 *                     (matches _wp_attached_file-style stored values and is the
 *                     most portable substring across domain/scheme changes).
 */
final class MatchKeys {

	/**
	 * Attachment post ID.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Absolute and root-relative URL tokens for every variant.
	 *
	 * @var string[]
	 */
	private array $urls;

	/**
	 * Absolute filesystem path tokens for every variant.
	 *
	 * @var string[]
	 */
	private array $paths;

	/**
	 * Uploads-relative path tokens for every variant.
	 *
	 * @var string[]
	 */
	private array $relative_paths;

	/**
	 * Construct an immutable, deduplicated set of match keys.
	 *
	 * @param int      $id             Attachment post ID.
	 * @param string[] $urls           URL tokens (absolute + root-relative).
	 * @param string[] $paths          Absolute filesystem path tokens.
	 * @param string[] $relative_paths Uploads-relative path tokens.
	 */
	public function __construct( int $id, array $urls, array $paths, array $relative_paths ) {
		$this->id             = $id;
		$this->urls           = array_values( array_unique( $urls ) );
		$this->paths          = array_values( array_unique( $paths ) );
		$this->relative_paths = array_values( array_unique( $relative_paths ) );
	}

	/**
	 * Attachment post ID.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * URL tokens (absolute + root-relative) for every variant.
	 *
	 * @return string[]
	 */
	public function urls(): array {
		return $this->urls;
	}

	/**
	 * Absolute filesystem path tokens for every variant.
	 *
	 * @return string[]
	 */
	public function paths(): array {
		return $this->paths;
	}

	/**
	 * Uploads-relative path tokens for every variant.
	 *
	 * @return string[]
	 */
	public function relative_paths(): array {
		return $this->relative_paths;
	}

	/**
	 * True when there are no URL or path tokens. Such an attachment can only be
	 * matched by ID (e.g. via _thumbnail_id), never by reference string.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->urls && array() === $this->paths;
	}

	/**
	 * Plain-array representation for storage or debugging.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'             => $this->id,
			'urls'           => $this->urls,
			'paths'          => $this->paths,
			'relative_paths' => $this->relative_paths,
		);
	}
}
