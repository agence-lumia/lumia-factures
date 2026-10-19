<?php
/**
 * Legacy Facture PDF Lumia (1.x) data for the migration tests.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Support;

defined( 'ABSPATH' ) || exit;

use WC_Order;

/**
 * Builds the legacy data the importer reads: the `fpl_sequences` table, the `_fpl_invoice_*`
 * order meta and the PDF files under `uploads/facture-pdf-lumia/`. Call cleanup() in tear_down.
 */
final class LegacyFixture {

	/**
	 * Files written under the legacy folder.
	 *
	 * @var string[]
	 */
	private array $files = [];

	/**
	 * Absolute path of the legacy storage folder.
	 */
	public static function root(): string {
		return wp_upload_dir()['basedir'] . '/facture-pdf-lumia';
	}

	/**
	 * Legacy counters table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'fpl_sequences';
	}

	/**
	 * Creates the legacy counters table (temporary inside a test) with its rows.
	 *
	 * @param array<string, int> $rows Counter values keyed by sequence key.
	 */
	public function sequences( array $rows ): void {
		global $wpdb;

		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$wpdb->query( "CREATE TABLE {$table} ( sequence_key VARCHAR(64) NOT NULL, current_value BIGINT UNSIGNED NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL, PRIMARY KEY (sequence_key) )" );
		foreach ( $rows as $key => $value ) {
			$wpdb->insert(
				$table,
				[
					'sequence_key'  => $key,
					'current_value' => $value,
					'updated_at'    => '2026-10-19 08:00:00',
				]
			);
		}
		// phpcs:enable
	}

	/**
	 * Legacy counters table rows, for byte comparisons.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function sequence_rows(): array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture.
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sequence_key", ARRAY_A );
	}

	/**
	 * Puts a legacy invoice on an order.
	 *
	 * @param WC_Order    $order     Order.
	 * @param string      $number    Legacy number.
	 * @param int         $sequence  Legacy counter value.
	 * @param string      $key       Legacy counter key.
	 * @param int         $issued_at UTC timestamp.
	 * @param string|null $pdf       File content written on disk, null for a file purged by the retention.
	 */
	public function invoice( WC_Order $order, string $number, int $sequence, string $key, int $issued_at, ?string $pdf ): void {
		$relative = gmdate( 'Y/m', $issued_at ) . '/invoice-' . $order->get_id() . '-' . bin2hex( random_bytes( 16 ) ) . '.pdf';

		if ( null !== $pdf ) {
			$path = self::root() . '/' . $relative;
			wp_mkdir_p( dirname( $path ) );
			file_put_contents( $path, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			$this->files[] = $path;
		}

		$order->update_meta_data( '_fpl_invoice_number', $number );
		$order->update_meta_data( '_fpl_invoice_sequence', $sequence );
		$order->update_meta_data( '_fpl_invoice_sequence_key', $key );
		$order->update_meta_data( '_fpl_invoice_date', $issued_at );
		$order->update_meta_data( '_fpl_invoice_file', $relative );
		$order->update_meta_data( '_fpl_invoice_generated_at', $issued_at + 5 );
		$order->save();
	}

	/**
	 * A minimal valid-looking PDF body.
	 *
	 * @param string $label Text making the file unique.
	 */
	public static function pdf( string $label ): string {
		return "%PDF-1.7\n% legacy invoice " . $label . "\n%%EOF\n";
	}

	/**
	 * Every `_fpl_*` meta of an order, read from a fresh object.
	 *
	 * @param int $order_id Order id.
	 * @return array<string, mixed>
	 */
	public static function legacy_meta( int $order_id ): array {
		$order = wc_get_order( $order_id );
		$meta  = [];

		foreach ( $order->get_meta_data() as $item ) {
			$data = $item->get_data();
			if ( str_starts_with( (string) $data['key'], '_fpl_' ) ) {
				$meta[ $data['key'] ] = $data['value'];
			}
		}

		ksort( $meta );

		return $meta;
	}

	/**
	 * SHA-256 of every legacy file, keyed by path.
	 *
	 * @return array<string, string>
	 */
	public function file_hashes(): array {
		$hashes = [];
		foreach ( $this->files as $path ) {
			$hashes[ $path ] = (string) hash_file( 'sha256', $path );
		}

		return $hashes;
	}

	/**
	 * Removes the legacy files and table.
	 */
	public function cleanup(): void {
		global $wpdb;

		foreach ( $this->files as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		$this->files = [];

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
