<?php
/**
 * X / Twitter provider adapter.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Record;
use CourtneyRDev\SocialWebmentionImporter\Parsing\Author_Resolver;
use CourtneyRDev\SocialWebmentionImporter\Parsing\Avatar_Resolver;
use CourtneyRDev\SocialWebmentionImporter\Parsing\Content_Resolver;
use CourtneyRDev\SocialWebmentionImporter\Parsing\Metadata;

/**
 * Handles `x.com` and legacy `twitter.com` status URLs.
 *
 * Extraction order (descending reliability):
 *  1. Public oEmbed (`publish.twitter.com/oembed`) — author name, profile
 *     URL, full tweet HTML. Credential-free; documented public endpoint.
 *  2. Server-rendered OG metadata (served to a WordPress-style UA) —
 *     name/handle from og:title, text from og:description, avatar from
 *     og:image only when the profile-image path rule proves it.
 *  3. Path-derived handle as a *candidate* only.
 * The identity store and manual review sit above all of these in the
 * confidence ranking enforced by Preview_Record::offer().
 */
class X_Provider implements Provider {

	/**
	 * Hosts recognized as X.
	 *
	 * @var string[]
	 */
	const HOSTS = array( 'x.com', 'www.x.com', 'twitter.com', 'www.twitter.com', 'mobile.twitter.com' );

	/**
	 * Provider slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'x';
	}

	/**
	 * Whether this adapter recognizes the URL.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public function handles( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return in_array( $host, self::HOSTS, true );
	}

	/**
	 * Normalize x.com/twitter.com status URLs to one canonical identity.
	 *
	 * `https://twitter.com/{handle}/status/{id}` and the x.com equivalent
	 * normalize to `https://x.com/{handle}/status/{id}` with remote_id {id},
	 * so the two domains deduplicate to the same record.
	 *
	 * @param string $url Absolute URL.
	 * @return array{canonical:string,remote_id:string,handle:string}
	 */
	public function normalize( $url ) {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$parts = array_values( array_filter( explode( '/', $path ) ) );

		$handle    = '';
		$remote_id = '';

		// /{handle}/status/{id} or /i/web/status/{id}.
		if ( count( $parts ) >= 3 && 'status' === $parts[ count( $parts ) - 2 ] && ctype_digit( $parts[ count( $parts ) - 1 ] ) ) {
			$remote_id = $parts[ count( $parts ) - 1 ];
			if ( 'i' !== $parts[0] && 'web' !== $parts[0] ) {
				$handle = $parts[0];
			}
		}

		$canonical = $remote_id
			? 'https://x.com/' . ( $handle ? rawurlencode( $handle ) : 'i/web' ) . '/status/' . $remote_id
			: 'https://x.com' . $path;

		return array(
			'canonical' => $canonical,
			'remote_id' => $remote_id,
			'handle'    => $handle,
		);
	}

	/**
	 * Fill the record from oEmbed, then server-rendered OG metadata.
	 *
	 * @param Preview_Record $record  Pre-normalized record.
	 * @param callable       $fetcher Fetcher with Safe_Fetcher::get()'s signature.
	 */
	public function extract( Preview_Record $record, callable $fetcher ) {
		if ( $record->author_handle ) {
			$record->offer( 'author_url', 'https://x.com/' . rawurlencode( $record->author_handle ), 'path-handle' );
		}

		$this->extract_from_oembed( $record, $fetcher );
		$this->extract_from_og( $record, $fetcher );

		if ( '' === $record->author_name ) {
			$record->warnings[] = __( 'X did not expose the author’s name; enter it manually.', 'social-webmention-importer' );
		}
		if ( '' === $record->avatar ) {
			$record->warnings[] = __( 'No author avatar could be proven from public metadata; pick one manually if wanted.', 'social-webmention-importer' );
		}
	}

