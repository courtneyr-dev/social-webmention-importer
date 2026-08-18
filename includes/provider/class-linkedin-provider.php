<?php
/**
 * LinkedIn provider adapter.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Provider;

use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Record;
use CourtneyRDev\SocialWebmentionImporter\Parsing\Author_Resolver;
use CourtneyRDev\SocialWebmentionImporter\Parsing\Avatar_Resolver;
use CourtneyRDev\SocialWebmentionImporter\Parsing\Content_Resolver;
use CourtneyRDev\SocialWebmentionImporter\Parsing\Metadata;

/**
 * Handles public LinkedIn post and activity URLs.
 *
 * LinkedIn commonly answers unauthenticated requests with HTTP 999 or an
 * authwall redirect. Those cases degrade to a manual-review record with the
 * URL and any path-derived hints — never a hard batch failure, and never a
 * prompt to add credentials (which the plugin refuses to support).
 *
 * Extraction order: JSON-LD Person author → explicit author metadata →
 * OG/title patterns → identity store → manual input. Company branding and
 * the OG share card are never proposed as the author's avatar.
 */
class LinkedIn_Provider implements Provider {

	/**
	 * Provider slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'linkedin';
	}

	/**
	 * Whether this adapter recognizes the URL.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public function handles( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 'linkedin.com' === $host || str_ends_with( $host, '.linkedin.com' );
	}

	/**
	 * Normalize LinkedIn post/activity URLs.
	 *
	 * Recognized shapes:
	 *  - /posts/{slug-or-handle}_{…}-activity-{id}-{suffix}
	 *  - /feed/update/urn:li:activity:{id}
	 *  - /feed/update/urn:li:ugcPost:{id}
	 *
	 * @param string $url Absolute URL.
	 * @return array{canonical:string,remote_id:string,handle:string}
	 */
	public function normalize( $url ) {
		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$remote_id = '';
		$handle    = '';

		if ( preg_match( '#/feed/update/urn:li:(?:activity|ugcPost|share):(\d+)#', rawurldecode( $path ), $m ) ) {
			$remote_id = $m[1];
		} elseif ( preg_match( '#/posts/(?<slug>[^/]+?)-activity-(?<id>\d+)-#', $path, $m ) ) {
			$remote_id = $m['id'];
			// The slug's leading segment is usually the member's public
			// handle: "jane-doe-123abc_topic-words".
			$slug_head = explode( '_', $m['slug'] )[0];
			if ( preg_match( '/^[a-z0-9][a-z0-9-]{2,}$/i', $slug_head ) ) {
				$handle = $slug_head;
			}
		}

		$canonical = $remote_id
			? 'https://www.linkedin.com/feed/update/urn:li:activity:' . $remote_id
			: 'https://www.linkedin.com' . $path;

		// A comment permalink identifies a different remote item than the
		// post itself: keep the comment ID in both the canonical URL and
		// the remote ID so replies dedupe independently of their post.
		$comment_urn = self::comment_urn_from_url( $url );
		if ( $comment_urn ) {
			if ( '' === $remote_id ) {
				$remote_id = $comment_urn['activity'];
				$canonical = 'https://www.linkedin.com/feed/update/urn:li:activity:' . $comment_urn['activity'];
			}
			$remote_id .= '.' . $comment_urn['comment'];
			$canonical .= '?commentUrn=' . rawurlencode( sprintf( 'urn:li:comment:(activity:%s,%s)', $comment_urn['activity'], $comment_urn['comment'] ) );
		}

		return array(
			'canonical' => $canonical,
			'remote_id' => $remote_id,
			'handle'    => $handle,
		);
	}

	/**
	 * Parse a comment URN out of a LinkedIn URL's query string.
	 *
	 * Comment permalinks look like
	 * `…/feed/update/urn:li:activity:{post}/?commentUrn=urn:li:comment:(activity:{post},{comment})`.
	 *
	 * @param string $url Absolute URL.
	 * @return array{activity:string,comment:string}|null Null when the URL has no comment URN.
	 */
	public static function comment_urn_from_url( $url ) {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $query ) {
			return null;
		}

		if ( preg_match( '/urn:li:comment:\(activity:(?<activity>\d+),(?<comment>\d+)\)/', rawurldecode( $query ), $m ) ) {
			return array(
				'activity' => $m['activity'],
				'comment'  => $m['comment'],
			);
		}

