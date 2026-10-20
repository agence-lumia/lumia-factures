<?php
/**
 * Builds the amounts statement of a credit note.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Amounts;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Tax;
use WC_Order_Refund;

/**
 * The single calculation path of credit note lines and totals: one refund, read against its order.
 *
 * Amounts print positive (WooCommerce stores a refund's items negative) and the summed rows always
 * equal the refunded amount. The refunded items become the lines, the shipping and fee rows; no
 * discount or coupon row (a refunded item already carries its discounted price). Whatever the
 * refund holds beyond its items (a refund "by amount", or an amount that differs from the items by
 * more than rounding) is one signed `adjustment` row, split over the VAT rates of the order.
 *
 * VAT rates and labels are read from the order's tax items, frozen when the order was placed,
 * never from the refund's tax items, which WooCommerce stamps with the shop rates of the refund day.
 */
final class CreditNoteStatementBuilder {

	/**
	 * Largest gap, in minor units, between the refunded amount and its items still taken as rounding.
	 */
	private const ROUNDING_TOLERANCE = 2;

	/**
	 * Bucket of the order's untaxed amounts in the adjustment split (WooCommerce rate ids start at 1).
	 */
	private const UNTAXED = 0;

	/**
	 * Builds the statement.
	 *
	 * @param WC_Order_Refund                     $refund   Refund.
	 * @param WC_Order                            $order    The refunded order (its VAT rates, currency).
	 * @param array<string, array<string, mixed>> $settings Module settings (`Module::settings()`).
	 */
	public static function build( WC_Order_Refund $refund, WC_Order $order, array $settings ): Statement {
		$content = isset( $settings['content'] ) && is_array( $settings['content'] ) ? $settings['content'] : [];
		$incl    = 'incl' === ( $content['price_display'] ?? 'incl' );

		$order_taxes = Lines::taxes( $order );
		$order_items = array_merge( Lines::products( $order ), array_values( $order->get_shipping_methods() ), array_values( $order->get_fees() ) );
		$rates       = Lines::rates( $order_taxes, $order_items );
		$labels      = [];
		foreach ( $order_taxes as $tax_item ) {
			$rate_id            = (int) $tax_item->get_rate_id();
			$labels[ $rate_id ] = Lines::rate_label( $rates[ $rate_id ] ?? null, (string) $tax_item->get_label() );
		}

		// Sum of the refunded items incl. VAT, whatever the display: the adjustment does not depend on it.
		$items_incl = 0;

		$lines = [];
		foreach ( Lines::products( $refund ) as $item ) {
			$lines[]     = Lines::line( $item, $content, $incl, $rates, -1 );
			$items_incl -= Lines::amount( $item->get_subtotal(), $item->get_subtotal_tax(), true );
		}

		$rows = [];
		if ( [] !== $lines ) {
			$subtotal = 0;
			foreach ( $lines as $line ) {
				$subtotal += $line->total;
			}
			$rows[] = new TotalRow( 'subtotal', __( 'Subtotal', 'lumia-factures' ), $subtotal );
		}

		$shipping = 0;
		foreach ( $refund->get_shipping_methods() as $shipping_item ) {
			$shipping   -= Lines::amount( $shipping_item->get_total(), $shipping_item->get_total_tax(), $incl );
			$items_incl -= Lines::amount( $shipping_item->get_total(), $shipping_item->get_total_tax(), true );
		}
		if ( 0 !== $shipping ) {
			$rows[] = new TotalRow( 'shipping', __( 'Shipping', 'lumia-factures' ), $shipping );
		}

		foreach ( $refund->get_fees() as $fee ) {
			$rows[]      = new TotalRow( 'fee', $fee->get_name(), -Lines::amount( $fee->get_total(), $fee->get_total_tax(), $incl ) );
			$items_incl -= Lines::amount( $fee->get_total(), $fee->get_total_tax(), true );
		}

		$total  = Money::to_minor( $refund->get_amount() );
		$groups = self::refunded_taxes( Lines::taxes( $refund ), $labels );

		$gap = $total - $items_incl;
		if ( abs( $gap ) > self::ROUNDING_TOLERANCE ) {
			$excl = 0;
			foreach ( self::split( $gap, $order_items ) as $rate_id => $share ) {
				$percent = $rates[ $rate_id ] ?? null;
				$tax     = null === $percent ? 0 : (int) round( $share * $percent / ( 100 + $percent ) );
				$excl   += $share - $tax;
				if ( 0 !== $tax ) {
					$label            = $labels[ $rate_id ] ?? Lines::rate_label( $percent, '' );
					$groups[ $label ] = ( $groups[ $label ] ?? 0 ) + $tax;
				}
			}

			$rows[] = new TotalRow( 'adjustment', __( 'Other refunded amount', 'lumia-factures' ), $incl ? $gap : $excl );
		}

		$rows = Lines::close( $rows, Lines::tax_rows( $groups, $incl ), $total, $incl, __( 'Total refunded', 'lumia-factures' ) );

		return new Statement( $lines, $rows, $order->get_currency(), $incl );
	}

