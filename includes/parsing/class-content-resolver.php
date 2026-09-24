<?php
/**
 * Response-text cleanup shared by providers.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Parsing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns provider markup and decorated page titles into clean response text.
 *
 * Preserves meaning (emoji, punctuation, links, paragraph breaks); strips
 * network boilerplate. Sanitization for storage happens later in the
 * importer — these helpers only *select and shape* the text.
 */
class Content_Resolver {

	/**
	 * X's link-shortener hosts resolved at import time by resolve_short_links().
	 *
	 * @var string[]
	 */
	const SHORTENER_HOSTS = array( 't.co', 'pic.twitter.com' );

	/**
	 * Convert an oEmbed blockquote (X's format) to text with preserved links
	 * and line breaks.
	 *
	 * The X oEmbed HTML is `<blockquote><p>text with <br> and <a>…</a></p>
	 * &mdash; Author (@handle) <a>date</a></blockquote>`; only the inner
	 * `<p>` is the tweet.
	 *
	 * @param string $oembed_html oEmbed `html` field.
	 * @return string Tweet text with `<br />` and `<a href>` preserved, '' on no match.
	 */
	public static function from_x_oembed_html( $oembed_html ) {
		if ( ! preg_match( '#<p[^>]*>(?<text>.+?)</p>#su', (string) $oembed_html, $m ) ) {
			return '';
		}

		$text = $m['text'];

		// Strip tracking query args X appends to its own profile links but
		// keep the links themselves.
		$text = str_replace( '?ref_src=twsrc%5Etfw', '', $text );

		return trim( $text );
	}

	/**
	 * Strip X's title decoration from text pulled out of a page title.
	 *
	 * `Name on X: "text" / X` → `text`.
	 *
	 * @param string $title Full page title.
	 * @return string Inner text or ''.
	 */
	public static function from_x_title( $title ) {
		if ( preg_match( '/on\s+(?:X|Twitter):\s+["\x{201C}](?<text>.+)["\x{201D}]\s*\/\s*(?:X|Twitter)\s*$/su', (string) $title, $m ) ) {
			return trim( $m['text'] );
		}
		return '';
	}

	/**
	 * Detect X/LinkedIn og:description truncation ("…advocated at sc").
	 *
	 * @param string $text Candidate text.
	 * @return bool True when the text appears cut off mid-word.
	 */
	public static function looks_truncated( $text ) {
		$text = rtrim( (string) $text );
		if ( '' === $text ) {
			return false;
		}
		if ( str_ends_with( $text, '…' ) || str_ends_with( $text, '...' ) ) {
			return true;
		}
		// Ends mid-word with no closing punctuation.
		return ( (bool) preg_match( '/\p{L}{2,}$/u', $text ) ) && ( ! preg_match( '/[.!?"\x{201D})\]]$/u', $text ) ) && ( mb_strlen( $text ) > 150 );
	}

	/**
	 * Normalize line endings and collapse 3+ blank lines to paragraph breaks.
	 *
	 * @param string $text Response text.
	 * @return string
	 */
	public static function tidy_whitespace( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Resolve X's link shorteners (`t.co`, `pic.twitter.com`) in oEmbed
	 * content to their final destination. The href becomes the real final
	 * URL (still fully working, query string and all); the visible text —
	 * oEmbed sets it to the shortened URL too — becomes just "host/path",
	 * scheme dropped, for a cleaner display. `rel="nofollow ugc"` is set
	 * on the rewritten anchor.
	 *
	 * Idempotent: only an anchor whose href host is a recognized shortener
	 * is touched, so running this again on content it already rewrote —
	 * the href is no longer a shortener host — or on content that never
	 * had a shortened link leaves it byte-for-byte alone, with no extra
	 * fetch. A link that fails to resolve (network error, blocked by the
	 * fetch policy, non-2xx response) is left completely untouched.
	 *
	 * @param string   $html    Content HTML with `<a href="…">` links.
	 * @param callable $resolve Callable(string $url): string|\WP_Error — the
	 *                          final URL, or a WP_Error on failure. Matches
	 *                          what a caller builds from
	 *                          Safe_Fetcher::get()'s `final_url` field.
	 * @return string
	 */
	public static function resolve_short_links( $html, callable $resolve ) {
		$html = (string) $html;

		if ( '' === trim( $html ) || false === stripos( $html, '<a ' ) ) {
			return $html;
		}

		$hosts = implode( '|', array_map( 'preg_quote', self::SHORTENER_HOSTS ) );

		return (string) preg_replace_callback(
			'#<a\s+[^>]*href=(["\'])(https?://(?:' . $hosts . ')(?:/[^"\']*)?)\1[^>]*>.*?</a>#si',
			function ( $matches ) use ( $resolve ) {
				$final = $resolve( $matches[2] );

				if ( is_wp_error( $final ) || ! is_string( $final ) || '' === $final ) {
					// Resolution failed; leave the original short link untouched.
					return $matches[0];
				}

				$display = self::host_and_path( $final );
				if ( '' === $display ) {
					return $matches[0];
				}

				return sprintf(
					'<a href="%s" rel="nofollow ugc">%s</a>',
					esc_url( $final ),
					esc_html( $display )
				);
			},
			$html
		);
	}

	/**
	 * "host/path" for a URL — no scheme, query, or fragment — for display text.
	 *
	 * @param string $url Absolute URL.
	 * @return string '' when the URL has no host.
	 */
	public static function host_and_path( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		return $parts['host'] . ( $parts['path'] ?? '' );
	}
}
