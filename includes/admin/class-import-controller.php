<?php
/**
 * Admin actions: preview, import, settings, refresh, post search.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CourtneyRDev\SocialWebmentionImporter\Import\Comment_Importer;
use CourtneyRDev\SocialWebmentionImporter\Import\Identity_Store;
use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Record;
use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Service;
use CourtneyRDev\SocialWebmentionImporter\Plugin;

/**
 * Handles every state-changing importer request.
 *
 * All actions require the `moderate_comments` capability plus edit rights
 * on the target post, and a per-action nonce. Nonces are CSRF protection
 * only — capability checks are the authorization layer.
 */
class Import_Controller {

	/**
	 * Transient TTL for preview batches and result reports.
	 *
	 * @var int
	 */
	const BATCH_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_post_swi_preview', array( static::class, 'handle_preview' ) );
		add_action( 'admin_post_swi_import', array( static::class, 'handle_import' ) );
		add_action( 'admin_post_swi_settings', array( static::class, 'handle_settings' ) );
		add_action( 'admin_post_swi_refresh', array( static::class, 'handle_refresh' ) );
		add_action( 'wp_ajax_swi_post_search', array( static::class, 'handle_post_search' ) );

		add_filter( 'comment_row_actions', array( static::class, 'comment_row_actions' ), 10, 2 );
		add_filter( 'post_row_actions', array( static::class, 'post_row_actions' ), 10, 2 );
		add_action( 'post_submitbox_misc_actions', array( static::class, 'submitbox_link' ) );
	}

	/**
	 * Transient name for a batch token, scoped to the current user.
	 *
	 * @param string $token Random token.
	 * @return string
	 */
	protected static function batch_key( $token ) {
		return 'swi_batch_' . get_current_user_id() . '_' . sanitize_key( $token );
	}

	/**
	 * Die with a permission error unless the current user may import to the post.
	 *
	 * @param int $post_id Target post ID.
	 */
	protected static function authorize_or_die( $post_id ) {
		if ( ! Plugin::user_can_import( $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to import social responses for this post.', 'social-webmention-importer' ), 403 );
		}
	}

	/**
	 * Step 1 → 2: fetch and parse the pasted URLs.
	 */
	public static function handle_preview() {
		check_admin_referer( 'swi_preview' );

		$post_id = isset( $_POST['swi_post_id'] ) ? absint( $_POST['swi_post_id'] ) : 0;
		self::authorize_or_die( $post_id );

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_die( esc_html__( 'Choose a target post first.', 'social-webmention-importer' ), 400 );
		}

		$raw = isset( $_POST['swi_urls'] ) ? wp_unslash( $_POST['swi_urls'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL list is validated per line below.

		$parsed = Preview_Service::parse_url_list( $raw );
		if ( ! $parsed['urls'] ) {
			wp_die( esc_html__( 'Paste at least one URL.', 'social-webmention-importer' ), 400 );
		}

		$service = new Preview_Service();
		$records = $service->preview_batch( $parsed['urls'], $post_id );

		$token = wp_generate_password( 12, false );
		set_transient(
			self::batch_key( $token ),
			array(
				'post_id'   => $post_id,
				'records'   => array_map( fn( $r ) => $r->to_array(), $records ),
				'truncated' => $parsed['truncated'],
			),
			self::BATCH_TTL
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'social-webmention-importer',
					'swi_batch' => $token,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Step 2 → 3: import the selected, possibly edited rows.
	 */
	public static function handle_import() {
		check_admin_referer( 'swi_import' );

		$token = isset( $_POST['swi_batch'] ) ? sanitize_key( $_POST['swi_batch'] ) : '';
		$batch = get_transient( self::batch_key( $token ) );

		if ( ! is_array( $batch ) ) {
			wp_die( esc_html__( 'This preview expired. Paste the URLs again.', 'social-webmention-importer' ), 400 );
		}

		$post_id = (int) $batch['post_id'];
		self::authorize_or_die( $post_id );

		$rows    = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized in apply_reviewer_edits().
		$results = array();

		// Explicit reviewer choice to publish on import (never a default).
		$approve_now = ! empty( $_POST['swi_approve_now'] );

		foreach ( $batch['records'] as $index => $stored ) {
			$record = Preview_Record::from_array( $stored );
			$row    = $rows[ $index ] ?? array();

			if ( empty( $row['import'] ) ) {
				$results[] = array(
					'source_url' => $record->source_url,
					'status'     => Comment_Importer::SKIPPED_BY_REVIEWER,
					'comment_id' => 0,
					'message'    => __( 'Skipped by reviewer.', 'social-webmention-importer' ),
				);
				continue;
			}

			if ( '' !== $record->error ) {
				$results[] = array(
					'source_url' => $record->source_url,
					'status'     => Comment_Importer::FAILED_FETCH,
					'comment_id' => 0,
					'message'    => $record->error,
				);
				continue;
			}

			$manual_fields = self::apply_reviewer_edits( $record, $row );

			// Mode B requires the reviewer to explicitly confirm the curated import.
			if ( Plugin::MODE_SOCIAL_LINKBACK === $record->mode && empty( $row['confirm_curated'] ) ) {
				$results[] = array(
					'source_url' => $record->source_url,
					'status'     => Comment_Importer::FAILED_VALIDATION,
					'comment_id' => 0,
					'message'    => __( 'Unverified sources need the curated-response confirmation checked.', 'social-webmention-importer' ),
				);
				continue;
			}

			$result               = Comment_Importer::import( $record, $manual_fields, $approve_now );
			$result['source_url'] = $record->source_url;
			$results[]            = $result;

			// Optionally remember the confirmed identity for future imports.
			if ( ! empty( $row['remember_identity'] ) && in_array( $result['status'], array( Comment_Importer::IMPORTED, Comment_Importer::UPDATED ), true ) ) {
				Identity_Store::save(
					$record->provider,
					array(
						'handle' => $record->author_handle,
						'name'   => $record->author_name,
						'url'    => $record->author_url,
						'avatar' => $record->avatar,
					)
				);
			}
		}//end foreach

		delete_transient( self::batch_key( $token ) );

		$results_token = wp_generate_password( 12, false );
		set_transient(
			self::batch_key( 'results_' . $results_token ),
			array(
				'post_id' => $post_id,
				'results' => $results,
			),
			self::BATCH_TTL
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'social-webmention-importer',
					'swi_results' => $results_token,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Overlay reviewer edits onto a stored record, tracking edited fields.
	 *
	 * @param Preview_Record       $record Stored preview record (modified in place).
	 * @param array<string,string> $row    Posted row values.
	 * @return string[] Names of fields the reviewer changed.
	 */
	public static function apply_reviewer_edits( Preview_Record $record, $row ) {
		$manual = array();

		$editable = array(
			'author_name'                   => 'sanitize_text_field',
			'author_handle'                 => 'sanitize_text_field',
			'author_url'                    => 'esc_url_raw',
			'avatar'                        => null,
			// URL or attachment ID; handled below.
							'content'       => null,
			// Sanitized against the comment policy at import.
							'published_gmt' => 'sanitize_text_field',
			'response_type'                 => 'sanitize_key',
			'mode'                          => 'sanitize_key',
		);

		foreach ( $editable as $field => $sanitizer ) {
			if ( ! array_key_exists( $field, $row ) ) {
				continue;
			}

			$value = $row[ $field ];
			if ( 'avatar' === $field ) {
				$value = is_numeric( $value ) ? (string) (int) $value : esc_url_raw( trim( (string) $value ) );
			} elseif ( 'content' === $field ) {
				$value = trim( (string) $value );
			} else {
				$value = call_user_func( $sanitizer, $value );
			}

			if ( (string) $value !== (string) $record->{$field} ) {
				$record->{$field}             = $value;
				$record->extraction[ $field ] = 'manual';
				$manual[]                     = $field;
			}
		}

		// A reviewer cannot upgrade an unverified row to Webmention mode.
		if ( Plugin::MODE_WEBMENTION === $record->mode && 'verified' !== $record->verification ) {
			$record->mode = Plugin::MODE_SOCIAL_LINKBACK;
		}

		if ( $manual ) {
			$record->extraction_method = 'manual';
		}

		return $manual;
	}

	/**
	 * Save the tools-screen settings (auto-approve permission).
	 */
	public static function handle_settings() {
		check_admin_referer( 'swi_settings' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change importer settings.', 'social-webmention-importer' ), 403 );
		}

		update_option( Plugin::OPTION_ALLOW_APPROVE, ! empty( $_POST['swi_allow_approve'] ) ? 1 : 0 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'social-webmention-importer',
					'swi_saved' => 1,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Manual refresh of one imported comment from its public source.
	 *
	 * Re-fetches, honors reviewer-locked fields, and handles remote
	 * deletion: a 404/410/gone source unpublishes the comment (pending)
	 * and notifies an administrator instead of leaving it public.
	 */
	public static function handle_refresh() {
		$comment_id = isset( $_GET['comment_id'] ) ? absint( $_GET['comment_id'] ) : 0;
		check_admin_referer( 'swi_refresh_' . $comment_id );

		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			wp_die( esc_html__( 'Unknown comment.', 'social-webmention-importer' ), 404 );
		}

		self::authorize_or_die( (int) $comment->comment_post_ID );

		$source = get_comment_meta( $comment_id, '_swi_original_source_url', true );
		if ( ! $source ) {
			wp_die( esc_html__( 'This comment was not imported by Social Webmention Importer.', 'social-webmention-importer' ), 400 );
		}

		$service = new Preview_Service();
		$record  = $service->preview_url( $source, (int) $comment->comment_post_ID );

		update_comment_meta( $comment_id, '_swi_last_checked_at_gmt', current_time( 'mysql', true ) );

		// Remote deletion / privatization: unpublish and alert, never silently keep.
		if ( '' !== $record->error || ( 'unknown' === $record->verification && '' === $record->author_name && '' === $record->content ) ) {
			wp_set_comment_status( $comment_id, 'hold' );

			$post_title = get_the_title( $comment->comment_post_ID );
			wp_mail(
				get_option( 'admin_email' ),
				__( 'Imported social response needs review', 'social-webmention-importer' ),
				sprintf(
					/* translators: 1: source URL, 2: post title. */
					__( 'The source %1$s for a response on “%2$s” is gone or no longer public. The comment was set to pending for review.', 'social-webmention-importer' ),
					$source,
					$post_title
				)
			);

			wp_safe_redirect( add_query_arg( 'swi_refreshed', 'gone', wp_get_referer() ?: admin_url( 'edit-comments.php' ) ) );
			exit;
		}

		// Preserve the stored mode; a refresh never flips curated → webmention.
		$record->mode                = get_comment_meta( $comment_id, '_swi_import_mode', true ) ?: $record->mode;
		$record->existing_comment_id = $comment_id;

		$result = Comment_Importer::import( $record, array() );

		wp_safe_redirect( add_query_arg( 'swi_refreshed', $result['status'], wp_get_referer() ?: admin_url( 'edit-comments.php' ) ) );
		exit;
	}

	/**
	 * AJAX post search for the target selector.
	 *
	 * Returns id/title/url triples for posts the user can edit, restricted
	 * to webmention-enabled post types.
	 */
	public static function handle_post_search() {
		check_ajax_referer( 'swi_post_search' );

		if ( ! Plugin::user_can_import() ) {
			wp_send_json_error( null, 403 );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		$post_types = function_exists( 'get_post_types_by_support' )
			? get_post_types_by_support( 'webmentions' )
			: array( 'post', 'page' );
		if ( ! $post_types ) {
			$post_types = array( 'post', 'page' );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				's'              => $term,
				'posts_per_page' => 20,
				'orderby'        => $term ? 'relevance' : 'date',
				'no_found_rows'  => true,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'url'   => get_permalink( $post ),
			);
		}

		wp_send_json_success( $items );
	}

	/**
	 * Comment-list row actions for imported responses.
	 *
	 * @param string[]    $actions Existing actions.
	 * @param \WP_Comment $comment Comment.
	 * @return string[]
	 */
	public static function comment_row_actions( $actions, $comment ) {
		if ( ! get_comment_meta( $comment->comment_ID, '_swi_original_source_url', true ) ) {
			return $actions;
		}
		if ( ! Plugin::user_can_import( (int) $comment->comment_post_ID ) ) {
			return $actions;
		}

		$refresh_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'swi_refresh',
					'comment_id' => $comment->comment_ID,
				),
				admin_url( 'admin-post.php' )
			),
			'swi_refresh_' . $comment->comment_ID
		);

		$edit_url = add_query_arg(
			array(
				'page'        => 'social-webmention-importer',
				'swi_post'    => $comment->comment_post_ID,
				'swi_prefill' => rawurlencode( get_comment_meta( $comment->comment_ID, '_swi_original_source_url', true ) ),
			),
			admin_url( 'tools.php' )
		);

		$actions['swi_refresh'] = sprintf( '<a href="%s">%s</a>', esc_url( $refresh_url ), esc_html__( 'Refresh social metadata', 'social-webmention-importer' ) );
		$actions['swi_edit']    = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit import details', 'social-webmention-importer' ) );

		return $actions;
	}

	/**
	 * "Import social responses" link on the post list.
	 *
	 * @param string[] $actions Existing actions.
	 * @param \WP_Post $post    Post.
	 * @return string[]
	 */
	public static function post_row_actions( $actions, $post ) {
		if ( 'publish' === $post->post_status && Plugin::user_can_import( $post->ID ) && post_type_supports( $post->post_type, 'webmentions' ) ) {
			$actions['swi_import'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::importer_url_for_post( $post->ID ) ),
				esc_html__( 'Import social responses', 'social-webmention-importer' )
			);
		}
		return $actions;
	}

	/**
	 * Link in the classic-editor publish box.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public static function submitbox_link( $post ) {
		if ( 'publish' !== $post->post_status || ! Plugin::user_can_import( $post->ID ) ) {
			return;
		}
		printf(
			'<div class="misc-pub-section"><a href="%s">%s</a></div>',
			esc_url( self::importer_url_for_post( $post->ID ) ),
			esc_html__( 'Import social responses', 'social-webmention-importer' )
		);
	}

	/**
	 * Importer URL with a post preselected.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function importer_url_for_post( $post_id ) {
		return add_query_arg(
			array(
				'page'     => 'social-webmention-importer',
				'swi_post' => (int) $post_id,
			),
			admin_url( 'tools.php' )
		);
	}
}
