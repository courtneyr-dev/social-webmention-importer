<?php
/**
 * Plugin Name:       Social Webmention Importer
 * Plugin URI:        https://github.com/courtneyr-dev/social-webmention-importer
 * Description:       Companion to the Webmention plugin. Paste public X/Twitter and LinkedIn post URLs, review the extracted author, avatar, and text, and import the responses into a post's conversation.
 * Version:           0.5.2
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Requires Plugins:  webmention
 * Author:            Courtney Robertson
 * Author URI:        https://courtneyr.dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-webmention-importer
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWI_VERSION', '0.5.2' );
define( 'SWI_PLUGIN_FILE', __FILE__ );
define( 'SWI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload plugin classes.
 *
 * Maps `CourtneyRDev\SocialWebmentionImporter\Admin\Import_Page` to
 * `includes/admin/class-import-page.php` (WordPress Coding Standards
 * file naming), lower-casing namespace segments into directories.
 *
 * @param string $class_name Fully qualified class name.
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$class    = array_pop( $parts );
		$path     = strtolower( implode( '/', $parts ) );
		$file     = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		$fullpath = SWI_PLUGIN_DIR . 'includes/' . ( $path ? $path . '/' : '' ) . $file;

		// Interfaces use the `interface-` prefix.
		if ( ! file_exists( $fullpath ) ) {
			$fullpath = SWI_PLUGIN_DIR . 'includes/' . ( $path ? $path . '/' : '' ) . 'interface-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		}

		if ( file_exists( $fullpath ) ) {
			require $fullpath;
		}
	}
);

add_action( 'plugins_loaded', array( Plugin::class, 'init' ) );
