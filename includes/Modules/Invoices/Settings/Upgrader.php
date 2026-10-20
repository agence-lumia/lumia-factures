<?php
/**
 * One-time upgrade of the stored invoice settings.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Settings;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Migration\LegacySource;
use Lumia\Factures\Modules\Invoices\Migration\Migration;

/**
 * Brings the stored settings of an existing installation to the current settings version.
 *
 * Version 2 (2.1) adds the `customer` group. A fresh installation keeps its defaults (every link
 * on). An existing one (2.0, or a 1.x site) gets the My Account and order page links off: the shop
 * may already show its documents there (Neonat does, through Bricks), and two lists would appear
 * the day of the update. The e-mail links stay on. A value the user already stored is kept.
 *
 * Runs once (the marker option), before Installer: the installer records its schema version, which
 * is one of the signs of an existing installation. It writes the settings option only; the
 * register, the counters and the archive are never touched.
 */
final class Upgrader {

	/**
	 * Option holding the version of the stored settings.
	 */
	public const OPTION = 'lumia_factures_invoices_settings_version';

	/**
	 * Current settings version. 2: 2.1, the `customer` group.
	 */
	public const VERSION = 2;

	/**
	 * Option of the invoice settings.
	 */
	private const SETTINGS_OPTION = 'lumia_factures_module_invoices';

	/**
	 * Customer settings turned off on an existing installation.
	 */
	private const PAGE_LINKS = [ 'account_orders', 'order_details' ];

	/**
	 * Upgrades the stored settings when their version is older than the code's.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		if ( self::is_existing_install() ) {
			self::turn_page_links_off();
		}

		update_option( self::OPTION, self::VERSION );
	}

	/**
	 * Whether the plugin ran on this site before: a recorded schema version, an issued or reserved
	 * document, a planned or finished legacy import, or 1.x data.
	 */
	public static function is_existing_install(): bool {
		if ( false !== get_option( Installer::OPTION, false ) ) {
			return true;
		}

		if ( Installer::tables_exist() && Register::has_rows() ) {
			return true;
		}

		if ( 'none' !== Migration::state()['status'] ) {
			return true;
		}

		return LegacySource::has_data();
	}

	/**
	 * Stores `customer.account_orders` and `customer.order_details` as false, unless the user
	 * already stored them.
	 *
	 * The stored array is written back whole with only these keys added: every other group stays
	 * as it was (Module::settings() merges the defaults on read). Settings still equal to the
	 * defaults were written by the activator, never by the user: their customer values are not a
	 * choice and are turned off too.
	 */
	private static function turn_page_links_off(): void {
		$stored = get_option( self::SETTINGS_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		$customer = isset( $stored['customer'] ) && is_array( $stored['customer'] ) ? $stored['customer'] : [];
		$chosen   = ! Schema::left_at_defaults( $stored );
		$changed  = false;

		foreach ( self::PAGE_LINKS as $key ) {
			if ( $chosen && array_key_exists( $key, $customer ) ) {
				continue;
			}

			if ( ! array_key_exists( $key, $customer ) || false !== $customer[ $key ] ) {
				$customer[ $key ] = false;
				$changed          = true;
			}
		}

		if ( ! $changed ) {
			return;
		}

		$stored['customer'] = $customer;
		update_option( self::SETTINGS_OPTION, $stored );
	}
}
