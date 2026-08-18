<?php
/**
 * Provider extraction against fixtures, plus confidence precedence.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Tests\Unit;

use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Record;
use CourtneyRDev\SocialWebmentionImporter\Provider\LinkedIn_Provider;
use CourtneyRDev\SocialWebmentionImporter\Provider\X_Provider;
use CourtneyRDev\SocialWebmentionImporter\Tests\Fixtures;
use WP_Error;
use WP_UnitTestCase;

/**
 * @covers \CourtneyRDev\SocialWebmentionImporter\Provider\X_Provider
 * @covers \CourtneyRDev\SocialWebmentionImporter\Provider\LinkedIn_Provider
 * @covers \CourtneyRDev\SocialWebmentionImporter\Import\Preview_Record
 */
class ProviderExtractionTest extends WP_UnitTestCase {

	/**
	 * Build an X record pre-populated the way Preview_Service does.
	 */
	protected function x_record( $url = 'https://x.com/alexdoe/status/1234567890123456789' ) {
		$provider              = new X_Provider();
		$record                = new Preview_Record();
		$record->source_url    = $url;
		$record->provider      = 'x';
		$normalized            = $provider->normalize( $url );
		$record->canonical_url = $normalized['canonical'];
		$record->remote_id     = $normalized['remote_id'];
		$record->author_handle = $normalized['handle'];
		return array( $provider, $record );
	}

	public function test_x_extraction_prefers_oembed_and_proves_avatar() {
		list( $provider, $record ) = $this->x_record();

		$provider->extract(
			$record,
			Fixtures::fetcher(
				array(
					'publish.twitter.com/oembed' => Fixtures::get( 'x-oembed.json' ),
					'x.com/alexdoe/status'       => Fixtures::get( 'x-status-og.html' ),
				)
			)
		);

		$this->assertSame( 'Alex Doe 👋', $record->author_name );
		$this->assertSame( 'oembed', $record->extraction['author_name'] );
		$this->assertSame( 'https://x.com/alexdoe', $record->author_url );
		$this->assertSame( 'alexdoe', $record->author_handle );
		$this->assertStringContainsString( 'changed how I think about docs', $record->content );
		// Avatar came from the og profile-image rule.
		$this->assertSame( 'https://pbs.twimg.com/profile_images/000000000000000000/FIXTURE_400x400.jpg', $record->avatar );
		$this->assertSame( '2026-08-18 00:00:00', $record->published_gmt );
		// The raw page is retained in memory for verification.
		$this->assertNotSame( '', $record->raw_body );
		// The network name never leaked into the author.
		$this->assertNotSame( 'X', $record->author_name );
	}

	public function test_x_media_tweet_gets_no_avatar() {
		list( $provider, $record ) = $this->x_record( 'https://x.com/alexdoe/status/1234567890123456790' );

		$provider->extract(
			$record,
			Fixtures::fetcher(
				array(
					'x.com/alexdoe/status' => Fixtures::get( 'x-status-media-og.html' ),
				)
			)
		);

		$this->assertSame( '', $record->avatar );
	}

	public function test_blocked_x_fetch_still_returns_editable_record_with_handle() {
		list( $provider, $record ) = $this->x_record();

		$provider->extract( $record, Fixtures::fetcher( array() ) ); // Everything 404s.

		$this->assertSame( '', $record->author_name );
		$this->assertSame( 'alexdoe', $record->author_handle );
		$this->assertSame( 'https://x.com/alexdoe', $record->author_url );
		$this->assertNotEmpty( $record->warnings );
	}

	public function test_linkedin_jsonld_person_wins_over_truncated_og() {
		$provider              = new LinkedIn_Provider();
		$record                = new Preview_Record();
		$record->source_url    = 'https://www.linkedin.com/posts/jordansample_topic-activity-7000000000000000000-AbCd';
		$record->provider      = 'linkedin';
		$normalized            = $provider->normalize( $record->source_url );
		$record->canonical_url = $normalized['canonical'];
		$record->remote_id     = $normalized['remote_id'];
		$record->author_handle = $normalized['handle'];

		$provider->extract(
			$record,
			Fixtures::fetcher( array( 'linkedin.com/posts/' => Fixtures::get( 'linkedin-post.html' ) ) )
		);

		$this->assertSame( 'Jordan Sample', $record->author_name );
		$this->assertSame( 'jsonld', $record->extraction['author_name'] );
		$this->assertSame( 'https://www.linkedin.com/in/jordansample', $record->author_url );
		// Full articleBody beat the truncated og:description.
		$this->assertStringContainsString( 'our industry needs', $record->content );
		$this->assertSame( 'https://media.licdn.com/dms/image/FIXTURE/profile-photo.jpg', $record->avatar );
		$this->assertSame( '2026-08-18 09:30:00', $record->published_gmt );
	}

	public function test_linkedin_authwall_degrades_to_manual_review() {
		$provider              = new LinkedIn_Provider();
		$record                = new Preview_Record();
		$record->source_url    = 'https://www.linkedin.com/posts/jordansample_topic-activity-7000000000000000000-AbCd';
		$record->provider      = 'linkedin';
		$record->author_handle = 'jordansample';

		$provider->extract(
			$record,
			Fixtures::fetcher( array( 'linkedin.com/posts/' => Fixtures::get( 'linkedin-authwall.html' ) ) )
		);

		$this->assertSame( '', $record->author_name );
		$this->assertNotEmpty( $record->warnings );
		// The company/network branding never became the author or avatar.
		$this->assertSame( '', $record->avatar );
	}

	public function test_confidence_precedence_and_reviewer_lock() {
		$record = new Preview_Record();

		$this->assertTrue( $record->offer( 'author_name', 'From Title', 'title-pattern' ) );
		$this->assertTrue( $record->offer( 'author_name', 'From oEmbed', 'oembed' ) );
		// Lower confidence never overwrites higher.
		$this->assertFalse( $record->offer( 'author_name', 'From Title Again', 'title-pattern' ) );
		$this->assertSame( 'From oEmbed', $record->author_name );

		// Identity store beats every parser…
		$this->assertTrue( $record->offer( 'author_name', 'Confirmed Person', 'identity-store' ) );
		// …and manual beats the store.
		$this->assertTrue( $record->offer( 'author_name', 'Manually Fixed', 'manual' ) );
		$this->assertFalse( $record->offer( 'author_name', 'Parser Comeback', 'jsonld' ) );
		$this->assertSame( 'Manually Fixed', $record->author_name );

		// Empty values are never offered successfully.
		$this->assertFalse( $record->offer( 'avatar', '', 'manual' ) );
	}
}
