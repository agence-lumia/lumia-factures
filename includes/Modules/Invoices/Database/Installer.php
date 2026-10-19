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
	 */
	public const DB_VERSION = 1;

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
	 * Creates or updates the tables. Idempotent.
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
  current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (sequence_key)
) ENGINE=InnoDB {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$documents} (
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
) ENGINE=InnoDB {$collate};"
		);

		update_option( self::OPTION, self::DB_VERSION );
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
