<?php
/**
 * Cancels the invoice of an unpaid order: a full refund that moves no money, credited by a credit note.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Documents;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Amounts\Money;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Throwable;
use WC_Order;
use WC_Order_Item;
use WC_Order_Refund;

/**
 * An issued invoice never changes: an order cancelled after its invoice (unpaid, failed, on hold)
 * is cancelled by a credit note. WooCommerce only issues a credit note for a refund, so the
 * cancellation records a refund of everything not refunded yet, without asking the gateway and
 * without restoring stock; CreditNoteIssuer issues its credit note through the normal flow
 * (`woocommerce_order_refunded`).
 */
final class InvoiceCancellation {

	/**
	 * Prefix of the option locking the cancellation of one order (two clicks, two requests).
	 */
	private const LOCK = 'lumia_factures_cancel_lock_';

	/**
	 * Age, in seconds, after which a lock left by a request that died is taken over.
	 */
	private const LOCK_TTL = 60;

	/**
	 * Largest gap, in minor units, between the remaining amount and the remaining items still taken
	 * as rounding (CreditNoteStatementBuilder::ROUNDING_TOLERANCE).
	 */
	private const ROUNDING_TOLERANCE = 2;

	/**
	 * Why the invoice of the order cannot be cancelled now. Reads only.
	 *
	 * @param WC_Order $order Order.
	 * @return string '' when it can; otherwise `import` (the Facture PDF Lumia import blocks issuing),
	 *                `no_invoice` (no issued invoice), `paid` (a paid order is refunded, not
	 *                cancelled) or `nothing_left` (everything is refunded already).
	 */
	public static function refusal( WC_Order $order ): string {
		if ( Migration::blocks_issuing() ) {
			return 'import';
		}

		$invoice = $order->get_id() > 0 ? Register::find( Issuer::TYPE, $order->get_id() ) : null;
		if ( null === $invoice || 'issued' !== $invoice->status || null === $invoice->number || '' === $invoice->number ) {
			return 'no_invoice';
		}

		if ( $order->is_paid() ) {
			return 'paid';
		}

		if ( Money::to_minor( $order->get_remaining_refund_amount() ) <= 0 ) {
			return 'nothing_left';
		}

		return '';
	}

	/**
	 * Cancels the invoice: records a full refund of what is left, no payment refunded, no stock
	 * restored. Its credit note follows through `woocommerce_order_refunded`.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Refund reason, printed on the credit note.
	 * @return WC_Order_Refund|null The refund, null when refused (see refusal()) or on failure (logged).
	 */
	public static function cancel( WC_Order $order, string $reason ): ?WC_Order_Refund {
		$order_id = $order->get_id();
		$token    = $order_id > 0 ? self::lock( $order_id ) : null;
		if ( null === $token ) {
			return null;
		}

		try {
			// Read again under the lock: a refund saved by another request since must be counted.
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh instanceof WC_Order || '' !== self::refusal( $fresh ) ) {
				return null;
			}

			$amount = (float) $fresh->get_remaining_refund_amount();

			$refund = wc_create_refund(
				[
					'order_id'       => $order_id,
					'amount'         => $amount,
					'reason'         => $reason,
					'line_items'     => self::remaining_items( $fresh, $amount ),
					'refund_payment' => false,
					'restock_items'  => false,
				]
			);

			if ( is_wp_error( $refund ) ) {
				Pipeline::log( 'The invoice of order ' . $order_id . ' could not be cancelled: ' . $refund->get_error_message() );
				return null;
			}

			$saved = wc_get_order( $refund->get_id() );

			return $saved instanceof WC_Order_Refund ? $saved : null;
		} catch ( Throwable $e ) {
			Pipeline::log( 'The invoice of order ' . $order_id . ' could not be cancelled: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			return null;
		} finally {
			self::unlock( $order_id, $token );
		}
	}

	/**
	 * What is left to refund of each item (products, shipping, fees), as `wc_create_refund()` takes it.
	 *
	 * Sent only when the items left add up to the amount left (to rounding): when an earlier refund
	 * was made "by amount", the items do not say what is left, and the refund goes by amount too
	 * (its credit note splits the VAT over the order's rates).
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount left to refund.
	 * @return array<int, array{qty: int|float, refund_total: float, refund_tax: array<int, float>}>
	 */
	private static function remaining_items( WC_Order $order, float $amount ): array {
		$decimals = wc_get_price_decimals();
		$items    = [];
		$sum      = 0.0;

		foreach ( $order->get_items( [ 'line_item', 'shipping', 'fee' ] ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item ) {
				continue;
			}

			$item_id = (int) $item_id;
			$type    = $item->get_type();
			$is_line = 'line_item' === $type;

			$quantity = $is_line && is_callable( [ $item, 'get_quantity' ] )
				? $item->get_quantity() + $order->get_qty_refunded_for_item( $item_id )
				: 0;
			$total    = is_callable( [ $item, 'get_total' ] )
				? round( (float) $item->get_total() - $order->get_total_refunded_for_item( $item_id, $type ), $decimals )
				: 0.0;

			$taxes      = is_callable( [ $item, 'get_taxes' ] ) ? $item->get_taxes() : [];
			$refund_tax = [];
			foreach ( (array) ( $taxes['total'] ?? [] ) as $rate_id => $tax ) {
				$left = round( (float) $tax - $order->get_tax_refunded_for_item( $item_id, (int) $rate_id, $type ), $decimals );
				if ( 0.0 !== $left ) {
					$refund_tax[ (int) $rate_id ] = $left;
				}
			}

			if ( $quantity <= 0 && 0.0 === $total && [] === $refund_tax ) {
				continue;
			}

			$items[ $item_id ] = [
				'qty'          => max( 0, $quantity ),
				'refund_total' => $total,
				'refund_tax'   => $refund_tax,
			];
			$sum              += $total + array_sum( $refund_tax );
		}

		if ( abs( Money::to_minor( $sum ) - Money::to_minor( $amount ) ) > self::ROUNDING_TOLERANCE ) {
			return [];
		}

		return $items;
	}

	/**
	 * Takes the order's cancellation lock.
	 *
	 * `add_option()` is not atomic (it reads first, then inserts with ON DUPLICATE KEY UPDATE, so two
	 * requests can both succeed): the row is inserted with INSERT IGNORE, which only one request can
	 * do, as Migration does for its run lock. A lock older than LOCK_TTL comes from a request that
	 * died and is taken over with a compare-and-swap UPDATE on its old value.
	 *
	 * @param int $order_id Order id.
	 * @return string|null Lock value, null when another request holds it.
	 */
	private static function lock( int $order_id ): ?string {
		global $wpdb;

		$name  = self::LOCK . $order_id;
		$token = time() . '-' . wp_generate_password( 12, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock, the options API cannot do it.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $token ) );
		wp_cache_delete( $name, 'options' );

		if ( 1 === $inserted ) {
			return $token;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass the cache.
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( ! is_string( $current ) || (int) $current >= time() - self::LOCK_TTL ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on the stale value.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $current ) );
		wp_cache_delete( $name, 'options' );

		return 1 === $taken ? $token : null;
	}

	/**
	 * Releases the lock, only when it is still ours.
	 *
	 * @param int    $order_id Order id.
	 * @param string $token    Lock value.
	 */
	private static function unlock( int $order_id, string $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded delete of the lock row.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK . $order_id, $token ) );
		wp_cache_delete( self::LOCK . $order_id, 'options' );
	}
}
