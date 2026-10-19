<?php
/**
 * Numbering and register tables.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * Installer: tables, unique keys and version upgrade.
 */
class DatabaseTest extends TestCase {

	public function test_tables_exist(): void {
		global $wpdb;

		foreach ( [ Installer::sequences_table(), Installer::documents_table() ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $found );
		}
	}

	public function test_table_names_use_the_prefix(): void {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'lumia_factures_sequences', Installer::sequences_table() );
		$this->assertSame( $wpdb->prefix . 'lumia_factures_documents', Installer::documents_table() );
	}

	public function test_unique_keys_exist(): void {
		global $wpdb;

		$table = Installer::documents_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );

		$unique = [];
		foreach ( $rows as $row ) {
			$unique[ $row['Key_name'] ] = (int) $row['Non_unique'];
		}

		$this->assertArrayHasKey( 'type_order', $unique );
		$this->assertArrayHasKey( 'type_number', $unique );
		$this->assertSame( 0, $unique['type_order'] );
		$this->assertSame( 0, $unique['type_number'] );
	}

	public function test_maybe_upgrade_installs_when_version_is_lower(): void {
		update_option( 'lumia_factures_db_version', 0 );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::DB_VERSION, (int) get_option( 'lumia_factures_db_version' ) );
	}

	public function test_maybe_upgrade_does_nothing_when_up_to_date(): void {
		update_option( 'lumia_factures_db_version', 99 );

		Installer::maybe_upgrade();

		$this->assertSame( 99, (int) get_option( 'lumia_factures_db_version' ) );
	}

	public function test_install_is_idempotent(): void {
		Installer::install();
		Installer::install();

		$this->test_tables_exist();
		$this->test_unique_keys_exist();
	}
}
