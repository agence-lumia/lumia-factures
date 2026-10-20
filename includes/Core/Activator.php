<?php
/**
 * Plugin activation.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation.
 *
 * Each module declares its own defaults through ::get_defaults().
 */
class Activator {

	/**
	 * Built-in module classes: the ONLY list, shared with uninstall.php (which
	 * does not boot the plugin and so cannot go through
	 * Modules::register_default_modules()). Add to it with every new module.
	 *
	 * @var array<string, class-string<AbstractModule>>
	 */
	public const MODULE_CLASSES = [
		'invoices' => \Lumia\Factures\Modules\Invoices\Module::class,
		'bricks'   => \Lumia\Factures\Modules\Bricks\Module::class,
	];

	/**
	 * Runs when the plugin is activated.
	 *
	 * Existing options are never overwritten: a reactivation keeps the settings.
	 */
	public static function activate(): void {
		// Initial state of each module: the locked (core) ones on, the optional ones off.
		$definitions      = Modules::default_definitions();
		$modules_defaults = [];
		foreach ( array_keys( self::MODULE_CLASSES ) as $id ) {
			$modules_defaults[ $id ] = ! empty( $definitions[ $id ]['locked'] );
		}

		$default_settings = [
			'global'  => [
				'update_channel' => 'stable',
			],
			'modules' => $modules_defaults,
		];

		// Create the global option only if it does not exist yet.
		if ( false === get_option( Settings::OPTION_KEY ) ) {
			add_option( Settings::OPTION_KEY, $default_settings );
		}

		// Let each module initialize its own options when needed.
		foreach ( self::MODULE_CLASSES as $id => $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$defaults   = $class::get_defaults();
			$option_key = 'lumia_factures_module_' . $id;

			if ( ! empty( $defaults ) && false === get_option( $option_key ) ) {
				add_option( $option_key, $defaults );
			}

			// A module that starts active (the locked core ones) never goes through
			// Modules::activate_module(), so its on_activate() runs here.
			if ( ! empty( $modules_defaults[ $id ] ) ) {
				( new $class( $id ) )->on_activate();
			}
		}
	}
}
