<?php
/**
 * Metadata, author, content, and avatar parsing rules.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Tests\Unit;

use CourtneyRDev\SocialWebmentionImporter\Parsing\Author_Resolver;
use CourtneyRDev\SocialWebmentionImporter\Parsing\Avatar_Resolver;
use CourtneyRDev\SocialWebmentionImporter\Parsing\Content_Resolver;
use CourtneyRDev\SocialWebmentionImporter\Parsing\Metadata;
use CourtneyRDev\SocialWebmentionImporter\Tests\Fixtures;
use WP_UnitTestCase;

/**
 * @covers \CourtneyRDev\SocialWebmentionImporter\Parsing\Metadata
 * @covers \CourtneyRDev\SocialWebmentionImporter\Parsing\Author_Resolver
 * @covers \CourtneyRDev\SocialWebmentionImporter\Parsing\Content_Resolver
 * @covers \CourtneyRDev\SocialWebmentionImporter\Parsing\Avatar_Resolver
 */
class ParsingTest extends WP_UnitTestCase {

	public function test_meta_tags_are_extracted_with_emoji_intact() {
		$meta = Metadata::meta_tags( Fixtures::get( 'x-status-og.html' ) );

		$this->assertSame( 'Alex Doe 👋 (@alexdoe) on X', $meta['og:title'] );
		$this->assertSame( 'X (formerly Twitter)', $meta['og:site_name'] );
	}

	public function test_jsonld_person_author_is_extracted() {
		$person = Metadata::jsonld_person_author( Metadata::jsonld_blocks( Fixtures::get( 'linkedin-post.html' ) ) );

		$this->assertSame( 'Jordan Sample', $person['name'] );
		$this->assertSame( 'https://www.linkedin.com/in/jordansample', $person['url'] );
		$this->assertSame( 'https://media.licdn.com/dms/image/FIXTURE/profile-photo.jpg', $person['image'] );
	}

	public function test_jsonld_organization_author_is_refused() {
		$html = '<html><head><script type="application/ld+json">{"@type":"Article","author":{"@type":"Organization","name":"LinkedIn"}}</script></head><body></body></html>';

		$this->assertNull( Metadata::jsonld_person_author( Metadata::jsonld_blocks( $html ) ) );
	}

	public function test_network_names_are_never_accepted_as_authors() {
		$this->assertSame( '', Author_Resolver::refuse_network_name( 'X' ) );
		$this->assertSame( '', Author_Resolver::refuse_network_name( 'x (Formerly Twitter)' ) );
		$this->assertSame( '', Author_Resolver::refuse_network_name( 'LinkedIn' ) );
		$this->assertSame( '', Author_Resolver::refuse_network_name( '  ' ) );
		$this->assertSame( 'Alex Doe', Author_Resolver::refuse_network_name( 'Alex Doe' ) );
	}

	public function test_x_title_pattern_yields_name_and_handle() {
		$parsed = Author_Resolver::from_x_title( 'Alex Doe 👋 (@alexdoe) on X' );

		$this->assertSame( 'Alex Doe 👋', $parsed['name'] );
		$this->assertSame( 'alexdoe', $parsed['handle'] );

		$quoted = Author_Resolver::from_x_title( 'Alex Doe 👋 on X: "Some tweet" / X' );
		$this->assertSame( 'Alex Doe 👋', $quoted['name'] );
	}

	public function test_linkedin_title_pattern_yields_name() {
		$this->assertSame( 'Jordan Sample', Author_Resolver::from_linkedin_title( 'Jordan Sample on LinkedIn: Sample article thoughts' ) );
		$this->assertSame( '', Author_Resolver::from_linkedin_title( 'Sign in or join LinkedIn' ) );
	}

	public function test_x_oembed_html_becomes_clean_text_with_links_and_breaks() {
		$data = json_decode( Fixtures::get( 'x-oembed.json' ), true );
		$text = Content_Resolver::from_x_oembed_html( $data['html'] );

		$this->assertStringContainsString( 'This sample article changed how I think about docs.', $text );
		$this->assertStringContainsString( '<br>', $text );
		$this->assertStringContainsString( '<a href="https://t.co/FIXTURE00">', $text );
		$this->assertStringNotContainsString( 'twitter-tweet', $text );
		$this->assertStringNotContainsString( 'ref_src', $text );
	}

	public function test_x_title_cleanup_extracts_quoted_text() {
		$this->assertSame(
			'Some tweet text',
			Content_Resolver::from_x_title( 'Alex Doe 👋 on X: "Some tweet text" / X' )
		);
	}

	public function test_truncation_detection() {
		$this->assertTrue( Content_Resolver::looks_truncated( 'Cut off with ellipsis…' ) );
		$this->assertTrue(
			Content_Resolver::looks_truncated(
				str_repeat( 'A long sentence keeps going here. ', 6 ) . 'and then it just sto'
			)
		);
		$this->assertFalse( Content_Resolver::looks_truncated( 'A complete sentence.' ) );
	}

	public function test_x_profile_image_is_accepted_as_avatar() {
		$this->assertSame(
			'https://pbs.twimg.com/profile_images/000000000000000000/FIXTURE_400x400.jpg',
			Avatar_Resolver::from_x_og_image( 'https://pbs.twimg.com/profile_images/000000000000000000/FIXTURE_400x400.jpg' )
		);
	}

	public function test_x_post_media_is_refused_as_avatar() {
		$this->assertSame( '', Avatar_Resolver::from_x_og_image( 'https://pbs.twimg.com/media/FIXTUREmediaimage.jpg' ) );
		$this->assertSame( '', Avatar_Resolver::from_x_og_image( 'https://evil.example/profile_images/fake.jpg' ) );
	}

	public function test_linkedin_og_image_is_always_refused_as_avatar() {
		$this->assertSame( '', Avatar_Resolver::from_linkedin_og_image() );
	}
}
