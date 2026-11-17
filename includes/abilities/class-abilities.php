<?php
/**
 * WordPress Abilities API integration.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CourtneyRDev\SocialWebmentionImporter\Import\Comment_Importer;
use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Service;
use CourtneyRDev\SocialWebmentionImporter\Plugin;
use WP_Error;

/**
 * Registers the importer's ability category, abilities, and switches.
 */
class Abilities {

	/**
	 * Ability category and namespace.
	 *
	 * @var string
	 */
	const CATEGORY = 'social-webmention-importer';

	/**
	 * Settings group used by the importer's existing settings form.
	 *
	 * @var string
	 */
	const SETTINGS_GROUP = 'swi_settings';

	/**
	 * Option that enables the preview ability.
	 *
	 * @var string
	 */
	const OPTION_PREVIEW = 'swi_ability_preview';

	/**
	 * Option that enables the import ability.
	 *
	 * @var string
	 */
	const OPTION_IMPORT = 'swi_ability_import';

	/**
	 * Register hooks when their WordPress APIs are available.
	 */
	public static function init() {
		if ( ! self::available() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( static::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( static::class, 'register_abilities' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( static::class, 'register_settings' ) );
		}
	}

	/**
	 * Whether this WordPress installation provides the Abilities API.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'wp_register_ability_category' ) && function_exists( 'wp_register_ability' );
	}

	/**
	 * Register the importer ability category.
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Social Webmention Importer', 'social-webmention-importer' ),
				'description' => __( 'Abilities for previewing and importing public social responses.', 'social-webmention-importer' ),
			)
		);
	}

	/**
	 * Register every importer ability.
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_preview_ability();
		self::register_import_ability();
	}

	/**
	 * Register ability switch settings.
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_PREVIEW,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => false,
				'default'           => false,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_IMPORT,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => false,
				'default'           => false,
			)
		);
	}

	/**
	 * Check whether the current user may preview a URL for a post.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return true|false|WP_Error
	 */
	public static function can_preview( $input ) {
		if ( ! self::preview_enabled() ) {
			return self::disabled_error();
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( ! current_user_can( 'manage_options' ) || ! get_post( $post_id ) || ! Plugin::user_can_import( $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Preview one public response URL without storing a batch transient.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function execute_preview( $input ) {
		if ( ! self::preview_enabled() ) {
			return self::disabled_error();
		}

		return ( new Preview_Service() )->preview_url( (string) $input['url'], (int) $input['post_id'] )->to_array();
	}

	/**
	 * Check whether the current user may import a URL for a post.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return true|false|WP_Error
	 */
	public static function can_import( $input ) {
		if ( ! self::import_enabled() ) {
			return self::import_disabled_error();
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( ! current_user_can( 'manage_options' ) || ! get_post( $post_id ) || ! Plugin::user_can_import( $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Import one public response URL as a comment.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array{source_url:string,status:string,comment_id:int,message:string}|WP_Error
	 */
	public static function execute_import( $input ) {
		if ( ! self::import_enabled() ) {
			return self::import_disabled_error();
		}

		if ( true !== ( $input['confirm'] ?? null ) ) {
			return new WP_Error(
				'swi_confirm_required',
				__( 'Confirm must be true before importing a social response.', 'social-webmention-importer' ),
				array( 'status' => 400 )
			);
		}

		$record = ( new Preview_Service() )->preview_url( (string) $input['url'], (int) $input['post_id'] );
		if ( '' !== $record->error ) {
			return array(
				'source_url' => $record->source_url,
				'status'     => Comment_Importer::FAILED_FETCH,
				'comment_id' => 0,
				'message'    => $record->error,
			);
		}

		if ( Plugin::MODE_SOCIAL_LINKBACK === $record->mode && empty( $input['confirm_curated'] ) ) {
			return array(
				'source_url' => $record->source_url,
				'status'     => Comment_Importer::FAILED_VALIDATION,
				'comment_id' => 0,
				'message'    => __( 'Unverified sources need the curated-response confirmation checked.', 'social-webmention-importer' ),
			);
		}

		$approve = ! empty( $input['approve'] )
			&& (bool) get_option( Plugin::OPTION_ALLOW_APPROVE )
			&& current_user_can( 'moderate_comments' );

		// Without approval, a new comment stays pending even when core's
		// discussion settings would publish it. Spam and trash verdicts pass through.
		$hold = static function ( $approved ) use ( $approve ) {
			if ( $approve || is_wp_error( $approved ) || in_array( $approved, array( 'spam', 'trash' ), true ) ) {
				return $approved;
			}
			return 0;
		};
		add_filter( 'pre_comment_approved', $hold, PHP_INT_MAX );
		try {
			$result = Comment_Importer::import( $record, array(), $approve );
		} finally {
			remove_filter( 'pre_comment_approved', $hold, PHP_INT_MAX );
		}

		return array(
			'source_url' => $record->source_url,
			'status'     => $result['status'],
			'comment_id' => $result['comment_id'],
			'message'    => $result['message'],
		);
	}

	/**
	 * Register the preview ability.
	 */
	protected static function register_preview_ability() {
		wp_register_ability(
			self::CATEGORY . '/preview',
			array(
				'label'               => __( 'Preview a social response', 'social-webmention-importer' ),
				'description'         => __( 'Fetches a public X, LinkedIn, or other URL and previews it as a response to a post.', 'social-webmention-importer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::preview_input_schema(),
				'output_schema'       => self::preview_output_schema(),
				'execute_callback'    => array( static::class, 'execute_preview' ),
				'permission_callback' => array( static::class, 'can_preview' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'mcp'          => array(
						'public' => false,
					),
				),
			)
		);
	}

	/**
	 * Register the import ability.
	 */
	protected static function register_import_ability() {
		wp_register_ability(
			self::CATEGORY . '/import',
			array(
				'label'               => __( 'Import a social response', 'social-webmention-importer' ),
				'description'         => __( 'Fetches a public response URL and imports it as a comment on a post.', 'social-webmention-importer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::import_input_schema(),
				'output_schema'       => self::import_output_schema(),
				'execute_callback'    => array( static::class, 'execute_import' ),
				'permission_callback' => array( static::class, 'can_import' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'mcp'          => array(
						'public' => false,
					),
				),
			)
		);
	}

	/**
	 * Input schema for the preview ability.
	 *
	 * @return array<string,mixed>
	 */
	protected static function preview_input_schema() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'url' ),
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'url'     => array(
					'type' => 'string',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Output schema for Preview_Record::to_array().
	 *
	 * @return array<string,mixed>
	 */
	protected static function preview_output_schema() {
		$string = array( 'type' => 'string' );

		return array(
			'type'                 => 'object',
			'required'             => array(
				'source_url',
				'canonical_url',
				'provider',
				'remote_id',
				'post_id',
				'verification',
				'mode',
				'response_type',
				'author_name',
				'author_handle',
				'author_url',
				'avatar',
				'content',
				'published_gmt',
				'extraction',
				'warnings',
				'error',
				'duplicate_status',
				'existing_comment_id',
				'extraction_method',
			),
			'properties'           => array(
				'source_url'          => $string,
				'canonical_url'       => $string,
				'provider'            => $string,
				'remote_id'           => $string,
				'post_id'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'verification'        => $string,
				'mode'                => $string,
				'response_type'       => $string,
				'author_name'         => $string,
				'author_handle'       => $string,
				'author_url'          => $string,
				'avatar'              => $string,
				'content'             => $string,
				'published_gmt'       => $string,
				'extraction'          => array(
					'type'                 => 'object',
					'additionalProperties' => $string,
				),
				'warnings'            => array(
					'type'  => 'array',
					'items' => $string,
				),
				'error'               => $string,
				'duplicate_status'    => $string,
				'existing_comment_id' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'extraction_method'   => $string,
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for the import ability.
	 *
	 * @return array<string,mixed>
	 */
	protected static function import_input_schema() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'url', 'confirm' ),
			'properties'           => array(
				'post_id'         => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'url'             => array(
					'type' => 'string',
				),
				'confirm'         => array(
					'type'  => 'boolean',
					'const' => true,
				),
				'approve'         => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'confirm_curated' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Output schema for an import result.
	 *
	 * @return array<string,mixed>
	 */
	protected static function import_output_schema() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'source_url', 'status', 'comment_id', 'message' ),
			'properties'           => array(
				'source_url' => array( 'type' => 'string' ),
				'status'     => array( 'type' => 'string' ),
				'comment_id' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'message'    => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Whether the preview switch is on.
	 *
	 * Only true, 1 and "1" count as on. The setting's sanitizer runs on
	 * admin_init only, so a WP-CLI or direct write can store "false", "no"
	 * or "off", and a plain (bool) cast would read those as on.
	 *
	 * @return bool
	 */
	protected static function preview_enabled() {
		return self::switch_enabled( self::OPTION_PREVIEW );
	}

	/**
	 * Whether the import switch is on.
	 *
	 * @return bool
	 */
	protected static function import_enabled() {
		return self::switch_enabled( self::OPTION_IMPORT );
	}

	/**
	 * Whether an ability switch contains an explicit true value.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private static function switch_enabled( $option ) {
		return in_array( get_option( $option, false ), array( true, 1, '1' ), true );
	}

	/**
	 * Error returned before any permission check or fetch when disabled.
	 *
	 * @return WP_Error
	 */
	protected static function disabled_error() {
		return new WP_Error(
			'swi_ability_disabled',
			__( 'The Social Webmention Importer preview ability is disabled.', 'social-webmention-importer' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Error returned before any permission check or fetch when import is disabled.
	 *
	 * @return WP_Error
	 */
	protected static function import_disabled_error() {
		return new WP_Error(
			'swi_ability_disabled',
			__( 'The Social Webmention Importer import ability is disabled.', 'social-webmention-importer' ),
			array( 'status' => 403 )
		);
	}
}
