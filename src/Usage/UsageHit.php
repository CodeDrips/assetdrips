<?php
/**
 * A single piece of evidence that an attachment is in use.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable evidence record produced by a scanner.
 *
 * One hit is enough to tier an attachment as USED. The fields are kept rich on
 * purpose: the review screen and CLI show operators *why* something is used, and
 * trust is the product. A hit therefore records the scanner, a human-readable
 * locator, how the match was made, and the exact token that matched.
 */
final class UsageHit {

	/**
	 * Match was made on the attachment ID (e.g. _thumbnail_id, block "id").
	 */
	public const MATCH_ID = 'id';

	/**
	 * Match was made on a URL or uploads-relative reference.
	 */
	public const MATCH_URL = 'url';

	/**
	 * Match was made on an absolute filesystem path reference.
	 */
	public const MATCH_PATH = 'path';

	/**
	 * Attachment post ID this hit refers to.
	 *
	 * @var int
	 */
	private int $attachment_id;

	/**
	 * Scanner source identifier, e.g. "content", "postmeta".
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Human-readable locator for where the reference was found.
	 *
	 * @var string
	 */
	private string $context;

	/**
	 * How the match was made: one of the MATCH_* constants.
	 *
	 * @var string
	 */
	private string $match_type;

	/**
	 * The exact value that matched (the evidence string).
	 *
	 * @var string
	 */
	private string $evidence;

	/**
	 * Construct an evidence record.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $source        Scanner source identifier.
	 * @param string $context       Human-readable locator.
	 * @param string $match_type    One of the MATCH_* constants.
	 * @param string $evidence      The exact value that matched.
	 */
	public function __construct( int $attachment_id, string $source, string $context, string $match_type, string $evidence ) {
		$this->attachment_id = $attachment_id;
		$this->source        = $source;
		$this->context       = $context;
		$this->match_type    = $match_type;
		$this->evidence      = $evidence;
	}

	/**
	 * Attachment post ID this hit refers to.
	 *
	 * @return int
	 */
	public function attachment_id(): int {
		return $this->attachment_id;
	}

	/**
	 * Scanner source identifier.
	 *
	 * @return string
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Human-readable locator for the reference.
	 *
	 * @return string
	 */
	public function context(): string {
		return $this->context;
	}

	/**
	 * How the match was made.
	 *
	 * @return string
	 */
	public function match_type(): string {
		return $this->match_type;
	}

	/**
	 * The exact value that matched.
	 *
	 * @return string
	 */
	public function evidence(): string {
		return $this->evidence;
	}

	/**
	 * Plain-array representation for JSON evidence storage.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'attachment_id' => $this->attachment_id,
			'source'        => $this->source,
			'context'       => $this->context,
			'match_type'    => $this->match_type,
			'evidence'      => $this->evidence,
		);
	}
}
