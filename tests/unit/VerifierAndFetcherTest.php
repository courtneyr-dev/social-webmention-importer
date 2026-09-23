<?php
/**
 * Target verification and fetch-policy (SSRF) rules.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Tests\Unit;

use CourtneyRDev\SocialWebmentionImporter\Http\Safe_Fetcher;
use CourtneyRDev\SocialWebmentionImporter\Import\Identity_Store;
use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Service;
use CourtneyRDev\SocialWebmentionImporter\Tests\Fixtures;
use CourtneyRDev\SocialWebmentionImporter\Verification\Target_Verifier;
use WP_UnitTestCase;

/**
 * @covers \CourtneyRDev\SocialWebmentionImporter\Verification\Target_Verifier
 * @covers \CourtneyRDev\SocialWebmentionImporter\Http\Safe_Fetcher::validate_url
 * @covers \CourtneyRDev\SocialWebmentionImporter\Import\Preview_Service
 */
class VerifierAndFetcherTest extends WP_UnitTestCase {

	public function test_exact_target_link_verifies() {
		$body = Fixtures::get( 'x-status-og.html' );

		$this->assertTrue( Target_Verifier::body_contains_target( $body, 'https://example-blog.test/2026/08/17/sample-article/' ) );
		// Scheme/www variants of the target still verify.
		$this->assertTrue( Target_Verifier::body_contains_target( $body, 'http://www.example-blog.test/2026/08/17/sample-article/' ) );
		// Fragments on the target are ignored.
		$this->assertTrue( Target_Verifier::body_contains_target( $body, 'https://example-blog.test/2026/08/17/sample-article/#comments' ) );
	}

	public function test_misleading_partial_domain_matches_are_rejected() {
		$body = '<a href="https://not-example-blog.test/2026/08/17/sample-article/">lookalike</a>';
		$this->assertFalse( Target_Verifier::body_contains_target( $body, 'https://example-blog.test/2026/08/17/sample-article/' ) );

		$subdomain_attack = '<a href="https://evil.example-blog.test.attacker.example/2026/08/17/sample-article/">nope</a>';
		$this->assertFalse( Target_Verifier::body_contains_target( $subdomain_attack, 'https://example-blog.test/2026/08/17/sample-article/' ) );
	}

	public function test_link_to_a_longer_path_does_not_verify_a_shorter_target() {
		$body = '<a href="https://example-blog.test/post-two/">reply</a>';
		$this->assertFalse( Target_Verifier::body_contains_target( $body, 'https://example-blog.test/post/' ) );
	}

	public function test_exact_path_still_verifies_alongside_a_longer_lookalike() {
		$body = '<p>See <a href="https://example-blog.test/post-two/">post two</a> and '
			. '<a href="https://example-blog.test/post/">the original</a>.</p>';
		$this->assertTrue( Target_Verifier::body_contains_target( $body, 'https://example-blog.test/post/' ) );
	}

	public function test_missing_link_does_not_verify() {
		$this->assertFalse( Target_Verifier::body_contains_target( Fixtures::get( 'x-status-media-og.html' ), 'https://example-blog.test/2026/08/17/sample-article/' ) );
	}

	/**
	 * @dataProvider unsafe_url_provider
	 */
	public function test_unsafe_urls_are_rejected( $url ) {
		$this->assertWPError( Safe_Fetcher::validate_url( $url ) );
	}

	public function unsafe_url_provider() {
		return array(
			'empty'                   => array( '' ),
			'malformed'               => array( 'http:///nohost' ),
			'ftp scheme'              => array( 'ftp://example.org/file' ),
			'javascript scheme'       => array( 'javascript:alert(1)' ),
			'file scheme'             => array( 'file:///etc/passwd' ),
			'credentials'             => array( 'https://user:pass@example.org/' ),
			'loopback ip'             => array( 'http://127.0.0.1/admin' ),
			'localhost'               => array( 'http://localhost/admin' ),
			'private 10.x'            => array( 'http://10.0.0.5/internal' ),
			'private 192.168'         => array( 'http://192.168.1.1/router' ),
			'link-local'              => array( 'http://169.254.169.254/latest/meta-data/' ),
			// A trailing dot makes this a fully-qualified form of the same
			// address; filter_var() alone doesn't see it as an IP literal,
			// so it must still be caught after host normalization.
			'trailing-dot link-local' => array( 'http://169.254.169.254./' ),
			'carrier-grade NAT'       => array( 'http://100.64.0.1/' ),
			// Not an IP literal and does not resolve: refused, not allowed
			// through as "not a literal, so not blocked".
			'unresolvable hostname'   => array( 'http://this-name-does-not-resolve.invalid/' ),
		);
	}

