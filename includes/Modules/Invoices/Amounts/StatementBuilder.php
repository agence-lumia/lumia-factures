<?php
/**
 * Builds the amounts statement of an order.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Amounts;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;
use WC_Order_Item_Tax;
use WC_Product;

/**
 * The single calculation path of invoice lines and totals.
 *
 * Every amount is read from the order's stored items and converted to minor units at once.
 * Lines show the price before discount (the discount is one row of the totals), and the
 * summed rows always equal the order total: a drift left by WooCommerce's own rounding is
 * printed as a `rounding` row, never absorbed silently into another row.
 */
final class StatementBuilder {

	/**
	 * Standard rates a derived rate snaps to when the gap is only rounding.
	 */
	private const KNOWN_RATES = [ 20.0, 10.0, 5.5, 2.1, 8.5, 13.0, 6.0, 21.0, 19.0 ];

	/**
	 * Builds the statement.
	 *
	 * @param WC_Order                            $order    Order.
	 * @param array<string, array<string, mixed>> $settings Module settings (`Module::settings()`).
	 */
	public static function build( WC_Order $order, array $settings ): Statement {
		$content = isset( $settings['content'] ) && is_array( $settings['content'] ) ? $settings['content'] : [];
		$incl    = 'incl' === ( $content['price_display'] ?? 'incl' );

		$tax_items = [];
		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			if ( $tax_item instanceof WC_Order_Item_Tax ) {
				$tax_items[] = $tax_item;
			}
		}

