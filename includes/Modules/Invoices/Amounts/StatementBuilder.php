<?php
/**
 * Builds the amounts statement of an order.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Amounts;

defined( 'ABSPATH' ) || exit;

use WC_Order;
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

		$lines = [];
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$lines[] = self::line( $item, $content, $incl, $tax_items );
			}
		}

		$rows = [];

		$subtotal = 0;
		foreach ( $lines as $line ) {
			$subtotal += $line->total;
		}
		$rows[] = new TotalRow( 'subtotal', self::label( $content, 'subtotal', __( 'Subtotal', 'lumia-factures' ) ), $subtotal );

		$discount = Money::to_minor( $order->get_discount_total() ) + ( $incl ? Money::to_minor( $order->get_discount_tax() ) : 0 );
		if ( 0 !== $discount ) {
			$rows[] = new TotalRow( 'discount', self::label( $content, 'discount', __( 'Discount', 'lumia-factures' ) ), -$discount );
		}

		$codes = $order->get_coupon_codes();
		if ( ! empty( $content['total_discount_code_enabled'] ) && [] !== $codes ) {
			$rows[] = new TotalRow(
				'coupons',
				self::label( $content, 'coupons', _n( 'Coupon code', 'Coupon codes', count( $codes ), 'lumia-factures' ) ),
				null,
				implode( ', ', $codes ),
				false,
				true
			);
		}

		$shipping = 0;
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			$shipping += Money::to_minor( $shipping_item->get_total() ) + ( $incl ? Money::to_minor( $shipping_item->get_total_tax() ) : 0 );
		}
		if ( 0 !== $shipping || ! empty( $content['total_shipping_always'] ) ) {
			$rows[] = new TotalRow( 'shipping', self::label( $content, 'shipping', __( 'Shipping', 'lumia-factures' ) ), $shipping );
		}

		foreach ( $order->get_fees() as $fee ) {
			$amount = Money::to_minor( $fee->get_total() ) + ( $incl ? Money::to_minor( $fee->get_total_tax() ) : 0 );
			$rows[] = new TotalRow( 'fee', $fee->get_name(), $amount );
		}

		$taxes      = self::tax_rows( $tax_items, $incl );
		$excl_label = self::label( $content, 'total_excl', __( 'Total excl. VAT', 'lumia-factures' ) );

		if ( ! $incl ) {
			$rows[] = new TotalRow( 'total_excl', $excl_label, self::sum( $rows ), null, false, true );
			$rows   = array_merge( $rows, $taxes );
		}

		$total    = Money::to_minor( $order->get_total() );
		$rounding = $total - self::sum( $rows );
		if ( 0 !== $rounding ) {
			$rows[] = new TotalRow( 'rounding', self::label( $content, 'rounding', __( 'Rounding', 'lumia-factures' ) ), $rounding );
		}

		$rows[] = new TotalRow( 'total', self::label( $content, 'total', __( 'Total', 'lumia-factures' ) ), $total, null, true );

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
	 * One product line, before discount.
	 *
	 * @param WC_Order_Item_Product $item      Order item.
	 * @param array<string, mixed>  $content   Content settings.
	 * @param bool                  $incl      Amounts incl. VAT.
	 * @param WC_Order_Item_Tax[]   $tax_items Order tax items.
	 */
	private static function line( WC_Order_Item_Product $item, array $content, bool $incl, array $tax_items ): Line {
		$name = $item->get_name();
		if ( ! empty( $content['item_parent_name_enabled'] ) && $item->get_variation_id() > 0 ) {
			$parent = wc_get_product( $item->get_product_id() );
			if ( $parent ) {
				$name = $parent->get_name();
			}
		}

		$attributes = [];
		if ( ! empty( $content['item_meta_enabled'] ) ) {
			// `true`: keep the attributes already in the variation name, they are needed once the parent name is shown.
			foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
				$attributes[] = [
					'label' => trim( wp_strip_all_tags( (string) $meta->display_key ) ),
					'value' => trim( wp_strip_all_tags( (string) $meta->display_value ) ),
				];
			}
		}

		$total    = Money::to_minor( $item->get_subtotal() ) + ( $incl ? Money::to_minor( $item->get_subtotal_tax() ) : 0 );
		$quantity = $item->get_quantity();
		$unit     = 0.0 === (float) $quantity ? $total : (int) round( $total / $quantity );

		$product  = $item->get_product();
		$product  = $product instanceof WC_Product ? $product : null;
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
			self::line_rate( $item, $tax_items ),
			$item->get_variation_id() > 0 ? $item->get_variation_id() : $item->get_product_id(),
			max( 0, $image_id )
		);
	}

	/**
	 * Rate of a line: the first rate actually applied, read from the order tax item
	 * (frozen when the order was placed), never from the current shop rates.
	 *
	 * @param WC_Order_Item_Product $item      Order item.
	 * @param WC_Order_Item_Tax[]   $tax_items Order tax items.
	 */
	private static function line_rate( WC_Order_Item_Product $item, array $tax_items ): ?float {
		$taxes = $item->get_taxes();
		$found = false;

		foreach ( (array) ( $taxes['subtotal'] ?? [] ) as $rate_id => $amount ) {
			if ( abs( (float) $amount ) < 0.000001 ) {
				continue;
			}
			$found = true;
			foreach ( $tax_items as $tax_item ) {
				if ( (int) $tax_item->get_rate_id() === (int) $rate_id && null !== $tax_item->get_rate_percent() ) {
					return (float) $tax_item->get_rate_percent();
				}
			}
			break;
		}

		return $found ? self::derive_tax_rate( $item ) : null;
	}

	/**
	 * Fallback for an old order whose tax items have no stored percent: derived from the
	 * line amounts, snapped to a standard rate when the gap is only rounding.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 */
	private static function derive_tax_rate( WC_Order_Item_Product $item ): ?float {
		$base = (float) $item->get_subtotal();
		$tax  = (float) $item->get_subtotal_tax();

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
	 * @param WC_Order_Item_Tax[] $tax_items Order tax items.
	 * @param bool                $incl      Amounts incl. VAT.
	 * @return TotalRow[]
	 */
	private static function tax_rows( array $tax_items, bool $incl ): array {
		$groups = [];
		foreach ( $tax_items as $tax_item ) {
			$percent = $tax_item->get_rate_percent();
			if ( null !== $percent ) {
				$label = sprintf(
					/* translators: %s: formatted tax rate, e.g. "20 %" */
					__( 'VAT %s', 'lumia-factures' ),
					self::format_rate( (float) $percent )
				);
			} else {
				// Old order without a stored percent: the tax item's own label.
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

	/**
	 * Label of a row: the stored setting, or the translated default when it is empty.
	 *
	 * @param array<string, mixed> $content Content settings.
	 * @param string               $key     Row key.
	 * @param string               $fallback Translated default.
	 */
	private static function label( array $content, string $key, string $fallback ): string {
		$stored = $content[ 'total_' . $key . '_label' ] ?? '';

		return is_string( $stored ) && '' !== trim( $stored ) ? $stored : $fallback;
	}
}
