<?php
/**
 * Provider adapter contract.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Record;

/**
 * A provider adapter recognizes URLs for one network, normalizes them to a
 * canonical identity, and extracts response data from public metadata.
 *
 * Adapters never fetch directly: they receive a fetcher callable so tests
 * can substitute fixtures, and the preview service controls rate limiting.
 */
interface Provider {

	/**
	 * Provider slug stored in `_swi_provider` ('x', 'linkedin', 'generic').
	 *
	 * @return string
	 */
	public function slug();

	/**
	 * Whether this adapter recognizes the URL.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public function handles( $url );

	/**
	 * Normalize a recognized URL.
	 *
	 * @param string $url Absolute URL.
	 * @return array{canonical:string,remote_id:string,handle:string}
	 *     Canonical URL (for dedup and public linking), stable remote ID when
	 *     the URL carries one, and a handle candidate from the path.
	 */
	public function normalize( $url );

	/**
	 * Fill a preview record from public data, best effort.
	 *
	 * Implementations call the fetcher for whatever public endpoints they
	 * use and offer values into the record with `Preview_Record::offer()`,
	 * which enforces confidence precedence. A failed fetch must degrade to
	 * an editable record, never throw.
	 *
	 * @param Preview_Record $record  Record pre-populated with source_url, canonical_url, remote_id, handle.
	 * @param callable       $fetcher Signature of Safe_Fetcher::get() — fn( string $url, array $args = [] ): array|\WP_Error.
	 * @return void
	 */
	public function extract( Preview_Record $record, callable $fetcher );
}
