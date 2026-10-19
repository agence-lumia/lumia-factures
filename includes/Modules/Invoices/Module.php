<?php
/**
 * Invoices module (core).
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\AbstractModule;
use Lumia\Factures\Modules\Invoices\Settings\Schema;

/**
 * Core module: numbering, documents, rendering, storage and emails (lot 2).
 * Always active, it cannot be deactivated.
 */
class Module extends AbstractModule {

	/**
	 * Registers the module hooks.
	 */
	public function init(): void {}

	/**
	 * Default settings, keyed by group.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_defaults(): array {
		return Schema::defaults();
	}

	/**
	 * Invoice settings: the stored values merged recursively over the defaults.
	 * Every other class of the module reads its settings through this helper.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		$stored = get_option( 'lumia_factures_module_invoices', [] );

		return self::merge_defaults( Schema::defaults(), is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Sanitizes and saves the settings posted by the form.
	 *
	 * An invalid number format is replaced by the current one; the errors are
	 * kept 60 seconds in a transient for the admin notice.
	 *
	 * @param array<string, mixed> $settings Raw payload (unslashed).
	 */
	public function save_settings( array $settings ): bool {
		$result = Schema::sanitize( $settings, self::settings() );

		if ( [] !== $result['errors'] ) {
			set_transient( 'lumia_factures_invoices_errors', $result['errors'], MINUTE_IN_SECONDS );
		} else {
			delete_transient( 'lumia_factures_invoices_errors' );
		}

		return $this->save_module_settings( $result['settings'] );
	}
}
