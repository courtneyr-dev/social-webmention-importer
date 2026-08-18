<?php
/**
 * Uninstall handler.
 *
 * Deliberately narrow: uninstalling removes ONLY plugin settings and
 * temporary caches. Imported comments and their metadata are content the
 * site owner curated — they are never deleted here, matching the plugin's
 * documented uninstall policy.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'swi_identities' );
delete_option( 'swi_allow_reviewer_approve' );

// Preview/report transients (bounded: they expire in 30 minutes anyway).
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup of namespaced transients.
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_swi\_batch\_%'
	    OR option_name LIKE '\_transient\_timeout\_swi\_batch\_%'"
);
