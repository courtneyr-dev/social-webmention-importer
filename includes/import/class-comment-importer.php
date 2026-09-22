<?php
/**
 * Comment creation, update, and plugin metadata registration.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CourtneyRDev\SocialWebmentionImporter\Plugin;
use WP_Error;

/**
 * Validates reviewed records and writes them as comments.
 *
 * Mode A (verified Webmention) writes the installed Webmention plugin's
 * schema — `protocol = webmention`, `webmention_source_url`,
 * `webmention_target_url`, `url`, `avatar`, `webmention_last_modified` —
 * so its display, facepile, and avatar layers treat the comment as their
 * own. Mode B (curated social response) is a normal textual comment with
 * `protocol = social-linkback` and plugin-owned attribution.
 */
class Comment_Importer {

	/**
	 * Row result statuses returned by import().
	 *
	 * @var string
	 */
	const IMPORTED            = 'imported';
	const UPDATED             = 'updated';
	const SKIPPED_DUPLICATE   = 'skipped_duplicate';
	const SKIPPED_BY_REVIEWER = 'skipped_by_reviewer';
	const FAILED_VERIFICATION = 'failed_verification';
	const FAILED_FETCH        = 'failed_fetch';
	const FAILED_VALIDATION   = 'failed_validation';
	const FAILED_INSERTION    = 'failed_insertion';

	/**
	 * Register plugin-owned comment meta with types, sanitizers, and
	 * authorization. Nothing here is exposed over REST: it is import
	 * provenance, not public content.
	 */
	public static function register_meta() {
		$auth = function () {
			return current_user_can( 'moderate_comments' );
		};

		$string_keys = array(
			'_swi_provider'              => 'sanitize_key',
			'_swi_remote_id'             => 'sanitize_text_field',
			'_swi_import_mode'           => 'sanitize_key',
			'_swi_imported_at_gmt'       => 'sanitize_text_field',
			'_swi_last_checked_at_gmt'   => 'sanitize_text_field',
			'_swi_original_source_url'   => 'esc_url_raw',
			'_swi_normalized_source_key' => 'sanitize_text_field',
			'_swi_extraction_method'     => 'sanitize_key',
			'_swi_snapshot_hash'         => 'sanitize_text_field',
		);

		foreach ( $string_keys as $key => $sanitizer ) {
			register_meta(
				'comment',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => $sanitizer,
					'auth_callback'     => $auth,
				)
			);
		}

