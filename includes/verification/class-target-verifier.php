<?php
/**
 * Source-to-target verification per the Webmention specification.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Verification;

/**
 * Checks that a fetched source document actually contains the target URL.
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
				return true;
			}

			$offset = $pos + 1;
		}

		return false;
	}
}
