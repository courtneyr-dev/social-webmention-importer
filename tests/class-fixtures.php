<?php
/**
 * Fixture loader shared by the suites.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Tests;

/**
 * Reads sanitized fixture files and builds stub fetchers.
 */
class Fixtures {

	/**
	 * Read a fixture file.
	 *
	 * @param string $name File name inside tests/fixtures.
	 * @return string
	 */
	public static function get( $name ) {
		return (string) file_get_contents( __DIR__ . '/fixtures/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
	}

	/**
	 * Build a fetcher stub that serves canned bodies by URL substring.
	 *
	 * @param array<string,string|\WP_Error> $map Substring → body or error.
	 * @return callable
	 */
	public static function fetcher( $map ) {
		return function ( $url ) use ( $map ) {
			foreach ( $map as $needle => $body ) {
				if ( false !== strpos( $url, $needle ) ) {
					if ( is_wp_error( $body ) ) {
						return $body;
					}
					return array(
						'code'         => 200,
						'body'         => $body,
						'content_type' => 'text/html',
						'final_url'    => $url,
					);
				}
			}
			return new \WP_Error( 'swi_http_404', 'The source responded with HTTP 404.', array( 'status' => 404 ) );
		};
	}
}
