<?php
/**
 * Response-text cleanup shared by providers.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Parsing;

/**
 * Turns provider markup and decorated page titles into clean response text.
 *
 * Preserves meaning (emoji, punctuation, links, paragraph breaks); strips
 * network boilerplate. Sanitization for storage happens later in the
 * importer — these helpers only *select and shape* the text.
 */
class Content_Resolver {

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
}