		return null;
	}

	/**
	 * Fill the record from JSON-LD, metadata, and title patterns.
	 *
	 * @param Preview_Record $record  Pre-normalized record.
	 * @param callable       $fetcher Fetcher with Safe_Fetcher::get()'s signature.
	 */
	public function extract( Preview_Record $record, callable $fetcher ) {
		if ( $record->author_handle ) {
			$record->offer( 'author_url', 'https://www.linkedin.com/in/' . rawurlencode( $record->author_handle ) . '/', 'path-handle' );
		}

		// Fetch the URL as pasted: the /posts/ page is public more often
		// than the /feed/update/ canonical, which redirects to the authwall.
		$response = $fetcher( $record->source_url );

		if ( is_wp_error( $response ) ) {
			$record->warnings[] = __( 'LinkedIn refused the public fetch (login wall). Fill the fields manually from the post.', 'social-webmention-importer' );
			return;
		}

		$body = $response['body'];

		if ( false !== stripos( $body, 'authwall' ) || ( false !== stripos( $body, 'signup-modal' ) && '' === Metadata::title( $body ) ) ) {
			$record->warnings[] = __( 'LinkedIn served a login-gated page; extracted fields may be incomplete.', 'social-webmention-importer' );
		}

		// A comment permalink must never inherit the POST author's identity:
		// the JSON-LD author/articleBody describe the post, not the reply.
		// LinkedIn's public data lists the thread's comments without IDs, so
		// the exact comment cannot be auto-matched — surface the public
		// commenter names and leave the fields to the reviewer.
		if ( self::comment_urn_from_url( $record->source_url ) ) {
			$this->describe_comment_thread( $record, $body );
			$record->raw_body = $body;
			return;
		}

		// 1. JSON-LD Person author.
		$person = Metadata::jsonld_person_author( Metadata::jsonld_blocks( $body ) );
		if ( $person ) {
			$record->offer( 'author_name', Author_Resolver::refuse_network_name( $person['name'] ), 'jsonld' );
			$record->offer( 'author_url', esc_url_raw( $person['url'] ), 'jsonld' );
			$record->offer( 'avatar', Avatar_Resolver::from_jsonld_person( $person['image'] ), 'jsonld' );
		}

		// 2./3. Explicit metadata and OG/title patterns.
		$meta  = Metadata::meta_tags( $body );
		$title = $meta['og:title'] ?? Metadata::title( $body );

		$record->offer( 'author_name', Author_Resolver::from_linkedin_title( $title ), 'title-pattern' );

		$description = (string) ( $meta['og:description'] ?? '' );
		if ( '' !== $description ) {
			if ( Content_Resolver::looks_truncated( $description ) ) {
				$record->warnings[] = __( 'LinkedIn truncated the post text; paste the full text manually.', 'social-webmention-importer' );
			}
			$record->offer( 'content', Content_Resolver::tidy_whitespace( $description ), 'meta-author' );
		}

		// JSON-LD articleBody beats the truncated og:description, and
		// datePublished beats no date at all.
		foreach ( Metadata::jsonld_blocks( $body ) as $block ) {
			if ( ! empty( $block['articleBody'] ) && is_string( $block['articleBody'] ) ) {
				$record->offer( 'content', Content_Resolver::tidy_whitespace( $block['articleBody'] ), 'jsonld' );
			}
			if ( ! empty( $block['datePublished'] ) && is_string( $block['datePublished'] ) ) {
				$timestamp = strtotime( $block['datePublished'] );
				if ( $timestamp ) {
					$record->offer( 'published_gmt', gmdate( 'Y-m-d H:i:s', $timestamp ), 'jsonld' );
				}
			}
		}

		// og:image is the share card / branding: deliberately not offered
		// (Avatar_Resolver::from_linkedin_og_image() documents the rule).

		$record->raw_body = $body;

		if ( '' === $record->author_name ) {
			$record->warnings[] = __( 'LinkedIn did not expose the author’s name; enter it manually.', 'social-webmention-importer' );
		}

		$comments = self::public_comments( $body );
		if ( $comments ) {
			$record->warnings[] = sprintf(
				/* translators: %d: number of public comments. */
				__( 'This post also lists %d public comments. Paste a specific comment’s permalink (⋯ → Copy link to comment) to import a reply.', 'social-webmention-importer' ),
				count( $comments )
			);
		}
	}

	/**
	 * Public comments (author name, text, date) from the page's JSON-LD.
	 *
	 * @param string $body Fetched page HTML.
	 * @return array<int,array{name:string,text:string,published:string}>
	 */
	public static function public_comments( $body ) {
		$comments = array();

		foreach ( Metadata::jsonld_blocks( $body ) as $block ) {
			foreach ( (array) ( $block['comment'] ?? array() ) as $comment ) {
				if ( ! is_array( $comment ) || 'Comment' !== ( $comment['@type'] ?? '' ) ) {
					continue;
				}
				$comments[] = array(
					'name'      => (string) ( $comment['author']['name'] ?? '' ),
					'text'      => (string) ( $comment['text'] ?? '' ),
					'published' => (string) ( $comment['datePublished'] ?? '' ),
				);
			}
		}

		return $comments;
	}

	/**
	 * Fill a comment-permalink record with reviewer guidance.
	 *
	 * @param Preview_Record $record Preview record.
	 * @param string         $body   Fetched page HTML.
	 */
	protected function describe_comment_thread( Preview_Record $record, $body ) {
		$record->warnings[] = __( 'This is a comment permalink. LinkedIn’s public data doesn’t identify which comment it points to, so fill the author and text manually from the post page.', 'social-webmention-importer' );

		$comments = self::public_comments( $body );
		if ( $comments ) {
			$names              = array_slice( array_filter( wp_list_pluck( $comments, 'name' ) ), 0, 8 );
			$record->warnings[] = sprintf(
				/* translators: %s: comma-separated commenter names. */
				__( 'Public commenters on this post: %s.', 'social-webmention-importer' ),
				implode( ', ', $names )
			);
		}

		if ( '' === $record->author_name ) {
			$record->warnings[] = __( 'LinkedIn did not expose the author’s name; enter it manually.', 'social-webmention-importer' );
		}
	}
}
