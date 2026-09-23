<?php
/**
 * Idempotent-import key generation and duplicate lookup.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the normalized source key and finds existing comments for it.
 *
 * Key shape: `{post_id}|{provider}|{remote_id or normalized canonical URL}`.
 * Because the X adapter normalizes twitter.com and x.com status URLs to one
 * canonical identity with the same remote ID, both domains produce the same
 * key and deduplicate.
 */
class Duplicate_Detector {

	/**
	 * Build the normalized source key for a record.
	 *
	 * @param Preview_Record $record Preview record (post_id, provider, remote_id, canonical_url set).
	 * @return string
	 */
	public static function normalized_key( Preview_Record $record ) {
		$identity = $record->remote_id;

		if ( '' === $identity ) {
			$identity = strtolower( untrailingslashit( preg_replace( '#^https?://(www\.)?#i', '', $record->canonical_url ) ) );
		}

		return $record->post_id . '|' . $record->provider . '|' . $identity;
	}

	/**
	 * Find an existing imported or received comment for this record.
	 *
	 * Checks, in order:
	 *  1. This plugin's own normalized key.
	 *  2. The Webmention plugin's `webmention_source_url` / `url` metas,
	 *     matched against both the pasted and canonical URL (covers a
	 *     webmention that arrived organically for the same tweet).
	 *
	 * @param Preview_Record $record Preview record.
	 * @return int Existing comment ID, or 0.
	 */
	public static function find_existing( Preview_Record $record ) {
		$args = array(
			'post_id'    => $record->post_id,
			'status'     => 'any',
			'number'     => 1,
			'fields'     => 'ids',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded lookup on one post's comments.
				array(
					'key'   => '_swi_normalized_source_key',
					'value' => self::normalized_key( $record ),
				),
			),
		);

		$found = get_comments( $args );
		if ( $found ) {
			return (int) $found[0];
		}

		$urls = array_values( array_unique( array_filter( array( $record->source_url, $record->canonical_url ) ) ) );
		if ( ! $urls ) {
			return 0;
		}

		$meta_query = array( 'relation' => 'OR' );
		foreach ( array( 'webmention_source_url', 'url', '_swi_original_source_url' ) as $key ) {
			$meta_query[] = array(
				'key'     => $key,
				'value'   => $urls,
				'compare' => 'IN',
			);
		}

		$found = get_comments(
			array(
				'post_id'    => $record->post_id,
				'status'     => 'any',
				'number'     => 1,
				'fields'     => 'ids',
				'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded lookup on one post's comments.
			)
		);

		return $found ? (int) $found[0] : 0;
	}
}