	public function test_public_https_url_is_accepted() {
		$this->assertTrue( Safe_Fetcher::validate_url( 'https://x.com/alexdoe/status/123' ) );
	}

	/**
	 * @dataProvider blocked_redirect_target_provider
	 */
	public function test_redirect_guard_rejects_a_blocked_hop( $location ) {
		$fetcher = new Safe_Fetcher();

		$this->expectException( \WpOrg\Requests\Exception::class );
		$this->expectExceptionMessage( 'Redirect target blocked' );

		// Simulates the `requests-requests.before_redirect` call Requests
		// makes for a real redirect hop, without any network I/O.
		$fetcher->reject_unsafe_redirect( $location );
	}

	public function blocked_redirect_target_provider() {
		return array(
			'link-local'              => array( 'http://169.254.169.254/' ),
			'trailing-dot link-local' => array( 'http://169.254.169.254./' ),
		);
	}

	public function test_redirect_guard_allows_a_safe_hop() {
		$fetcher = new Safe_Fetcher();

		// A public IP literal, not a hostname, so this never depends on
		// real DNS resolution.
		$fetcher->reject_unsafe_redirect( 'https://93.184.216.34/redirected' );
		$this->addToAssertionCount( 1 );
	}

	public function test_get_registers_the_redirect_guard_only_while_a_fetch_is_in_flight() {
		$observed_during_fetch = null;

		$probe = function () use ( &$observed_during_fetch ) {
			$observed_during_fetch = has_action( 'requests-requests.before_redirect' );
			return new \WP_Error( 'test_short_circuit', 'stop before any real HTTP happens' );
		};
		add_filter( 'pre_http_request', $probe, 5, 3 );

		// A public IP literal so this never depends on real DNS/network.
		Safe_Fetcher::get( 'https://93.184.216.34/whatever' );

		remove_filter( 'pre_http_request', $probe, 5 );

		$this->assertTrue( $observed_during_fetch, 'The redirect guard must be registered while get() has a fetch in flight.' );
		$this->assertFalse( has_action( 'requests-requests.before_redirect' ), 'The redirect guard must be removed once get() returns.' );
	}

	public function test_redirect_guard_fires_through_the_real_requests_hooks_bridge() {
		$fetcher  = new Safe_Fetcher();
		$callback = array( $fetcher, 'reject_unsafe_redirect' );

		// Registered exactly like get() does, then dispatched the way
		// WP_HTTP_Requests_Hooks actually fires it for a real redirect hop
		// (never by calling reject_unsafe_redirect() directly) — so a
		// hook-name mismatch between get()'s add_action() and what the
		// Requests library/WordPress bridge actually dispatches would fail
		// this test even though it leaves the direct-call tests green.
		add_action( 'requests-requests.before_redirect', $callback, 10, 1 );

		$hooks = new \WP_HTTP_Requests_Hooks( 'https://93.184.216.34/', array() );

		try {
			$this->expectException( \WpOrg\Requests\Exception::class );
			$hooks->dispatch( 'requests.before_redirect', array( 'http://169.254.169.254/', array(), null, array() ) );
		} finally {
			remove_action( 'requests-requests.before_redirect', $callback, 10 );
		}
	}

	public function test_identity_store_is_not_applied_off_an_unverified_path_handle() {
		Identity_Store::save(
			'x',
			array(
				'handle' => 'alexdoe',
				'name'   => 'Someone Else Entirely',
				'url'    => 'https://x.com/someone-else',
				'avatar' => 'https://example.org/someone-else.jpg',
			)
		);

		// Both fetches fail (nothing is served), so extraction never
		// confirms the handle beyond the path-derived guess.
		$service = new Preview_Service( null, Fixtures::fetcher( array() ) );
		$record  = $service->preview_url( 'https://x.com/alexdoe/status/1234567890123456789', 999999 );

		$this->assertSame( 'alexdoe', $record->author_handle );
		$this->assertSame( 'path-handle', $record->extraction['author_handle'] ?? 'none' );
		$this->assertSame( '', $record->author_name );
		$this->assertNotSame( 'Someone Else Entirely', $record->author_name );
	}

