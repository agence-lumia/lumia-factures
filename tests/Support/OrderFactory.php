<?php
/**
 * Real WooCommerce orders for the tests.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Support;

defined( 'ABSPATH' ) || exit;

use RuntimeException;
use WC_Coupon;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Item_Tax;
use WC_Order_Refund;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Tax;

/**
 * Static helpers that build saved products and orders through the WooCommerce API.
 */
final class OrderFactory {

	/**
	 * Enables taxes with a French base and inserts one rate per tax class.
	 *
	 * @param array<string, float> $percents Rate percent keyed by tax class slug ('' = standard).
	 * @return array<string, int> Inserted rate ids keyed by tax class slug.
	 */
	public static function tax_rates( array $percents ): array {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_default_country', 'FR' );
		update_option( 'woocommerce_tax_based_on', 'billing' );
		update_option( 'woocommerce_shipping_tax_class', '' );

		$ids = [];
		foreach ( $percents as $class => $percent ) {
			$ids[ $class ] = (int) WC_Tax::_insert_tax_rate(
				[
					'tax_rate_country'  => 'FR',
					'tax_rate'          => (string) $percent,
					'tax_rate_name'     => 'TVA',
					'tax_rate_priority' => '1',
					'tax_rate_compound' => '0',
					'tax_rate_shipping' => '1',
					'tax_rate_order'    => '0',
					'tax_rate_class'    => (string) $class,
				]
			);
		}

		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );

