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
	 * Whether the preview switch is on.
	 *
	 * @return bool
	 */
	protected static function preview_enabled() {
		return (bool) get_option( self::OPTION_PREVIEW, false );
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
}