	/**
	 * VAT refunded, keyed by the label of the order's rate: the refund's own tax items (what
	 * WooCommerce recorded), positive.
	 *
	 * @param WC_Order_Item_Tax[] $tax_items Refund tax items.
	 * @param array<int, string>  $labels    Row label of each order rate, keyed by rate id.
	 * @return array<string, int>
	 */
	private static function refunded_taxes( array $tax_items, array $labels ): array {
		$groups = [];
		foreach ( $tax_items as $tax_item ) {
			// A rate the order does not know (never seen in practice): the refund tax item's own label.
			$label = $labels[ (int) $tax_item->get_rate_id() ] ?? (string) $tax_item->get_label();

			$groups[ $label ] = ( $groups[ $label ] ?? 0 )
				- Money::to_minor( $tax_item->get_tax_total() )
				- Money::to_minor( $tax_item->get_shipping_tax_total() );
		}

		return $groups;
	}

	/**
	 * Splits a signed amount over the order's rates, prorata the amount paid incl. VAT at each rate,
	 * with the largest remainders so the shares add up exactly to the amount.
	 *
	 * An item without tax counts in the untaxed bucket. The weights are what was paid (after
	 * discount); an item taxed at several rates counts at each of them, which only skews the
	 * weights (compound rates are not used for VAT).
	 *
	 * @param int                       $amount Signed amount, minor units.
	 * @param array<int, WC_Order_Item> $items  Order products, shipping and fees.
	 * @return array<int, int> Signed share keyed by rate id (`UNTAXED` for the untaxed part).
	 */
	private static function split( int $amount, array $items ): array {
		$weights = [];
		foreach ( $items as $item ) {
			if ( ! is_callable( [ $item, 'get_taxes' ] ) || ! is_callable( [ $item, 'get_total' ] ) ) {
				continue;
			}

			$taxes = $item->get_taxes();
			$taxed = false;
			foreach ( (array) ( $taxes['total'] ?? [] ) as $rate_id => $tax ) {
				if ( abs( (float) $tax ) < 0.000001 ) {
					continue;
				}
				$weights[ (int) $rate_id ] = ( $weights[ (int) $rate_id ] ?? 0 ) + Lines::amount( $item->get_total(), (float) $tax, true );
				$taxed                     = true;
			}
			if ( ! $taxed ) {
				$weights[ self::UNTAXED ] = ( $weights[ self::UNTAXED ] ?? 0 ) + Money::to_minor( $item->get_total() );
			}
		}

		$weights = array_filter( $weights, static fn( int $weight ): bool => $weight > 0 );
		if ( [] === $weights ) {
			return [ self::UNTAXED => $amount ];
		}

		$whole      = abs( $amount );
		$sum        = array_sum( $weights );
		$shares     = [];
		$remainders = [];
		$given      = 0;
		foreach ( $weights as $rate_id => $weight ) {
			$shares[ $rate_id ]     = intdiv( $whole * $weight, $sum );
			$remainders[ $rate_id ] = ( $whole * $weight ) % $sum;
			$given                 += $shares[ $rate_id ];
		}

		// Stable sort: equal remainders keep the order of the rates.
		arsort( $remainders );
		foreach ( array_keys( $remainders ) as $rate_id ) {
			if ( $given >= $whole ) {
				break;
			}
			++$shares[ $rate_id ];
			++$given;
		}

		$sign = $amount < 0 ? -1 : 1;

		return array_map( static fn( int $share ): int => $sign * $share, $shares );
	}
}
