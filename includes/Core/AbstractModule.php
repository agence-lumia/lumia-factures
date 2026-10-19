<?php
/**
 * Common base of the modules.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Common base for the Lümia Factures modules.
 *
 * Provides: option handling, empty admin assets by default, lifecycle
 * hooks and static methods for install/uninstall.
 */
abstract class AbstractModule implements ModuleInterface {

	/**
	 * Unique module identifier (e.g. "invoices").
	 */
	protected string $id;

	/**
	 * Constructor: receives the module ID from the registry.
	 *
	 * @param string $id Module identifier.
	 */
	public function __construct( string $id ) {
		$this->id = $id;
	}

	/* ================================================================
	 * OPTIONS
	 * ================================================================ */

	/**
	 * WordPress option key for this module.
	 */
	protected function get_module_option_key(): string {
		return 'lumia_factures_module_' . $this->id;
	}

	/**
	 * Returns the module settings (stored values merged over the defaults).
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->get_module_settings( static::get_defaults() );
	}

	/**
	 * Saves the module settings.
	 *
	 * The base implementation keeps only the keys declared by get_defaults()
	 * and coerces each value to the type of its default. A module with richer
	 * settings overrides this method.
	 *
	 * @param array<string, mixed> $settings Settings to store (raw form payload).
	 */
	public function save_settings( array $settings ): bool {
		return $this->save_module_settings( self::sanitize_against_defaults( static::get_defaults(), $settings ) );
	}

	/**
	 * Keeps the keys known by $defaults and coerces values to the default's type.
	 *
	 * Associative arrays are descended into; lists keep their scalar entries,
	 * sanitized as text.
	 *
	 * @param array<mixed> $defaults Reference values.
	 * @param array<mixed> $input    Raw values.
	 * @return array<mixed>
	 */
	protected static function sanitize_against_defaults( array $defaults, array $input ): array {
		$clean = [];

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = $input[ $key ];

			if ( is_array( $default ) ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
				$clean[ $key ] = self::is_list( $default )
					? array_values( array_map( 'sanitize_text_field', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) )
					: self::sanitize_against_defaults( $default, $value );
			} elseif ( is_bool( $default ) ) {
				$clean[ $key ] = ! empty( $value );
			} elseif ( is_int( $default ) ) {
				$clean[ $key ] = is_scalar( $value ) ? (int) $value : $default;
			} elseif ( is_float( $default ) ) {
				$clean[ $key ] = is_scalar( $value ) ? (float) $value : $default;
			} else {
				$clean[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $default;
			}
		}

		return $clean;
	}

