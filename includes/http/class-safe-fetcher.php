<?php
/**
 * Constrained HTTP client for fetching reviewer-supplied source URLs.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/**
 * Wraps `wp_safe_remote_get()` with the plugin's fetch policy.
 *
 * Policy: HTTP(S) only, no credentials in the URL, no loopback / private /
 * link-local hosts (core's `wp_http_validate_url()` enforces the IP checks and
 * re-validates every redirect hop inside `wp_safe_remote_get()`), short
 * timeout, small redirect budget, and a 1 MB response cap.
 */
class Safe_Fetcher {

	/**
	 * Response size cap in bytes.
	 *
	 * @var int
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Reject URLs the fetch policy does not allow.
	 *
	 * Runs before any network I/O so tests can exercise it without HTTP.
	 *
	 * @param string $url Candidate URL.
	 * @return true|WP_Error True when fetchable, WP_Error otherwise.
	 */
	public static function validate_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return new WP_Error( 'swi_empty_url', __( 'Empty URL.', 'social-webmention-importer' ) );
		}

		$url    = trim( $url );
		$parsed = wp_parse_url( $url );

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return new WP_Error( 'swi_malformed_url', __( 'The URL could not be parsed.', 'social-webmention-importer' ) );
		}

		if ( empty( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'swi_bad_scheme', __( 'Only http and https URLs can be fetched.', 'social-webmention-importer' ) );
		}

		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			return new WP_Error( 'swi_credentials_in_url', __( 'URLs containing credentials are rejected.', 'social-webmention-importer' ) );
		}

		// wp_http_validate_url() rejects loopback and private-range hosts
		// (127.0.0.0/8, 10/8, 172.16/12, 192.168/16) unless the site allows
		// them via `http_request_host_is_external`.
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'swi_unsafe_url', __( 'The URL points at a host this site refuses to fetch.', 'social-webmention-importer' ) );
		}

		// Core does NOT block link-local/reserved ranges — notably
		// 169.254.169.254, the cloud-metadata endpoint — so check IP
		// literals against the full private+reserved set ourselves. A
		// hostname (rather than an IP literal already) is resolved first,
		// so a name that merely points at a blocked range is caught too.
		$host = trim( (string) $parsed['host'], '[]' );
		$ip   = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		if ( self::is_blocked_ip_literal( $ip ) ) {
			return new WP_Error( 'swi_unsafe_url', __( 'The URL points at a host this site refuses to fetch.', 'social-webmention-importer' ) );
		}

		return true;
	}

	/**
	 * Whether a host is an IP literal inside a private, loopback,
	 * link-local, or otherwise reserved range.
	 *
	 * @param string $host Host portion of a URL (IPv6 may be bracketed).
	 * @return bool True when the host is a blocked IP literal.
	 */
	public static function is_blocked_ip_literal( $host ) {
		$ip = trim( (string) $host, '[]' );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
			// Hostname, not an IP literal.
		}

		// NO_PRIV_RANGE covers 10/8, 172.16/12, 192.168/16, fc00::/7 …;
		// NO_RES_RANGE covers 0/8, 127/8, 169.254/16, 240/4, ::1, fe80::/10 ….
		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Fetch a URL under the plugin's fetch policy.
	 *
	 * @param string $url  Absolute URL to fetch.
	 * @param array  $args {
	 *     Optional overrides.
	 *
	 *     @type string $user_agent User agent to send. Defaults to the
	 *                              WordPress UA, which providers like X use
	 *                              to decide whether to serve server-rendered
	 *                              metadata instead of a JavaScript shell.
	 *     @type int    $timeout    Seconds. Default 10.
	 * }
	 * @return array|WP_Error {
	 *     @type int    $code         HTTP status code.
	 *     @type string $body         Response body (capped at MAX_BYTES).
	 *     @type string $content_type Content-Type header value.
	 *     @type string $final_url    URL after redirects, when known.
	 * }
	 */
	public static function get( $url, $args = array() ) {
		$valid = self::validate_url( $url );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		global $wp_version;

		$defaults = array(
			'user_agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' ),
			'timeout'    => 10,
		);
		$args     = wp_parse_args( $args, $defaults );

		// Re-validate every hop: Requests follows redirects *inside* a
		// single Requests::request() call, so `pre_http_request` — a
		// WP_Http-level filter checked once before that call — never fires
		// again for an internal redirect and cannot block one.
		// `requests-requests.before_redirect` is the WordPress action
		// WP_HTTP_Requests_Hooks bridges from the Requests library's own
		// `requests.before_redirect` hook, dispatched once per hop (the
		// same mechanism core's own `reject_unsafe_urls` uses), so this is
		// where a redirect guard actually runs.
		$guard = new self();
		add_action( 'requests-requests.before_redirect', array( $guard, 'reject_unsafe_redirect' ), 10, 4 );

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => min( 15, max( 3, (int) $args['timeout'] ) ),
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => $args['user_agent'],
			)
		);

		remove_action( 'requests-requests.before_redirect', array( $guard, 'reject_unsafe_redirect' ), 10 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// LinkedIn answers unauthenticated automation with 999; treat all
		// non-2xx as a typed error the preview can explain per row.
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'swi_http_' . $code,
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The source responded with HTTP %d.', 'social-webmention-importer' ),
					$code
				),
				array( 'status' => $code )
			);
		}

		return array(
			'code'         => $code,
			'body'         => wp_remote_retrieve_body( $response ),
			'content_type' => wp_remote_retrieve_header( $response, 'content-type' ),
			'final_url'    => (string) ( $response['http_response'] ?? null ? $response['http_response']->get_response_object()->url : $url ),
		);
	}

	/**
	 * Reject a redirect target the fetch policy does not allow.
	 *
	 * Hooked to `requests-requests.before_redirect` for the lifetime of one
	 * `get()` call. Throwing here is caught by WP_Http::request() and
	 * surfaces to the caller as a WP_Error, matching every other rejection
	 * in this class.
	 *
	 * @param string $location Redirect target URL.
	 * @param array  $headers  Request headers for the next hop.
	 * @param mixed  $data     Request body for the next hop.
	 * @param array  $options  Requests options for the next hop.
	 * @throws \WpOrg\Requests\Exception When the redirect target is blocked.
	 */
	public function reject_unsafe_redirect( $location, $headers, $data, $options ): void {
		if ( is_wp_error( $this->validate_url( (string) $location ) ) ) {
			throw new \WpOrg\Requests\Exception( 'Redirect target blocked', 'swi.redirect_blocked' );
		}
	}
}
