<?php
/**
 * In-memory sample order of the live preview.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Amounts\Money;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Item_Tax;
use WC_Product;
use WC_Tax;

/**
 * A sample order built from the shop's own products and base address, never saved.
 *
 * The preview falls back to it when the shop has no order yet. Its id is 0 and every write method
 * is a no-op: nothing reaches the orders, items, meta, comments, counters or register. Its items
 * are set up front, so WooCommerce never reads the items of order 0 (orphan rows would show up).
 *
 * Amounts are computed here in minor units, as WooCommerce would store them (per-rate rounding),
 * never through `calculate_totals()`, which saves the order and its items: the summed rows of the
 * statement equal the total exactly, without a rounding row.
 */
final class DemoOrder extends WC_Order {

	/**
	 * Quantities of the sample lines, in order.
	 */
	private const QUANTITIES = [ 1, 2, 1 ];

	/**
	 * Products queried to find the priced ones.
	 */
	private const CANDIDATES = 30;

	/**
	 * Price of the sample line used when the shop has no priced product.
	 */
	private const SAMPLE_PRICE = 10;

	/**
	 * Shipping cost, excl. VAT (WooCommerce shipping costs are always entered excl. VAT).
	 */
	private const SHIPPING = 5;

	/**
	 * Builds the sample order.
	 */
	public static function build(): self {
		$order        = new self();
		$order->items = [
			'line_items'     => [],
			'tax_lines'      => [],
			'shipping_lines' => [],
			'fee_lines'      => [],
			'coupon_lines'   => [],
		];

		$order->set_currency( (string) get_option( 'woocommerce_currency' ) );
		$order->set_prices_include_tax( wc_prices_include_tax() );
		$order->set_date_created( time() );

		$location = self::base_location();
		$order->fill_customer( $location );
		$order->fill_payment_method();
		$order->fill_amounts( $location );

		return $order;
	}

	/**
	 * No write: the order is never saved.
	 *
	 * @return int 0.
	 */
	public function save() {
		return 0;
	}

	/**
	 * No write: the meta are never saved.
	 *
	 * @return void
	 */
	public function save_meta_data() {
	}

	/**
	 * No write: there is nothing to delete.
	 *
	 * @param bool $force_delete Unused.
	 * @return bool False.
	 */
	public function delete( $force_delete = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature of WC_Data::delete().
		return false;
	}

