<?php
/**
 * Author-name normalization and network-name refusal rules.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Parsing;

/**
 * Cleans candidate author names and refuses network-level attributions.
 *
 * The one non-negotiable rule from the product brief: a missing person's
 * name is left blank for the reviewer, never replaced with "X", "Twitter",
 * or "LinkedIn".
 */
class Author_Resolver {

	/**
	 * Strings that are network attribution, not a person.
	 *
	 * Compared case-insensitively after trimming.
	 *
	 * @var string[]
	 */
	const NETWORK_NAMES = array(
		'x',
		'twitter',
		'x (formerly twitter)',
		'linkedin',
		'x corp',
		'twitter, inc.',
	);

	/**
	 * Reject values that name the network instead of a person.
	 *
	 * @param string $name Candidate display name.
	 * @return string The name, or '' when it is a network attribution.
	 */
	public static function refuse_network_name( $name ) {
		$normalized = strtolower( trim( (string) $name ) );
		if ( '' === $normalized || in_array( $normalized, self::NETWORK_NAMES, true ) ) {
			return '';
		}
		return trim( (string) $name );
	}

	/**
	 * Extract a display name from an X page title / og:title.
	 *
	 * Handles the two live patterns:
	 *   "Name (@handle) on X"
	 *   "Name on X: \"tweet text\" / X"
	 *
	 * @param string $title Title or og:title text.
	 * @return array{name:string,handle:string} Empty strings when no match.
	 */
	public static function from_x_title( $title ) {
		$title = trim( (string) $title );

		if ( preg_match( '/^(?<name>.+?)\s+\(@(?<handle>[A-Za-z0-9_]{1,15})\)\s+on\s+(?:X|Twitter)\b/u', $title, $m ) ) {
			return array(
				'name'   => self::refuse_network_name( $m['name'] ),
				'handle' => $m['handle'],
			);
		}

		if ( preg_match( '/^(?<name>.+?)\s+on\s+(?:X|Twitter):\s/u', $title, $m ) ) {
			return array(
				'name'   => self::refuse_network_name( $m['name'] ),
				'handle' => '',
			);
		}

		return array(
			'name'   => '',
			'handle' => '',
		);
	}

	/**
	 * Extract a display name from a LinkedIn page title / og:title.
	 *
	 * Handles "Name on LinkedIn: post text…" and "Name posted on LinkedIn".
	 *
	 * @param string $title Title or og:title text.
	 * @return string Display name or ''.
	 */
	public static function from_linkedin_title( $title ) {
		$title = trim( (string) $title );

		if ( preg_match( '/^(?<name>.+?)\s+on\s+LinkedIn(?::|$)/u', $title, $m ) ) {
			return self::refuse_network_name( $m['name'] );
		}

		if ( preg_match( '/^(?<name>.+?)\s+posted\s+on\s+LinkedIn/u', $title, $m ) ) {
			return self::refuse_network_name( $m['name'] );
		}

		return '';
	}
}