		return $ids;
	}

	/**
	 * A saved simple product.
	 *
	 * @param float  $price     Regular price, as entered in the shop.
	 * @param string $tax_class Tax class slug.
	 * @param string $name      Product name.
	 */
	public static function simple_product( float $price, string $tax_class = '', string $name = 'Product' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( (string) $price );
		$product->set_tax_class( $tax_class );
		$product->set_sku( 'SKU-' . wp_generate_password( 6, false ) );
		$product->save();

		return $product;
	}

	/**
	 * A saved variation of a saved variable product with one custom attribute.
	 *
	 * @param string $attribute_label Attribute label, e.g. "Conditionnement".
	 * @param string $value           Attribute value of the variation.
	 * @param float  $price           Variation price.
	 * @param string $parent_name     Parent product name.
	 */
	public static function variable_product( string $attribute_label, string $value, float $price, string $parent_name = 'Croquettes' ): WC_Product_Variation {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( $attribute_label );
		$attribute->set_options( [ $value ] );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( $parent_name );
		$parent->set_attributes( [ $attribute ] );
		$parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( [ sanitize_title( $attribute_label ) => $value ] );
		$variation->set_regular_price( (string) $price );
		$variation->save();

		return $variation;
	}

	/**
	 * A saved order, totals calculated by WooCommerce.
	 *
	 * @param array<int, array{0: WC_Product, 1?: int|float}> $lines Product and quantity (1 by default).
	 * @param array<string, mixed>                            $args  `coupon` (code, type, amount), `shipping` (amount),
	 *                                                                `fee` (name, amount), `prices_include_tax` (bool),
	 *                                                                `currency`.
	 */
	public static function order( array $lines, array $args = [] ): WC_Order {
		$incl = ! empty( $args['prices_include_tax'] );
		update_option( 'woocommerce_prices_include_tax', $incl ? 'yes' : 'no' );

		$order = wc_create_order();
		$order->set_billing_country( 'FR' );
		$order->set_shipping_country( 'FR' );
		$order->set_prices_include_tax( $incl );
		if ( isset( $args['currency'] ) ) {
			$order->set_currency( (string) $args['currency'] );
		}

		foreach ( $lines as $line ) {
			$order->add_product( $line[0], $line[1] ?? 1 );
		}

		if ( isset( $args['shipping'] ) ) {
			$shipping = new WC_Order_Item_Shipping();
			$shipping->set_method_title( 'Colissimo' );
			$shipping->set_method_id( 'flat_rate' );
			$shipping->set_total( (string) $args['shipping'] );
			$order->add_item( $shipping );
		}

		if ( isset( $args['fee'] ) ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( (string) $args['fee']['name'] );
			$fee->set_amount( (string) $args['fee']['amount'] );
			$fee->set_total( (string) $args['fee']['amount'] );
			$fee->set_tax_status( 'taxable' );
			$order->add_item( $fee );
		}

		$order->save();
		$order->calculate_totals( true );

		if ( isset( $args['coupon'] ) ) {
			$coupon = new WC_Coupon();
			$coupon->set_code( (string) $args['coupon']['code'] );
			$coupon->set_discount_type( (string) $args['coupon']['type'] );
			$coupon->set_amount( (string) $args['coupon']['amount'] );
			$coupon->save();

			$order->apply_coupon( (string) $args['coupon']['code'] );
		}

		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * A saved refund of an order, created by WooCommerce (`wc_create_refund()`), no payment refunded
	 * and no stock restored.
	 *
	 * @param WC_Order                         $order      Order.
	 * @param array<int, array<string, mixed>> $line_items Refunded items keyed by order item id, as `wc_create_refund()`
	 *                                                     takes them (`qty`, `refund_total`, `refund_tax`): see `refund_line()`.
	 * @param float|null                       $amount     Refunded amount, null for the sum of the items.
	 * @param string                           $reason     Refund reason.
	 * @throws RuntimeException When WooCommerce refuses the refund.
	 */
	public static function refund( WC_Order $order, array $line_items, ?float $amount, string $reason = '' ): WC_Order_Refund {
		if ( null === $amount ) {
			$amount = 0.0;
			foreach ( $line_items as $line ) {
				$amount += (float) ( $line['refund_total'] ?? 0 ) + array_sum( array_map( 'floatval', (array) ( $line['refund_tax'] ?? [] ) ) );
			}
			$amount = round( $amount, wc_get_price_decimals() );
		}

		$refund = wc_create_refund(
			[
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => $reason,
				'line_items'     => $line_items,
				'refund_payment' => false,
				'restock_items'  => false,
			]
		);
		if ( is_wp_error( $refund ) ) {
			throw new RuntimeException( esc_html( $refund->get_error_message() ) );
		}

		$fresh = wc_get_order( $refund->get_id() );
		if ( ! $fresh instanceof WC_Order_Refund ) {
			throw new RuntimeException( 'The refund was not saved.' );
		}

		return $fresh;
	}

	/**
	 * One refunded item as the order screen sends it: the item's share of its total and taxes.
	 *
	 * The screen has one tax input per rate of the order on every row and posts them all, 0 for a
	 * rate the item does not carry (WooCommerce 11+ keeps that 0 and records a tax item at 0).
	 *
	 * @param WC_Order_Item_Product|WC_Order_Item_Shipping|WC_Order_Item_Fee $item     Order item.
	 * @param int|null                                                      $quantity Quantity refunded (products), null for the whole item.
	 * @return array{qty: int|float, refund_total: float, refund_tax: array<int, float>}
	 */
	public static function refund_line( WC_Order_Item_Product|WC_Order_Item_Shipping|WC_Order_Item_Fee $item, ?int $quantity = null ): array {
		$decimals = wc_get_price_decimals();
		$ordered  = $item instanceof WC_Order_Item_Product ? $item->get_quantity() : 0;
		$share    = null === $quantity || 0.0 === (float) $ordered ? 1.0 : $quantity / $ordered;
		$taxes    = $item->get_taxes();

		$refund_tax = [];
		$order      = $item->get_order();
		if ( $order instanceof WC_Order ) {
			foreach ( $order->get_items( 'tax' ) as $tax_item ) {
				if ( $tax_item instanceof WC_Order_Item_Tax ) {
					$refund_tax[ (int) $tax_item->get_rate_id() ] = 0.0;
				}
			}
		}
		foreach ( (array) ( $taxes['total'] ?? [] ) as $rate_id => $tax ) {
			$refund_tax[ (int) $rate_id ] = round( (float) $tax * $share, $decimals );
		}

		return [
			'qty'          => null === $quantity ? $ordered : $quantity,
			'refund_total' => round( (float) $item->get_total() * $share, $decimals ),
			'refund_tax'   => $refund_tax,
		];
	}
}
