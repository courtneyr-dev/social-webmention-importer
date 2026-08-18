<?php
/**
 * Provider detection, URL normalization, and duplicate keys.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Tests\Unit;

use CourtneyRDev\SocialWebmentionImporter\Import\Duplicate_Detector;
use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Record;
use CourtneyRDev\SocialWebmentionImporter\Provider\Generic_Provider;
use CourtneyRDev\SocialWebmentionImporter\Provider\LinkedIn_Provider;
use CourtneyRDev\SocialWebmentionImporter\Provider\Provider_Registry;
use CourtneyRDev\SocialWebmentionImporter\Provider\X_Provider;
use WP_UnitTestCase;

/**
 * @covers \CourtneyRDev\SocialWebmentionImporter\Provider\Provider_Registry
 * @covers \CourtneyRDev\SocialWebmentionImporter\Provider\X_Provider
 * @covers \CourtneyRDev\SocialWebmentionImporter\Provider\LinkedIn_Provider
 * @covers \CourtneyRDev\SocialWebmentionImporter\Import\Duplicate_Detector
 */
class NormalizationTest extends WP_UnitTestCase {

	public function test_registry_detects_providers() {
		$registry = new Provider_Registry();

		$this->assertInstanceOf( X_Provider::class, $registry->for_url( 'https://x.com/alexdoe/status/123' ) );
		$this->assertInstanceOf( X_Provider::class, $registry->for_url( 'https://twitter.com/alexdoe/status/123' ) );
		$this->assertInstanceOf( X_Provider::class, $registry->for_url( 'https://mobile.twitter.com/alexdoe/status/123' ) );
		$this->assertInstanceOf( LinkedIn_Provider::class, $registry->for_url( 'https://www.linkedin.com/posts/jordansample_topic-activity-7000000000000000000-AbCd' ) );
		$this->assertInstanceOf( Generic_Provider::class, $registry->for_url( 'https://example.org/a-reply/' ) );
		// A lookalike host is NOT X.
		$this->assertInstanceOf( Generic_Provider::class, $registry->for_url( 'https://x.com.evil.example/alexdoe/status/123' ) );
	}

	public function test_x_and_twitter_status_urls_normalize_identically() {
		$provider = new X_Provider();

		$a = $provider->normalize( 'https://twitter.com/AlexDoe/status/1234567890123456789' );
		$b = $provider->normalize( 'https://x.com/AlexDoe/status/1234567890123456789?s=20&t=tracker' );
		$c = $provider->normalize( 'https://www.twitter.com/AlexDoe/status/1234567890123456789/' );

		$this->assertSame( '1234567890123456789', $a['remote_id'] );
		$this->assertSame( $a['canonical'], $b['canonical'] );
		$this->assertSame( $a['canonical'], $c['canonical'] );
		$this->assertSame( 'AlexDoe', $a['handle'] );
	}

	public function test_x_i_web_status_has_no_handle() {
		$provider   = new X_Provider();
		$normalized = $provider->normalize( 'https://x.com/i/web/status/1234567890123456789' );

		$this->assertSame( '1234567890123456789', $normalized['remote_id'] );
		$this->assertSame( '', $normalized['handle'] );
	}

	public function test_linkedin_posts_url_extracts_activity_id_and_handle() {
		$provider   = new LinkedIn_Provider();
		$normalized = $provider->normalize( 'https://www.linkedin.com/posts/jordansample_great-topic-activity-7000000000000000000-AbCd?utm_source=share' );

		$this->assertSame( '7000000000000000000', $normalized['remote_id'] );
		$this->assertSame( 'jordansample', $normalized['handle'] );
		$this->assertSame( 'https://www.linkedin.com/feed/update/urn:li:activity:7000000000000000000', $normalized['canonical'] );
	}

	public function test_linkedin_feed_update_urn_extracts_id() {
		$provider = new LinkedIn_Provider();

		$plain   = $provider->normalize( 'https://www.linkedin.com/feed/update/urn:li:activity:7000000000000000000/' );
		$encoded = $provider->normalize( 'https://www.linkedin.com/feed/update/urn%3Ali%3Aactivity%3A7000000000000000000' );

		$this->assertSame( '7000000000000000000', $plain['remote_id'] );
		$this->assertSame( '7000000000000000000', $encoded['remote_id'] );
	}

	public function test_equivalent_x_and_twitter_urls_share_a_duplicate_key() {
		$provider = new X_Provider();

		$one                = new Preview_Record();
		$one->post_id       = 42;
		$one->provider      = 'x';
		$normalized         = $provider->normalize( 'https://twitter.com/AlexDoe/status/99887766' );
		$one->canonical_url = $normalized['canonical'];
		$one->remote_id     = $normalized['remote_id'];

		$two                = new Preview_Record();
		$two->post_id       = 42;
		$two->provider      = 'x';
		$normalized         = $provider->normalize( 'https://x.com/AlexDoe/status/99887766' );
		$two->canonical_url = $normalized['canonical'];
		$two->remote_id     = $normalized['remote_id'];

		$this->assertSame( Duplicate_Detector::normalized_key( $one ), Duplicate_Detector::normalized_key( $two ) );
	}

	public function test_url_only_duplicate_key_normalizes_scheme_www_and_trailing_slash() {
		$one                = new Preview_Record();
		$one->post_id       = 7;
		$one->provider      = 'generic';
		$one->canonical_url = 'https://www.example.org/reply/';

		$two                = new Preview_Record();
		$two->post_id       = 7;
		$two->provider      = 'generic';
		$two->canonical_url = 'http://example.org/reply';

		$this->assertSame( Duplicate_Detector::normalized_key( $one ), Duplicate_Detector::normalized_key( $two ) );
	}
}
