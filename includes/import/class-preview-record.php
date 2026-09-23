<?php
/**
 * Value object describing one previewed source URL.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One editable preview row.
 *
 * Providers fill what they can; the review screen lets the administrator
 * correct any field before import. `extraction` records where each parsed
 * field came from so refreshes never overwrite higher-confidence data.
 */
class Preview_Record {

	/**
	 * Extraction-confidence ranking, highest wins.
	 *
	 * Keys are extraction-method slugs stored in `_swi_extraction_method`
	 * and per-field in `extraction`.
	 *
	 * @var array<string,int>
	 */
	const CONFIDENCE = array(
		'manual'         => 100,
		'identity-store' => 90,
		'oembed'         => 80,
		'jsonld'         => 70,
		'mf2'            => 70,
		'meta-author'    => 50,
		'title-pattern'  => 40,
		'path-handle'    => 20,
		'none'           => 0,
	);

	/**
	 * Exact URL the reviewer pasted.
	 *
	 * @var string
	 */
	public $source_url = '';

	/**
	 * Canonical URL of the social response (may differ from source_url
	 * after normalization, e.g. twitter.com → x.com).
	 *
	 * @var string
	 */
	public $canonical_url = '';

	/**
	 * Provider slug: 'x', 'linkedin', or 'generic'.
	 *
	 * @var string
	 */
	public $provider = '';

	/**
	 * Stable remote status/activity ID when the URL carries one.
	 *
	 * @var string
	 */
	public $remote_id = '';

	/**
	 * Target post ID.
	 *
	 * @var int
	 */
	public $post_id = 0;

	/**
	 * Whether the fetched source contains the target URL ('verified',
	 * 'unverified', or 'unknown' when the fetch failed).
	 *
	 * @var string
	 */
	public $verification = 'unknown';

	/**
	 * Proposed import mode: Plugin::MODE_WEBMENTION or Plugin::MODE_SOCIAL_LINKBACK.
	 *
	 * @var string
	 */
	public $mode = 'social-linkback';

	/**
	 * Response type: 'comment', 'mention', 'repost', or 'like'.
	 *
	 * @var string
	 */
	public $response_type = 'comment';

	/**
	 * Author display name. Never a network name; empty means unresolved.
	 *
	 * @var string
	 */
	public $author_name = '';

	/**
	 * Author handle without the leading @ (candidate only until confirmed).
	 *
	 * @var string
	 */
	public $author_handle = '';

	/**
	 * Author public profile URL.
	 *
	 * @var string
	 */
	public $author_url = '';

	/**
	 * Avatar URL, or a numeric Media Library attachment ID as a string.
	 *
	 * @var string
	 */
	public $avatar = '';

	/**
	 * Response text (sanitized on import, plain-ish HTML here).
	 *
	 * @var string
	 */
	public $content = '';

	/**
	 * Publication datetime in GMT, `Y-m-d H:i:s`, empty when unknown.
	 *
	 * @var string
	 */
	public $published_gmt = '';

	/**
	 * Per-field extraction method, e.g. [ 'author_name' => 'oembed' ].
	 *
	 * @var array<string,string>
	 */
	public $extraction = array();

	/**
	 * Human-readable warnings for the review screen.
	 *
	 * @var string[]
	 */
	public $warnings = array();

	/**
	 * Row-level error code/message when the URL cannot be previewed at all.
	 *
	 * @var string
	 */
	public $error = '';

	/**
	 * Duplicate status: 'new', 'update' (existing comment will be updated),
	 * or 'duplicate' (identical, will be skipped).
	 *
	 * @var string
	 */
	public $duplicate_status = 'new';

	/**
	 * Existing comment ID when duplicate_status is 'update' or 'duplicate'.
	 *
	 * @var int
	 */
	public $existing_comment_id = 0;

	/**
	 * Overall extraction method (best field source) for `_swi_extraction_method`.
	 *
	 * @var string
	 */
	public $extraction_method = 'none';

	/**
	 * Raw fetched HTML, held in memory for the target verifier only.
	 *
	 * Never persisted: `to_array()` drops it, honoring the rule against
	 * storing full remote HTML after parsing.
	 *
	 * @var string
	 */
	public $raw_body = '';

	/**
	 * Set a field only when the new source outranks the recorded one.
	 *
	 * @param string $field  Property name.
	 * @param mixed  $value  Proposed value.
	 * @param string $method Extraction-method slug (a CONFIDENCE key).
	 * @return bool Whether the value was applied.
	 */
	public function offer( $field, $value, $method ) {
		if ( '' === $value || null === $value || ! property_exists( $this, $field ) ) {
			return false;
		}

		$current_method = $this->extraction[ $field ] ?? 'none';
		$current_rank   = self::CONFIDENCE[ $current_method ] ?? 0;
		$new_rank       = self::CONFIDENCE[ $method ] ?? 0;

		if ( '' !== (string) $this->{$field} && $new_rank <= $current_rank ) {
			return false;
		}

		$this->{$field}             = $value;
		$this->extraction[ $field ] = $method;

		$best_rank = self::CONFIDENCE[ $this->extraction_method ] ?? 0;
		if ( $new_rank > $best_rank ) {
			$this->extraction_method = $method;
		}

		return true;
	}

	/**
	 * Export for transient storage and form round-trips.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		$vars = get_object_vars( $this );
		unset( $vars['raw_body'] );
		return $vars;
	}

	/**
	 * Rebuild from `to_array()` output.
	 *
	 * @param array<string,mixed> $data Stored array.
	 * @return Preview_Record
	 */
	public static function from_array( $data ) {
		$record = new self();
		foreach ( (array) $data as $key => $value ) {
			if ( property_exists( $record, $key ) ) {
				$record->{$key} = $value;
			}
		}
		return $record;
	}
}
