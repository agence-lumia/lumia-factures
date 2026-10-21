<?php
/**
 * Helpers shared by the statement builders.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Amounts;

defined( 'ABSPATH' ) || exit;

use WC_Abstract_Order;
use WC_Order_Item;
use WC_Order_Item_Product;
use WC_Order_Item_Tax;
use WC_Product;

/**
 * Reads the stored items of an order or a refund into minor units, product lines, tax rates and
 * the closing rows of a statement.
 *
 * The invoice (`StatementBuilder`) and the credit note (`CreditNoteStatementBuilder`) read items the
 * same way. A refund stores its items with negative amounts and quantities: the credit note passes a
 * sign of -1 so they print positive.
 */
final class Lines {

	/**
	 * Standard rates a derived rate snaps to when the gap is only rounding.
	 */
	private const KNOWN_RATES = [ 20.0, 10.0, 5.5, 2.1, 8.5, 13.0, 6.0, 21.0, 19.0 ];

	/**
	 * Product items of an order or a refund.
	 *
	 * @param WC_Abstract_Order $order Order or refund.
	 * @return WC_Order_Item_Product[]
	 */
	public static function products( WC_Abstract_Order $order ): array {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Tax items of an order or a refund.
	 *
	 * @param WC_Abstract_Order $order Order or refund.
	 * @return WC_Order_Item_Tax[]
	 */
	public static function taxes( WC_Abstract_Order $order ): array {
		$items = [];
		foreach ( $order->get_items( 'tax' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Tax ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * An amount in minor units, its tax added in incl. VAT mode.
	 *
	 * The float sum is rounded once: rounding the excl. VAT amount and the tax apart turns
	 * a 9.99 price stored as 8.325 + 1.665 into 8.33 + 1.67 = 10.00.
	 *
	 * @param float|string $excl Amount excl. VAT.
	 * @param float|string $tax  Its tax.
	 * @param bool         $incl Amounts incl. VAT.
	 */
	public static function amount( float|string $excl, float|string $tax, bool $incl ): int {
		return Money::to_minor( (float) $excl + ( $incl ? (float) $tax : 0.0 ) );
	}

	/**
	 * One product line, before discount.
	 *
	 * @param WC_Order_Item_Product  $item    Order or refund item.
	 * @param array<string, mixed>   $content Content settings.
	 * @param bool                   $incl    Amounts incl. VAT.
	 * @param array<int, float|null> $rates   Rate percent keyed by rate id.
	 * @param int                    $sign    1 for an order item, -1 for a refund item (stored negative).
	 */
	public static function line( WC_Order_Item_Product $item, array $content, bool $incl, array $rates, int $sign = 1 ): Line {
		$parent_name = ! empty( $content['item_parent_name_enabled'] );
		$product     = $item->get_product();
		$product     = $product instanceof WC_Product ? $product : null;

		$name = $item->get_name();
		if ( $parent_name && $item->get_variation_id() > 0 ) {
			$parent = wc_get_product( $item->get_product_id() );
			if ( $parent ) {
				$name = $parent->get_name();
			}
		}

		$attributes = [];
		if ( ! empty( $content['item_meta_enabled'] ) ) {
			// With the parent name, keep the attributes already in the variation name (they would
			// disappear otherwise); without it, WooCommerce hides them so they are not printed twice.
			foreach ( $item->get_formatted_meta_data( '_', $parent_name ) as $meta ) {
				$label = trim( wp_strip_all_tags( (string) $meta->display_key ) );
				if ( null === $product && $meta->display_key === $meta->key ) {
					// Product deleted: WooCommerce can no longer map the attribute slug to its label.
					$label = ucfirst( str_replace( [ '-', '_' ], ' ', $label ) );
				}
				$attributes[] = [
					'label' => $label,
					'value' => trim( wp_strip_all_tags( (string) $meta->display_value ) ),
				];
			}
		}

		$total    = $sign * self::amount( $item->get_subtotal(), $item->get_subtotal_tax(), $incl );
		$quantity = $sign * $item->get_quantity();
		$unit     = 0.0 === (float) $quantity ? $total : (int) round( $total / $quantity );

		$image_id = 0;
		if ( $product ) {
			$image_id = (int) $product->get_image_id();
			if ( $image_id <= 0 && $product->get_parent_id() > 0 ) {
				$parent   = wc_get_product( $product->get_parent_id() );
				$image_id = $parent ? (int) $parent->get_image_id() : 0;
			}
		}

		return new Line(
			$name,
			$attributes,
			$product ? (string) $product->get_sku() : '',
			$quantity,
			$unit,
			$total,
			self::line_rate( $item, $rates, $sign ),
			$item->get_variation_id() > 0 ? $item->get_variation_id() : $item->get_product_id(),
			max( 0, $image_id )
		);
	}

	/**
	 * Percent of every rate of the order, keyed by rate id.
	 *
	 * The stored `rate_percent` of the tax item when there is one. Orders placed before
	 * WooCommerce 3.7 have no such meta and WooCommerce reads it back as 0.0: a 0 % rate
	 * that carries tax is therefore unknown, and derived from the items taxed at that rate id.
	 *
	 * @param WC_Order_Item_Tax[]       $tax_items Order tax items.
	 * @param array<int, WC_Order_Item> $items     Products, shipping and fees.
	 * @return array<int, float|null>
	 */
	public static function rates( array $tax_items, array $items ): array {
		$rates = [];
		foreach ( $tax_items as $tax_item ) {
			$rate_id = (int) $tax_item->get_rate_id();
			$percent = $tax_item->get_rate_percent();
			$taxed   = 0 !== Money::to_minor( $tax_item->get_tax_total() ) + Money::to_minor( $tax_item->get_shipping_tax_total() );

			if ( is_numeric( $percent ) && ! ( 0.0 === (float) $percent && $taxed ) ) {
				$rates[ $rate_id ] = (float) $percent;
				continue;
			}

			$base = 0.0;
			$tax  = 0.0;
			foreach ( $items as $item ) {
				if ( ! is_callable( [ $item, 'get_taxes' ] ) || ! is_callable( [ $item, 'get_total' ] ) ) {
					continue;
				}
				$is_product = $item instanceof WC_Order_Item_Product;
				$taxes      = $item->get_taxes();
				$amount     = (float) ( $taxes[ $is_product ? 'subtotal' : 'total' ][ $rate_id ] ?? 0 );
				if ( abs( $amount ) < 0.000001 ) {
					continue;
				}
				$base += (float) ( $is_product ? $item->get_subtotal() : $item->get_total() );
				$tax  += $amount;
			}

			$rates[ $rate_id ] = self::derive_rate( $base, $tax );
		}

		return $rates;
	}

	/**
	 * Label of a VAT row: `VAT 20 %`, or the tax item's own label when the percent is unknown.
	 *
	 * @param float|null $percent  Rate percent, null when unknown.
	 * @param string     $fallback Label used when the percent is unknown.
	 */
	public static function rate_label( ?float $percent, string $fallback ): string {
		if ( null === $percent ) {
			return $fallback;
		}

		return sprintf(
			/* translators: %s: formatted tax rate, e.g. "20 %" */
			__( 'VAT %s', 'lumia-factures' ),
			self::format_rate( $percent )
		);
	}

	/**
	 * Formats a tax rate: `20 %`, `5,5 %` (shop decimal separator, no-break space).
	 *
	 * @param float $rate Rate percent.
	 */
	public static function format_rate( float $rate ): string {
		$number = rtrim( rtrim( number_format( $rate, 4, '.', '' ), '0' ), '.' );

		return str_replace( '.', wc_get_price_decimal_separator(), $number ) . "\u{00A0}%";
	}

	/**
	 * One `tax` row per label, in first-seen order. Informational ("of which") in incl. VAT mode,
	 * summed in excl. VAT mode.
	 *
	 * @param array<string, int> $groups VAT amount keyed by row label, minor units.
	 * @param bool               $incl   Amounts incl. VAT.
	 * @return TotalRow[]
	 */
	public static function tax_rows( array $groups, bool $incl ): array {
		$rows = [];
		foreach ( $groups as $label => $amount ) {
			$rows[] = new TotalRow( 'tax', (string) $label, $amount, null, false, $incl );
		}

		return $rows;
	}

	/**
	 * Closes a statement: the rows that follow the amounts, in display order.
	 *
	 * Excl. VAT mode: the informational "Total excl. VAT", then the summed VAT rows, a `rounding`
	 * row for any drift left, the grand total. Incl. VAT mode: the `rounding` row, the grand total,
	 * then the informational "Total excl. VAT" and VAT rows ("of which").
	 *
	 * @param TotalRow[] $rows        Rows of the amounts (no VAT row yet).
	 * @param TotalRow[] $taxes       VAT rows (`tax_rows()`).
	 * @param int        $total       Grand total, minor units.
	 * @param bool       $incl        Amounts incl. VAT.
	 * @param string     $grand_label Label of the grand total.
	 * @return TotalRow[]
	 */
	public static function close( array $rows, array $taxes, int $total, bool $incl, string $grand_label ): array {
		$excl_label = __( 'Total excl. VAT', 'lumia-factures' );

		if ( ! $incl ) {
			$rows[] = new TotalRow( 'total_excl', $excl_label, self::sum( $rows ), null, false, true );
			$rows   = array_merge( $rows, $taxes );
		}

		$rounding = $total - self::sum( $rows );
		if ( 0 !== $rounding ) {
			$rows[] = new TotalRow( 'rounding', __( 'Rounding', 'lumia-factures' ), $rounding );
		}

		$rows[] = new TotalRow( 'total', $grand_label, $total, null, true );

		if ( $incl ) {
			$tax_sum = 0;
			foreach ( $taxes as $tax ) {
				$tax_sum += (int) $tax->amount;
			}
			$rows[] = new TotalRow( 'total_excl', $excl_label, $total - $tax_sum, null, false, true );
			$rows   = array_merge( $rows, $taxes );
		}

		return $rows;
	}

	/**
	 * Sum of the summed rows built so far.
	 *
	 * @param TotalRow[] $rows Rows.
	 */
	public static function sum( array $rows ): int {
		return ( new Statement( [], $rows, '', false ) )->sum();
	}

	/**
	 * Rate of a line: the first rate actually applied, read from the order tax items
	 * (frozen when the order was placed), never from the current shop rates.
	 *
	 * @param WC_Order_Item_Product  $item  Order or refund item.
	 * @param array<int, float|null> $rates Rate percent keyed by rate id.
	 * @param int                    $sign  1 for an order item, -1 for a refund item.
	 */
	private static function line_rate( WC_Order_Item_Product $item, array $rates, int $sign ): ?float {
		$taxes = $item->get_taxes();

		foreach ( (array) ( $taxes['subtotal'] ?? [] ) as $rate_id => $amount ) {
			if ( abs( (float) $amount ) < 0.000001 ) {
				continue;
			}

			return $rates[ (int) $rate_id ] ?? self::derive_rate( $sign * (float) $item->get_subtotal(), $sign * (float) $amount );
		}

		return null;
	}

	/**
	 * Fallback for an old order: the rate derived from amounts, snapped to a standard rate
	 * when the gap is only rounding.
	 *
	 * @param float $base Amount excl. VAT.
	 * @param float $tax  Tax on that amount.
	 */
	private static function derive_rate( float $base, float $tax ): ?float {
		if ( $base <= 0.0 || $tax <= 0.0 ) {
			return null;
		}

		$rate = $tax / $base * 100;
		foreach ( self::KNOWN_RATES as $known ) {
			if ( abs( $rate - $known ) < 0.35 ) {
				return $known;
			}
		}

		return round( $rate, 1 );
	}
}
