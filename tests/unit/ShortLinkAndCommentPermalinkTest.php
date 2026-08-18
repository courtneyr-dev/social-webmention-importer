<?php
/**
 * Provider link-shortener expansion and LinkedIn comment permalinks.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Tests\Unit;

use CourtneyRDev\SocialWebmentionImporter\Import\Duplicate_Detector;
use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Record;
use CourtneyRDev\SocialWebmentionImporter\Provider\LinkedIn_Provider;
use CourtneyRDev\SocialWebmentionImporter\Tests\Fixtures;
use CourtneyRDev\SocialWebmentionImporter\Verification\Target_Verifier;
use WP_UnitTestCase;

/**
 * @covers \CourtneyRDev\SocialWebmentionImporter\Verification\Target_Verifier
 * @covers \CourtneyRDev\SocialWebmentionImporter\Provider\LinkedIn_Provider
 */
class ShortLinkAndCommentPermalinkTest extends WP_UnitTestCase {

	public function test_short_links_are_found_deduped_and_capped() {
		$body = '
			<a href="https://lnkd.in/eAbc123">one</a>
			<a href="https://lnkd.in/eAbc123">same</a>
			<a href="https://t.co/Xyz789">two</a>
			<a href="https://lnkd.in/eDef456">three</a>
			<a href="https://evil.example/lnkd.in/nope">not a shortener</a>
		';

		$links = Target_Verifier::find_short_links( $body );

		$this->assertSame(
			array( 'https://lnkd.in/eAbc123', 'https://t.co/Xyz789', 'https://lnkd.in/eDef456' ),
			$links
		);

		$many = implode( ' ', array_map( fn( $i ) => "https://lnkd.in/e{$i}", range( 100, 120 ) ) );
		$this->assertCount( 5, Target_Verifier::find_short_links( $many ) );
	}

	public function test_short_link_resolves_via_interstitial_body() {
		$response = array(
			'code'      => 200,
			'body'      => Fixtures::get( 'lnkd-interstitial.html' ),
			'final_url' => 'https://lnkd.in/FIXTURE01',
		);

		$this->assertTrue(
			Target_Verifier::short_link_resolves_to_target( $response, 'https://example-blog.test/2026/08/17/sample-article/' )
		);
		$this->assertFalse(
			Target_Verifier::short_link_resolves_to_target( $response, 'https://example-blog.test/other-post/' )
		);
	}

	public function test_short_link_resolves_via_redirect_final_url() {
		$response = array(
			'code'      => 200,
			'body'      => '<html>anything</html>',
			'final_url' => 'https://www.example-blog.test/2026/08/17/sample-article/',
		);

		$this->assertTrue(
			Target_Verifier::short_link_resolves_to_target( $response, 'https://example-blog.test/2026/08/17/sample-article/' )
		);
		$this->assertFalse(
			Target_Verifier::short_link_resolves_to_target( new \WP_Error( 'nope', 'nope' ), 'https://example-blog.test/2026/08/17/sample-article/' )
		);
	}

	public function test_linkedin_activity_page_extracts_full_post_author() {
		$provider           = new LinkedIn_Provider();
		$record             = new Preview_Record();
		$record->source_url = 'https://www.linkedin.com/feed/update/urn:li:activity:7000000000000000001/';
		$record->provider   = 'linkedin';
		$normalized         = $provider->normalize( $record->source_url );
		$record->canonical_url = $normalized['canonical'];
		$record->remote_id     = $normalized['remote_id'];

		$provider->extract(
			$record,
			Fixtures::fetcher( array( 'linkedin.com/feed/update' => Fixtures::get( 'linkedin-activity.html' ) ) )
		);

		$this->assertSame( 'Jordan Sample', $record->author_name );
		$this->assertSame( 'https://www.linkedin.com/in/jordansample', $record->author_url );
		$this->assertSame( 'https://media.licdn.com/dms/image/FIXTURE/profile-photo.jpg', $record->avatar );
		$this->assertStringContainsString( 'sample article about documentation', $record->content );
		// The reviewer learns the thread has importable public comments.
		$this->assertStringContainsString( '2 public comments', implode( ' ', $record->warnings ) );
	}

	public function test_comment_permalink_parses_and_dedupes_separately_from_post() {
		$provider = new LinkedIn_Provider();

		$post_url    = 'https://www.linkedin.com/feed/update/urn:li:activity:7000000000000000001/';
		$comment_url = $post_url . '?commentUrn=urn%3Ali%3Acomment%3A%28activity%3A7000000000000000001%2C7311111111111111111%29';

		$urn = LinkedIn_Provider::comment_urn_from_url( $comment_url );
		$this->assertSame( '7000000000000000001', $urn['activity'] );
		$this->assertSame( '7311111111111111111', $urn['comment'] );
		$this->assertNull( LinkedIn_Provider::comment_urn_from_url( $post_url ) );

		$post_norm    = $provider->normalize( $post_url );
		$comment_norm = $provider->normalize( $comment_url );

		$this->assertSame( '7000000000000000001.7311111111111111111', $comment_norm['remote_id'] );
		$this->assertStringContainsString( 'commentUrn=', $comment_norm['canonical'] );

		$post_record                = new Preview_Record();
		$post_record->post_id       = 5;
		$post_record->provider      = 'linkedin';
		$post_record->remote_id     = $post_norm['remote_id'];
		$post_record->canonical_url = $post_norm['canonical'];

		$comment_record                = new Preview_Record();
		$comment_record->post_id       = 5;
		$comment_record->provider      = 'linkedin';
		$comment_record->remote_id     = $comment_norm['remote_id'];
		$comment_record->canonical_url = $comment_norm['canonical'];

		$this->assertNotSame(
			Duplicate_Detector::normalized_key( $post_record ),
			Duplicate_Detector::normalized_key( $comment_record ),
			'A reply and its post are different remote items.'
		);
	}

	public function test_comment_permalink_never_inherits_the_post_authors_identity() {
		$provider           = new LinkedIn_Provider();
		$record             = new Preview_Record();
		$record->source_url = 'https://www.linkedin.com/feed/update/urn:li:activity:7000000000000000001/?commentUrn=urn%3Ali%3Acomment%3A%28activity%3A7000000000000000001%2C7311111111111111111%29';
		$record->provider   = 'linkedin';
		$normalized         = $provider->normalize( $record->source_url );
		$record->canonical_url = $normalized['canonical'];
		$record->remote_id     = $normalized['remote_id'];

		$provider->extract(
			$record,
			Fixtures::fetcher( array( 'linkedin.com/feed/update' => Fixtures::get( 'linkedin-activity.html' ) ) )
		);

		// The post author (Jordan Sample) must not be attributed to the reply.
		$this->assertSame( '', $record->author_name );
		$this->assertSame( '', $record->avatar );
		$this->assertSame( '', $record->content );
		// The reviewer gets the public commenter names to work from.
		$this->assertStringContainsString( 'Riley Fixture, Casey Sample', implode( ' ', $record->warnings ) );
	}

	public function test_public_comments_are_listed_from_jsonld() {
		$comments = LinkedIn_Provider::public_comments( Fixtures::get( 'linkedin-activity.html' ) );

		$this->assertCount( 2, $comments );
		$this->assertSame( 'Riley Fixture', $comments[0]['name'] );
		$this->assertSame( 'This helped our team a lot.', $comments[1]['text'] );
	}
}
