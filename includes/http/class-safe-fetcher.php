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
 * link-local / reserved / carrier-grade-NAT hosts, short timeout, small
 * redirect budget, and a 1 MB response cap.
 *
 * `validate_url()` normalizes the host the way core's own
 * `wp_http_validate_url()` does (trims a trailing dot, so
 * `169.254.169.254.` is still recognized as the IP literal it is) and, for
 * a name rather than an IP literal, resolves it via `gethostbyname()`
 * before checking the resolved address against the blocked ranges; a name
 * that fails to resolve is refused rather than treated as safe. The same
 * check runs again on every redirect hop via `reject_unsafe_redirect()`,
 * hooked to `requests-requests.before_redirect` (see `get()`), since
 * `pre_http_request` never fires for a redirect Requests follows
 * internally.
 *
 * Known residual gaps, both out of scope for v1: the resolved address is
 * not re-checked at the moment the socket actually connects, so a
 * DNS-rebinding attacker who repoints a name between this check and the
 * connect is not caught; and `gethostbyname()` only resolves IPv4 (A)
 * records, so a name whose AAAA record points at a private/loopback IPv6
 * address while its A record is public would pass this check even on a
 * host that prefers IPv6 connections.
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
		// literals against the full private+reserved set ourselves.
		// Normalize the host the way core's own wp_http_validate_url()
		// does — trimming a trailing dot — before deciding whether it is
		// an IP literal already or a name to resolve: `169.254.169.254.`
		// is a fully-qualified form of that same address, but fails
		// filter_var()'s literal match while the dot is still there,
		// which would otherwise send it down the hostname-resolution path
		// and past this check entirely (gethostbyname() cannot resolve a
		// malformed name like that, and an unresolvable name used to fall
		// through as "not an IP literal, so not blocked").
		$host = rtrim( trim( (string) $parsed['host'], '[]' ), '.' );

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ip = $host;
		} else {
			$ip = gethostbyname( $host );
			if ( $ip === $host ) {
				// gethostbyname() could not resolve it. Refuse rather than
				// treat an unresolvable name as automatically safe.
				return new WP_Error( 'swi_unsafe_url', __( 'The URL points at a host this site refuses to fetch.', 'social-webmention-importer' ) );
			}
		}

		if ( self::is_blocked_ip_literal( $ip ) ) {
			return new WP_Error( 'swi_unsafe_url', __( 'The URL points at a host this site refuses to fetch.', 'social-webmention-importer' ) );
		}

		return true;
	}

	/**
	 * Whether a host is an IP literal inside a private, loopback,
	 * link-local, or otherwise reserved range.
	 *
	 * @param string $host Host portion of a URL (IPv6 may be bracketed, and
	 *                     IPv4 may carry a trailing dot from a
	 *                     fully-qualified name).
	 * @return bool True when the host is a blocked IP literal.
	 */
	public static function is_blocked_ip_literal( $host ) {
		$ip = rtrim( trim( (string) $host, '[]' ), '.' );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
			// Hostname, not an IP literal.
		}

		// NO_PRIV_RANGE covers 10/8, 172.16/12, 192.168/16, fc00::/7 …;
		// NO_RES_RANGE covers 0/8, 127/8, 169.254/16, 240/4, ::1, fe80::/10 …;
		// GLOBAL_RANGE additionally excludes 100.64.0.0/10, the
		// carrier-grade-NAT range RFC 6598 reserves and that neither of
		// the other two flags covers.
		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE
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
		add_action( 'requests-requests.before_redirect', array( $guard, 'reject_unsafe_redirect' ), 10, 1 );

		// A `finally` block, not a plain call-then-remove: some other
		// callback hooked to the request (e.g. `http_api_debug`) throwing
		// would otherwise leave the guard registered for every later,
		// unrelated fetch in the same request lifecycle.
		try {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => min( 15, max( 3, (int) $args['timeout'] ) ),
					'redirection'         => 3,
					'limit_response_size' => self::MAX_BYTES,
					'user-agent'          => $args['user_agent'],
				)
			);
		} finally {
			remove_action( 'requests-requests.before_redirect', array( $guard, 'reject_unsafe_redirect' ), 10 );
		}

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
	 * `get()` call (only the redirect target is needed, so `add_action()`
	 * requests just that one argument of the four the hook provides).
	 * Throwing here is caught by WP_Http::request(), which wraps whatever
	 * Requests exception it catches the same way regardless of type: the
	 * caller gets a WP_Error, but under core's own `http_request_failed`
	 * code, not one of this class's `swi_*` codes.
	 *
	 * @param string $location Redirect target URL.
	 * @throws \WpOrg\Requests\Exception When the redirect target is blocked.
	 */
	public function reject_unsafe_redirect( $location ): void {
		if ( is_wp_error( $this->validate_url( (string) $location ) ) ) {
			throw new \WpOrg\Requests\Exception( esc_html__( 'Redirect target blocked.', 'social-webmention-importer' ), 'swi.redirect_blocked' );
		}
	}
}
