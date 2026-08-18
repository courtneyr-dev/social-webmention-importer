<?php
/**
 * End-to-end preview verification through the service layer.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Tests\Integration;

use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Service;
use CourtneyRDev\SocialWebmentionImporter\Plugin;
use CourtneyRDev\SocialWebmentionImporter\Tests\Fixtures;
use WP_UnitTestCase;

/**
 * @covers \CourtneyRDev\SocialWebmentionImporter\Import\Preview_Service
 */
class PreviewVerificationTest extends WP_UnitTestCase {

	public function test_target_behind_link_shortener_verifies_as_webmention() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$target  = get_permalink( $post_id );

		$source_body  = '<html><body><p>Read this: <a href="https://lnkd.in/eShort1">https://lnkd.in/eShort1</a></p></body></html>';
		$interstitial = '<html><body><a href="' . $target . '">' . $target . '</a></body></html>';

		$service = new Preview_Service(
			null,
			Fixtures::fetcher(
				array(
					'example.org/reply' => $source_body,
					'lnkd.in/eShort1'      => $interstitial,
				)
			)
		);

		$record = $service->preview_url( 'http://example.org/reply', $post_id );

		$this->assertSame( 'verified', $record->verification );
		$this->assertSame( Plugin::MODE_WEBMENTION, $record->mode );
		$this->assertStringContainsString( 'shortener', implode( ' ', $record->warnings ) );
	}

	public function test_unrelated_short_link_does_not_verify() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$source_body  = '<html><body><a href="https://lnkd.in/eShort2">link</a></body></html>';
		$interstitial = '<html><body><a href="https://unrelated.example/">https://unrelated.example/</a></body></html>';

		$service = new Preview_Service(
			null,
			Fixtures::fetcher(
				array(
					'example.org/reply' => $source_body,
					'lnkd.in/eShort2'      => $interstitial,
				)
			)
		);

		$record = $service->preview_url( 'http://example.org/reply', $post_id );

		$this->assertSame( 'unverified', $record->verification );
		$this->assertSame( Plugin::MODE_SOCIAL_LINKBACK, $record->mode );
	}
}
