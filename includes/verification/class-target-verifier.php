<?php
/**
 * Source-to-target verification per the Webmention specification.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks that a fetched source document actually contains the target URL,
 * directly or behind a provider link shortener.
 *
 * Matching mirrors the installed Webmention plugin's receiver: the target is
 * reduced to a scheme-less, www-less, fragment-less form and searched for in
 * the entity-decoded body. That intentionally matches `https://`, `http://`,
 * and `www.` variants — but a *partial* domain (e.g. `courtneyr.dev.evil.com`)
 * must not verify, so the character before the match is checked too.
 */
class Target_Verifier {

	/**
	 * Reduce a target URL to the comparable form.
	 *
	 * @param string $target Target URL.
	 * @return string
	 */
	public static function normalize_target( $target ) {
		$target = preg_replace( '/#.*/', '', (string) $target );
		$target = untrailingslashit( trim( $target ) );
		return str_replace(
			array( 'http://www.', 'http://', 'https://www.', 'https://' ),
			'',
			$target
		);
	}

	/**
	 * Whether the body contains the target URL.
	 *
	 * @param string $body   Fetched source document.
	 * @param string $target Target URL.
	 * @return bool
	 */
	public static function body_contains_target( $body, $target ) {
		$needle = self::normalize_target( $target );
		if ( '' === $needle || '' === (string) $body ) {
			return false;
		}

		$haystack = htmlspecialchars_decode( (string) $body );
		$offset   = 0;

		for ( ;; ) {
			$pos = strpos( $haystack, $needle, $offset );
			if ( false === $pos ) {
				break;
			}

			$before = $pos > 0 ? $haystack[ $pos - 1 ] : '';

			// Reject partial-domain matches: the character before the host
			// must not extend the hostname (a letter, digit, dot, or hyphen
			// would mean the match sits inside a longer domain such as
			// `not-courtneyr.dev` or a subdomain of an attacker's site).
			if ( '' === $before || ! preg_match( '/[A-Za-z0-9.-]/', $before ) ) {
				// Reject partial-path matches too: the character after the
				// match must end the path/URL rather than extend it, or a
				// link to `/post-two/` would wrongly verify `/post/`.
				$next = substr( $haystack, $pos + strlen( $needle ), 1 );
				if ( '' !== $next && ! in_array( $next, array( '/', '?', '#', '"', "'", ' ', '<', '&', "\n" ), true ) ) {
					$offset = $pos + 1;
					continue;
				}

				return true;
			}

			$offset = $pos + 1;
		}//end for

		return false;
	}

	/**
	 * Shortener hosts whose links providers substitute for the real URL.
	 *
	 * LinkedIn rewrites outbound links through lnkd.in (a 200 interstitial
	 * page containing the destination), X through t.co (a redirect).
	 *
	 * @var string[]
	 */
	const SHORTENER_HOSTS = array( 'lnkd.in', 't.co' );

	/**
	 * Extract provider short links from a source document.
	 *
	 * @param string $body  Fetched source document.
	 * @param int    $limit Maximum links to return. Default 5.
	 * @return string[] Unique short-link URLs, at most $limit.
	 */
	public static function find_short_links( $body, $limit = 5 ) {
		$hosts   = implode( '|', array_map( 'preg_quote', self::SHORTENER_HOSTS ) );
		$decoded = htmlspecialchars_decode( (string) $body );

		if ( ! preg_match_all( '#https://(?:' . $hosts . ')/[A-Za-z0-9_-]+#', $decoded, $matches ) ) {
			return array();
		}

		return array_slice( array_values( array_unique( $matches[0] ) ), 0, max( 0, (int) $limit ) );
	}

	/**
	 * Whether a fetched short link resolves to the target.
	 *
	 * Accepts either signal: the response's final URL is the target (t.co
	 * redirects), or the response body contains the target URL (lnkd.in
	 * interstitials embed the destination link).
	 *
	 * @param array|\WP_Error $response Safe_Fetcher::get() result for the short link.
	 * @param string          $target   Target URL.
	 * @return bool
	 */
	public static function short_link_resolves_to_target( $response, $target ) {
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return false;
		}

		$needle = self::normalize_target( $target );

		$final = self::normalize_target( (string) ( $response['final_url'] ?? '' ) );
		if ( '' !== $needle && $final === $needle ) {
			return true;
		}

		return self::body_contains_target( (string) ( $response['body'] ?? '' ), $target );
	}
}
