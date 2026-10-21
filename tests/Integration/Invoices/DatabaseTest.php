<?php
/**
 * Numbering and register tables.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\Activator;
use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * Installer: tables, unique keys and version upgrade.
 */
class DatabaseTest extends TestCase {

	/**
	 * Rows of the v1 copy before the upgrade (ARRAY_A, by id).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $v1_rows_before = [];

	/**
	 * Rows of the v1 copy after the upgrade (ARRAY_A, by id).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $v1_rows_after = [];

	/**
	 * Indexes of the v1 copy after the upgrade: key name => Non_unique.
	 *
	 * @var array<string, int>
	 */
	private static array $v1_indexes_after = [];

	/**
	 * What a second dbDelta() reported on the upgraded copy.
	 *
	 * @var array<mixed>
	 */
	private static array $v1_second_delta = [];

	/**
	 * Upgrades a v1 copy of the documents table, outside any test transaction.
	 *
	 * Any ALTER commits the transaction the WordPress test suite wraps each test in, whatever
	 * the table: the copy is created, filled and upgraded here, and the test only reads it.
	 * The real table is never altered while a test runs.
	 */
	public static function set_up_before_class(): void {
		global $wpdb;

		parent::set_up_before_class();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::v1_table();
		$collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		// The 2.0.x definition (DB_VERSION 1), verbatim.
		$wpdb->query(
			"CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(20) NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL,
  number VARCHAR(100) NULL,
  sequence_key VARCHAR(64) NULL,
  sequence BIGINT UNSIGNED NULL,
  issued_at DATETIME NULL,
  file VARCHAR(255) NULL,
  checksum CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY type_order (type, order_id),
  UNIQUE KEY type_number (type, number),
  KEY order_id (order_id)
) ENGINE=InnoDB {$collate}"
		);

