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

	public function test_short_links_are_resolved_to_host_and_path_with_nofollow_ugc() {
		$html = 'Check this out <a href="https://t.co/FIXTURE00">https://t.co/FIXTURE00</a> neat.';

		$resolved = Content_Resolver::resolve_short_links(
			$html,
			function ( $url ) {
				$this->assertSame( 'https://t.co/FIXTURE00', $url );
				// The href keeps the real, working final URL (query string
				// and all); only the visible text is shortened to host/path.
				return 'https://example-blog.test/2026/08/17/sample-article/?utm_source=x';
			}
		);

		$this->assertSame(
			'Check this out <a href="https://example-blog.test/2026/08/17/sample-article/?utm_source=x" rel="nofollow ugc">example-blog.test/2026/08/17/sample-article/</a> neat.',
			$resolved
		);
	}

	public function test_pic_twitter_com_links_also_resolve() {
		$html = '<a href="https://pic.twitter.com/FIXTURE00">pic.twitter.com/FIXTURE00</a>';

		$resolved = Content_Resolver::resolve_short_links(
			$html,
			function () {
				return 'https://x.com/alexdoe/status/123/photo/1';
			}
		);

		$this->assertSame(
			'<a href="https://x.com/alexdoe/status/123/photo/1" rel="nofollow ugc">x.com/alexdoe/status/123/photo/1</a>',
			$resolved
		);
	}

	public function test_resolution_failure_leaves_the_original_link_untouched() {
		$html = '<a href="https://t.co/FIXTURE00">https://t.co/FIXTURE00</a>';

		$resolved = Content_Resolver::resolve_short_links(
			$html,
			function () {
				return new \WP_Error( 'swi_http_404', 'not found' );
			}
		);

		$this->assertSame( $html, $resolved );
	}

	public function test_resolving_short_links_is_idempotent() {
		$html     = '<a href="https://t.co/FIXTURE00">https://t.co/FIXTURE00</a>';
		$resolver = function () {
			return 'https://example-blog.test/post/';
		};

		$once  = Content_Resolver::resolve_short_links( $html, $resolver );
		$twice = Content_Resolver::resolve_short_links(
			$once,
			function () {
				$this->fail( 'An already-resolved link must not be resolved again.' );
			}
		);

		$this->assertSame( $once, $twice );
	}

	public function test_links_that_are_not_shorteners_are_left_alone() {
		$html = '<a href="https://example.org/already-a-real-link">example.org/already-a-real-link</a>';

		$resolved = Content_Resolver::resolve_short_links(
			$html,
			function () {
				$this->fail( 'Non-shortener links must never be fetched.' );
			}
		);

		$this->assertSame( $html, $resolved );
	}
}
