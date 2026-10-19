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
	 * Records the archived file of an issued document.
	 *
	 * @param int    $id       Row id.
	 * @param string $file     Relative path.
	 * @param string $checksum SHA-256 of the file.
	 */
	public static function attach_file( int $id, string $file, string $checksum ): bool {
		global $wpdb;

		$table = Installer::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET file = %s, checksum = %s WHERE id = %d", $file, $checksum, $id ) );

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
}