		$rows = [
			// Issued here, with its archive.
			[ 'invoice', 9001, 'issued', 'FA-2026-00001', 'invoice_2026', 1, '2026-01-05 10:00:00', '2026/01/FA-2026-00001-0123456789abcdef.pdf', str_repeat( 'a', 64 ), '2026-01-05 10:00:00' ],
			// Imported from 1.x: no counter, no file yet.
			[ 'invoice', 9002, 'issued', '202610029241', null, null, '2025-10-02 09:30:00', null, null, '2026-10-19 08:00:00' ],
			// A reservation in progress.
			[ 'invoice', 9003, 'reserved', null, null, null, null, null, null, '2026-10-19 08:01:00' ],
		];
		foreach ( $rows as $row ) {
			$wpdb->insert(
				$table,
				array_combine( [ 'type', 'order_id', 'status', 'number', 'sequence_key', 'sequence', 'issued_at', 'file', 'checksum', 'created_at' ], $row ),
				[ '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
			);
		}

		self::$v1_rows_before = self::rows_by_id( $table );

		dbDelta( Installer::documents_schema( $table ) );

		self::$v1_rows_after   = self::rows_by_id( $table );
		self::$v1_second_delta = dbDelta( Installer::documents_schema( $table ) );

		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ) as $index ) {
			self::$v1_indexes_after[ (string) $index['Key_name'] ] = (int) $index['Non_unique'];
		}
		// phpcs:enable

		self::commit_transaction();
	}

	/**
	 * Drops the v1 copy.
	 */
	public static function tear_down_after_class(): void {
		global $wpdb;

		$table = self::v1_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		parent::tear_down_after_class();
	}

	/**
	 * Name of the v1 copy, never the real table.
	 */
	private static function v1_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'lumia_factures_documents_v1test';
	}

	/**
	 * Every row of a table, keyed by id.
	 *
	 * @param string $table Table name.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows_by_id( string $table ): array {
		global $wpdb;

		$rows = [];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture table.
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A ) as $row ) {
			$rows[ (int) $row['id'] ] = $row;
		}

		return $rows;
	}

	public function test_upgrade_from_v1_keeps_issued_rows(): void {
		$this->assertCount( 3, self::$v1_rows_before );
		$this->assertArrayNotHasKey( 'parent_id', reset( self::$v1_rows_before ) );

		// Same ids, every v1 field identical, parent_id added at 0.
		$this->assertSame( array_keys( self::$v1_rows_before ), array_keys( self::$v1_rows_after ) );
		foreach ( self::$v1_rows_before as $id => $before ) {
			$after = self::$v1_rows_after[ $id ];

			$this->assertArrayHasKey( 'parent_id', $after );
			$this->assertSame( '0', $after['parent_id'] );

			unset( $after['parent_id'] );
			$this->assertSame( $before, $after );
		}

		// The new key is added, the unique keys are kept.
		$this->assertSame( 1, self::$v1_indexes_after['parent_id'] ?? null );
		$this->assertSame( 0, self::$v1_indexes_after['type_order'] ?? null );
		$this->assertSame( 0, self::$v1_indexes_after['type_number'] ?? null );

		// A second upgrade changes nothing.
		$this->assertSame( [], self::$v1_second_delta );
	}

	public function test_documents_schema_matches_install(): void {
		$captured = [];
		$capture  = static function ( array $queries ) use ( &$captured ): array {
			$captured = array_merge( $captured, $queries );

			return [];
		};

		add_filter( 'dbdelta_queries', $capture );
		try {
			Installer::install();
		} finally {
			remove_filter( 'dbdelta_queries', $capture );
		}

		$this->assertContains( Installer::documents_schema( Installer::documents_table() ), $captured );
	}

	public function test_install_alters_nothing_on_current_schema(): void {
		$captured = [];
		$capture  = static function ( array $queries ) use ( &$captured ): array {
			$captured = array_merge( $captured, $queries );

			return [];
		};

		add_filter( 'dbdelta_queries', $capture );
		try {
			Installer::install();
		} finally {
			remove_filter( 'dbdelta_queries', $capture );
		}

		// dbDelta( ..., false ) only computes the changes: no DDL runs inside the test transaction.
		$this->assertCount( 2, $captured );
		$this->assertSame( [], dbDelta( $captured, false ) );
	}

	public function test_parent_id_column_and_key_exist(): void {
		global $wpdb;

		$table = Installer::documents_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$column = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'parent\\_id'", ARRAY_A );
		$rows   = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		// phpcs:enable

		$this->assertIsArray( $column );
		$this->assertSame( 'parent_id', $column['Field'] );
		$this->assertStringStartsWith( 'bigint', strtolower( (string) $column['Type'] ) );
		$this->assertStringContainsString( 'unsigned', strtolower( (string) $column['Type'] ) );
		$this->assertSame( 'NO', $column['Null'] );
		$this->assertSame( '0', $column['Default'] );

		$keys = [];
		foreach ( $rows as $row ) {
			$keys[ $row['Key_name'] ][] = [ $row['Column_name'], (int) $row['Non_unique'] ];
		}
		$this->assertSame( [ [ 'parent_id', 1 ] ], $keys['parent_id'] ?? null );
	}

	public function test_version_not_recorded_when_column_missing(): void {
		update_option( Installer::OPTION, 1 );

		// No DDL in a test (it would commit the transaction); the column lookup answers "absent".
		$table   = Installer::documents_table();
		$missing = static function ( string $query ) use ( $table ): string {
			if ( 0 === stripos( $query, "SHOW COLUMNS FROM {$table} " ) ) {
				return "SHOW COLUMNS FROM {$table} LIKE 'lumia\\_factures\\_absent'";
			}

			return $query;
		};
		// Capture the log line instead of spilling it into the test output.
		$log_file     = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		$previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.

		add_filter( 'dbdelta_queries', '__return_empty_array' );
		add_filter( 'query', $missing );
		try {
			Installer::maybe_upgrade();
			$this->assertSame( 1, (int) get_option( Installer::OPTION ) );

			// Still lower: the next request tries again.
			Installer::maybe_upgrade();
			$this->assertSame( 1, (int) get_option( Installer::OPTION ) );

			$this->assertStringContainsString( 'no parent_id column', (string) file_get_contents( $log_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test file.
		} finally {
			remove_filter( 'query', $missing );
			ini_set( 'error_log', $previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
			wp_delete_file( $log_file );
		}

		// Once the column is there, the version is recorded.
		try {
			Installer::maybe_upgrade();
		} finally {
			remove_filter( 'dbdelta_queries', '__return_empty_array' );
		}
		$this->assertSame( Installer::DB_VERSION, (int) get_option( Installer::OPTION ) );
	}

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

	public function test_module_init_upgrades_the_schema(): void {
		delete_option( 'lumia_factures_db_version' );

		( new Module( 'invoices' ) )->init();

		$this->assertSame( Installer::DB_VERSION, (int) get_option( 'lumia_factures_db_version' ) );
	}

	public function test_plugin_activation_installs_the_schema(): void {
		delete_option( 'lumia_factures_db_version' );

		Activator::activate();

		$this->assertSame( Installer::DB_VERSION, (int) get_option( 'lumia_factures_db_version' ) );
	}

	public function test_install_is_idempotent(): void {
		Installer::install();
		Installer::install();

		$this->test_tables_exist();
		$this->test_unique_keys_exist();
	}
}