	/**
	 * Reads the module settings from the database, merged with the defaults.
	 *
	 * The merge is RECURSIVE. wp_parse_args() only merges the first level: as
	 * soon as a key exists in the database, its value replaces the default as a
	 * whole. For modules with nested settings, any sub-key added in a later
	 * version would therefore be missing from existing installs until the user
	 * reopens the screen and saves again.
	 *
	 * @param array<string, mixed> $defaults Default values to apply.
	 * @return array<string, mixed>
	 */
	protected function get_module_settings( array $defaults = [] ): array {
		$stored = get_option( $this->get_module_option_key(), [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return empty( $defaults ) ? $stored : self::merge_defaults( $defaults, $stored );
	}

	/**
	 * Merges the stored settings over the default values.
	 *
	 * Only ASSOCIATIVE arrays are descended into: a list must be replaced as a
	 * whole, never merged index by index — otherwise removing an entry would be
	 * impossible, the default value coming back at its position.
	 *
	 * @param array<string, mixed> $defaults Reference values.
	 * @param array<string, mixed> $stored   Values read from the database.
	 * @return array<string, mixed>
	 */
	protected static function merge_defaults( array $defaults, array $stored ): array {
		$merged = $defaults;

		foreach ( $stored as $key => $value ) {
			$default = $defaults[ $key ] ?? null;

			$merged[ $key ] = ( is_array( $value ) && is_array( $default ) && ! self::is_list( $default ) )
				? self::merge_defaults( $default, $value )
				: $value;
		}

		return $merged;
	}

	/**
	 * True for an array with consecutive numeric keys (or an empty one).
	 *
	 * @param array<mixed> $value Array to test.
	 */
	private static function is_list( array $value ): bool {
		return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Saves the module settings to the database.
	 *
	 * @param array<string, mixed> $data Settings to store.
	 */
	protected function save_module_settings( array $data ): bool {
		return update_option( $this->get_module_option_key(), $data );
	}

	/**
	 * Converts STORED settings into a FORM payload.
	 *
	 * save_settings() is written for what the settings screen posts. For most
	 * modules this shape matches the stored one, and the identity is enough.
	 * A module whose stored shape differs from its form overrides this method.
	 *
	 * Used by the configuration import: an imported file must go through
	 * exactly the form's sanitization path, never a second one.
	 *
	 * @param array<string, mixed> $stored Settings as they are in the database.
	 * @return array<string, mixed>
	 */
	public function to_form_payload( array $stored ): array {
		return $stored;
	}

	/**
	 * Module data to attach to the configuration export, IN ADDITION to its
	 * `lumia_factures_module_{id}` option.
	 *
	 * A module that keeps part of its state in an option of its own must
	 * declare it here, otherwise it is missing from the export.
	 *
	 * @return array<string, mixed> Empty = nothing to export beyond the settings.
	 */
	public function get_export_extras(): array {
		return [];
	}

	/**
	 * Re-imports what get_export_extras() produced.
	 *
	 * Same rule as for the settings: the file content must never land in the
	 * database as is — it goes back through the module's sanitizer.
	 *
	 * @param array<string, mixed> $extras Block read from the imported file.
	 */
	public function import_extras( array $extras ): void {
		// Nothing by default.
	}

	/* ================================================================
	 * ASSETS (empty defaults)
	 * ================================================================ */

	/**
	 * Admin CSS URLs of the module.
	 *
	 * @return string[]
	 */
	public function get_admin_css(): array {
		return [];
	}

	/**
	 * Admin JS URLs of the module.
	 *
	 * @return string[]
	 */
	public function get_admin_js(): array {
		return [];
	}

	/**
	 * Already registered script handles the module's JS depends on.
	 *
	 * @return string[]
	 */
	public function get_admin_js_deps(): array {
		return [];
	}

	/**
	 * JS data of the module.
	 *
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [];
	}

	/* ================================================================
	 * LIFECYCLE
	 * ================================================================ */

	/**
	 * Called when the module is activated.
	 * Override to create tables, schedule crons, etc.
	 */
	public function on_activate(): void {}

	/**
	 * Called when the module is deactivated.
	 * Override to clean up crons, etc.
	 */
	public function on_deactivate(): void {}

	/* ================================================================
	 * REQUIRED CAPABILITY
	 * ================================================================ */

	/**
	 * Capability required to open the module screen and call its endpoints.
	 *
	 * `manage_woocommerce`, like the rest of the plugin: shop managers handle
	 * the invoices.
	 */
	public static function get_required_capability(): string {
		return 'manage_woocommerce';
	}

	/* ================================================================
	 * INSTALL / UNINSTALL
	 * ================================================================ */

	/**
	 * Default values of the module options (created when the plugin is activated).
	 * Return [] if the defaults are handled on the fly in get_settings().
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [];
	}

	/**
	 * Keys to delete on uninstall (only when LUMIA_FACTURES_REMOVE_ALL_DATA is true).
	 *
	 * `meta` designates post meta and `user_meta` user metadata: they are two
	 * distinct tables, and a key filed in the wrong one is never deleted.
	 *
	 * All the keys are optional: uninstall.php reads each with `?? []`.
	 * `post_type` and `taxonomy` trigger the deletion of the matching contents
	 * and terms. `tables` lists tables owned by the module, WITHOUT a prefix
	 * (dropped with DROP TABLE), `cron` lists scheduled task hooks — also
	 * unscheduled when the plugin is deactivated.
	 *
	 * @return array{options?: string[], meta?: string[], user_meta?: string[], post_type?: string[], taxonomy?: string[], tables?: string[], cron?: string[]}
	 */
	public static function get_uninstall_keys(): array {
		return [
			'options'   => [],
			'meta'      => [],
			'user_meta' => [],
		];
	}
}
