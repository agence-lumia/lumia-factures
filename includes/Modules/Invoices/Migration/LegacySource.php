<?php
/**
 * Read-only access to the legacy Facture PDF Lumia (1.x) data.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;

/**
 * Reads the option `fpl_settings`, the table `{prefix}fpl_sequences` and the order meta
 * `_fpl_invoice_*`. Nothing here writes: the legacy data stays as a fallback.
 */
final class LegacySource {

	/**
	 * Legacy settings option.
	 */
	public const OPTION = 'fpl_settings';

	/**
	 * Legacy order meta holding the invoice number.
	 */
	public const META_NUMBER = '_fpl_invoice_number';

	/**
	 * Whether the site has legacy data to import: settings or any invoiced order.
	 */
	public static function has_data(): bool {
		return false !== get_option( self::OPTION, false ) || [] !== self::order_ids( 0, 1 );
	}

	/**
	 * Legacy settings.
	 *
	 * @return array<string, mixed>|null Null when the option is missing or not an array.
	 */
	public static function settings(): ?array {
		$settings = get_option( self::OPTION, null );

		return is_array( $settings ) ? $settings : null;
	}

	/**
	 * Legacy counters, keyed by sequence key.
	 *
	 * The table is read with a plain SELECT whose error is suppressed rather than looked up in
	 * SHOW TABLES: a missing table simply gives no counter.
	 *
	 * @return array<string, int> Empty when the table is missing.
	 */
	public static function sequences(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fpl_sequences';

		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- legacy table, name built from the site prefix.
		$rows = $wpdb->get_results( "SELECT sequence_key, current_value FROM {$table}", ARRAY_A );
		$wpdb->suppress_errors( $suppressed );

		$sequences = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$sequences[ (string) $row['sequence_key'] ] = (int) $row['current_value'];
		}

		return $sequences;
	}

	/**
	 * Legacy invoices of the orders after a given id, by ascending order id.
	 *
	 * `issued_at` is `_fpl_invoice_date`, else `_fpl_invoice_generated_at`, else the order creation
	 * date; `date_source` says which one (`invoice_date`, `generated_at`, `order_created`).
	 * `file` is the path relative to `uploads/facture-pdf-lumia/` ('' when none is recorded).
	 *
	 * @param int $after_order_id Cursor: only orders with a greater id.
	 * @param int $limit          Maximum number of orders.
	 * @return array<int, array{order_id: int, number: string, sequence: int, sequence_key: string, issued_at: int, date_source: string, file: string}>
	 */
	public static function invoices( int $after_order_id, int $limit ): array {
		$invoices = [];
		$limit    = max( 1, $limit );
		$found    = 0;

		// An order that cannot be read is skipped, and more ids are read in its place: a short
		// batch always means the end, so a caller paging until a short or empty batch misses nothing.
		while ( $found < $limit ) {
			$ids = self::order_ids( $after_order_id, $limit - $found );
			if ( [] === $ids ) {
				break;
			}

			foreach ( $ids as $order_id ) {
				$after_order_id = $order_id;
				$invoice        = self::invoice( $order_id );
				if ( null !== $invoice ) {
					$invoices[] = $invoice;
					++$found;
				}
			}
		}

		return $invoices;
	}

	/**
	 * The legacy invoice of one order.
	 *
	 * @param int $order_id Order id.
	 * @return array{order_id: int, number: string, sequence: int, sequence_key: string, issued_at: int, date_source: string, file: string}|null
	 */
	private static function invoice( int $order_id ): ?array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			error_log( '[lumia-factures] Order ' . $order_id . ' carries a legacy invoice number but could not be loaded: skipped.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
			return null;
		}

		$number = trim( (string) $order->get_meta( self::META_NUMBER ) );
		if ( '' === $number ) {
			return null;
		}

		[ $issued_at, $source ] = self::issued_at( $order );

		return [
			'order_id'     => $order_id,
			'number'       => $number,
			'sequence'     => (int) $order->get_meta( '_fpl_invoice_sequence' ),
			'sequence_key' => (string) $order->get_meta( '_fpl_invoice_sequence_key' ),
			'issued_at'    => $issued_at,
			'date_source'  => $source,
			'file'         => (string) $order->get_meta( '_fpl_invoice_file' ),
		];
	}

	/**
	 * Ids of the orders carrying a legacy invoice number, after a cursor.
	 *
	 * A prepared query on the meta table of the active order storage, not `wc_get_orders()`:
	 * it has no storage-independent "id greater than" filter for the cursor, and its default
	 * statuses skip trashed orders, whose invoices were issued all the same and must be imported
	 * (otherwise their numbers could be issued again).
	 *
	 * @param int $after_order_id Cursor.
	 * @param int $limit          Maximum number of ids.
	 * @return int[]
	 */
	private static function order_ids( int $after_order_id, int $limit ): array {
		global $wpdb;

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders = OrderUtil::get_table_for_orders();
			$meta   = OrderUtil::get_table_for_order_meta();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from WooCommerce.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.order_id FROM {$meta} m INNER JOIN {$orders} o ON o.id = m.order_id WHERE m.meta_key = %s AND m.meta_value <> '' AND o.type = 'shop_order' AND m.order_id > %d ORDER BY m.order_id ASC LIMIT %d", self::META_NUMBER, $after_order_id, $limit ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- legacy order storage, no WooCommerce API with an id cursor.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value <> '' AND p.post_type = 'shop_order' AND pm.post_id > %d ORDER BY pm.post_id ASC LIMIT %d", self::META_NUMBER, $after_order_id, $limit ) );
		}

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * Issue date of a legacy invoice and where it comes from.
	 *
	 * @param WC_Order $order Order.
	 * @return array{0: int, 1: string} UTC timestamp and source.
	 */
	private static function issued_at( WC_Order $order ): array {
		foreach ( [
			'_fpl_invoice_date'         => 'invoice_date',
			'_fpl_invoice_generated_at' => 'generated_at',
		] as $key => $source ) {
			$value = $order->get_meta( $key );
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return [ (int) $value, $source ];
			}
		}

		$created = $order->get_date_created();

		return [ null !== $created ? $created->getTimestamp() : time(), 'order_created' ];
	}
}
