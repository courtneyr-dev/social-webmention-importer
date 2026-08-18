<?php
/**
 * Comment creation, updates, dedupe, moderation, and display integration.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Tests\Integration;

use CourtneyRDev\SocialWebmentionImporter\Display\Attribution;
use CourtneyRDev\SocialWebmentionImporter\Import\Comment_Importer;
use CourtneyRDev\SocialWebmentionImporter\Import\Duplicate_Detector;
use CourtneyRDev\SocialWebmentionImporter\Import\Identity_Store;
use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Record;
use CourtneyRDev\SocialWebmentionImporter\Plugin;
use WP_UnitTestCase;

/**
 * @covers \CourtneyRDev\SocialWebmentionImporter\Import\Comment_Importer
 * @covers \CourtneyRDev\SocialWebmentionImporter\Import\Duplicate_Detector
 * @covers \CourtneyRDev\SocialWebmentionImporter\Import\Identity_Store
 * @covers \CourtneyRDev\SocialWebmentionImporter\Display\Attribution
 */
class ImporterTest extends WP_UnitTestCase {

	/**
	 * Target post shared by the tests.
	 *
	 * @var int
	 */
	protected static $post_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$post_id = $factory->post->create(
			array(
				'post_title'  => 'Sample article',
				'post_status' => 'publish',
			)
		);
	}

	public function set_up() {
		parent::set_up();
		Comment_Importer::register_meta();
		delete_option( Plugin::OPTION_ALLOW_APPROVE );
	}

	/**
	 * A reviewed curated (Mode B) record for the shared post.
	 */
	protected function curated_record() {
		$record                = new Preview_Record();
		$record->source_url    = 'https://x.com/alexdoe/status/1234567890123456789';
		$record->canonical_url = 'https://x.com/alexdoe/status/1234567890123456789';
		$record->provider      = 'x';
		$record->remote_id     = '1234567890123456789';
		$record->post_id       = self::$post_id;
		$record->verification  = 'unverified';
		$record->mode          = Plugin::MODE_SOCIAL_LINKBACK;
		$record->response_type = 'comment';
		$record->author_name   = 'Alex Doe';
		$record->author_handle = 'alexdoe';
		$record->author_url    = 'https://x.com/alexdoe';
		$record->avatar        = 'https://pbs.twimg.com/profile_images/000000000000000000/FIXTURE_400x400.jpg';
		$record->content       = 'This sample article changed how I think about docs.';
		$record->published_gmt = '2026-08-18 00:00:00';
		return $record;
	}

	/**
	 * A reviewed verified (Mode A) record.
	 */
	protected function verified_record() {
		$record               = $this->curated_record();
		$record->verification = 'verified';
		$record->mode         = Plugin::MODE_WEBMENTION;
		return $record;
	}

	public function test_curated_import_is_pending_labeled_and_fully_attributed() {
		$result = Comment_Importer::import( $this->curated_record() );

		$this->assertSame( Comment_Importer::IMPORTED, $result['status'] );
		$comment = get_comment( $result['comment_id'] );

		$this->assertSame( '0', $comment->comment_approved, 'Imports default to pending.' );
		$this->assertSame( 'comment', $comment->comment_type );
		$this->assertSame( 'Alex Doe', $comment->comment_author );
		// The author URL drives the theme's CSS network badge.
		$this->assertSame( 'https://x.com/alexdoe', $comment->comment_author_url );

		$this->assertSame( 'social-linkback', get_comment_meta( $comment->comment_ID, 'protocol', true ) );
		$this->assertSame( 'x', get_comment_meta( $comment->comment_ID, '_swi_provider', true ) );
		$this->assertSame( Plugin::MODE_SOCIAL_LINKBACK, get_comment_meta( $comment->comment_ID, '_swi_import_mode', true ) );
		$this->assertNotEmpty( get_comment_meta( $comment->comment_ID, 'avatar', true ) );
		// A curated response never claims Webmention storage.
		$this->assertSame( '', get_comment_meta( $comment->comment_ID, 'webmention_source_url', true ) );

		// The front-end label names the network, links the source, and
		// carries screen-reader text.
		$text = Attribution::append_source_label( $comment->comment_content, $comment );
		$this->assertStringContainsString( 'Originally posted on X', $text );
		$this->assertStringContainsString( 'https://x.com/alexdoe/status/1234567890123456789', $text );
		$this->assertStringContainsString( 'screen-reader-text', $text );
	}

	public function test_verified_import_uses_webmention_schema() {
		$result  = Comment_Importer::import( $this->verified_record() );
		$comment = get_comment( $result['comment_id'] );

		$this->assertSame( 'webmention', get_comment_meta( $comment->comment_ID, 'protocol', true ) );
		$this->assertSame( 'https://x.com/alexdoe/status/1234567890123456789', get_comment_meta( $comment->comment_ID, 'webmention_source_url', true ) );
		$this->assertSame( get_permalink( self::$post_id ), get_comment_meta( $comment->comment_ID, 'webmention_target_url', true ) );
		$this->assertNotEmpty( get_comment_meta( $comment->comment_ID, 'webmention_last_modified', true ) );
		$this->assertSame( '1', get_comment_meta( $comment->comment_ID, '_swi_target_verified', true ) );

		// No source label on verified Webmentions — their display belongs
		// to the Webmention plugin.
		$text = Attribution::append_source_label( $comment->comment_content, $comment );
		$this->assertStringNotContainsString( 'Originally posted on', $text );
	}

	public function test_unverified_record_cannot_import_as_webmention() {
		$record       = $this->curated_record();
		$record->mode = Plugin::MODE_WEBMENTION; // Verification stays 'unverified'.

		$result = Comment_Importer::import( $record );
		$this->assertSame( Comment_Importer::FAILED_VERIFICATION, $result['status'] );
	}

	public function test_missing_author_name_fails_validation_instead_of_using_network_name() {
		$record              = $this->curated_record();
		$record->author_name = '';

		$result = Comment_Importer::import( $record );
		$this->assertSame( Comment_Importer::FAILED_VALIDATION, $result['status'] );
	}

	public function test_reimport_updates_instead_of_duplicating_and_twitter_url_dedupes() {
		$first = Comment_Importer::import( $this->curated_record() );
		$this->assertSame( Comment_Importer::IMPORTED, $first['status'] );

		// Same tweet pasted with the legacy domain: same normalized key.
		$again                      = $this->curated_record();
		$again->source_url          = 'https://twitter.com/alexdoe/status/1234567890123456789';
		$again->content             = 'This sample article changed how I think about docs. (edited)';
		$again->existing_comment_id = 0; // Force detector lookup.

		$this->assertSame( $first['comment_id'], Duplicate_Detector::find_existing( $again ) );

		$second = Comment_Importer::import( $again );
		$this->assertSame( Comment_Importer::UPDATED, $second['status'] );
		$this->assertSame( $first['comment_id'], $second['comment_id'] );

		$comments = get_comments(
			array(
				'post_id' => self::$post_id,
				'status'  => 'any',
			)
		);
		$this->assertCount( 1, $comments, 'No duplicate comment rows.' );
		$this->assertStringContainsString( '(edited)', $comments[0]->comment_content );
	}

	public function test_reviewer_locked_fields_survive_refresh() {
		$result = Comment_Importer::import( $this->curated_record(), array( 'author_name' ) );

		// A later parser-driven update (no manual fields) proposes a worse name.
		$refresh                      = $this->curated_record();
		$refresh->author_name         = 'Wrong Parser Name';
		$refresh->existing_comment_id = $result['comment_id'];

		Comment_Importer::import( $refresh, array() );

		$comment = get_comment( $result['comment_id'] );
		$this->assertSame( 'Alex Doe', $comment->comment_author, 'Locked field kept the reviewer value.' );
		// Unlocked fields did refresh.
		$this->assertNotEmpty( get_comment_meta( $result['comment_id'], '_swi_snapshot_hash', true ) );
	}

	public function test_moderation_state_survives_update() {
		$result = Comment_Importer::import( $this->curated_record() );
		wp_set_comment_status( $result['comment_id'], 'approve' );

		$again                      = $this->curated_record();
		$again->existing_comment_id = $result['comment_id'];
		Comment_Importer::import( $again );

		$this->assertSame( '1', get_comment( $result['comment_id'] )->comment_approved, 'Approval survived the update.' );
	}

	public function test_imports_stay_pending_even_when_site_moderation_is_open() {
		update_option( 'comment_moderation', 0 );
		update_option( 'comment_previously_approved', 0 );

		$result  = Comment_Importer::import( $this->curated_record() );
		$comment = get_comment( $result['comment_id'] );

		$this->assertSame( '0', $comment->comment_approved, 'Pending by default regardless of site settings.' );
	}

	public function test_content_is_sanitized_against_comment_policy() {
		$record          = $this->curated_record();
		$record->content = 'Nice <script>alert(1)</script> post <img src=x onerror=alert(1)> <a href="https://example.org" onclick="evil()">link</a>';

		$result  = Comment_Importer::import( $record );
		$comment = get_comment( $result['comment_id'] );

		$this->assertStringNotContainsString( '<script', $comment->comment_content );
		$this->assertStringNotContainsString( 'onerror', $comment->comment_content );
		$this->assertStringNotContainsString( 'onclick', $comment->comment_content );
		$this->assertStringNotContainsString( '<img', $comment->comment_content );
		$this->assertStringContainsString( '<a href="https://example.org"', $comment->comment_content );
	}

	public function test_one_failed_row_does_not_roll_back_successes() {
		$good = Comment_Importer::import( $this->curated_record() );
		$this->assertSame( Comment_Importer::IMPORTED, $good['status'] );

		$bad                = $this->curated_record();
		$bad->source_url    = 'https://x.com/other/status/999';
		$bad->canonical_url = 'https://x.com/other/status/999';
		$bad->remote_id     = '999';
		$bad->author_name   = ''; // Fails validation.
		$result             = Comment_Importer::import( $bad );

		$this->assertSame( Comment_Importer::FAILED_VALIDATION, $result['status'] );
		$this->assertNotNull( get_comment( $good['comment_id'] ), 'Successful import survived.' );
	}

	public function test_identity_store_round_trip() {
		Identity_Store::save(
			'x',
			array(
				'handle' => 'AlexDoe',
				'name'   => 'Alex Doe',
				'url'    => 'https://x.com/alexdoe',
				'avatar' => 'https://pbs.twimg.com/profile_images/000000000000000000/FIXTURE_400x400.jpg',
			)
		);

		$record                = new Preview_Record();
		$record->provider      = 'x';
		$record->author_handle = 'alexdoe'; // Case-insensitive key.
		Identity_Store::apply( $record );

		$this->assertSame( 'Alex Doe', $record->author_name );
		$this->assertSame( 'identity-store', $record->extraction['author_name'] );

		// A parser value can no longer displace the confirmed identity.
		$record->offer( 'author_name', 'Scraped Name', 'jsonld' );
		$this->assertSame( 'Alex Doe', $record->author_name );
	}

	public function test_capability_gate() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->assertFalse( Plugin::user_can_import( self::$post_id ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->assertTrue( Plugin::user_can_import( self::$post_id ) );
	}

	public function test_provider_and_no_avatar_comment_classes() {
		$with_avatar = Comment_Importer::import( $this->curated_record() );

		$without              = $this->curated_record();
		$without->source_url  = 'https://www.linkedin.com/feed/update/urn:li:activity:123?commentUrn=urn%3Ali%3Acomment%3A%28activity%3A123%2C456%29';
		$without->canonical_url = 'https://www.linkedin.com/feed/update/urn:li:activity:123?commentUrn=urn%3Ali%3Acomment%3A%28activity%3A123%2C456%29';
		$without->provider    = 'linkedin';
		$without->remote_id   = '123.456';
		$without->avatar      = '';
		$without->author_name = 'Jordan Sample';
		$without->content     = 'A different LinkedIn reply.';
		$no_avatar            = Comment_Importer::import( $without );

		$classes = Attribution::provider_comment_class( array( 'comment' ), array(), $with_avatar['comment_id'], get_comment( $with_avatar['comment_id'] ) );
		$this->assertContains( 'swi-provider-x', $classes );
		$this->assertNotContains( 'swi-no-avatar', $classes );

		$classes = Attribution::provider_comment_class( array( 'comment' ), array(), $no_avatar['comment_id'], get_comment( $no_avatar['comment_id'] ) );
		$this->assertContains( 'swi-provider-linkedin', $classes );
		$this->assertContains( 'swi-no-avatar', $classes );

		// A non-imported comment gets no swi classes.
		$plain   = self::factory()->comment->create( array( 'comment_post_ID' => self::$post_id ) );
		$classes = Attribution::provider_comment_class( array( 'comment' ), array(), $plain, get_comment( $plain ) );
		$this->assertSame( array( 'comment' ), $classes );
	}

	public function test_registered_meta_is_typed_and_authorized() {
		$registered = get_registered_meta_keys( 'comment' );

		$this->assertArrayHasKey( '_swi_provider', $registered );
		$this->assertSame( 'string', $registered['_swi_provider']['type'] );
		$this->assertFalse( $registered['_swi_provider']['show_in_rest'] );
		$this->assertSame( 'boolean', $registered['_swi_target_verified']['type'] );

		// Unauthorized users cannot edit protected _swi meta.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( current_user_can( 'edit_comment_meta', 0, '_swi_provider' ) );
	}
}
