<?php
/**
 * Minimal stand-in for lumia-tools v2.2.2, active next to lumia-factures (the Neonat case).
 *
 * Declares what the real plugin puts in the shared namespaces: the LUMIA_VERSION
 * constant, the Lumia\Tools\Core\Plugin class, the `lumia-tools` top-level menu,
 * the `lumia_settings` option, the `admin_post_lumia_save_settings` action and
 * its asset handles (`lumia-admin` plus the real ones of lumia-tools' Admin).
 *
 * @package Lumia\Factures
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- reproduces another plugin's globals on purpose.

namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LUMIA_VERSION' ) ) {
	define( 'LUMIA_VERSION', '2.2.2' );
}

/**
 * Stub of the lumia-tools main class.
 */
class Plugin {

	public const OPTION = 'lumia_settings';

	public const MENU_SLUG = 'lumia-tools';

	/**
	 * Asset handles registered by lumia-tools.
	 *
	 * @var string[]
	 */
	public const HANDLES = [
		'lumia-admin',
		'lumia-tokens-css',
		'lumia-reset-css',
		'lumia-layout-css',
		'lumia-sidebar-css',
		'lumia-components-css',
		'lumia-buttons-css',
		'lumia-admin-js',
		'lumia-notifications-css',
		'lumia-notifications-js',
	];

	/**
	 * Registers the stub hooks.
	 */
	public static function boot(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_menu_page( 'Lumia Tools', 'Lumia Tools', 'manage_options', self::MENU_SLUG, '__return_null' );
			}
		);
		// Like lumia-tools, the notification assets load on the whole admin.
		add_action(
			'admin_enqueue_scripts',
			static function (): void {
				foreach ( self::HANDLES as $handle ) {
					if ( str_ends_with( $handle, '-js' ) ) {
						wp_enqueue_script( $handle, 'https://example.org/' . $handle . '.js', [], LUMIA_VERSION, true );
					} else {
						wp_enqueue_style( $handle, 'https://example.org/' . $handle . '.css', [], LUMIA_VERSION );
					}
				}
			}
		);
		add_action( 'admin_post_lumia_save_settings', '__return_null' );
	}
}

Plugin::boot();
