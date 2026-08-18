<?php
/**
 * Batch preview orchestration.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Import;

use CourtneyRDev\SocialWebmentionImporter\Http\Safe_Fetcher;
use CourtneyRDev\SocialWebmentionImporter\Plugin;
use CourtneyRDev\SocialWebmentionImporter\Provider\Provider_Registry;
use CourtneyRDev\SocialWebmentionImporter\Verification\Target_Verifier;

/**
 * Turns a pasted URL list into editable preview records.
 *
 * Each URL is processed independently: a fetch or parse failure produces an
 * editable error row, never a batch failure. Fetches are lightly throttled
 * between rows to stay polite to providers.
 */
class Preview_Service {

	/**
	 * Provider registry.
	 *
	 * @var Provider_Registry
	 */
	protected $registry;

	/**
	 * Fetcher callable, Safe_Fetcher::get by default.
	 *
	 * @var callable
	 */
	protected $fetcher;

	/**
	 * Constructor with injectable collaborators for testing.
	 *
	 * @param Provider_Registry|null $registry Provider registry.
	 * @param callable|null          $fetcher  Fetcher with Safe_Fetcher::get()'s signature.
	 */
	public function __construct( ?Provider_Registry $registry = null, ?callable $fetcher = null ) {
		$this->registry = $registry ?? new Provider_Registry();
		$this->fetcher  = $fetcher ?? array( Safe_Fetcher::class, 'get' );
	}

	/**
	 * Split a textarea of URLs into a clean, capped list.
	 *
	 * Blank lines and surrounding whitespace are tolerated; order and
	 * duplicates within the paste are preserved so the reviewer sees a row
	 * per pasted line (in-batch duplicates get flagged during preview).
	 *
	 * @param string $raw Textarea content.
	 * @return array{urls:string[],truncated:int} URLs and how many lines were dropped over the cap.
	 */
	public static function parse_url_list( $raw ) {
		$lines = preg_split( '/\R+/', (string) $raw );
		$urls  = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$urls[] = $line;
			}
		}

		$limit     = Plugin::batch_limit();
		$truncated = max( 0, count( $urls ) - $limit );

		return array(
			'urls'      => array_slice( $urls, 0, $limit ),
			'truncated' => $truncated,
		);
	}

	/**
	 * Preview one URL against a target post.
	 *
	 * @param string $url     Pasted URL.
	 * @param int    $post_id Target post ID.
	 * @return Preview_Record
	 */
	public function preview_url( $url, $post_id ) {
		$record             = new Preview_Record();
		$record->source_url = trim( $url );
		$record->post_id    = (int) $post_id;

		$valid = Safe_Fetcher::validate_url( $record->source_url );
		if ( is_wp_error( $valid ) ) {
			$record->error = $valid->get_error_message();
			return $record;
		}

		$provider         = $this->registry->for_url( $record->source_url );
		$record->provider = $provider->slug();

		$normalized            = $provider->normalize( $record->source_url );
		$record->canonical_url = $normalized['canonical'];
		$record->remote_id     = $normalized['remote_id'];
		$record->author_handle = $normalized['handle'];
		if ( $record->author_handle ) {
			$record->extraction['author_handle'] = 'path-handle';
		}

		$provider->extract( $record, $this->fetcher );

		// Reviewer-confirmed identities outrank every parser source.
		Identity_Store::apply( $record );

		$this->verify( $record );
		$this->flag_duplicates( $record );

		return $record;
	}

	/**
	 * Preview a whole batch.
	 *
	 * @param string[] $urls    URLs (already capped).
	 * @param int      $post_id Target post ID.
	 * @return Preview_Record[]
	 */
	public function preview_batch( $urls, $post_id ) {
		$records   = array();
		$seen_keys = array();

		foreach ( $urls as $index => $url ) {
			if ( $index > 0 ) {
				// Politeness delay between remote fetches within a batch.
				usleep( 250000 );
			}

			$record = $this->preview_url( $url, $post_id );

			// Flag duplicates *within* the pasted batch too.
			$key = Duplicate_Detector::normalized_key( $record );
			if ( isset( $seen_keys[ $key ] ) && '' === $record->error ) {
				$record->duplicate_status = 'duplicate';
				$record->warnings[]       = __( 'Duplicate of another row in this batch.', 'social-webmention-importer' );
			}
			$seen_keys[ $key ] = true;

			$records[] = $record;
		}

		return $records;
	}

	/**
	 * Run source-contains-target verification and propose the import mode.
	 *
	 * @param Preview_Record $record Record with raw_body when a fetch succeeded.
	 */
	protected function verify( Preview_Record $record ) {
		$target = get_permalink( $record->post_id );
		if ( ! $target ) {
			$record->error = __( 'Target post does not exist.', 'social-webmention-importer' );
			return;
		}

		/**
		 * Filters the URL the source must contain to verify.
		 *
		 * Lets a staging site verify sources that link to the production
		 * permalink instead of the staging domain.
		 *
		 * @param string         $target Target permalink.
		 * @param Preview_Record $record The record being verified.
		 */
		$target = apply_filters( 'swi_verification_target_url', $target, $record );

		if ( '' === $record->raw_body ) {
			$record->verification = 'unknown';
			$record->mode         = Plugin::MODE_SOCIAL_LINKBACK;
			$record->warnings[]   = __( 'The source page could not be read, so the target link could not be verified. Import is available as a curated social response.', 'social-webmention-importer' );
			return;
		}

		if ( Target_Verifier::body_contains_target( $record->raw_body, $target ) ) {
			$record->verification = 'verified';
			$record->mode         = Plugin::MODE_WEBMENTION;
		} elseif ( $this->short_links_reach_target( $record->raw_body, $target ) ) {
			// Providers rewrite outbound links (lnkd.in, t.co), so the
			// literal target may only exist behind the shortener.
			$record->verification = 'verified';
			$record->mode         = Plugin::MODE_WEBMENTION;
			$record->warnings[]   = __( 'The link to this post is wrapped in the network’s URL shortener; it was expanded and verified.', 'social-webmention-importer' );
		} else {
			$record->verification = 'unverified';
			$record->mode         = Plugin::MODE_SOCIAL_LINKBACK;
			$record->warnings[]   = __( 'The source does not link to this post, so it cannot be stored as a Webmention. Import it as a curated social response instead.', 'social-webmention-importer' );
		}

		// The raw body has served verification; drop it (never persisted).
		$record->raw_body = '';
	}

	/**
	 * Whether any provider short link in the body resolves to the target.
	 *
	 * Fetches at most five unique short links through the same constrained
	 * fetcher used for sources.
	 *
	 * @param string $body   Fetched source document.
	 * @param string $target Target permalink.
	 * @return bool
	 */
	protected function short_links_reach_target( $body, $target ) {
		foreach ( Target_Verifier::find_short_links( $body ) as $short_url ) {
			$response = call_user_func( $this->fetcher, $short_url );
			if ( Target_Verifier::short_link_resolves_to_target( $response, $target ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mark create/update/skip status against existing comments.
	 *
	 * @param Preview_Record $record Record.
	 */
	protected function flag_duplicates( Preview_Record $record ) {
		if ( '' !== $record->error ) {
			return;
		}

		$existing = Duplicate_Detector::find_existing( $record );
		if ( $existing ) {
			$record->existing_comment_id = $existing;
			$record->duplicate_status    = 'update';
			$record->warnings[]          = sprintf(
				/* translators: %d: comment ID. */
				__( 'A response for this source already exists (comment %d); importing will update it.', 'social-webmention-importer' ),
				$existing
			);
		}
	}
}
