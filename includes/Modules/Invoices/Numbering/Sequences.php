<?php
/**
 * Gapless numbering counters.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Numbering;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Database\Installer;

/**
 * Counters stored in the sequences table.
 *
 * The next value is produced by one atomic UPDATE using LAST_INSERT_ID(expr),
 * which stores the new value in the connection and is read back with
 * SELECT LAST_INSERT_ID(). It is never computed in PHP from a SELECT, so two
 * requests cannot read the same counter. (Legacy 1.x read $wpdb->insert_id
 * after an UPDATE, which an UPDATE does not set.)
 */
final class Sequences {

	/**
	 * Maximum attempts of next() (creation of the row, then increment).
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Counter key for a document type and a date.
	 *
	 * @param string               $type      Document type, e.g. `invoice`.
	 * @param int                  $timestamp UTC timestamp.
	 * @param array<string, mixed> $numbering The `numbering` settings.
	 * @return string `invoice`, `invoice_2026` (yearly) or `invoice_2026_10` (monthly).
	 */
	public static function key( string $type, int $timestamp, array $numbering ): string {
		if ( empty( $numbering['reset_enabled'] ) ) {
			return $type;
		}

		if ( 'monthly' === ( $numbering['reset_period'] ?? 'yearly' ) ) {
			return $type . '_' . wp_date( 'Y', $timestamp ) . '_' . wp_date( 'm', $timestamp );
		}

		return $type . '_' . wp_date( 'Y', $timestamp );
	}

	/**
	 * Atomically takes the next value of a counter, creating it when missing.
	 *
	 * @param string $key   Counter key.
	 * @param int    $start First value of a new counter (below 1 is treated as 1).
	 * @return int The value, or 0 on failure.
	 */
	public static function next( string $key, int $start ): int {
		global $wpdb;

		$table = Installer::sequences_table();
		$start = max( 1, $start );

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- atomic counter, table name from Installer.
			$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET current_value = LAST_INSERT_ID(current_value + 1), updated_at = %s WHERE sequence_key = %s", gmdate( 'Y-m-d H:i:s' ), $key ) );

			if ( 1 === (int) $affected ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LAST_INSERT_ID() is per connection.
				return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
			}

			// The row does not exist yet: create it just below the first value, then increment.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (sequence_key, current_value, updated_at) VALUES (%s, %d, %s)", $key, $start - 1, gmdate( 'Y-m-d H:i:s' ) ) );
		}

		error_log( '[lumia-factures] Could not obtain the next value of the sequence ' . $key . '.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return 0;
	}

	/**
	 * Current value of a counter (0 when it does not exist).
	 *
	 * @param string $key Counter key.
	 */
	public static function current( string $key ): int {
		global $wpdb;

		$table = Installer::sequences_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT current_value FROM {$table} WHERE sequence_key = %s", $key ) );
	}

	/**
	 * Raises a counter to a value. Never lowers it.
	 *
	 * @param string $key   Counter key.
	 * @param int    $value New current value.
	 * @return bool False when the value is not above the current one (or on failure).
	 */
	public static function raise( string $key, int $value ): bool {
		global $wpdb;

		$table = Installer::sequences_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		if ( $value <= self::current( $key ) ) {
			return false;
		}

		// INSERT IGNORE covers a missing row; the guarded UPDATE keeps the "never lowers" rule under concurrency.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (sequence_key, current_value, updated_at) VALUES (%s, 0, %s)", $key, $now ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET current_value = %d, updated_at = %s WHERE sequence_key = %s AND current_value < %d", $value, $now, $key, $value ) );

		return 1 === (int) $affected;
	}

	/**
	 * All counters.
	 *
	 * @return array<int, array{key: string, value: int, updated_at: string}>
	 */
	public static function all(): array {
		global $wpdb;

		$table = Installer::sequences_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
		$rows = $wpdb->get_results( "SELECT sequence_key, current_value, updated_at FROM {$table} ORDER BY sequence_key ASC", ARRAY_A );
		$out  = [];

		foreach ( (array) $rows as $row ) {
			$out[] = [
				'key'        => (string) $row['sequence_key'],
				'value'      => (int) $row['current_value'],
				'updated_at' => (string) $row['updated_at'],
			];
		}

		return $out;
	}
}
