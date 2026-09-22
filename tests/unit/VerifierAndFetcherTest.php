<?php
/**
 * Target verification and fetch-policy (SSRF) rules.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Tests\Unit;

use CourtneyRDev\SocialWebmentionImporter\Http\Safe_Fetcher;
use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Service;
use CourtneyRDev\SocialWebmentionImporter\Tests\Fixtures;
use CourtneyRDev\SocialWebmentionImporter\Verification\Target_Verifier;
use WP_UnitTestCase;

/**
 * @covers \CourtneyRDev\SocialWebmentionImporter\Verification\Target_Verifier
 * @covers \CourtneyRDev\SocialWebmentionImporter\Http\Safe_Fetcher::validate_url
 * @covers \CourtneyRDev\SocialWebmentionImporter\Import\Preview_Service::parse_url_list
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
			'empty'             => array( '' ),
			'malformed'         => array( 'http:///nohost' ),
			'ftp scheme'        => array( 'ftp://example.org/file' ),
			'javascript scheme' => array( 'javascript:alert(1)' ),
			'file scheme'       => array( 'file:///etc/passwd' ),
			'credentials'       => array( 'https://user:pass@example.org/' ),
			'loopback ip'       => array( 'http://127.0.0.1/admin' ),
			'localhost'         => array( 'http://localhost/admin' ),
			'private 10.x'      => array( 'http://10.0.0.5/internal' ),
			'private 192.168'   => array( 'http://192.168.1.1/router' ),
			'link-local'        => array( 'http://169.254.169.254/latest/meta-data/' ),
		);
	}

	public function test_public_https_url_is_accepted() {
		$this->assertTrue( Safe_Fetcher::validate_url( 'https://x.com/alexdoe/status/123' ) );
	}

	public function test_redirect_guard_rejects_a_blocked_hop() {
		$fetcher = new Safe_Fetcher();

		$this->expectException( \WpOrg\Requests\Exception::class );
		$this->expectExceptionMessage( 'Redirect target blocked' );

		// Simulates the `requests-requests.before_redirect` call Requests
		// makes for a real redirect hop, without any network I/O.
		$fetcher->reject_unsafe_redirect( 'http://169.254.169.254/', array(), null, array() );
	}

	public function test_redirect_guard_allows_a_safe_hop() {
		$fetcher = new Safe_Fetcher();

		// No exception means the hop is allowed through.
		$fetcher->reject_unsafe_redirect( 'https://example.org/redirected', array(), null, array() );
		$this->addToAssertionCount( 1 );
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