	/**
	 * Primary path: the public oEmbed endpoint.
	 *
	 * @param Preview_Record $record  Preview record.
	 * @param callable       $fetcher Fetcher.
	 */
	protected function extract_from_oembed( Preview_Record $record, callable $fetcher ) {
		$endpoint = add_query_arg(
			array(
				'url'         => rawurlencode( $record->canonical_url ),
				'omit_script' => 'true',
				'dnt'         => 'true',
			),
			'https://publish.twitter.com/oembed'
		);

		$response = $fetcher( $endpoint );
		if ( is_wp_error( $response ) ) {
			$record->warnings[] = __( 'X oEmbed lookup failed; fields fall back to page metadata.', 'social-webmention-importer' );
			return;
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			return;
		}

		$name = Author_Resolver::refuse_network_name( $data['author_name'] ?? '' );
		$record->offer( 'author_name', $name, 'oembed' );
		$record->offer( 'author_url', esc_url_raw( (string) ( $data['author_url'] ?? '' ) ), 'oembed' );

		if ( ! empty( $data['author_url'] ) ) {
			$handle_path = trim( (string) wp_parse_url( $data['author_url'], PHP_URL_PATH ), '/' );
			// Offer the handle outright when the URL had none, or
			// re-offer it at oEmbed confidence when it corroborates the
			// one already there — a bare path-derived handle never
			// outranks the identity store's own lookup gate
			// (Preview_Service::preview_url()) until something
			// independent confirms it actually belongs to this author.
			if ( preg_match( '/^[A-Za-z0-9_]{1,15}$/', $handle_path )
				&& ( '' === $record->author_handle || 0 === strcasecmp( $handle_path, $record->author_handle ) )
			) {
				$record->offer( 'author_handle', $handle_path, 'oembed' );
			}
		}

		$text = Content_Resolver::from_x_oembed_html( (string) ( $data['html'] ?? '' ) );
		$record->offer( 'content', Content_Resolver::tidy_whitespace( $text ), 'oembed' );

		// oEmbed HTML ends with a human-readable date link ("August 18, 2026").
		if ( preg_match( '/>([A-Z][a-z]+ \d{1,2}, \d{4})<\/a><\/blockquote>/', (string) ( $data['html'] ?? '' ), $m ) ) {
			$timestamp = strtotime( $m[1] . ' 00:00:00 UTC' );
			if ( $timestamp ) {
				$record->offer( 'published_gmt', gmdate( 'Y-m-d H:i:s', $timestamp ), 'oembed' );
				$record->warnings[] = __( 'X only exposes the tweet date, not the time; adjust if it matters.', 'social-webmention-importer' );
			}
		}
	}

	/**
	 * Secondary path: server-rendered OG metadata.
	 *
	 * @param Preview_Record $record  Preview record.
	 * @param callable       $fetcher Fetcher.
	 */
	protected function extract_from_og( Preview_Record $record, callable $fetcher ) {
		$response = $fetcher( $record->canonical_url );
		if ( is_wp_error( $response ) ) {
			$record->warnings[] = __( 'Could not fetch the X page itself; verification and fallback metadata are unavailable.', 'social-webmention-importer' );
			return;
		}

		$meta  = Metadata::meta_tags( $response['body'] );
		$title = $meta['og:title'] ?? Metadata::title( $response['body'] );

		$parsed = Author_Resolver::from_x_title( $title );
		$record->offer( 'author_name', $parsed['name'], 'title-pattern' );
		if ( $parsed['handle'] ) {
			$record->offer( 'author_handle', $parsed['handle'], 'title-pattern' );
			$record->offer( 'author_url', 'https://x.com/' . rawurlencode( $parsed['handle'] ), 'title-pattern' );
		}

		$description = (string) ( $meta['og:description'] ?? '' );
		if ( '' !== $description && ! Content_Resolver::looks_truncated( $description ) ) {
			$record->offer( 'content', Content_Resolver::tidy_whitespace( $description ), 'meta-author' );
		}

		$avatar = Avatar_Resolver::from_x_og_image( (string) ( $meta['og:image'] ?? '' ) );
		$record->offer( 'avatar', $avatar, 'meta-author' );

		// Stash the raw body for the target verifier so it need not re-fetch.
		// Excluded from persistence by Preview_Record::to_array().
		$record->raw_body = $response['body'];
	}
}
