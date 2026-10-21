<?php
/**
 * Builds the amounts statement of an order.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Amounts;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use WC_Order_Item_Tax;

/**
 * The single calculation path of invoice lines and totals.
 *
 * Every amount is read from the order's stored items and converted to minor units at once.
 * Lines show the price before discount (the discount is one row of the totals), and the
 * summed rows always equal the order total: a drift left by WooCommerce's own rounding is
 * printed as a `rounding` row, never absorbed silently into another row. The item readers
 * are shared with the credit note (`Lines`).
 */
final class StatementBuilder {

	/**
	 * Builds the statement.
	 *
	 * @param WC_Order                            $order    Order.
	 * @param array<string, array<string, mixed>> $settings Module settings (`Module::settings()`).
	 */
	public static function build( WC_Order $order, array $settings ): Statement {
		$content = isset( $settings['content'] ) && is_array( $settings['content'] ) ? $settings['content'] : [];
		$incl    = 'incl' === ( $content['price_display'] ?? 'incl' );

		$tax_items      = Lines::taxes( $order );
		$product_items  = Lines::products( $order );
		$shipping_items = array_values( $order->get_shipping_methods() );
		$fee_items      = array_values( $order->get_fees() );

		$rates = Lines::rates( $tax_items, array_merge( $product_items, $shipping_items, $fee_items ) );

		$lines = [];
		foreach ( $product_items as $item ) {
			$lines[] = Lines::line( $item, $content, $incl, $rates );
		}

		$rows = [];

		$subtotal = 0;
		foreach ( $lines as $line ) {
			$subtotal += $line->total;
		}
		$rows[] = new TotalRow( 'subtotal', __( 'Subtotal', 'lumia-factures' ), $subtotal );

		$discount = Lines::amount( $order->get_discount_total(), $order->get_discount_tax(), $incl );
		if ( 0 !== $discount ) {
			$rows[] = new TotalRow( 'discount', __( 'Discount', 'lumia-factures' ), -$discount );
		}

		$codes = array_map( 'wc_strtoupper', $order->get_coupon_codes() );
		if ( ! empty( $content['total_discount_code_enabled'] ) && [] !== $codes ) {
			$rows[] = new TotalRow(
				'coupons',
				_n( 'Coupon code', 'Coupon codes', count( $codes ), 'lumia-factures' ),
				null,
				implode( ', ', $codes ),
				false,
				true
			);
		}

		$shipping = 0;
		foreach ( $shipping_items as $shipping_item ) {
			$shipping += Lines::amount( $shipping_item->get_total(), $shipping_item->get_total_tax(), $incl );
		}
		if ( 0 !== $shipping || ! empty( $content['total_shipping_always'] ) ) {
			$rows[] = new TotalRow( 'shipping', __( 'Shipping', 'lumia-factures' ), $shipping );
		}

		foreach ( $fee_items as $fee ) {
			$rows[] = new TotalRow( 'fee', $fee->get_name(), Lines::amount( $fee->get_total(), $fee->get_total_tax(), $incl ) );
		}

		$total = Money::to_minor( $order->get_total() );
		if ( $incl ) {
			$rows = self::balance_discount( $rows, $total );
		}

		$rows = Lines::close(
			$rows,
			self::tax_rows( $tax_items, $rates, $incl ),
			$total,
			$incl,
			_x( 'Total', 'grand total row of the invoice, VAT included', 'lumia-factures' )
		);

		return new Statement( $lines, $rows, $order->get_currency(), $incl );
	}

	/**
	 * Formats a tax rate: `20 %`, `5,5 %` (shop decimal separator, no-break space).
	 *
	 * @param float $rate Rate percent.
	 */
	public static function format_rate( float $rate ): string {
		return Lines::format_rate( $rate );
	}

	/**
	 * One `tax` row per rate: the products, fees and shipping tax of the order tax items.
	 * Informational ("of which") in incl. VAT mode, summed in excl. VAT mode.
	 *
	 * @param WC_Order_Item_Tax[]    $tax_items Order tax items.
	 * @param array<int, float|null> $rates     Rate percent keyed by rate id.
	 * @param bool                   $incl      Amounts incl. VAT.
	 * @return TotalRow[]
	 */
	private static function tax_rows( array $tax_items, array $rates, bool $incl ): array {
		$groups = [];
		foreach ( $tax_items as $tax_item ) {
			// Last resort (no stored percent, nothing to derive it from): the tax item's own label.
			$label = Lines::rate_label( $rates[ (int) $tax_item->get_rate_id() ] ?? null, (string) $tax_item->get_label() );

			$groups[ $label ] = ( $groups[ $label ] ?? 0 )
				+ Money::to_minor( $tax_item->get_tax_total() )
				+ Money::to_minor( $tax_item->get_shipping_tax_total() );
		}

		return Lines::tax_rows( $groups, $incl );
	}

	/**
	 * In incl. VAT mode, makes the discount the balancing figure of the summed rows.
	 *
	 * WooCommerce rounds the discount per tax rate, so a 5.00 coupon split over two rates reads 4.99
	 * once re-added incl. VAT, with a 0.01 rounding row to compensate. The discount the customer was
	 * given is what balances the order total, so it is printed as that and no rounding row remains.
	 * A gap larger than a few minor units is not rounding and is left to the rounding row.
	 *
	 * @param TotalRow[] $rows  Rows built so far (no tax rows yet).
	 * @param int        $total Order total, minor units.
	 * @return TotalRow[]
	 */
	private static function balance_discount( array $rows, int $total ): array {
		foreach ( $rows as $index => $row ) {
			if ( 'discount' !== $row->key ) {
				continue;
			}

			$others = 0;
			foreach ( $rows as $other ) {
				if ( $other !== $row && ! $other->informational && ! $other->grand && null !== $other->amount ) {
					$others += $other->amount;
				}
			}

			$balanced = $total - $others;
			if ( $balanced < 0 && abs( $balanced - (int) $row->amount ) <= 5 ) {
				$rows[ $index ] = new TotalRow( 'discount', $row->label, $balanced );
			}
		}

		return $rows;
	}
}