	/**
	 * No write: no note is added.
	 *
	 * @param string               $note             Unused.
	 * @param int                  $is_customer_note Unused.
	 * @param bool                 $added_by_user    Unused.
	 * @param array<string, mixed> $meta_data        Unused.
	 * @return int 0.
	 */
	public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false, $meta_data = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature of WC_Order::add_order_note().
		return 0;
	}

	/**
	 * No write: the status never changes.
	 *
	 * @param string $new_status Unused.
	 * @param string $note       Unused.
	 * @param bool   $manual     Unused.
	 * @return bool False.
	 */
	public function update_status( $new_status, $note = '', $manual = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature of WC_Order::update_status().
		return false;
	}

	/**
	 * No write: the order is never paid.
	 *
	 * @param string $transaction_id Unused.
	 * @return bool False.
	 */
	public function payment_complete( $transaction_id = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature of WC_Order::payment_complete().
		return false;
	}

	/**
	 * No order number, and no `woocommerce_order_number` filter: a numbering plugin would read order 0.
	 *
	 * @return string ''.
	 */
	public function get_order_number() {
		return '';
	}

	/**
	 * No refund: WooCommerce would query the refunds of order 0.
	 *
	 * @return array<int, mixed> Empty.
	 */
	public function get_refunds() {
		return [];
	}

	/**
	 * Items of the sample order. A group never set up is empty: WooCommerce would read order 0.
	 *
	 * @param string|string[] $types Item types.
	 * @return array<int|string, mixed>
	 */
	public function get_items( $types = 'line_item' ) {
		foreach ( array_filter( (array) $types ) as $type ) {
			$group = $this->type_to_group( $type );
			if ( $group && ! isset( $this->items[ $group ] ) ) {
				$this->items[ $group ] = [];
			}
		}

		return parent::get_items( $types );
	}

	/**
	 * Adds a product line in memory, never saved.
	 *
	 * @param WC_Product|false|null $product Product, or none.
	 * @param int|float             $qty     Quantity.
	 * @param array<string, mixed>  $args    Item props, over the product's.
	 * @return int 0: the item has no id.
	 */
	public function add_product( $product, $qty = 1, $args = [] ) {
		$defaults = [ 'quantity' => $qty ];
		if ( $product instanceof WC_Product ) {
			$variation = $product->is_type( 'variation' );
			$total     = wc_get_price_excluding_tax( $product, [ 'qty' => $qty ] );
			$defaults  = [
				'name'         => $product->get_name(),
				'tax_class'    => $product->get_tax_class(),
				'product_id'   => $variation ? $product->get_parent_id() : $product->get_id(),
				'variation_id' => $variation ? $product->get_id() : 0,
				'variation'    => $variation ? $product->get_attributes() : [],
				'subtotal'     => $total,
				'total'        => $total,
				'quantity'     => $qty,
			];
		}

		$item = new WC_Order_Item_Product();
		$item->set_props( array_merge( $defaults, is_array( $args ) ? $args : [] ) );
		$this->add_item( $item );

		return 0;
	}

	/**
	 * The shop base address, used as the customer address and as the tax location.
	 *
	 * @return array{country: string, state: string, postcode: string, city: string, address_1: string, address_2: string}
	 */
	private static function base_location(): array {
		$countries = WC()->countries;

		return [
			'country'   => (string) $countries->get_base_country(),
			'state'     => (string) $countries->get_base_state(),
			'postcode'  => (string) $countries->get_base_postcode(),
			'city'      => (string) $countries->get_base_city(),
			'address_1' => (string) $countries->get_base_address(),
			'address_2' => (string) $countries->get_base_address_2(),
		];
	}

	/**
	 * Billing and shipping parties: a sample name at the shop address, no phone.
	 *
	 * @param array<string, string> $location Shop base address.
	 */
	private function fill_customer( array $location ): void {
		$this->set_billing_first_name( __( 'Sample customer', 'lumia-factures' ) );
		$this->set_shipping_first_name( __( 'Sample recipient', 'lumia-factures' ) );
		$this->set_billing_email( 'customer@example.com' );

		foreach ( [ 'address_1', 'address_2', 'city', 'postcode', 'state', 'country' ] as $field ) {
			$this->{'set_billing_' . $field}( $location[ $field ] );
			$this->{'set_shipping_' . $field}( $location[ $field ] );
		}
	}

	/**
	 * The first enabled payment method.
	 */
	private function fill_payment_method(): void {
		$gateways = WC()->payment_gateways();
		if ( ! $gateways ) {
			return;
		}

		foreach ( $gateways->payment_gateways() as $gateway ) {
			if ( 'yes' === $gateway->enabled ) {
				$this->set_payment_method( (string) $gateway->id );
				$this->set_payment_method_title( (string) $gateway->get_title() );

				return;
			}
		}
	}

	/**
	 * Product lines, shipping, tax items and totals.
	 *
	 * @param array<string, string> $location Shop base address (tax location).
	 */
	private function fill_amounts( array $location ): void {
		$taxes_on = wc_tax_enabled();
		$incl     = $this->get_prices_include_tax();

		$net         = 0; // Lines and shipping excl. VAT, minor units.
		$cart_tax    = [];  // Product tax by rate id, minor units.
		$ship_tax    = [];  // Shipping tax by rate id, minor units.
		$rates       = [];  // Rate rows by rate id.
		$physical    = false;
		$ship_class  = [];  // Tax classes of the taxable products to ship.
		$has_product = false;

		foreach ( self::products() as $index => $product ) {
			$has_product = true;
			$qty         = self::QUANTITIES[ $index ];
			$gross       = Money::to_minor( (string) $product->get_price() ) * $qty;
			$line_rates  = $taxes_on && $product->is_taxable() ? self::find_rates( $location, (string) $product->get_tax_class(), false ) : [];
			$line_taxes  = self::taxes( $gross, $line_rates, $incl );
			$excl        = $incl ? $gross - array_sum( $line_taxes ) : $gross;

			$this->add_product( $product, $qty, self::item_amounts( $excl, $line_taxes ) );

			$net  += $excl;
			$rates = $rates + $line_rates;
			foreach ( $line_taxes as $rate_id => $amount ) {
				$cart_tax[ $rate_id ] = ( $cart_tax[ $rate_id ] ?? 0 ) + $amount;
			}

			if ( $product->needs_shipping() ) {
				$physical = true;
				if ( $taxes_on && $product->is_shipping_taxable() ) {
					$ship_class[] = (string) $product->get_tax_class();
				}
			}
		}

		if ( ! $has_product ) {
			$gross      = Money::to_minor( (string) self::SAMPLE_PRICE );
			$line_rates = $taxes_on ? self::find_rates( $location, '', false ) : [];
			$line_taxes = self::taxes( $gross, $line_rates, $incl );
			$excl       = $incl ? $gross - array_sum( $line_taxes ) : $gross;

			$this->add_product( null, 1, [ 'name' => __( 'Sample product', 'lumia-factures' ) ] + self::item_amounts( $excl, $line_taxes ) );

			$net     += $excl;
			$rates    = $rates + $line_rates;
			$cart_tax = $line_taxes;
			$physical = true;
			if ( $taxes_on ) {
				$ship_class[] = '';
			}
		}

		$shipping = 0;
		if ( $physical && wc_shipping_enabled() ) {
			$shipping   = Money::to_minor( (string) self::SHIPPING );
			$ship_rates = $taxes_on ? self::shipping_rates( $location, $ship_class ) : [];
			$ship_tax   = self::taxes( $shipping, $ship_rates, false );
			$rates      = $rates + $ship_rates;

			$item = new WC_Order_Item_Shipping();
			$item->set_method_title( __( 'Shipping', 'lumia-factures' ) );
			$item->set_total( self::major( $shipping ) );
			$item->set_taxes( [ 'total' => array_map( [ self::class, 'major' ], $ship_tax ) ] );
			$this->add_item( $item );
		}

		foreach ( array_keys( $cart_tax + $ship_tax ) as $rate_id ) {
			$item = new WC_Order_Item_Tax();
			$item->set_rate( $rate_id );
			$item->set_rate_percent( (float) ( $rates[ $rate_id ]['rate'] ?? 0 ) );
			$item->set_tax_total( self::major( $cart_tax[ $rate_id ] ?? 0 ) );
			$item->set_shipping_tax_total( self::major( $ship_tax[ $rate_id ] ?? 0 ) );
			$this->add_item( $item );
		}

		$this->set_discount_total( self::major( 0 ) );
		$this->set_discount_tax( self::major( 0 ) );
		$this->set_shipping_total( self::major( $shipping ) );
		$this->set_shipping_tax( self::major( array_sum( $ship_tax ) ) );
		$this->set_cart_tax( self::major( array_sum( $cart_tax ) ) );
		$this->set_total( self::major( $net + $shipping + array_sum( $cart_tax ) + array_sum( $ship_tax ) ) );
	}

	/**
	 * Up to three latest published products with a price; for a variable product, its first
	 * published variation with a price.
	 *
	 * @return WC_Product[]
	 */
	private static function products(): array {
		$candidates = wc_get_products(
			[
				'status'  => 'publish',
				'limit'   => self::CANDIDATES,
				'orderby' => [
					'date' => 'DESC',
					'ID'   => 'DESC',
				],
			]
		);

		$found = [];
		foreach ( is_array( $candidates ) ? $candidates : [] as $product ) {
			if ( ! $product instanceof WC_Product || $product->is_type( [ 'grouped', 'external' ] ) ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) ) {
				$product = self::first_variation( $product );
			} elseif ( '' === (string) $product->get_price() ) {
				$product = null;
			}

			if ( $product instanceof WC_Product ) {
				$found[] = $product;
				if ( count( $found ) === count( self::QUANTITIES ) ) {
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * First published variation with a price.
	 *
	 * @param WC_Product $variable Variable product.
	 */
	private static function first_variation( WC_Product $variable ): ?WC_Product {
		foreach ( $variable->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child instanceof WC_Product && 'publish' === $child->get_status() && '' !== (string) $child->get_price() ) {
				return $child;
			}
		}

		return null;
	}

	/**
	 * Rates at the shop base address for a tax class.
	 *
	 * @param array<string, string> $location  Shop base address.
	 * @param string                $tax_class Tax class slug ('' = standard).
	 * @param bool                  $shipping  Shipping rates only.
	 * @return array<int, array<string, mixed>>
	 */
	private static function find_rates( array $location, string $tax_class, bool $shipping ): array {
		$args = [
			'country'   => $location['country'],
			'state'     => $location['state'],
			'postcode'  => $location['postcode'],
			'city'      => $location['city'],
			'tax_class' => $tax_class,
		];

		$rates = $shipping ? WC_Tax::find_shipping_rates( $args ) : WC_Tax::find_rates( $args );

		return is_array( $rates ) ? $rates : [];
	}

	/**
	 * Shipping rates, the tax class resolved as `WC_Tax::get_shipping_tax_rates()` does for a cart:
	 * the shipping tax class option or, with `inherit`, the class of the taxable products to ship
	 * (none: no shipping tax; the standard class when it is one of them; else the only one, or the
	 * first in the shop's class order).
	 *
	 * @param array<string, string> $location Shop base address.
	 * @param string[]              $classes  Tax classes of the taxable products to ship.
	 * @return array<int, array<string, mixed>>
	 */
	private static function shipping_rates( array $location, array $classes ): array {
		$option = get_option( 'woocommerce_shipping_tax_class' );
		if ( 'inherit' !== $option ) {
			return self::find_rates( $location, (string) $option, true );
		}

		$classes = array_values( array_unique( $classes ) );
		if ( [] === $classes ) {
			return [];
		}

		$class = '';
		if ( ! in_array( '', $classes, true ) ) {
			if ( 1 === count( $classes ) ) {
				$class = $classes[0];
			} else {
				foreach ( WC_Tax::get_tax_class_slugs() as $slug ) {
					if ( in_array( $slug, $classes, true ) ) {
						$class = (string) $slug;
						break;
					}
				}
			}
		}

		return self::find_rates( $location, $class, true );
	}

	/**
	 * Taxes of an amount, each rate rounded to minor units.
	 *
	 * @param int                              $amount Amount, minor units.
	 * @param array<int, array<string, mixed>> $rates  Rates.
	 * @param bool                             $incl   The amount includes the taxes.
	 * @return array<int, int> Minor units keyed by rate id.
	 */
	private static function taxes( int $amount, array $rates, bool $incl ): array {
		if ( [] === $rates ) {
			return [];
		}

		$taxes = [];
		foreach ( WC_Tax::calc_tax( $amount / 10 ** wc_get_price_decimals(), $rates, $incl ) as $rate_id => $tax ) {
			$taxes[ (int) $rate_id ] = Money::to_minor( (float) $tax );
		}

		return $taxes;
	}

	/**
	 * Item props of a product line, before and after discount alike (no discount).
	 *
	 * @param int             $excl  Line amount excl. VAT, minor units.
	 * @param array<int, int> $taxes Line taxes by rate id, minor units.
	 * @return array<string, mixed>
	 */
	private static function item_amounts( int $excl, array $taxes ): array {
		$majors = array_map( [ self::class, 'major' ], $taxes );

		return [
			'subtotal'     => self::major( $excl ),
			'total'        => self::major( $excl ),
			'taxes'        => [
				'total'    => $majors,
				'subtotal' => $majors,
			],
			'subtotal_tax' => self::major( array_sum( $taxes ) ),
			'total_tax'    => self::major( array_sum( $taxes ) ),
		];
	}

	/**
	 * Minor units back to a decimal string.
	 *
	 * @param int $minor Amount, minor units.
	 */
	private static function major( int $minor ): string {
		$decimals = wc_get_price_decimals();

		return wc_format_decimal( $minor / 10 ** $decimals, $decimals );
	}
}
