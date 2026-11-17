<?php
/**
 * Abilities API registration, permissions, switches, and execution.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Tests\Integration;

use CourtneyRDev\SocialWebmentionImporter\Abilities\Abilities;
use CourtneyRDev\SocialWebmentionImporter\Import\Comment_Importer;
use CourtneyRDev\SocialWebmentionImporter\Import\Identity_Store;
use CourtneyRDev\SocialWebmentionImporter\Plugin;
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
		delete_option( Abilities::OPTION_IMPORT );
		delete_option( Identity_Store::OPTION );
		delete_option( Plugin::OPTION_ALLOW_APPROVE );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		delete_option( Abilities::OPTION_PREVIEW );
		delete_option( Abilities::OPTION_IMPORT );
		delete_option( Identity_Store::OPTION );
		delete_option( Plugin::OPTION_ALLOW_APPROVE );
		delete_transient( 'swi_ability_test_sentinel' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_registers_category_and_abilities_with_frozen_metadata() {
		$category        = wp_get_ability_category( Abilities::CATEGORY );
		$preview_ability = wp_get_ability( Abilities::CATEGORY . '/preview' );
		$import_ability  = wp_get_ability( Abilities::CATEGORY . '/import' );

		$this->assertNotNull( $category );
		$this->assertSame( Abilities::CATEGORY, $category->get_slug() );
		$this->assertNotNull( $preview_ability );
		$this->assertSame( Abilities::CATEGORY . '/preview', $preview_ability->get_name() );
		$this->assertSame( Abilities::CATEGORY, $preview_ability->get_category() );
		$this->assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			$preview_ability->get_meta()['annotations']
		);
		$this->assertTrue( $preview_ability->get_meta()['show_in_rest'] );
		$this->assertSame( array( 'public' => false ), $preview_ability->get_meta()['mcp'] );

		$this->assertNotNull( $import_ability );
		$this->assertSame( Abilities::CATEGORY . '/import', $import_ability->get_name() );
		$this->assertSame( Abilities::CATEGORY, $import_ability->get_category() );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			$import_ability->get_meta()['annotations']
		);
		$this->assertTrue( $import_ability->get_meta()['show_in_rest'] );
		$this->assertSame( array( 'public' => false ), $import_ability->get_meta()['mcp'] );

		$namespace_abilities = array_filter(
			wp_get_abilities(),
			static fn( $registered ) => str_starts_with( $registered->get_name(), Abilities::CATEGORY . '/' )
		);
		$this->assertCount( 2, $namespace_abilities );
	}

	public function test_registers_ability_switches_as_private_boolean_settings_defaulting_off() {
		global $wp_registered_settings;

		Abilities::register_settings();

		foreach ( array( Abilities::OPTION_PREVIEW, Abilities::OPTION_IMPORT ) as $option ) {
			$setting = $wp_registered_settings[ $option ];

			$this->assertSame( Abilities::SETTINGS_GROUP, $setting['group'] );
			$this->assertSame( 'boolean', $setting['type'] );
			$this->assertSame( 'rest_sanitize_boolean', $setting['sanitize_callback'] );
			$this->assertFalse( $setting['show_in_rest'] );
			$this->assertFalse( $setting['default'] );
		}
	}

	public function test_preview_switch_reads_only_true_values_as_on() {
		$ability = wp_get_ability( Abilities::CATEGORY . '/preview' );
		$input   = array(
			'post_id' => self::$post_id,
			'url'     => 'https://93.184.216.34/response',
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Store raw values, as WP-CLI or a direct database write would.
		remove_all_filters( 'sanitize_option_' . Abilities::OPTION_PREVIEW );

		foreach ( array( 'false', 'no', 'off', '0', '', 0, false ) as $value ) {
			update_option( Abilities::OPTION_PREVIEW, $value );
			$result = $ability->check_permissions( $input );
			$this->assertWPError( $result, 'Stored value ' . wp_json_encode( $value ) . ' must read as off.' );
			$this->assertSame( 'swi_ability_disabled', $result->get_error_code() );
		}

		foreach ( array( true, 1, '1' ) as $value ) {
			update_option( Abilities::OPTION_PREVIEW, $value );
			// Clear the option caches so the read below goes to the database, not the cached write.
			wp_cache_delete( Abilities::OPTION_PREVIEW, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$this->assertTrue( $ability->check_permissions( $input ), 'Stored value ' . wp_json_encode( $value ) . ' must read as on.' );
		}
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

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( $ability->check_permissions( $input ), 'Editors are denied: they can moderate and edit the post but lack manage_options.' );

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

	public function test_import_switch_reads_only_true_values_as_on() {
		$ability = wp_get_ability( Abilities::CATEGORY . '/import' );
		$input   = $this->import_input();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Store raw values, as WP-CLI or a direct database write would.
		remove_all_filters( 'sanitize_option_' . Abilities::OPTION_IMPORT );

		foreach ( array( 'false', 'no', 'off', '0', '', 0, false ) as $value ) {
			update_option( Abilities::OPTION_IMPORT, $value );
			$result = $ability->check_permissions( $input );
			$this->assertWPError( $result, 'Stored value ' . wp_json_encode( $value ) . ' must read as off.' );
			$this->assertSame( 'swi_ability_disabled', $result->get_error_code() );
		}

		foreach ( array( true, 1, '1' ) as $value ) {
			update_option( Abilities::OPTION_IMPORT, $value );
			// Clear the option caches so the read below goes to the database, not the cached write.
			wp_cache_delete( Abilities::OPTION_IMPORT, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$this->assertTrue( $ability->check_permissions( $input ), 'Stored value ' . wp_json_encode( $value ) . ' must read as on.' );
		}
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

	public function test_import_permission_matrix_and_existing_post_requirement() {
		$ability = wp_get_ability( Abilities::CATEGORY . '/import' );
		$input   = $this->import_input();
		update_option( Abilities::OPTION_IMPORT, true );

		$this->assertFalse( $ability->check_permissions( $input ), 'Logged-out users are denied.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $ability->check_permissions( $input ), 'Subscribers are denied.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( current_user_can( 'edit_post', self::$post_id ) );
		$this->assertTrue( current_user_can( 'moderate_comments' ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertFalse( $ability->check_permissions( $input ), 'Editors are denied because they lack manage_options.' );

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
		$operator_input            = $input;
		$operator_input['post_id'] = $operator_post_id;
		$this->assertTrue( $ability->check_permissions( $operator_input ), 'A user with every required capability and edit access passes.' );

		$no_edit_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$no_edit    = get_userdata( $no_edit_id );
		$no_edit->add_cap( 'manage_options' );
		$no_edit->add_cap( 'moderate_comments' );
		wp_set_current_user( $no_edit_id );
		$this->assertFalse( current_user_can( 'edit_post', self::$post_id ) );
		$this->assertFalse( $ability->check_permissions( $input ), 'manage_options and moderate_comments without edit access to the post are denied.' );

		$no_moderate_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$no_moderate    = get_userdata( $no_moderate_id );
		$no_moderate->add_cap( 'manage_options' );
		$no_moderate->add_cap( 'edit_posts' );
		$no_moderate->add_cap( 'edit_published_posts' );
		$no_moderate_post_id = self::factory()->post->create(
			array(
				'post_author' => $no_moderate_id,
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( $no_moderate_id );
		$no_moderate_input            = $input;
		$no_moderate_input['post_id'] = $no_moderate_post_id;
		$this->assertTrue( current_user_can( 'edit_post', $no_moderate_post_id ) );
		$this->assertFalse( $ability->check_permissions( $no_moderate_input ), 'manage_options and edit access without moderate_comments are denied.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $ability->check_permissions( $input ), 'Administrators pass for an existing editable post.' );
		$input['post_id'] = 999999;
		$this->assertFalse( $ability->check_permissions( $input ), 'A missing post is denied.' );
	}

	public function test_import_switch_off_returns_the_same_error_before_permissions_or_side_effects() {
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$ability = wp_get_ability( Abilities::CATEGORY . '/import' );
		$input   = $this->import_input();
		update_option( Abilities::OPTION_IMPORT, false );
		update_option( Identity_Store::OPTION, array( 'sentinel' => array( 'name' => 'unchanged' ) ) );
		set_transient( 'swi_ability_test_sentinel', 'unchanged', 300 );

		$post_meta_before = get_post_meta( self::$post_id );
		$comments_before  = $this->comment_count();
		$identity_before  = get_option( Identity_Store::OPTION );
		$option_before    = get_option( Abilities::OPTION_IMPORT );
		$http_calls       = 0;
		$probe            = static function ( $preempt ) use ( &$http_calls ) {
			++$http_calls;
			return $preempt;
		};
		add_filter( 'pre_http_request', $probe );

		try {
			$permission      = $ability->check_permissions( $input );
			$result          = $ability->execute( $input );
			$callback_result = Abilities::execute_import( $input );
		} finally {
			remove_filter( 'pre_http_request', $probe );
		}

		$this->assertWPError( $permission );
		$this->assertSame( 'swi_ability_disabled', $permission->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $permission->get_error_data() );
		// Core's WP_Ability::execute() replaces a failed permission check with its own error and a notice.
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertWPError( $callback_result );
		$this->assertSame( 'swi_ability_disabled', $callback_result->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $callback_result->get_error_data() );
		$this->assertSame( 0, $http_calls );
		$this->assertSame( $post_meta_before, get_post_meta( self::$post_id ) );
		$this->assertSame( $comments_before, $this->comment_count() );
		$this->assertSame( $identity_before, get_option( Identity_Store::OPTION ) );
		$this->assertSame( $option_before, get_option( Abilities::OPTION_IMPORT ) );
		$this->assertSame( 'unchanged', get_transient( 'swi_ability_test_sentinel' ) );
	}

	public function test_import_switch_on_imports_verified_response_without_writing_identity_store() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Abilities::OPTION_IMPORT, true );
		update_option( Identity_Store::OPTION, array( 'sentinel' => array( 'name' => 'unchanged' ) ) );
		$identity_before = get_option( Identity_Store::OPTION );

		$http_calls = 0;
		$result     = $this->execute_import_with_body(
			$this->import_input(),
			$this->response_body( true ),
			$http_calls
		);

		$this->assertNotWPError( $result );
		$this->assertSame( Comment_Importer::IMPORTED, $result['status'] );
		$this->assertSame( 'https://93.184.216.34/import-response', $result['source_url'] );
		$this->assertGreaterThan( 0, $result['comment_id'] );
		$this->assertSame( 1, $http_calls );
		$this->assertSame( self::$post_id, (int) get_comment( $result['comment_id'] )->comment_post_ID );
		$this->assertSame( 'webmention', get_comment_meta( $result['comment_id'], 'protocol', true ) );
		$this->assertSame( '0', get_comment( $result['comment_id'] )->comment_approved );
		$this->assertSame( $identity_before, get_option( Identity_Store::OPTION ), 'The ability never saves a reviewer identity.' );
	}

	public function test_import_returns_failed_fetch_record_without_http_for_rejected_url() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Abilities::OPTION_IMPORT, true );
		$input           = $this->import_input( 'file:///etc/passwd' );
		$comments_before = $this->comment_count();
		$http_calls      = 0;
		$probe           = static function ( $preempt ) use ( &$http_calls ) {
			++$http_calls;
			return $preempt;
		};
		add_filter( 'pre_http_request', $probe );

		try {
			$result = wp_get_ability( Abilities::CATEGORY . '/import' )->execute( $input );
		} finally {
			remove_filter( 'pre_http_request', $probe );
		}

		$this->assertNotWPError( $result );
		$this->assertSame( Comment_Importer::FAILED_FETCH, $result['status'] );
		$this->assertSame( 0, $result['comment_id'] );
		$this->assertNotSame( '', $result['message'] );
		$this->assertSame( 0, $http_calls );
		$this->assertSame( $comments_before, $this->comment_count() );
	}

	public function test_import_returns_failed_fetch_record_without_http_for_private_ip_url() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Abilities::OPTION_IMPORT, true );
		$input            = $this->import_input( 'http://10.0.0.5/internal' );
		$comments_before  = $this->comment_count();
		$post_meta_before = get_post_meta( self::$post_id );
		$http_calls       = 0;
		$probe            = static function ( $preempt ) use ( &$http_calls ) {
			++$http_calls;
			return $preempt;
		};
		add_filter( 'pre_http_request', $probe );

		try {
			$result = wp_get_ability( Abilities::CATEGORY . '/import' )->execute( $input );
		} finally {
			remove_filter( 'pre_http_request', $probe );
		}

		$this->assertNotWPError( $result );
		$this->assertSame( Comment_Importer::FAILED_FETCH, $result['status'] );
		$this->assertSame( 0, $result['comment_id'] );
		$this->assertNotSame( '', $result['message'] );
		$this->assertSame( 0, $http_calls );
		$this->assertSame( $comments_before, $this->comment_count() );
		$this->assertSame( $post_meta_before, get_post_meta( self::$post_id ) );
	}

	public function test_import_requires_curated_confirmation_before_writing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Abilities::OPTION_IMPORT, true );
		$input           = $this->import_input( 'https://93.184.216.34/curated-response' );
		$comments_before = $this->comment_count();

		$http_calls = 0;
		$rejected   = $this->execute_import_with_body( $input, $this->response_body( false ), $http_calls );

		$this->assertSame( Comment_Importer::FAILED_VALIDATION, $rejected['status'] );
		$this->assertSame( 0, $rejected['comment_id'] );
		$this->assertSame( $comments_before, $this->comment_count() );

		$input['confirm_curated'] = true;
		$accepted                 = $this->execute_import_with_body( $input, $this->response_body( false ), $http_calls );

		$this->assertSame( Comment_Importer::IMPORTED, $accepted['status'] );
		$this->assertGreaterThan( 0, $accepted['comment_id'] );
		$this->assertSame( 'social-linkback', get_comment_meta( $accepted['comment_id'], 'protocol', true ) );
		$this->assertSame( 2, $http_calls );
	}

	public function test_import_duplicate_url_updates_instead_of_duplicating() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Abilities::OPTION_IMPORT, true );
		$comments_before = $this->comment_count();
		$http_calls      = 0;
		$input           = $this->import_input( 'https://93.184.216.34/duplicate-response' );

		$first  = $this->execute_import_with_body( $input, $this->response_body( true, 'First fixture response.' ), $http_calls );
		$second = $this->execute_import_with_body( $input, $this->response_body( true, 'Updated fixture response.' ), $http_calls );

		$this->assertSame( Comment_Importer::IMPORTED, $first['status'] );
		$this->assertSame( Comment_Importer::UPDATED, $second['status'] );
		$this->assertSame( $first['comment_id'], $second['comment_id'] );
		$this->assertSame( $comments_before + 1, $this->comment_count() );
		$this->assertStringContainsString( 'Updated fixture response.', get_comment( $second['comment_id'] )->comment_content );
		$this->assertSame( 2, $http_calls );
	}

	public function test_import_approve_requires_both_site_setting_and_moderation_capability() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		update_option( Abilities::OPTION_IMPORT, true );
		update_option( 'comment_moderation', 1 );
		$http_calls = 0;

		$setting_off_input            = $this->import_input( 'https://93.184.216.34/approval-setting-off' );
		$setting_off_input['approve'] = true;
		$setting_off                  = $this->execute_import_with_body( $setting_off_input, $this->response_body( true ), $http_calls );
		$this->assertSame( '0', get_comment( $setting_off['comment_id'] )->comment_approved, 'The allow-approve option is off.' );

		update_option( Plugin::OPTION_ALLOW_APPROVE, true );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$no_cap_input            = $this->import_input( 'https://93.184.216.34/approval-no-capability' );
		$no_cap_input['approve'] = true;
		$this->assertFalse( Abilities::can_import( $no_cap_input ), 'A user without moderation capability cannot pass the ability permission callback.' );
		$no_cap = $this->execute_import_callback_with_body( $no_cap_input, $this->response_body( true ), $http_calls );
		$this->assertSame( '0', get_comment( $no_cap['comment_id'] )->comment_approved, 'The importer ignores approve without moderate_comments.' );

		wp_set_current_user( $admin_id );
		$allowed_input            = $this->import_input( 'https://93.184.216.34/approval-allowed' );
		$allowed_input['approve'] = true;
		$allowed                  = $this->execute_import_with_body( $allowed_input, $this->response_body( true ), $http_calls );
		$this->assertSame( '1', get_comment( $allowed['comment_id'] )->comment_approved, 'Both approval gates are open.' );
		$this->assertSame( 3, $http_calls );
	}

	public function test_import_without_approve_stays_pending_when_core_would_publish() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Abilities::OPTION_IMPORT, true );
		update_option( Plugin::OPTION_ALLOW_APPROVE, true );
		update_option( 'comment_moderation', 0 );
		update_option( 'comment_previously_approved', 0 );
		$http_calls = 0;

		$result = $this->execute_import_with_body(
			$this->import_input( 'https://93.184.216.34/approve-omitted' ),
			$this->response_body( true ),
			$http_calls
		);

		$this->assertSame( Comment_Importer::IMPORTED, $result['status'] );
		$this->assertSame( '0', get_comment( $result['comment_id'] )->comment_approved, 'Approve omitted keeps the comment pending.' );
		$this->assertSame( 1, $http_calls );
	}

	public function test_import_input_schema_and_execute_callback_require_confirmation() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Abilities::OPTION_IMPORT, true );
		$ability = wp_get_ability( Abilities::CATEGORY . '/import' );
		$input   = $this->import_input();

		$missing_confirm = $input;
		unset( $missing_confirm['confirm'] );
		$false_confirm             = $input;
		$false_confirm['confirm']  = false;
		$string_confirm            = $input;
		$string_confirm['confirm'] = 'true';
		$extra_property            = $input;
		$extra_property['extra']   = true;
		$zero_post                 = $input;
		$zero_post['post_id']      = 0;
		$comments_before           = $this->comment_count();
		$post_meta_before          = get_post_meta( self::$post_id );
		$http_calls                = 0;
		$probe                     = static function ( $preempt ) use ( &$http_calls ) {
			++$http_calls;
			return $preempt;
		};
		add_filter( 'pre_http_request', $probe );
		try {
			$missing_result  = $ability->execute( $missing_confirm );
			$false_result    = $ability->execute( $false_confirm );
			$string_result   = $ability->execute( $string_confirm );
			$extra_result    = $ability->execute( $extra_property );
			$zero_result     = $ability->execute( $zero_post );
			$callback_result = Abilities::execute_import( $false_confirm );
		} finally {
			remove_filter( 'pre_http_request', $probe );
		}

		$this->assertSame( 'ability_invalid_input', $missing_result->get_error_code() );
		$this->assertTrue( $ability->get_input_schema()['properties']['confirm']['const'] );
		$this->assertSame( 'swi_confirm_required', $false_result->get_error_code() );
		// Core's boolean validation accepts the string "true"; the runtime confirm check refuses it.
		$this->assertSame( 'swi_confirm_required', $string_result->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $extra_result->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $zero_result->get_error_code() );
		$this->assertWPError( $callback_result );
		$this->assertSame( 'swi_confirm_required', $callback_result->get_error_code() );
		$this->assertSame( array( 'status' => 400 ), $callback_result->get_error_data() );
		$this->assertSame( 0, $http_calls );
		$this->assertSame( $comments_before, $this->comment_count() );
		$this->assertSame( $post_meta_before, get_post_meta( self::$post_id ) );
	}

	/**
	 * Build valid import input.
	 *
	 * @param string $url Source URL.
	 * @return array<string,mixed>
	 */
	protected function import_input( $url = 'https://93.184.216.34/import-response' ) {
		return array(
			'post_id' => self::$post_id,
			'url'     => $url,
			'confirm' => true,
		);
	}

	/**
	 * Build a generic provider fixture response.
	 *
	 * @param bool   $links_to_target Whether the source links to the target post.
	 * @param string $content         Fixture response text.
	 * @return string
	 */
	protected function response_body( $links_to_target, $content = 'A fixture response.' ) {
		$link = $links_to_target
			? '<a href="' . esc_url( get_permalink( self::$post_id ) ) . '">Target</a>'
			: '';

		return '<html><head><meta name="author" content="Riley Fixture"><meta property="og:description" content="'
			. esc_attr( $content ) . '"></head><body>' . $link . '</body></html>';
	}

	/**
	 * Execute the registered import ability with a preempted HTTP response.
	 *
	 * @param array<string,mixed> $input      Ability input.
	 * @param string              $body       Fixture response body.
	 * @param int                 $http_calls HTTP call counter.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function execute_import_with_body( $input, $body, &$http_calls ) {
		$probe = $this->http_probe( $body, $http_calls );
		add_filter( 'pre_http_request', $probe );
		try {
			return wp_get_ability( Abilities::CATEGORY . '/import' )->execute( $input );
		} finally {
			remove_filter( 'pre_http_request', $probe );
		}
	}

	/**
	 * Execute the import callback directly to test importer defense in depth.
	 *
	 * @param array<string,mixed> $input      Ability input.
	 * @param string              $body       Fixture response body.
	 * @param int                 $http_calls HTTP call counter.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function execute_import_callback_with_body( $input, $body, &$http_calls ) {
		$probe = $this->http_probe( $body, $http_calls );
		add_filter( 'pre_http_request', $probe );
		try {
			return Abilities::execute_import( $input );
		} finally {
			remove_filter( 'pre_http_request', $probe );
		}
	}

	/**
	 * Build an HTTP preemption callback around a fixture body.
	 *
	 * @param string $body       Fixture response body.
	 * @param int    $http_calls HTTP call counter.
	 * @return callable
	 */
	protected function http_probe( $body, &$http_calls ) {
		return static function () use ( &$http_calls, $body ) {
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
	}

	/**
	 * Count all comments in the test database.
	 *
	 * @return int
	 */
	protected function comment_count() {
		return (int) get_comments(
			array(
				'count'  => true,
				'status' => 'all',
			)
		);
	}
}