		register_meta(
			'comment',
			'_swi_extraction_confidence',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
			)
		);

		register_meta(
			'comment',
			'_swi_target_verified',
			array(
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
			)
		);

		register_meta(
			'comment',
			'_swi_manual_fields',
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => function ( $value ) {
					return array_values( array_map( 'sanitize_key', (array) $value ) );
				},
				'auth_callback'     => $auth,
			)
		);
	}

	/**
	 * Validate a reviewed record before writing anything.
	 *
	 * @param Preview_Record $record Reviewed record.
	 * @return true|WP_Error
	 */
	public static function validate( Preview_Record $record ) {
		if ( '' === $record->source_url || is_wp_error( \CourtneyRDev\SocialWebmentionImporter\Http\Safe_Fetcher::validate_url( $record->source_url ) ) ) {
			return new WP_Error( self::FAILED_VALIDATION, __( 'Missing or unsafe source URL.', 'social-webmention-importer' ) );
		}
		if ( ! $record->post_id || ! get_post( $record->post_id ) ) {
			return new WP_Error( self::FAILED_VALIDATION, __( 'Target post is missing.', 'social-webmention-importer' ) );
		}
		if ( '' === trim( $record->author_name ) ) {
			return new WP_Error( self::FAILED_VALIDATION, __( 'Author name is required — the network name is never used as a substitute.', 'social-webmention-importer' ) );
		}
		if ( '' === $record->provider ) {
			return new WP_Error( self::FAILED_VALIDATION, __( 'Provider is required.', 'social-webmention-importer' ) );
		}
		if ( ! in_array( $record->mode, array( Plugin::MODE_WEBMENTION, Plugin::MODE_SOCIAL_LINKBACK ), true ) ) {
			return new WP_Error( self::FAILED_VALIDATION, __( 'Import mode is required.', 'social-webmention-importer' ) );
		}
		if ( Plugin::MODE_WEBMENTION === $record->mode && 'verified' !== $record->verification ) {
			return new WP_Error( self::FAILED_VERIFICATION, __( 'This source did not verify as linking to the target, so it cannot be imported as a Webmention.', 'social-webmention-importer' ) );
		}
		if ( in_array( $record->response_type, array( 'comment', 'mention' ), true ) && '' === trim( $record->content ) ) {
			return new WP_Error( self::FAILED_VALIDATION, __( 'Response text is required for replies and mentions.', 'social-webmention-importer' ) );
		}
		if ( ! in_array( $record->response_type, self::allowed_response_types( $record->mode ), true ) ) {
			return new WP_Error( self::FAILED_VALIDATION, __( 'Unknown response type.', 'social-webmention-importer' ) );
		}
		return true;
	}

	/**
	 * Response types allowed per mode.
	 *
	 * Verified Webmentions may use any comment type the Webmention plugin
	 * has registered; curated responses are always textual comments so they
	 * never masquerade as protocol traffic.
	 *
	 * @param string $mode Import mode.
	 * @return string[]
	 */
	public static function allowed_response_types( $mode ) {
		if ( Plugin::MODE_SOCIAL_LINKBACK === $mode ) {
			return array( 'comment' );
		}

		$types = array( 'comment', 'mention', 'repost', 'like' );

		if ( function_exists( 'get_webmention_comment_type_names' ) ) {
			$registered = (array) get_webmention_comment_type_names();
			$types      = array_values( array_unique( array_merge( array( 'comment' ), array_intersect( $types, $registered ) ) ) );
		}

		return $types;
	}

	/**
	 * Import one reviewed record. Never throws; returns a per-row result.
	 *
	 * @param Preview_Record $record        Reviewed record.
	 * @param string[]       $manual_fields Fields the reviewer edited (locked on refresh).
	 * @param bool           $approve       Approve on import. Only honored for users who can
	 *                                      moderate comments; this is the "explicit reviewer
	 *                                      action" the no-auto-publish rule requires.
	 * @return array{status:string,comment_id:int,message:string}
	 */
	public static function import( Preview_Record $record, $manual_fields = array(), $approve = false ) {
		$valid = self::validate( $record );
		if ( is_wp_error( $valid ) ) {
			return array(
				'status'     => $valid->get_error_code(),
				'comment_id' => 0,
				'message'    => $valid->get_error_message(),
			);
		}

		$existing = $record->existing_comment_id ? $record->existing_comment_id : Duplicate_Detector::find_existing( $record );

		$commentdata = self::build_commentdata( $record, $manual_fields );

		if ( $existing ) {
			$result = self::update_existing( $existing, $record, $commentdata, $manual_fields );
			if ( self::UPDATED === $result['status'] ) {
				self::maybe_approve( $result['comment_id'], $approve );
			}
			return $result;
		}

		// Force pending unless the site setting allows reviewer approval.
		$force_pending = ! get_option( Plugin::OPTION_ALLOW_APPROVE );
		$approve_cb    = function ( $approved ) use ( $force_pending ) {
			return $force_pending ? 0 : $approved;
		};
		add_filter( 'pre_comment_approved', $approve_cb, 999 );

		// Batch imports arrive faster than human commenters; core's flood
		// throttle would reject row two. The Webmention receiver does the
		// same dance around its insert.
		remove_action( 'check_comment_flood', 'check_comment_flood_db', 10 );

		// Imported content is sanitized by build_commentdata(); run the rest
		// of core's checks (moderation, spam hooks) normally.
		$comment_id = wp_new_comment( $commentdata, true );

		add_action( 'check_comment_flood', 'check_comment_flood_db', 10, 4 );
		remove_filter( 'pre_comment_approved', $approve_cb, 999 );

		if ( is_wp_error( $comment_id ) ) {
			return array(
				'status'     => self::FAILED_INSERTION,
				'comment_id' => 0,
				'message'    => $comment_id->get_error_message(),
			);
		}

		$approved = self::maybe_approve( (int) $comment_id, $approve );

		return array(
			'status'     => self::IMPORTED,
			'comment_id' => (int) $comment_id,
			'message'    => $approved
				? __( 'Imported and approved.', 'social-webmention-importer' )
				: __( 'Imported (pending moderation).', 'social-webmention-importer' ),
		);
	}

	/**
	 * Approve a freshly imported/updated comment when the reviewer asked
	 * for it and is allowed to moderate.
	 *
	 * @param int  $comment_id Comment ID.
	 * @param bool $approve    Whether the reviewer requested approval.
	 * @return bool Whether the comment was approved.
	 */
	protected static function maybe_approve( $comment_id, $approve ) {
		if ( ! $approve || ! $comment_id || ! current_user_can( 'moderate_comments' ) ) {
			return false;
		}
		return (bool) wp_set_comment_status( $comment_id, 'approve' );
	}

	/**
	 * Build the full commentdata array (including comment_meta) for a record.
	 *
	 * @param Preview_Record $record        Reviewed record.
	 * @param string[]       $manual_fields Reviewer-edited field names.
	 * @return array
	 */
	public static function build_commentdata( Preview_Record $record, $manual_fields = array() ) {
		$published_gmt = $record->published_gmt && strtotime( $record->published_gmt . ' UTC' )
			? $record->published_gmt
			: current_time( 'mysql', true );

		$content = wp_kses( $record->content, self::allowed_content_tags() );

		$meta = array(
			'protocol'                   => Plugin::MODE_WEBMENTION === $record->mode ? 'webmention' : 'social-linkback',
			'url'                        => esc_url_raw( $record->canonical_url ? $record->canonical_url : $record->source_url ),
			'_swi_provider'              => $record->provider,
			'_swi_remote_id'             => $record->remote_id,
			'_swi_import_mode'           => $record->mode,
			'_swi_imported_at_gmt'       => current_time( 'mysql', true ),
			'_swi_last_checked_at_gmt'   => current_time( 'mysql', true ),
			'_swi_original_source_url'   => esc_url_raw( $record->source_url ),
			'_swi_normalized_source_key' => Duplicate_Detector::normalized_key( $record ),
			'_swi_extraction_method'     => $record->extraction_method,
			'_swi_extraction_confidence' => Preview_Record::CONFIDENCE[ $record->extraction_method ] ?? 0,
			'_swi_target_verified'       => 'verified' === $record->verification,
			'_swi_manual_fields'         => array_values( array_map( 'sanitize_key', $manual_fields ) ),
			'_swi_snapshot_hash'         => self::snapshot_hash( $record ),
		);

		if ( Plugin::MODE_WEBMENTION === $record->mode ) {
			$meta['webmention_source_url']    = esc_url_raw( $record->source_url );
			$meta['webmention_target_url']    = esc_url_raw( get_permalink( $record->post_id ) );
			$meta['webmention_last_modified'] = current_time( 'mysql', true );
		}

		if ( '' !== $record->avatar ) {
			$meta['avatar'] = is_numeric( $record->avatar ) ? (int) $record->avatar : esc_url_raw( $record->avatar );
		}

		return array(
			'comment_post_ID'      => $record->post_id,
			'comment_author'       => sanitize_text_field( $record->author_name ),
			'comment_author_email' => '',
			'comment_author_url'   => esc_url_raw( $record->author_url ),
			'comment_content'      => $content,
			'comment_type'         => Plugin::MODE_SOCIAL_LINKBACK === $record->mode ? 'comment' : $record->response_type,
			'comment_parent'       => 0,
			'user_id'              => 0,
			'comment_date'         => get_date_from_gmt( $published_gmt ),
			'comment_date_gmt'     => $published_gmt,
			'comment_agent'        => 'Social Webmention Importer/' . SWI_VERSION,
			'comment_meta'         => $meta,
		);
	}

	/**
	 * Update an existing comment without duplicating it.
	 *
	 * Keeps the comment ID and moderation state; honors reviewer-locked
	 * fields recorded in `_swi_manual_fields`; refreshes the snapshot hash
	 * and check time.
	 *
	 * @param int            $comment_id    Existing comment ID.
	 * @param Preview_Record $record        Reviewed record.
	 * @param array          $commentdata   Fresh commentdata from build_commentdata().
	 * @param string[]       $manual_fields Fields edited in this review session.
	 * @return array{status:string,comment_id:int,message:string}
	 */
	protected static function update_existing( $comment_id, Preview_Record $record, $commentdata, $manual_fields ) {
		$existing = get_comment( $comment_id );
		if ( ! $existing ) {
			return array(
				'status'     => self::FAILED_INSERTION,
				'comment_id' => 0,
				'message'    => __( 'The existing comment disappeared mid-import.', 'social-webmention-importer' ),
			);
		}

		$previously_locked = (array) get_comment_meta( $comment_id, '_swi_manual_fields', true );
		$locked            = array_diff( $previously_locked, $manual_fields );

		// Field → commentdata key map for lockable comment fields.
		$lockable = array(
			'author_name'   => 'comment_author',
			'author_url'    => 'comment_author_url',
			'content'       => 'comment_content',
			'published_gmt' => 'comment_date_gmt',
		);

		$update = array(
			'comment_ID' => $comment_id,
		);

		foreach ( $lockable as $field => $key ) {
			if ( in_array( $field, $locked, true ) ) {
				continue;
				// Reviewer-locked from an earlier session; parser output never overwrites it.
			}
			$update[ $key ] = $commentdata[ $key ];
		}
		if ( isset( $update['comment_date_gmt'] ) ) {
			$update['comment_date'] = get_date_from_gmt( $update['comment_date_gmt'] );
		}

		$result = wp_update_comment( $update, true );
		if ( is_wp_error( $result ) ) {
			return array(
				'status'     => self::FAILED_INSERTION,
				'comment_id' => $comment_id,
				'message'    => $result->get_error_message(),
			);
		}

		// Meta: avatar is lockable; provenance always refreshes.
		$meta = $commentdata['comment_meta'];
		if ( in_array( 'avatar', $locked, true ) ) {
			unset( $meta['avatar'] );
		}
		unset( $meta['_swi_imported_at_gmt'] );
		// First-import timestamp is preserved.
		$meta['_swi_manual_fields'] = array_values( array_unique( array_merge( $previously_locked, $manual_fields ) ) );

		foreach ( $meta as $key => $value ) {
			update_comment_meta( $comment_id, $key, $value );
		}

		return array(
			'status'     => self::UPDATED,
			'comment_id' => $comment_id,
			'message'    => __( 'Existing response updated.', 'social-webmention-importer' ),
		);
	}

	/**
	 * Hash of the public-facing snapshot, for cheap change detection on refresh.
	 *
	 * @param Preview_Record $record Record.
	 * @return string
	 */
	public static function snapshot_hash( Preview_Record $record ) {
		return md5( wp_json_encode( array( $record->author_name, $record->author_url, $record->content, $record->avatar, $record->published_gmt ) ) );
	}

	/**
	 * Allowed tags for imported response content.
	 *
	 * Core's comment policy ('data' context) minus obscure tags, plus the
	 * paragraph/line-break structure social posts actually use. Scripts,
	 * forms, embeds, iframes, and event handlers are all outside this list
	 * and get stripped.
	 *
	 * @return array
	 */
	public static function allowed_content_tags() {
		return array(
			'a'          => array(
				'href' => true,
				'rel'  => true,
			),
			'p'          => array(),
			'br'         => array(),
			'em'         => array(),
			'strong'     => array(),
			'blockquote' => array( 'cite' => true ),
			'code'       => array(),
		);
	}
}
