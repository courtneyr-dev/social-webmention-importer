<?php
/**
 * Abilities API registration, permissions, switches, and execution.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Tests\Integration;

use CourtneyRDev\SocialWebmentionImporter\Abilities\Abilities;
use WP_UnitTestCase;

/**
 * @covers \CourtneyRDev\SocialWebmentionImporter\Abilities\Abilities
 */
class AbilitiesTest extends WP_UnitTestCase {

	/**
	 * Target post shared by the tests.
	 *
	 * @var int
	 */
	protected static $post_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$post_id = $factory->post->create(
			array(
				'post_status' => 'publish',
			)
		);

		Abilities::init();
		wp_get_abilities();
	}

	public function set_up() {
		parent::set_up();
		delete_option( Abilities::OPTION_PREVIEW );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		delete_option( Abilities::OPTION_PREVIEW );
		delete_transient( 'swi_ability_test_sentinel' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_registers_category_and_preview_ability_with_frozen_metadata() {
		$category = wp_get_ability_category( Abilities::CATEGORY );
		$ability  = wp_get_ability( Abilities::CATEGORY . '/preview' );

		$this->assertNotNull( $category );
		$this->assertSame( Abilities::CATEGORY, $category->get_slug() );
		$this->assertNotNull( $ability );
		$this->assertSame( Abilities::CATEGORY . '/preview', $ability->get_name() );
		$this->assertSame( Abilities::CATEGORY, $ability->get_category() );
		$this->assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			$ability->get_meta()['annotations']
		);
		$this->assertTrue( $ability->get_meta()['show_in_rest'] );
		$this->assertSame( array( 'public' => false ), $ability->get_meta()['mcp'] );

		$namespace_abilities = array_filter(
			wp_get_abilities(),
			static fn( $registered ) => str_starts_with( $registered->get_name(), Abilities::CATEGORY . '/' )
		);
		$this->assertCount( 1, $namespace_abilities );
	}

	public function test_registers_preview_switch_as_a_private_boolean_setting_defaulting_off() {
		global $wp_registered_settings;

		Abilities::register_settings();
		$setting = $wp_registered_settings[ Abilities::OPTION_PREVIEW ];

		$this->assertSame( Abilities::SETTINGS_GROUP, $setting['group'] );
		$this->assertSame( 'boolean', $setting['type'] );
		$this->assertSame( 'rest_sanitize_boolean', $setting['sanitize_callback'] );
		$this->assertFalse( $setting['show_in_rest'] );
		$this->assertFalse( $setting['default'] );
	}

	public function test_preview_permission_matrix_and_existing_post_requirement() {
		$ability = wp_get_ability( Abilities::CATEGORY . '/preview' );
		$input   = array(
			'post_id' => self::$post_id,
			'url'     => 'https://93.184.216.34/response',
		);
		update_option( Abilities::OPTION_PREVIEW, true );

		$this->assertFalse( $ability->check_permissions( $input ), 'Logged-out users are denied.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $ability->check_permissions( $input ), 'Subscribers are denied.' );

		$operator_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$operator    = get_userdata( $operator_id );
		$operator->add_cap( 'manage_options' );
		$operator->add_cap( 'moderate_comments' );
		$operator->add_cap( 'edit_posts' );
		$operator->add_cap( 'edit_published_posts' );
		$operator_post_id = self::factory()->post->create(
			array(
				'post_author' => $operator_id,
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( $operator_id );
		$this->assertTrue(
			$ability->check_permissions(
				array(
					'post_id' => $operator_post_id,
					'url'     => $input['url'],
				)
			),
			'A user with the required capabilities and edit access passes.'
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $ability->check_permissions( $input ), 'Administrators pass for an existing editable post.' );
		$input['post_id'] = 999999;
		$this->assertFalse( $ability->check_permissions( $input ), 'A missing post is denied.' );
	}

	public function test_switch_off_returns_the_same_error_before_permissions_or_side_effects() {
		$ability = wp_get_ability( Abilities::CATEGORY . '/preview' );
		$input   = array(
			'post_id' => self::$post_id,
			'url'     => 'https://93.184.216.34/response',
		);
		update_option( Abilities::OPTION_PREVIEW, false );
		set_transient( 'swi_ability_test_sentinel', 'unchanged', 300 );

		$post_meta_before = get_post_meta( self::$post_id );
		$comments_before  = (int) get_comments(
			array(
				'count'  => true,
				'status' => 'all',
			)
		);
		$option_before    = get_option( Abilities::OPTION_PREVIEW );
		$http_calls       = 0;
		$probe            = static function ( $preempt ) use ( &$http_calls ) {
			++$http_calls;
			return $preempt;
		};
		add_filter( 'pre_http_request', $probe );

		try {
			$permission = $ability->check_permissions( $input );
			$result     = Abilities::execute_preview( $input );
		} finally {
			remove_filter( 'pre_http_request', $probe );
		}

		$this->assertWPError( $permission );
		$this->assertSame( 'swi_ability_disabled', $permission->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $permission->get_error_data() );
		$this->assertWPError( $result );
		$this->assertSame( 'swi_ability_disabled', $result->get_error_code() );
		$this->assertSame( 0, $http_calls );
		$this->assertSame( $post_meta_before, get_post_meta( self::$post_id ) );
		$this->assertSame(
			$comments_before,
			(int) get_comments(
				array(
					'count'  => true,
					'status' => 'all',
				)
			)
		);
		$this->assertSame( $option_before, get_option( Abilities::OPTION_PREVIEW ) );
		$this->assertSame( 'unchanged', get_transient( 'swi_ability_test_sentinel' ) );
	}

	public function test_switch_on_executes_preview_with_fixture_and_stores_no_batch_transient() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Abilities::OPTION_PREVIEW, true );
		$target = get_permalink( self::$post_id );
		$body   = '<html><head><meta name="author" content="Riley Fixture"><meta property="og:description" content="A fixture response."></head><body><a href="'
			. esc_url( $target ) . '">Target</a></body></html>';

		$http_calls             = 0;
		$probe                  = static function () use ( &$http_calls, $body ) {
			++$http_calls;
			return array(
				'headers'  => array( 'content-type' => 'text/html' ),
				'body'     => $body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		$batch_transient_writes = 0;
		$transient_probe        = static function ( $transient ) use ( &$batch_transient_writes ) {
			if ( str_starts_with( $transient, 'swi_batch_' ) ) {
				++$batch_transient_writes;
			}
		};
		$comments_before        = (int) get_comments(
			array(
				'count'  => true,
				'status' => 'all',
			)
		);
		add_filter( 'pre_http_request', $probe );
		add_action( 'set_transient', $transient_probe, 10, 3 );

		try {
			$result = wp_get_ability( Abilities::CATEGORY . '/preview' )->execute(
				array(
					'post_id' => self::$post_id,
					'url'     => 'https://93.184.216.34/response',
				)
			);
		} finally {
			remove_filter( 'pre_http_request', $probe );
			remove_action( 'set_transient', $transient_probe, 10 );
		}

		$this->assertNotWPError( $result );
		$this->assertSame( 'https://93.184.216.34/response', $result['source_url'] );
		$this->assertSame( self::$post_id, $result['post_id'] );
		$this->assertSame( 'Riley Fixture', $result['author_name'] );
		$this->assertSame( 'verified', $result['verification'] );
		$this->assertSame( 1, $http_calls );
		$this->assertSame( 0, $batch_transient_writes );
		$this->assertSame(
			$comments_before,
			(int) get_comments(
				array(
					'count'  => true,
					'status' => 'all',
				)
			)
		);
	}

	public function test_invalid_url_returns_a_preview_record_error_without_http() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Abilities::OPTION_PREVIEW, true );
		$http_calls = 0;
		$probe      = static function ( $preempt ) use ( &$http_calls ) {
			++$http_calls;
			return $preempt;
		};
		add_filter( 'pre_http_request', $probe );

		try {
			$result = wp_get_ability( Abilities::CATEGORY . '/preview' )->execute(
				array(
					'post_id' => self::$post_id,
					'url'     => 'file:///etc/passwd',
				)
			);
		} finally {
			remove_filter( 'pre_http_request', $probe );
		}

		$this->assertNotWPError( $result );
		$this->assertNotSame( '', $result['error'] );
		$this->assertSame( 0, $http_calls );
	}

	public function test_input_schema_rejects_missing_and_out_of_range_values() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Abilities::OPTION_PREVIEW, true );
		$ability = wp_get_ability( Abilities::CATEGORY . '/preview' );

		$missing_url = $ability->execute( array( 'post_id' => self::$post_id ) );
		$zero_post   = $ability->execute(
			array(
				'post_id' => 0,
				'url'     => 'https://93.184.216.34/response',
			)
		);

		$this->assertWPError( $missing_url );
		$this->assertSame( 'ability_invalid_input', $missing_url->get_error_code() );
		$this->assertWPError( $zero_post );
		$this->assertSame( 'ability_invalid_input', $zero_post->get_error_code() );
	}
}
