<?php
/**
 * Tables of the Invoices module: numbering counters and register of documents.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the two custom tables with dbDelta().
 */
class Installer {

	/**
	 * Schema version. Bump it when a table definition changes.
	 *
	 * 1: 2.0.x. 2: 2.1, `parent_id` on the documents table (the order a credit note belongs to).
	 */
	public const DB_VERSION = 2;

	/**
	 * Option holding the installed schema version.
	 */
	public const OPTION = 'lumia_factures_db_version';

	/**
	 * Counters table name (with the site prefix).
	 */
	public static function sequences_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'lumia_factures_sequences';
	}

	/**
	 * Register of documents table name (with the site prefix).
	 */
	public static function documents_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'lumia_factures_documents';
	}

	/**
	 * The exact `CREATE TABLE` statement of the documents table that install() passes to dbDelta().
	 *
	 * `parent_id` is the order a credit note belongs to (its `order_id` holds the refund id); 0 for
	 * an invoice. On an existing table dbDelta() appends the column at the end of the table: the
	 * column order is irrelevant, rows are always read by name.
	 *
	 * Integers carry their display width (`BIGINT(20)`, as in WordPress core): MariaDB reports
	 * `bigint(20) unsigned`, and dbDelta() re-issued an `ALTER TABLE ... CHANGE COLUMN` for every
	 * `BIGINT UNSIGNED` column on each run. MySQL 8 ignores the width; the storage is the same.
	 *
	 * @param string $table Table name, with the site prefix.
	 */
	public static function documents_schema( string $table ): string {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(20) NOT NULL,
  order_id BIGINT(20) UNSIGNED NOT NULL,
  parent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL,
  number VARCHAR(100) NULL,
  sequence_key VARCHAR(64) NULL,
  sequence BIGINT(20) UNSIGNED NULL,
  issued_at DATETIME NULL,
  file VARCHAR(255) NULL,
  checksum CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY type_order (type, order_id),
  UNIQUE KEY type_number (type, number),
  KEY order_id (order_id),
  KEY parent_id (parent_id)
) ENGINE=InnoDB {$collate}";
	}

	/**
	 * Creates or updates the tables. Idempotent.
	 *
	 * The schema version is recorded only once the column of the current version really exists:
	 * dbDelta() reports nothing when an ALTER fails (missing privilege, locked table), and a version
	 * recorded over a v1 table would never be upgraded again. The next init tries again.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sequences = self::sequences_table();
		$documents = self::documents_table();
		$collate   = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$sequences} (
  sequence_key VARCHAR(64) NOT NULL,
  current_value BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (sequence_key)
) ENGINE=InnoDB {$collate};"
		);

		dbDelta( self::documents_schema( $documents ) );

		if ( ! self::has_column( $documents, 'parent_id' ) ) {
			error_log( '[lumia-factures] The documents table has no parent_id column after dbDelta(): schema version ' . self::DB_VERSION . ' not recorded, the upgrade runs again on the next request.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		update_option( self::OPTION, self::DB_VERSION );
	}

	/**
	 * Whether a table has a column.
	 *
	 * @param string $table  Table name, with the site prefix.
	 * @param string $column Column name.
	 */
	private static function has_column( string $table, string $column ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema lookup, table name from Installer.
		return $column === $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $wpdb->esc_like( $column ) ) );
	}

	/**
	 * Whether both tables exist.
	 *
	 * They may not yet: the tables are created when the module starts, and it does not start while
	 * a requirement fails (the legacy plugin still active), when the read-only WP-CLI commands
	 * already work.
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		$exists = true;
		foreach ( [ self::sequences_table(), self::documents_table() ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup, never cached.
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				$exists = false;
			}
		}

		/**
		 * Whether the tables of the module exist.
		 *
		 * @internal Test seam only, not a public extension point: a test cannot drop the tables
		 *           (any DDL commits the test transaction).
		 *
		 * @param bool $exists True when both tables exist.
		 */
		return (bool) apply_filters( 'lumia_factures_tables_exist', $exists );
	}

	/**
	 * Installs the tables when the stored version is lower than the code's.
	 * Runs on init: updating a plugin does not run its activation hook.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION, 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}
}
