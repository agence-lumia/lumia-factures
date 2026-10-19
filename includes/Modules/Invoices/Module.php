<?php
/**
 * Invoices module (core).
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\AbstractModule;
use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Settings\Schema;

/**
 * Core module: numbering, documents, rendering, storage and emails (lot 2).
 * Always active, it cannot be deactivated.
 */
class Module extends AbstractModule {

	/**
	 * Registers the module hooks.
	 */
	public function init(): void {
		// Called directly: init() already runs inside the `init` hook at priority 10,
		// a callback added to it now would never fire. Updating a plugin does not run
		// the activation hook, so the tables are checked on every boot.
		Installer::maybe_upgrade();
	}

	/**
	 * Creates the tables on activation.
	 */
	public function on_activate(): void {
		Installer::install();
	}

	/**
	 * Data owned by the module, removed on uninstall only when
	 * LUMIA_FACTURES_REMOVE_ALL_DATA is true. The storage folder is never deleted.
	 *
	 * @return array{options?: string[], meta?: string[], user_meta?: string[], post_type?: string[], taxonomy?: string[], tables?: string[], cron?: string[]}
	 */
	public static function get_uninstall_keys(): array {
		return [
			'options' => [ 'lumia_factures_module_invoices', Installer::OPTION ],
			'meta'    => [ '_lumia_factures_invoice_number', '_lumia_factures_invoice_date' ],
			'tables'  => [ 'lumia_factures_sequences', 'lumia_factures_documents' ],
		];
	}

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