	public function test_identity_store_applies_when_x_oembed_alone_confirms_the_handle() {
		Identity_Store::save(
			'x',
			array(
				'handle' => 'alexdoe',
				'name'   => 'The Real Alex Doe',
				'url'    => 'https://x.com/alexdoe',
				'avatar' => 'https://example.org/real-alex.jpg',
			)
		);

		// oEmbed succeeds and its author_url corroborates the path-derived
		// handle; the OG page fetch fails, so oEmbed alone confirms it.
		$service = new Preview_Service(
			null,
			Fixtures::fetcher(
				array(
					'publish.twitter.com/oembed' => Fixtures::get( 'x-oembed.json' ),
				)
			)
		);
		$record  = $service->preview_url( 'https://x.com/alexdoe/status/1234567890123456789', 999999 );

		// identity-store outranks oembed, so a successful apply() leaves
		// this as the final method — proof the store really did apply,
		// not just that the name happens to match.
		$this->assertSame( 'identity-store', $record->extraction['author_handle'] ?? 'none' );
		$this->assertSame( 'The Real Alex Doe', $record->author_name );
	}

	public function test_identity_store_applies_when_linkedin_jsonld_confirms_the_handle() {
		Identity_Store::save(
			'linkedin',
			array(
				'handle' => 'jordansample',
				'name'   => 'The Real Jordan Sample',
				'url'    => 'https://www.linkedin.com/in/jordansample',
				'avatar' => 'https://example.org/real-jordan.jpg',
			)
		);

		$service = new Preview_Service(
			null,
			Fixtures::fetcher( array( 'linkedin.com/posts/' => Fixtures::get( 'linkedin-post.html' ) ) )
		);
		$record  = $service->preview_url(
			'https://www.linkedin.com/posts/jordansample_topic-activity-7000000000000000000-AbCd',
			999999
		);

		$this->assertSame( 'identity-store', $record->extraction['author_handle'] ?? 'none' );
		$this->assertSame( 'The Real Jordan Sample', $record->author_name );
	}

	public function test_identity_store_applies_when_generic_jsonld_confirms_the_author_url() {
		Identity_Store::save(
			'generic',
			array(
				'handle' => '',
				'name'   => 'The Real Riley',
				'url'    => 'https://riley.example/about',
				'avatar' => 'https://example.org/real-riley.jpg',
			)
		);

		// Generic sources never carry a handle at all, so the store must
		// be keyed — and gated — on author_url instead.
		$body = '<html><head><script type="application/ld+json">'
			. '{"@context":"https://schema.org","@type":"Article","author":'
			. '{"@type":"Person","name":"Riley Fixture","url":"https://riley.example/about"}}'
			. '</script></head><body>A generic reply.</body></html>';

		$service = new Preview_Service( null, Fixtures::fetcher( array( 'example.org/riley-reply' => $body ) ) );
		$record  = $service->preview_url( 'https://example.org/riley-reply', 999999 );

		$this->assertSame( '', $record->author_handle );
		$this->assertSame( 'identity-store', $record->extraction['author_url'] ?? 'none' );
		$this->assertSame( 'The Real Riley', $record->author_name );
	}

	public function test_url_list_parsing_tolerates_blank_lines_and_enforces_the_cap() {
		$raw = "  https://x.com/a/status/1  \n\n\nhttps://x.com/a/status/2\r\n   \r\n";

		$parsed = Preview_Service::parse_url_list( $raw );
		$this->assertSame( array( 'https://x.com/a/status/1', 'https://x.com/a/status/2' ), $parsed['urls'] );
		$this->assertSame( 0, $parsed['truncated'] );

		$many   = implode( "\n", array_map( fn( $i ) => "https://x.com/a/status/{$i}", range( 1, 30 ) ) );
		$parsed = Preview_Service::parse_url_list( $many );
		$this->assertCount( 25, $parsed['urls'] );
		$this->assertSame( 5, $parsed['truncated'] );
	}
}
