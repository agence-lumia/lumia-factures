<?php
/**
 * Plugin deactivation.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin deactivation.
 */
class Deactivator {

	/**
	 * Runs when the plugin is deactivated.
	 *
	 * Scheduled tasks would survive deactivation: WordPress would keep firing
	 * them on a hook nobody listens to anymore. wp_unschedule_hook() removes
	 * every event of the hook, whatever its arguments (wp_clear_scheduled_hook()
	 * only removes the events scheduled without any). No data is deleted here.
	 */
	public static function deactivate(): void {
		foreach ( Activator::MODULE_CLASSES as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			foreach ( $class::get_uninstall_keys()['cron'] ?? [] as $hook ) {
				wp_unschedule_hook( $hook );
			}
		}
	}
}
