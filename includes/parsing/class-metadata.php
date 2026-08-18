<?php
/**
 * HTML metadata extraction shared by all providers.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Parsing;

use DOMDocument;
use DOMXPath;

/**
 * Pulls meta tags, JSON-LD blocks, and the document title out of raw HTML.
 *
 * Pure functions over strings — no network, no WordPress state — so the
 * whole class is unit-testable with fixture files.
 */
class Metadata {

	/**
	 * Parse an HTML document into a DOMDocument, tolerating real-world tag soup.
	 *
	 * @param string $html Raw HTML.
	 * @return DOMDocument|null Null when the input is empty or unparseable.
	 */
	public static function dom( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return null;
		}

		$dom            = new DOMDocument();
		$previous_state = libxml_use_internal_errors( true );
		// Prefix with an encoding hint so multibyte content (emoji in tweet
		// text) survives loadHTML on the default ISO-8859-1 assumption.
		$loaded = $dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_state );

		return $loaded ? $dom : null;
	}

	/**
	 * Collect `<meta>` values keyed by property/name/itemprop.
	 *
	 * @param string $html Raw HTML.
	 * @return array<string,string> First value wins per key.
	 */
	public static function meta_tags( $html ) {
		$dom = self::dom( $html );
		if ( ! $dom ) {
			return array();
		}

		$xpath = new DOMXPath( $dom );
		$meta  = array();

		foreach ( $xpath->query( '//meta[(@name or @property or @itemprop) and @content]' ) as $tag ) {
			$key = $tag->getAttribute( 'property' );
			if ( '' === $key ) {
				$key = $tag->getAttribute( 'name' );
			}
			if ( '' === $key ) {
				$key = $tag->getAttribute( 'itemprop' );
			}
			if ( '' === $key || strlen( $key ) > 200 || isset( $meta[ $key ] ) ) {
				continue;
			}
			$meta[ $key ] = $tag->getAttribute( 'content' );
		}

		return $meta;
	}

	/**
	 * Decode every JSON-LD script block in the document.
	 *
	 * @param string $html Raw HTML.
	 * @return array<int,array> Decoded objects; @graph containers are flattened.
	 */
	public static function jsonld_blocks( $html ) {
		$dom = self::dom( $html );
		if ( ! $dom ) {
			return array();
		}

		$xpath  = new DOMXPath( $dom );
		$blocks = array();

		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $script ) {
			$decoded = json_decode( $script->textContent, true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode API.
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
				foreach ( $decoded['@graph'] as $node ) {
					if ( is_array( $node ) ) {
						$blocks[] = $node;
					}
				}
				continue;
			}
			// A block may itself be a list of nodes.
			if ( array_is_list( $decoded ) ) {
				foreach ( $decoded as $node ) {
					if ( is_array( $node ) ) {
						$blocks[] = $node;
					}
				}
				continue;
			}
			$blocks[] = $decoded;
		}//end foreach

		return $blocks;
	}

	/**
	 * Document `<title>` text.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function title( $html ) {
		$dom = self::dom( $html );
		if ( ! $dom ) {
			return '';
		}

		$nodes = $dom->getElementsByTagName( 'title' );
		return $nodes->length ? trim( $nodes->item( 0 )->textContent ) : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode API.
	}

	/**
	 * Find the first JSON-LD author that is a Person (never an Organization).
	 *
	 * @param array<int,array> $blocks Output of jsonld_blocks().
	 * @return array{name:string,url:string,image:string}|null
	 */
	public static function jsonld_person_author( $blocks ) {
		foreach ( $blocks as $block ) {
			$author = $block['author'] ?? null;
			if ( ! $author ) {
				continue;
			}
			// Author may be a single object or a list.
			$candidates = ( isset( $author['@type'] ) || isset( $author['name'] ) ) ? array( $author ) : (array) $author;
			foreach ( $candidates as $candidate ) {
				if ( ! is_array( $candidate ) ) {
					continue;
				}
				$type = $candidate['@type'] ?? '';
				if ( 'Person' !== $type ) {
					continue;
				}
				$image = $candidate['image'] ?? '';
				if ( is_array( $image ) ) {
					$image = $image['url'] ?? ( $image['contentUrl'] ?? '' );
				}
				return array(
					'name'  => (string) ( $candidate['name'] ?? '' ),
					'url'   => (string) ( $candidate['url'] ?? ( $candidate['@id'] ?? '' ) ),
					'image' => (string) $image,
				);
			}
		}//end foreach
		return null;
	}
}
