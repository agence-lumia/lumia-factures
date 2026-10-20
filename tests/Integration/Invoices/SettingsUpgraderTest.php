<?php
/**
 * One-time upgrade of the stored invoice settings (customer access).
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the tests listen to WordPress core hooks (added_option, updated_option).

use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Migration\LegacySource;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Settings\Schema;
use Lumia\Factures\Modules\Invoices\Settings\Upgrader;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * Upgrader: page links off on an existing installation, once, and nothing else written.
 */
class SettingsUpgraderTest extends TestCase {

	private const SETTINGS = 'lumia_factures_module_invoices';

	public function set_up(): void {
		parent::set_up();
		// A fresh site: none of the signs of an earlier installation.
		delete_option( Upgrader::OPTION );
		delete_option( Installer::OPTION );
		delete_option( Migration::OPTION );
		delete_option( LegacySource::OPTION );
		delete_option( self::SETTINGS );
		Migration::forget_no_data();
	}

	/**
	 * Settings stored by 2.0: no credit_notes nor customer group.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function settings_2_0(): array {
		$stored = Schema::defaults();
		unset( $stored['credit_notes'], $stored['customer'] );

		return $stored;
	}

	public function test_fresh_activation_keeps_links_on(): void {
		$this->assertFalse( Register::has_rows(), 'The test starts with an empty register.' );
		// The activator stores the defaults before the module's on_activate().
		add_option( self::SETTINGS, Schema::defaults() );

		( new Module( 'invoices' ) )->on_activate();

		$this->assertSame( Schema::defaults(), get_option( self::SETTINGS ) );
		$this->assertTrue( Module::settings()['customer']['account_orders'] );
		$this->assertTrue( Module::settings()['customer']['order_details'] );
		$this->assertSame( Upgrader::VERSION, (int) get_option( Upgrader::OPTION ) );
	}

	public function test_upgrade_from_2_0_turns_page_links_off_when_db_version_present(): void {
		update_option( Installer::OPTION, 1 );
		update_option( self::SETTINGS, $this->settings_2_0() );

		Upgrader::maybe_upgrade();

		$stored = get_option( self::SETTINGS );
		$this->assertSame(
			[
				'account_orders' => false,
				'order_details'  => false,
			],
			$stored['customer']
		);
		$this->assertFalse( Module::settings()['customer']['account_orders'] );
		$this->assertFalse( Module::settings()['customer']['order_details'] );
	}

	public function test_upgrade_turns_page_links_off_when_documents_exist(): void {
		$this->assertNotNull( Register::reserve( 'invoice', 987654 ) );

		Upgrader::maybe_upgrade();

		$this->assertFalse( Module::settings()['customer']['account_orders'] );
		$this->assertFalse( Module::settings()['customer']['order_details'] );
	}

	public function test_upgrade_turns_page_links_off_when_migration_not_none(): void {
		update_option( Migration::OPTION, [ 'status' => 'done' ] );

		Upgrader::maybe_upgrade();

		$this->assertFalse( Module::settings()['customer']['account_orders'] );
		$this->assertFalse( Module::settings()['customer']['order_details'] );
	}

	public function test_upgrade_turns_page_links_off_when_legacy_1x_data_present(): void {
		update_option( LegacySource::OPTION, [ 'numbering' => [ 'format' => '{year}{month}{number:6}' ] ] );
		// First activation of 2.1 on a 1.x site: the activator stored the defaults (customer on) just
		// before; they are not a user choice.
		add_option( self::SETTINGS, Schema::defaults() );

		( new Module( 'invoices' ) )->on_activate();

		$this->assertFalse( Module::settings()['customer']['account_orders'] );
		$this->assertFalse( Module::settings()['customer']['order_details'] );
		$this->assertSame( Schema::defaults()['customer']['email_links'], Module::settings()['customer']['email_links'] );
	}

	public function test_upgrade_keeps_email_links_untouched(): void {
		update_option( Installer::OPTION, 1 );
		update_option( self::SETTINGS, $this->settings_2_0() );

		Upgrader::maybe_upgrade();

		$this->assertArrayNotHasKey( 'email_links', get_option( self::SETTINGS )['customer'], 'Never written: read from the defaults.' );
		$this->assertSame( Schema::defaults()['customer']['email_links'], Module::settings()['customer']['email_links'] );

		// A stored list is kept as it is.
		delete_option( Upgrader::OPTION );
		$stored                            = $this->settings_2_0();
		$stored['company']['phone']        = '0102';
		$stored['customer']['email_links'] = [ 'customer_invoice' ];
		update_option( self::SETTINGS, $stored );

		Upgrader::maybe_upgrade();

		$this->assertSame( [ 'customer_invoice' ], get_option( self::SETTINGS )['customer']['email_links'] );
	}

	public function test_upgrade_keeps_a_value_the_user_stored(): void {
		update_option( Installer::OPTION, 2 );
		$stored                               = Schema::defaults();
		$stored['company']['phone']           = '0102';
		$stored['customer']['account_orders'] = true;
		unset( $stored['customer']['order_details'] );
		update_option( self::SETTINGS, $stored );

		Upgrader::maybe_upgrade();

		$this->assertTrue( get_option( self::SETTINGS )['customer']['account_orders'] );
		$this->assertFalse( get_option( self::SETTINGS )['customer']['order_details'] );
	}

	public function test_upgrade_preserves_every_other_group_byte_for_byte(): void {
		update_option( Installer::OPTION, 1 );
		$stored                         = $this->settings_2_0();
		$stored['numbering']['format']  = '{year}{month}{number:6}';
		$stored['company']['phone']     = '01 02';
		$stored['content']['font_size'] = '9.5';
		$stored['design']['font_size']  = 10;
		$stored['legacy_unknown']       = [ 'kept' => '1' ];
		unset( $stored['company']['siret'] );
		update_option( self::SETTINGS, $stored );

		Upgrader::maybe_upgrade();

		$after = get_option( self::SETTINGS );
		$this->assertArrayHasKey( 'customer', $after );
		unset( $after['customer'] );
		$this->assertSame( $stored, $after );
	}

	public function test_upgrade_runs_once(): void {
		update_option( Installer::OPTION, 1 );
		update_option( self::SETTINGS, $this->settings_2_0() );

		Upgrader::maybe_upgrade();
		$this->assertSame( Upgrader::VERSION, (int) get_option( Upgrader::OPTION ) );

		// The user turns a link back on: a later boot never turns it off again.
		$stored                               = get_option( self::SETTINGS );
		$stored['customer']['account_orders'] = true;
		update_option( self::SETTINGS, $stored );

		$writes = 0;
		$count  = static function ( $option ) use ( &$writes ): void {
			if ( self::SETTINGS === $option || Upgrader::OPTION === $option ) {
				++$writes;
			}
		};
		add_action( 'updated_option', $count );
		add_action( 'added_option', $count );

		Upgrader::maybe_upgrade();
		( new Module( 'invoices' ) )->on_activate();

		$this->assertSame( 0, $writes );
		$this->assertTrue( Module::settings()['customer']['account_orders'] );
	}

	public function test_fresh_site_records_the_marker_without_writing_settings(): void {
		Upgrader::maybe_upgrade();

		$this->assertFalse( get_option( self::SETTINGS ) );
		$this->assertSame( Upgrader::VERSION, (int) get_option( Upgrader::OPTION ) );
	}

	public function test_upgrade_runs_before_installer_in_on_activate(): void {
		add_option( self::SETTINGS, Schema::defaults() );

		$order  = [];
		$record = static function ( $option ) use ( &$order ): void {
			if ( Upgrader::OPTION === $option || Installer::OPTION === $option ) {
				$order[] = $option;
			}
		};
		add_action( 'added_option', $record );
		add_action( 'updated_option', $record );

		( new Module( 'invoices' ) )->on_activate();

		$this->assertSame( [ Upgrader::OPTION, Installer::OPTION ], $order );
		// The schema version the installer just recorded did not make the site look upgraded.
		$this->assertTrue( Module::settings()['customer']['account_orders'] );
		$this->assertTrue( Module::settings()['customer']['order_details'] );
	}

	public function test_upgrade_never_touches_register_sequences_or_archive(): void {
		global $wpdb;

		update_option( Installer::OPTION, 1 );
		update_option( self::SETTINGS, $this->settings_2_0() );
		$this->assertNotNull( Register::reserve( 'invoice', 987655 ) );
		Sequences::raise( 'invoice_2099', 41 );

		$documents = Installer::documents_table();
		$sequences = Installer::sequences_table();
		$snapshot  = static function () use ( $wpdb, $documents, $sequences ): array {
			$root = Archive::root();
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test snapshot, table names from Installer.
			return [
				$wpdb->get_results( "SELECT * FROM {$documents} ORDER BY id", ARRAY_A ),
				$wpdb->get_results( "SELECT * FROM {$sequences} ORDER BY sequence_key", ARRAY_A ),
				is_dir( $root ) ? (array) scandir( $root ) : [],
			];
			// phpcs:enable
		};

		$before = $snapshot();
		Upgrader::maybe_upgrade();

		$this->assertSame( $before, $snapshot() );
		$this->assertFalse( Module::settings()['customer']['account_orders'] );
	}
}
