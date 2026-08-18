<?php
/**
 * Reviewer-confirmed identity cache.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Import;

/**
 * Stores reviewer-confirmed public identities in one option.
 *
 * Keyed by provider plus stable handle (lower-cased) or profile URL.
 * Only public, reviewer-confirmed data is kept: provider, handle, display
 * name, profile URL, avatar (URL or attachment ID), and the confirmation
 * timestamp. Reviewer-confirmed values always outrank parser output
 * (enforced by the 'identity-store' vs parser ranks in Preview_Record).
 */
class Identity_Store {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'swi_identities';

	/**
	 * Bound on stored identities to keep the option small.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 500;

	/**
	 * Build the store key for a provider + handle/profile-URL pair.
	 *
	 * @param string $provider Provider slug.
	 * @param string $handle   Handle without @ (may be empty).
	 * @param string $profile  Profile URL fallback when no handle exists.
	 * @return string Empty string when neither identity part exists.
	 */
	public static function key( $provider, $handle, $profile = '' ) {
		$handle = strtolower( trim( (string) $handle ) );
		if ( '' !== $handle ) {
			return $provider . ':' . $handle;
		}
		$profile = strtolower( untrailingslashit( trim( (string) $profile ) ) );
		return '' !== $profile ? $provider . ':url:' . md5( $profile ) : '';
	}

	/**
	 * Look up a confirmed identity.
	 *
	 * @param string $provider Provider slug.
	 * @param string $handle   Handle candidate.
	 * @param string $profile  Profile URL candidate.
	 * @return array{name:string,url:string,avatar:string,handle:string,confirmed_gmt:string}|null
	 */
	public static function get( $provider, $handle, $profile = '' ) {
		$key = self::key( $provider, $handle, $profile );
		if ( '' === $key ) {
			return null;
		}
		$all = get_option( self::OPTION, array() );
		return isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Save a reviewer-confirmed identity.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $identity Identity fields: handle (without the @), name
	 *                         (display name), url (public profile URL), and
	 *                         avatar (URL or attachment ID).
	 * @return bool Whether the identity was stored.
	 */
	public static function save( $provider, $identity ) {
		$key = self::key( $provider, $identity['handle'] ?? '', $identity['url'] ?? '' );
		if ( '' === $key || empty( $identity['name'] ) ) {
			return false;
		}

		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$all[ $key ] = array(
			'handle'        => sanitize_text_field( $identity['handle'] ?? '' ),
			'name'          => sanitize_text_field( $identity['name'] ),
			'url'           => esc_url_raw( $identity['url'] ?? '' ),
			'avatar'        => is_numeric( $identity['avatar'] ?? '' ) ? (string) (int) $identity['avatar'] : esc_url_raw( $identity['avatar'] ?? '' ),
			'confirmed_gmt' => current_time( 'mysql', true ),
		);

		// Evict oldest entries beyond the cap.
		if ( count( $all ) > self::MAX_ENTRIES ) {
			uasort( $all, fn( $a, $b ) => strcmp( $a['confirmed_gmt'] ?? '', $b['confirmed_gmt'] ?? '' ) );
			$all = array_slice( $all, count( $all ) - self::MAX_ENTRIES, null, true );
		}

		return update_option( self::OPTION, $all, false );
	}

	/**
	 * Apply a stored identity to a preview record.
	 *
	 * @param Preview_Record $record Record with provider + handle/author_url candidates.
	 * @return void
	 */
	public static function apply( Preview_Record $record ) {
		$identity = self::get( $record->provider, $record->author_handle, $record->author_url );
		if ( ! $identity ) {
			return;
		}

		$record->offer( 'author_name', $identity['name'], 'identity-store' );
		$record->offer( 'author_url', $identity['url'], 'identity-store' );
		$record->offer( 'avatar', $identity['avatar'], 'identity-store' );
		if ( ! empty( $identity['handle'] ) ) {
			$record->offer( 'author_handle', $identity['handle'], 'identity-store' );
		}
	}
}
