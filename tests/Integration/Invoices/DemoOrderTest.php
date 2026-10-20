<?php
/**
 * Demo order of the live preview: built in memory, never saved, adds up.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Admin\DemoOrder;
use Lumia\Factures\Modules\Invoices\Amounts\Money;
use Lumia\Factures\Modules\Invoices\Amounts\Statement;
use Lumia\Factures\Modules\Invoices\Amounts\StatementBuilder;
use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Item_Tax;
use WC_Product_Simple;
use WC_Tax;

/**
 * `DemoOrder::build()` reads the shop, writes nothing, and its amounts add up to its total.
 */
class DemoOrderTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_price_num_decimals', '2' );
		update_option( 'woocommerce_default_country', 'FR' );
		update_option( 'woocommerce_store_address', '12 rue des Lilas' );
		update_option( 'woocommerce_store_address_2', 'Bat. B' );
		update_option( 'woocommerce_store_city', 'Lyon' );
		update_option( 'woocommerce_store_postcode', '69002' );
		update_option( 'woocommerce_ship_to_countries', '' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_shipping_tax_class', '' );
		delete_option( 'lumia_factures_module_invoices' );
	}

	/**
	 * A saved simple product, its creation date in the past to order the candidates.
	 *
	 * @param float  $price     Price ('' when negative: no price).
	 * @param string $name      Name.
	 * @param int    $days_ago  Creation date, days before now.
	 * @param string $tax_class Tax class slug.
	 */
	private function product( float $price, string $name, int $days_ago = 0, string $tax_class = '' ): WC_Product_Simple {
		$product = OrderFactory::simple_product( max( 0.0, $price ), $tax_class, $name );
		if ( $price < 0 ) {
			$product->set_regular_price( '' );
		}
		$product->set_date_created( time() - $days_ago * DAY_IN_SECONDS );
		$product->save();

		return $product;
	}

	/**
	 * Row counts of every table an order could be written to.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		global $wpdb;

		$tables = [
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->comments,
			$wpdb->commentmeta,
			$wpdb->prefix . 'woocommerce_order_items',
			$wpdb->prefix . 'woocommerce_order_itemmeta',
			$wpdb->prefix . 'wc_orders',
			$wpdb->prefix . 'wc_orders_meta',
			$wpdb->prefix . 'wc_order_addresses',
			$wpdb->prefix . 'wc_order_operational_data',
			Installer::documents_table(),
		];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: reads the tables themselves.
		$out = [];
		foreach ( $tables as $table ) {
			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				$out[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test, table names from $wpdb.
			}
		}
		$out['sequences'] = $wpdb->get_results( 'SELECT * FROM ' . Installer::sequences_table() . ' ORDER BY sequence_key', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Test, table name from Installer.
		// phpcs:enable

		return $out;
	}

	/**
	 * The statement of the demo order.
	 *
	 * @param DemoOrder $order   Demo order.
	 * @param string    $display `incl` or `excl`.
	 */
	private function statement( DemoOrder $order, string $display ): Statement {
		$settings                             = Module::settings();
		$settings['content']['price_display'] = $display;

		return StatementBuilder::build( $order, $settings );
	}

	/**
	 * Asserts that the order adds up exactly: items to total, statement to total, no rounding row.
	 *
	 * @param DemoOrder $order Demo order.
	 */
	private function assert_adds_up( DemoOrder $order ): void {
		$total = Money::to_minor( $order->get_total() );

		$sum = 0;
		foreach ( $order->get_items() as $item ) {
			$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
			$sum += Money::to_minor( $item->get_subtotal() ) + Money::to_minor( $item->get_subtotal_tax() );
		}
		$sum += Money::to_minor( $order->get_shipping_total() ) + Money::to_minor( $order->get_shipping_tax() );
		$this->assertSame( $total, $sum, 'Items and shipping add up to the total.' );

		$taxes = 0;
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			$this->assertInstanceOf( WC_Order_Item_Tax::class, $tax );
			$taxes += Money::to_minor( $tax->get_tax_total() ) + Money::to_minor( $tax->get_shipping_tax_total() );
		}
		$this->assertSame( Money::to_minor( $order->get_cart_tax() ) + Money::to_minor( $order->get_shipping_tax() ), $taxes, 'Tax items add up to the order taxes.' );

		foreach ( [ 'incl', 'excl' ] as $display ) {
			$statement = $this->statement( $order, $display );
			$keys      = array_map( static fn( $row ) => $row->key, $statement->rows );
			$this->assertNotContains( 'rounding', $keys, "No rounding row ({$display})." );
			$this->assertSame( $total, $statement->sum(), "The statement adds up to the total ({$display})." );
		}
	}

	public function test_build_writes_nothing(): void {
		OrderFactory::tax_rates(
			[
				''             => 20.0,
				'reduced-rate' => 5.5,
			]
		);
		$this->product( 12.5, 'A', 2 );
		$this->product( 3.3, 'B', 1, 'reduced-rate' );

		$before = $this->snapshot();

		$order = DemoOrder::build();
		$order->get_items( [ 'line_item', 'tax', 'shipping', 'fee', 'coupon' ] );
		$order->get_meta( '_lumia_factures_invoice_number' );
		$order->get_refunds();

		$this->assertSame( 0, $order->get_id() );
		$this->assertCount( 2, $order->get_items() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_write_methods_are_noops(): void {
		$this->product( 10.0, 'A' );
		$order  = DemoOrder::build();
		$before = $this->snapshot();

		$this->assertSame( 0, $order->save() );
		$order->save_meta_data();
		$this->assertFalse( $order->delete( true ) );
		$this->assertSame( 0, $order->add_order_note( 'Note' ) );
		$this->assertFalse( $order->update_status( 'completed' ) );
		$this->assertFalse( $order->payment_complete( 'tx' ) );

		$this->assertSame( 0, $order->get_id() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_add_product_is_in_memory(): void {
		$order   = DemoOrder::build();
		$product = $this->product( 4.0, 'Extra' );
		$before  = $this->snapshot();
		$count   = count( $order->get_items() );

		$this->assertSame( 0, $order->add_product( $product, 3 ) );

		$items = $order->get_items();
		$this->assertCount( $count + 1, $items );
		$item = end( $items );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$this->assertSame( 0, $item->get_id() );
		$this->assertSame( $product->get_id(), $item->get_product_id() );
		$this->assertSame( 3, $item->get_quantity() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_demo_adds_up_incl_and_excl(): void {
		OrderFactory::tax_rates(
			[
				''             => 20.0,
				'reduced-rate' => 5.5,
			]
		);
		$this->product( 9.99, 'A', 3 );
		$this->product( 3.33, 'B', 2, 'reduced-rate' );
		$this->product( 7.77, 'C', 1 );

		foreach ( [ 'yes', 'no' ] as $incl ) {
			update_option( 'woocommerce_prices_include_tax', $incl );
			$order = DemoOrder::build();

			$this->assertCount( 3, $order->get_items() );
			$this->assertCount( 2, $order->get_items( 'tax' ) );
			$this->assertCount( 1, $order->get_items( 'shipping' ) );
			$this->assert_adds_up( $order );
		}
	}

	public function test_compound_rate_adds_up(): void {
		OrderFactory::tax_rates( [ '' => 10.0 ] );
		WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => 'FR',
				'tax_rate'          => '5',
				'tax_rate_name'     => 'Compound',
				'tax_rate_priority' => '2',
				'tax_rate_compound' => '1',
				'tax_rate_shipping' => '1',
				'tax_rate_order'    => '1',
				'tax_rate_class'    => '',
			]
		);
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$this->product( 9.99, 'A', 2 );
		$this->product( 1.11, 'B', 1 );

		foreach ( [ 'yes', 'no' ] as $incl ) {
			update_option( 'woocommerce_prices_include_tax', $incl );
			$order = DemoOrder::build();

			$taxes    = $order->get_items( 'tax' );
			$compound = array_filter( $taxes, static fn( $tax ) => $tax instanceof WC_Order_Item_Tax && $tax->is_compound() );
			$this->assertCount( 2, $taxes );
			$this->assertCount( 1, $compound );
			$this->assert_adds_up( $order );
		}
	}

	public function test_follows_prices_include_tax_option(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$this->product( 12.0, 'A' );

		update_option( 'woocommerce_prices_include_tax', 'yes' );
		$order = DemoOrder::build();
		$item  = current( $order->get_items() );
		$this->assertTrue( $order->get_prices_include_tax() );
		$this->assertSame( '10.00', (string) $item->get_subtotal() );
		$this->assertSame( 200, Money::to_minor( $item->get_subtotal_tax() ) );

		update_option( 'woocommerce_prices_include_tax', 'no' );
		$order = DemoOrder::build();
		$item  = current( $order->get_items() );
		$this->assertFalse( $order->get_prices_include_tax() );
		$this->assertSame( '12.00', (string) $item->get_subtotal() );
		$this->assertSame( 240, Money::to_minor( $item->get_subtotal_tax() ) );
	}

	public function test_respects_product_tax_status(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		update_option( 'woocommerce_shipping_tax_class', 'inherit' );
		$product = $this->product( 10.0, 'Untaxed' );
		$product->set_tax_status( 'none' );
		$product->save();

		$order = DemoOrder::build();
		$item  = current( $order->get_items() );

		$this->assertSame( 0, Money::to_minor( $item->get_subtotal_tax() ) );
		$this->assertSame( [], $order->get_items( 'tax' ) );
		$this->assertSame( 0, Money::to_minor( $order->get_shipping_tax() ) );
		$this->assert_adds_up( $order );
	}

	public function test_without_taxes_has_no_tax_items(): void {
		$this->product( 10.0, 'A', 1 );
		$this->product( 2.5, 'B' );

		$order = DemoOrder::build();

		$this->assertSame( [], $order->get_items( 'tax' ) );
		$this->assertSame( 2750, Money::to_minor( $order->get_total() ) ); // Latest first: 2.50 + 2 x 10 + 5 shipping.
		$this->assert_adds_up( $order );
	}

	public function test_virtual_only_has_no_shipping(): void {
		$product = $this->product( 10.0, 'Download' );
		$product->set_virtual( true );
		$product->save();

		$order = DemoOrder::build();

		$this->assertSame( [], $order->get_items( 'shipping' ) );
		$this->assertSame( 0, Money::to_minor( $order->get_shipping_total() ) );
		$this->assertSame( 1000, Money::to_minor( $order->get_total() ) );
	}

	public function test_shipping_inherit_class(): void {
		$ids = OrderFactory::tax_rates(
			[
				''             => 20.0,
				'reduced-rate' => 5.5,
			]
		);
		update_option( 'woocommerce_shipping_tax_class', 'inherit' );
		$this->product( 10.0, 'Food', 0, 'reduced-rate' );

		$order = DemoOrder::build();

		$shipping = current( $order->get_items( 'shipping' ) );
		$this->assertInstanceOf( WC_Order_Item_Shipping::class, $shipping );
		$this->assertSame( [ $ids['reduced-rate'] ], array_keys( $shipping->get_taxes()['total'] ) );
		$this->assertSame( 28, Money::to_minor( $order->get_shipping_tax() ) ); // 5 x 5.5 % = 0.275.

		$by_rate = [];
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			$by_rate[ $tax->get_rate_id() ] = $tax;
		}
		$this->assertSame( [ $ids['reduced-rate'] ], array_keys( $by_rate ) );
		$this->assertSame( 5.5, (float) $by_rate[ $ids['reduced-rate'] ]->get_rate_percent() );
		$this->assert_adds_up( $order );
	}

	public function test_zero_decimal_currency(): void {
		update_option( 'woocommerce_currency', 'JPY' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		OrderFactory::tax_rates( [ '' => 10.0 ] );
		$this->product( 1234.0, 'A', 1 );
		$this->product( 555.0, 'B' );

		$order = DemoOrder::build();

		$this->assertSame( 'JPY', $order->get_currency() );
		$this->assertStringNotContainsString( '.', (string) $order->get_total() );
		$this->assert_adds_up( $order );
	}

	public function test_currency_is_base_option(): void {
		update_option( 'woocommerce_currency', 'CHF' );
		add_filter( 'woocommerce_currency', static fn() => 'USD' );

		$this->assertSame( 'CHF', DemoOrder::build()->get_currency() );
	}

	public function test_without_products_uses_sample_line(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );

		$order = DemoOrder::build();
		$items = $order->get_items();

		$this->assertCount( 1, $items );
		$item = current( $items );
		$this->assertSame( 'Sample product', $item->get_name() );
		$this->assertSame( 0, $item->get_product_id() );
		$this->assertSame( 1000, Money::to_minor( $item->get_subtotal() ) );
		$this->assertSame( 200, Money::to_minor( $item->get_subtotal_tax() ) );
		$this->assert_adds_up( $order );
	}

	public function test_uses_latest_priced_products_and_variations(): void {
		$this->product( 1.0, 'Oldest', 5 );
		$this->product( 2.0, 'Third', 3 );
		$this->product( 3.0, 'Second', 2 );
		$this->product( -1.0, 'No price', 0 );
		$draft = $this->product( 4.0, 'Draft', 0 );
		$draft->set_status( 'draft' );
		$draft->save();

		$variation = OrderFactory::variable_product( 'Size', 'Large', 6.0, 'Shirt' );
		$parent    = wc_get_product( $variation->get_parent_id() );
		$parent->set_date_created( time() - DAY_IN_SECONDS );
		$parent->save();

		$items = array_values( DemoOrder::build()->get_items() );

		$this->assertCount( 3, $items );
		$this->assertSame( $variation->get_id(), $items[0]->get_variation_id() );
		$this->assertSame( $parent->get_id(), $items[0]->get_product_id() );
		$this->assertSame( 'Second', $items[1]->get_name() );
		$this->assertSame( 'Third', $items[2]->get_name() );
		$this->assertSame( [ 1, 2, 1 ], array_map( static fn( $item ) => $item->get_quantity(), $items ) );
	}

	public function test_ignores_orphan_items_of_order_zero(): void {
		global $wpdb;

		foreach ( [ 'line_item', 'fee', 'coupon', 'shipping', 'tax' ] as $type ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test: an orphan row as left by a broken plugin.
				$wpdb->prefix . 'woocommerce_order_items',
				[
					'order_item_name' => 'Orphan',
					'order_item_type' => $type,
					'order_id'        => 0,
				]
			);
		}
		wp_cache_flush();
		$this->product( 10.0, 'Real' );

		$order = DemoOrder::build();

		foreach ( [ 'line_item', 'fee', 'coupon', 'shipping', 'tax' ] as $type ) {
			foreach ( $order->get_items( $type ) as $item ) {
				$this->assertNotSame( 'Orphan', $item->get_name(), "Orphan {$type} item read." );
			}
		}
		$this->assertCount( 1, $order->get_items() );
		$this->assertSame( [], $order->get_items( 'fee' ) );
		$this->assertSame( [], $order->get_items( 'coupon' ) );
	}

	public function test_customer_is_shop_base_address(): void {
		$order = DemoOrder::build();

		$this->assertSame( 'Sample customer', $order->get_billing_first_name() );
		$this->assertSame( 'Sample recipient', $order->get_shipping_first_name() );
		$this->assertSame( 'customer@example.com', $order->get_billing_email() );
		$this->assertSame( '', $order->get_billing_phone() );
		$this->assertSame( '', $order->get_shipping_phone() );
		foreach ( [ 'billing', 'shipping' ] as $party ) {
			$this->assertSame( '12 rue des Lilas', $order->{"get_{$party}_address_1"}() );
			$this->assertSame( 'Bat. B', $order->{"get_{$party}_address_2"}() );
			$this->assertSame( 'Lyon', $order->{"get_{$party}_city"}() );
			$this->assertSame( '69002', $order->{"get_{$party}_postcode"}() );
			$this->assertSame( 'FR', $order->{"get_{$party}_country"}() );
		}
	}

	public function test_order_number_is_empty_and_unfiltered(): void {
		$calls = 0;
		add_filter(
			'woocommerce_order_number',
			static function ( $number ) use ( &$calls ) {
				++$calls;

				return 'X-' . $number;
			}
		);

		$this->assertSame( '', DemoOrder::build()->get_order_number() );
		$this->assertSame( 0, $calls );
	}
}
