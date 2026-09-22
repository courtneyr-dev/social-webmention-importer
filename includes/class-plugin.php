<?php
/**
 * Plugin bootstrap: dependency checks, service wiring, hook registration.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CourtneyRDev\SocialWebmentionImporter\Admin\Import_Controller;
use CourtneyRDev\SocialWebmentionImporter\Admin\Import_Page;
use CourtneyRDev\SocialWebmentionImporter\Display\Attribution;
use CourtneyRDev\SocialWebmentionImporter\Import\Comment_Importer;

/**
 * Boots the plugin once all plugins are loaded.
 *
 * The importer refuses to expose any UI or actions unless the Webmention
 * plugin is active, because Mode A storage relies on its comment schema
 * and its avatar display path.
 */
class Plugin {

	/**
	 * Import mode value for a spec-compliant, verified Webmention.
	 *
	 * @var string
	 */
	const MODE_WEBMENTION = 'webmention';

	/**
	 * Import mode value for a manually curated social response whose source
	 * does not link to the target article.
	 *
	 * @var string
	 */
	const MODE_SOCIAL_LINKBACK = 'social-linkback';

	/**
	 * Option name that allows imports to skip pending moderation.
	 *
	 * @var string
	 */
	const OPTION_ALLOW_APPROVE = 'swi_allow_reviewer_approve';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( ! self::dependency_active() ) {
			add_action( 'admin_notices', array( static::class, 'dependency_notice' ) );
			return;
		}

		Comment_Importer::register_meta();
		Attribution::init();

		if ( is_admin() ) {
			Import_Page::init();
			Import_Controller::init();
		}
	}

	/**
	 * Whether the Webmention plugin is active.
	 *
	 * Checked through a class it has loaded since 5.x rather than
	 * `is_plugin_active()`, so the check also works in wp-cli and cron.
	 *
	 * @return bool
	 */
	public static function dependency_active() {
		return class_exists( '\Webmention\Receiver' );
	}

	/**
	 * Admin notice shown when the Webmention plugin is missing or inactive.
	 */
	public static function dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Social Webmention Importer requires the Webmention plugin to be installed and active. The importer is disabled until it is.', 'social-webmention-importer' )
		);
	}

	/**
	 * Capability check shared by every importer action: the user must be
	 * able to moderate comments AND edit the specific target post.
	 *
	 * @param int $post_id Target post ID (0 to check the screen-level capability only).
	 * @return bool
	 */
	public static function user_can_import( $post_id = 0 ) {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return false;
		}
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Maximum number of URLs accepted per batch.
	 *
	 * @return int
	 */
	public static function batch_limit() {
		/**
		 * Filters the maximum number of URLs the importer accepts per batch.
		 *
		 * @param int $limit Default 25.
		 */
		return (int) apply_filters( 'swi_batch_limit', 25 );
	}
}
