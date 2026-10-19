<?php
/**
 * Register of documents: one row per order and document type.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Documents;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Database\Installer;

/**
 * Unique keys (type, order) and (type, number) make double issuing impossible:
 * a second reserve() or a duplicate number fails at the database, with no
 * transaction.
 */
final class Register {

	/**
	 * Finds the document of an order.
	 *
	 * @param string $type     Document type.
	 * @param int    $order_id Order id.
	 */
	public static function find( string $type, int $order_id ): ?IssuedDocument {
		global $wpdb;

		$table = Installer::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND order_id = %d", $type, $order_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$issued_at = null === $row['issued_at'] ? 0 : (int) strtotime( $row['issued_at'] . ' UTC' );

		return new IssuedDocument(
			(int) $row['id'],
			(string) $row['type'],
			(int) $row['order_id'],
			(string) $row['status'],
			null === $row['number'] ? null : (string) $row['number'],
			null === $row['sequence_key'] ? null : (string) $row['sequence_key'],
			null === $row['sequence'] ? null : (int) $row['sequence'],
			$issued_at,
			null === $row['file'] ? null : (string) $row['file'],
			null === $row['checksum'] ? null : (string) $row['checksum']
		);
	}

	/**
	 * Whether a number is already recorded for a document type.
	 *
	 * @param string $type   Document type.
	 * @param string $number Printed number.
	 */
	public static function number_exists( string $type, string $number ): bool {
		global $wpdb;

		$table = Installer::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s AND number = %s", $type, $number ) );
	}

	/**
	 * Reserves the right to issue a document for an order.
	 *
	 * @param string $type     Document type.
	 * @param int    $order_id Order id.
	 * @return int|null Row id, or null when the order already has a row (or on failure).
	 */
	public static function reserve( string $type, int $order_id ): ?int {
		global $wpdb;

		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- register write.
		$result = $wpdb->insert(
			Installer::documents_table(),
			[
				'type'       => $type,
				'order_id'   => $order_id,
				'status'     => 'reserved',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%d', '%s', '%s' ]
		);
		$error  = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );

		if ( 1 === $result ) {
			return (int) $wpdb->insert_id;
		}

		// A duplicate key is the normal "already reserved" answer; anything else is a real failure.
		if ( false === stripos( $error, 'duplicate' ) ) {
			error_log( '[lumia-factures] Could not reserve a ' . $type . ' row for order ' . $order_id . ': ' . $error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return null;
	}

	/**
	 * Records an invoice issued elsewhere (the legacy plugin) directly as issued.
	 *
	 * @param string $type         Document type.
	 * @param int    $order_id     Order id.
	 * @param string $number       Printed number.
	 * @param string $sequence_key Counter key ('' when unknown).
	 * @param int    $sequence     Counter value (0 when unknown).
	 * @param int    $issued_at    UTC timestamp.
	 * @return int|null Row id, or null when the order already has a row or the number is taken (nothing inserted).
	 */
	public static function import( string $type, int $order_id, string $number, string $sequence_key, int $sequence, int $issued_at ): ?int {
		global $wpdb;

		if ( '' === $number || $order_id <= 0 ) {
			return null;
		}

		// One INSERT: the unique keys (type, order) and (type, number) refuse a duplicate at the database.
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- register write.
		$result = $wpdb->insert(
			Installer::documents_table(),
			[
				'type'         => $type,
				'order_id'     => $order_id,
				'status'       => 'issued',
				'number'       => $number,
				'sequence_key' => '' === $sequence_key ? null : $sequence_key,
				'sequence'     => $sequence > 0 ? $sequence : null,
				'issued_at'    => gmdate( 'Y-m-d H:i:s', $issued_at ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		$error  = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );

		if ( 1 === $result ) {
			return (int) $wpdb->insert_id;
		}

		if ( false === stripos( $error, 'duplicate' ) ) {
			error_log( '[lumia-factures] Could not import ' . $type . ' ' . $number . ' of order ' . $order_id . ': ' . $error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return null;
	}

	/**
	 * Gives a reserved row its number and marks it issued.
	 *
	 * @param int    $id        Row id.
	 * @param string $number    Printed number.
	 * @param string $key       Counter key.
	 * @param int    $sequence  Counter value.
	 * @param int    $issued_at UTC timestamp.
	 * @return bool True only when exactly one reserved row changed.
	 */
	public static function assign( int $id, string $number, string $key, int $sequence, int $issued_at ): bool {
		global $wpdb;

		$table = Installer::documents_table();

		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'issued', number = %s, sequence_key = %s, sequence = %d, issued_at = %s WHERE id = %d AND status = 'reserved'", $number, $key, $sequence, gmdate( 'Y-m-d H:i:s', $issued_at ), $id ) );
		$wpdb->suppress_errors( $suppressed );

		return 1 === $affected;
	}

	/**
	 * Replaces the archived file of an issued document.
	 *
	 * The only legitimate caller is the issuer's restore of a missing or damaged
	 * archive: the number and the date are kept, only the file is re-recorded.
	 *
	 * @param int    $id       Row id.
	 * @param string $old_file Currently recorded relative path (must match).
	 * @param string $file     New relative path.
	 * @param string $checksum SHA-256 of the new file.
	 * @return bool True only when exactly one issued row changed.
	 */
	public static function replace_file( int $id, string $old_file, string $file, string $checksum ): bool {
		global $wpdb;

		$table = Installer::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET file = %s, checksum = %s WHERE id = %d AND status = 'issued' AND file = %s", $file, $checksum, $id, $old_file ) );

		return 1 === $affected;
	}

	/**
	 * Records the archived file of an issued document, once.
	 *
	 * Refuses a reserved row and a row that already has a file: an issued
	 * invoice's archived PDF never changes (see replace_file() for the restore).
	 *
	 * @param int    $id       Row id.
	 * @param string $file     Relative path.
	 * @param string $checksum SHA-256 of the file.
	 */
	public static function attach_file( int $id, string $file, string $checksum ): bool {
		global $wpdb;

		$table = Installer::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET file = %s, checksum = %s WHERE id = %d AND status = 'issued' AND file IS NULL", $file, $checksum, $id ) );

		return 1 === $affected;
	}

	/**
	 * Deletes a reserved row that never got a number (failure before numbering).
	 *
	 * @param int $id Row id.
	 */
	public static function release( int $id ): void {
		global $wpdb;

		$table = Installer::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND status = 'reserved' AND number IS NULL", $id ) );
	}

	/**
	 * Deletes a reserved row left behind by a request that died between reserve() and assign().
	 *
	 * Only a row still without number and older than the given age is deleted, so a call
	 * in progress is never disturbed.
	 *
	 * @param int $id         Row id.
	 * @param int $older_than Minimum age in seconds.
	 * @return bool True when the row was deleted.
	 */
	public static function release_stale( int $id, int $older_than ): bool {
		global $wpdb;

		$table = Installer::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
		$affected = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND status = 'reserved' AND number IS NULL AND created_at < %s", $id, gmdate( 'Y-m-d H:i:s', time() - $older_than ) ) );

		return 1 === $affected;
	}
}