		$product_items = [];
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$product_items[] = $item;
			}
		}
		$shipping_items = array_values( $order->get_shipping_methods() );
		$fee_items      = array_values( $order->get_fees() );

		$rates = self::rates( $tax_items, array_merge( $product_items, $shipping_items, $fee_items ) );

		$lines = [];
		foreach ( $product_items as $item ) {
			$lines[] = self::line( $item, $content, $incl, $rates );
		}

		$rows = [];

		$subtotal = 0;
		foreach ( $lines as $line ) {
			$subtotal += $line->total;
		}
		$rows[] = new TotalRow( 'subtotal', __( 'Subtotal', 'lumia-factures' ), $subtotal );

		$discount = self::amount( $order->get_discount_total(), $order->get_discount_tax(), $incl );
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
			$shipping += self::amount( $shipping_item->get_total(), $shipping_item->get_total_tax(), $incl );
		}
		if ( 0 !== $shipping || ! empty( $content['total_shipping_always'] ) ) {
			$rows[] = new TotalRow( 'shipping', __( 'Shipping', 'lumia-factures' ), $shipping );
		}

		foreach ( $fee_items as $fee ) {
			$rows[] = new TotalRow( 'fee', $fee->get_name(), self::amount( $fee->get_total(), $fee->get_total_tax(), $incl ) );
		}

		$taxes      = self::tax_rows( $tax_items, $rates, $incl );
		$excl_label = __( 'Total excl. VAT', 'lumia-factures' );

		if ( ! $incl ) {
			$rows[] = new TotalRow( 'total_excl', $excl_label, self::sum( $rows ), null, false, true );
			$rows   = array_merge( $rows, $taxes );
		}

		$total    = Money::to_minor( $order->get_total() );
		$rounding = $total - self::sum( $rows );
		if ( 0 !== $rounding ) {
			$rows[] = new TotalRow( 'rounding', __( 'Rounding', 'lumia-factures' ), $rounding );
		}

		$rows[] = new TotalRow( 'total', __( 'Total', 'lumia-factures' ), $total, null, true );

		if ( $incl ) {
			$tax_sum = 0;
			foreach ( $taxes as $tax ) {
				$tax_sum += (int) $tax->amount;
			}
			$rows[] = new TotalRow( 'total_excl', $excl_label, $total - $tax_sum, null, false, true );
			$rows   = array_merge( $rows, $taxes );
		}

		return new Statement( $lines, $rows, $order->get_currency(), $incl );
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
	 * An amount in minor units, its tax added in incl. VAT mode.
	 *
	 * The float sum is rounded once: rounding the excl. VAT amount and the tax apart turns
	 * a 9.99 price stored as 8.325 + 1.665 into 8.33 + 1.67 = 10.00.
	 *
	 * @param float|string $excl Amount excl. VAT.
	 * @param float|string $tax  Its tax.
	 * @param bool         $incl Amounts incl. VAT.
	 */
	private static function amount( float|string $excl, float|string $tax, bool $incl ): int {
		return Money::to_minor( (float) $excl + ( $incl ? (float) $tax : 0.0 ) );
	}

	/**
	 * One product line, before discount.
	 *
	 * @param WC_Order_Item_Product  $item    Order item.
	 * @param array<string, mixed>   $content Content settings.
	 * @param bool                   $incl    Amounts incl. VAT.
	 * @param array<int, float|null> $rates   Rate percent keyed by rate id.
	 */
	private static function line( WC_Order_Item_Product $item, array $content, bool $incl, array $rates ): Line {
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

		$total    = self::amount( $item->get_subtotal(), $item->get_subtotal_tax(), $incl );
		$quantity = $item->get_quantity();
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
			self::line_rate( $item, $rates ),
			$item->get_variation_id() > 0 ? $item->get_variation_id() : $item->get_product_id(),
			max( 0, $image_id )
		);
	}

	/**
	 * Rate of a line: the first rate actually applied, read from the order tax items
	 * (frozen when the order was placed), never from the current shop rates.
	 *
	 * @param WC_Order_Item_Product  $item  Order item.
	 * @param array<int, float|null> $rates Rate percent keyed by rate id.
	 */
	private static function line_rate( WC_Order_Item_Product $item, array $rates ): ?float {
		$taxes = $item->get_taxes();

		foreach ( (array) ( $taxes['subtotal'] ?? [] ) as $rate_id => $amount ) {
			if ( abs( (float) $amount ) < 0.000001 ) {
				continue;
			}

			return $rates[ (int) $rate_id ] ?? self::derive_rate( (float) $item->get_subtotal(), (float) $amount );
		}

		return null;
	}

	/**
	 * Percent of every rate of the order, keyed by rate id.
	 *
	 * The stored `rate_percent` of the tax item when there is one. Orders placed before
	 * WooCommerce 3.7 have no such meta and WooCommerce reads it back as 0.0: a 0 % rate
	 * that carries tax is therefore unknown, and derived from the items taxed at that rate id.
	 *
	 * @param WC_Order_Item_Tax[]        $tax_items Order tax items.
	 * @param array<int, WC_Order_Item>  $items     Products, shipping and fees.
	 * @return array<int, float|null>
	 */
	private static function rates( array $tax_items, array $items ): array {
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
			$percent = $rates[ (int) $tax_item->get_rate_id() ] ?? null;
			if ( null !== $percent ) {
				$label = sprintf(
					/* translators: %s: formatted tax rate, e.g. "20 %" */
					__( 'VAT %s', 'lumia-factures' ),
					self::format_rate( $percent )
				);
			} else {
				// Last resort (no stored percent, nothing to derive it from): the tax item's own label.
				$label = (string) $tax_item->get_label();
			}

			$groups[ $label ] = ( $groups[ $label ] ?? 0 )
				+ Money::to_minor( $tax_item->get_tax_total() )
				+ Money::to_minor( $tax_item->get_shipping_tax_total() );
		}

		$rows = [];
		foreach ( $groups as $label => $amount ) {
			$rows[] = new TotalRow( 'tax', (string) $label, $amount, null, false, $incl );
		}

		return $rows;
	}

	/**
	 * Sum of the summed rows built so far.
	 *
	 * @param TotalRow[] $rows Rows.
	 */
	private static function sum( array $rows ): int {
		return ( new Statement( [], $rows, '', false ) )->sum();
	}
}
