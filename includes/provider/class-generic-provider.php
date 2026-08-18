<?php
/**
 * Fallback provider for any other public URL.
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
 * Best-effort extraction from JSON-LD, explicit author metadata, and OG tags
 * for URLs no dedicated adapter claims.
 *
 * Kept deliberately conservative: it proposes nothing it cannot attribute to
 * a person, and never proposes og:image as an avatar.
 */
class Generic_Provider implements Provider {

	/**
	 * Provider slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'generic';
	}

	/**
	 * The generic adapter accepts any URL; the registry consults it last.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public function handles( $url ) {
		return (bool) wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Generic URLs pass through unchanged with no remote ID or handle.
	 *
	 * @param string $url Absolute URL.
	 * @return array{canonical:string,remote_id:string,handle:string}
	 */
	public function normalize( $url ) {
		return array(
			'canonical' => $url,
			'remote_id' => '',
			'handle'    => '',
		);
	}

	/**
	 * Fill the record from JSON-LD, author metadata, and OG tags.
	 *
	 * @param Preview_Record $record  Pre-normalized record.
	 * @param callable       $fetcher Fetcher with Safe_Fetcher::get()'s signature.
	 */
	public function extract( Preview_Record $record, callable $fetcher ) {
		$response = $fetcher( $record->source_url );
		if ( is_wp_error( $response ) ) {
			$record->warnings[] = $response->get_error_message();
			return;
		}

		$body = $response['body'];

		$person = Metadata::jsonld_person_author( Metadata::jsonld_blocks( $body ) );
		if ( $person ) {
			$record->offer( 'author_name', Author_Resolver::refuse_network_name( $person['name'] ), 'jsonld' );
			$record->offer( 'author_url', esc_url_raw( $person['url'] ), 'jsonld' );
			$record->offer( 'avatar', Avatar_Resolver::from_jsonld_person( $person['image'] ), 'jsonld' );
		}

		$meta = Metadata::meta_tags( $body );

		$record->offer( 'author_name', Author_Resolver::refuse_network_name( $meta['author'] ?? '' ), 'meta-author' );

		$description = (string) ( $meta['og:description'] ?? ( $meta['description'] ?? '' ) );
		$record->offer( 'content', Content_Resolver::tidy_whitespace( $description ), 'meta-author' );

		$published = (string) ( $meta['article:published_time'] ?? '' );
		if ( $published && strtotime( $published ) ) {
			$record->offer( 'published_gmt', gmdate( 'Y-m-d H:i:s', strtotime( $published ) ), 'meta-author' );
		}

		$record->raw_body = $body;
	}
}
