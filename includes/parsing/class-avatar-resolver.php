<?php
/**
 * Avatar acceptance rules.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Parsing;

/**
 * Decides whether an image URL may be proposed as a person's avatar.
 *
 * `og:image` usually represents post media, not the author, so a URL is
 * accepted only when a tested provider-specific rule proves it depicts
 * the author. Everything else falls back to manual selection.
 */
class Avatar_Resolver {

	/**
	 * Accept an X og:image only when it is a profile image.
	 *
	 * Text-only tweets use `https://pbs.twimg.com/profile_images/…` as the
	 * OG image; tweets with attached media use `…/media/…` instead. Only the
	 * former depicts the author.
	 *
	 * @param string $url og:image URL.
	 * @return string The URL when it is an author avatar, '' otherwise.
	 */
	public static function from_x_og_image( $url ) {
		$url  = trim( (string) $url );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( 'pbs.twimg.com' === $host && str_starts_with( $path, '/profile_images/' ) ) {
			return $url;
		}

		return '';
	}

	/**
	 * Accept a JSON-LD Person image (already asserted to depict the author).
	 *
	 * @param string $url Person image URL.
	 * @return string Valid URL or ''.
	 */
	public static function from_jsonld_person( $url ) {
		$url = trim( (string) $url );
		return wp_http_validate_url( $url ) ? $url : '';
	}

	/**
	 * LinkedIn og:image is the share card or company branding — never
	 * accepted as an avatar.
	 *
	 * @return string Always ''.
	 */
	public static function from_linkedin_og_image() {
		return '';
	}
}
