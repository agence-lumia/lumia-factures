<?php
/**
 * Amounts statement.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Amounts\Money;
use Lumia\Factures\Modules\Invoices\Amounts\Statement;
use Lumia\Factures\Modules\Invoices\Amounts\StatementBuilder;
use Lumia\Factures\Modules\Invoices\Amounts\TotalRow;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;

/**
 * StatementBuilder: lines before discount, rows that add up to the order total.
 */
class StatementTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_currency_pos', 'right_space' );
		update_option( 'woocommerce_price_decimal_sep', ',' );
		update_option( 'woocommerce_price_thousand_sep', ' ' );
		update_option( 'woocommerce_price_num_decimals', '2' );
		update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
	}

	public function test_lecomte_case_line_shows_price_before_discount(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order(
			[ [ OrderFactory::simple_product( 35.0 ) ] ],
			[
				'prices_include_tax' => true,
				'coupon'             => [
					'code'   => 'lecomte5',
					'type'   => 'fixed_cart',
					'amount' => 5,
				],
			]
		);

		$statement = $this->build( $order, 'incl' );

		$this->assertCount( 1, $statement->lines );
		$this->assertSame( 3500, $statement->lines[0]->unit );
		$this->assertSame( 3500, $statement->lines[0]->total );
		$this->assertSame( 3500, $this->row( $statement, 'subtotal' )->amount );
		$this->assertSame( -500, $this->row( $statement, 'discount' )->amount );
		$this->assertSame( 'LECOMTE5', $this->row( $statement, 'coupons' )->text );
		$this->assertTrue( $this->row( $statement, 'coupons' )->informational );
		$this->assertSame( 3000, $this->row( $statement, 'total' )->amount );
		$this->assertTrue( $this->row( $statement, 'total' )->grand );
		foreach ( $statement->lines as $line ) {
			$this->assertStringNotContainsStringIgnoringCase( 'LECOMTE5', wp_json_encode( $line ) );
		}
		$this->assertAddsUp( $statement );
	}

	public function test_excl_display_with_two_rates_and_shipping(): void {
		OrderFactory::tax_rates(
			[
				''             => 20.0,
				'reduced-rate' => 5.5,
			]
		);
		$order = OrderFactory::order(
			[
				[ OrderFactory::simple_product( 12.5 ), 2 ],
				[ OrderFactory::simple_product( 7.3, 'reduced-rate' ), 3 ],
			],
			[ 'shipping' => 5.90 ]
		);

		$statement = $this->build( $order, 'excl' );

		$this->assertFalse( $statement->incl_tax );
		$this->assertSame( 2500, $statement->lines[0]->total );
		$this->assertSame( 1250, $statement->lines[0]->unit );
		$this->assertSame( 2190, $statement->lines[1]->total );
		$this->assertSame( 20.0, $statement->lines[0]->tax_rate );
		$this->assertSame( 5.5, $statement->lines[1]->tax_rate );
		$this->assertSame( 590, $this->row( $statement, 'shipping' )->amount );

		$excl = $this->row( $statement, 'total_excl' );
		$this->assertTrue( $excl->informational );
		$this->assertSame( 2500 + 2190 + 590, $excl->amount );

		$taxes = $this->rows( $statement, 'tax' );
		$this->assertCount( 2, $taxes );
		$labels = array_map( static fn( TotalRow $row ): string => $row->label, $taxes );
		$this->assertContains( "VAT 20\u{00A0}%", $labels );
		$this->assertContains( "VAT 5,5\u{00A0}%", $labels );
		foreach ( $taxes as $tax ) {
			$this->assertFalse( $tax->informational );
		}

		$keys = array_map( static fn( TotalRow $row ): string => $row->key, $statement->rows );
		$this->assertLessThan( array_search( 'total', $keys, true ), array_search( 'tax', $keys, true ) );
		$this->assertSame( Money::to_minor( $order->get_total() ), $this->row( $statement, 'total' )->amount );
		$this->assertAddsUp( $statement );
	}

	public function test_percent_coupon_incl_display(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order(
			[ [ OrderFactory::simple_product( 50.0 ), 2 ] ],
			[
				'prices_include_tax' => true,
				'coupon'             => [
					'code'   => 'dix',
					'type'   => 'percent',
					'amount' => 10,
				],
			]
		);

		$statement = $this->build( $order, 'incl' );

		$this->assertTrue( $statement->incl_tax );
		$this->assertSame( 5000, $statement->lines[0]->unit );
		$this->assertSame( 10000, $statement->lines[0]->total );
		$this->assertSame( -1000, $this->row( $statement, 'discount' )->amount );
		$this->assertSame( 9000, $this->row( $statement, 'total' )->amount );

		// "Of which" rows come after the total and are not summed.
		$keys = array_map( static fn( TotalRow $row ): string => $row->key, $statement->rows );
		$this->assertGreaterThan( array_search( 'total', $keys, true ), array_search( 'total_excl', $keys, true ) );
		$this->assertTrue( $this->row( $statement, 'total_excl' )->informational );
		$this->assertSame( 7500, $this->row( $statement, 'total_excl' )->amount );
		$this->assertTrue( $this->row( $statement, 'tax' )->informational );
		$this->assertSame( 1500, $this->row( $statement, 'tax' )->amount );
		$this->assertAddsUp( $statement );
	}

	public function test_fees_are_listed_by_name(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order(
			[ [ OrderFactory::simple_product( 20.0 ) ] ],
			[
				'fee' => [
					'name'   => 'Frais de dossier',
					'amount' => 3,
				],
			]
		);

		$incl = $this->build( $order, 'incl' );
		$fee  = $this->row( $incl, 'fee' );
		$this->assertSame( 'Frais de dossier', $fee->label );
		$this->assertSame( 360, $fee->amount );
		$this->assertAddsUp( $incl );

		$excl = $this->build( $order, 'excl' );
		$this->assertSame( 300, $this->row( $excl, 'fee' )->amount );
		$this->assertAddsUp( $excl );
	}

	public function test_rounding_row_only_when_needed(): void {
		update_option( 'woocommerce_tax_round_at_subtotal', 'yes' );
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$product = OrderFactory::simple_product( 0.99 );
		$order   = OrderFactory::order( [ [ $product ], [ $product ], [ $product ] ] );

		$statement = $this->build( $order, 'excl' );

		$rounding = $this->rows( $statement, 'rounding' );
		if ( [] !== $rounding ) {
			$this->assertNotSame( 0, $rounding[0]->amount );
		}
		$this->assertAddsUp( $statement );
	}

	public function test_incl_discount_is_the_balancing_figure_without_rounding_row(): void {
		OrderFactory::tax_rates(
			[
				''             => 20.0,
				'reduced-rate' => 5.5,
			]
		);
		$order = OrderFactory::order(
			[
				[ OrderFactory::simple_product( 12.5 ), 2 ],
				[ OrderFactory::simple_product( 9.9, 'reduced-rate' ) ],
			],
			[
				'prices_include_tax' => true,
				'shipping'           => 5,
				'coupon'             => [
					'code'   => 'bienvenue',
					'type'   => 'fixed_cart',
					'amount' => 5,
				],
			]
		);

		$statement = $this->build( $order, 'incl' );

		$this->assertSame( -500, $this->row( $statement, 'discount' )->amount );
		$this->assertSame( [], $this->rows( $statement, 'rounding' ) );
		$this->assertSame( 3590, $this->row( $statement, 'total' )->amount );
		$this->assertAddsUp( $statement );
	}

	public function test_rounding_row_absorbs_real_drift(): void {
		$ids     = OrderFactory::tax_rates( [ '' => 20.0 ] );
		$product = OrderFactory::simple_product( 1.0 );
		$order   = OrderFactory::order( [ [ $product ], [ $product ], [ $product ] ] );
		$rate_id = $ids[''];

		// Stored amounts with more decimals than the price: each line rounds up on its own.
		foreach ( $order->get_items() as $item ) {
			$item->set_subtotal( '0.995' );
			$item->set_total( '0.995' );
			$item->set_taxes(
				[
					'subtotal' => [ $rate_id => '0.199' ],
					'total'    => [ $rate_id => '0.199' ],
				]
			);
			$item->save();
		}
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			$tax->set_tax_total( '0.60' );
			$tax->save();
		}
		$order->set_total( '3.58' );
		$order->save();
		$order = wc_get_order( $order->get_id() );

		$statement = $this->build( $order, 'excl' );

		$rounding = $this->row( $statement, 'rounding' );
		$this->assertSame( -2, $rounding->amount );
		$this->assertFalse( $rounding->informational );
		$this->assertSame( 358, $this->row( $statement, 'total' )->amount );
		$this->assertAddsUp( $statement );
	}

	public function test_variation_shows_parent_name_and_attribute(): void {
		$variation = OrderFactory::variable_product( 'Conditionnement', '500 g', 12.0 );
		$order     = OrderFactory::order( [ [ $variation ] ] );

		$statement = $this->build( $order, 'incl' );
		$line      = $statement->lines[0];

		$this->assertSame( 'Croquettes', $line->name );
		$this->assertContains(
			[
				'label' => 'Conditionnement',
				'value' => '500 g',
			],
			$line->attributes
		);
		$this->assertSame( $variation->get_id(), $line->product_id );

		$hidden = $this->build( $order, 'incl', [ 'item_meta_enabled' => false ] );
		$this->assertSame( [], $hidden->lines[0]->attributes );

		$this->assertAddsUp( $statement );
	}

	public function test_variation_name_falls_back_when_parent_deleted(): void {
		$variation = OrderFactory::variable_product( 'Conditionnement', '500 g', 12.0 );
		$order     = OrderFactory::order( [ [ $variation, 2 ] ] );
		$item_name = $order->get_items()[ array_key_first( $order->get_items() ) ]->get_name();
		$parent    = wc_get_product( $variation->get_parent_id() );

		$variation->delete( true );
		$parent->delete( true );
		$order = wc_get_order( $order->get_id() );

		$statement = $this->build( $order, 'incl' );
		$line      = $statement->lines[0];

		$this->assertSame( $item_name, $line->name );
		$this->assertSame( 2400, $line->total );
		$this->assertSame( 1200, $line->unit );
		$this->assertSame( '', $line->sku );
		$this->assertSame( 0, $line->image_id );
		$this->assertContains(
			[
				'label' => 'Conditionnement',
				'value' => '500 g',
			],
			$line->attributes
		);
		$this->assertAddsUp( $statement );
	}

	public function test_tax_rate_comes_from_order_tax_item(): void {
		global $wpdb;

		$ids   = OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		$wpdb->update( $wpdb->prefix . 'woocommerce_tax_rates', [ 'tax_rate' => '10.0000' ], [ 'tax_rate_id' => $ids[''] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- simulates a later change of the shop rate.
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );

		$statement = $this->build( wc_get_order( $order->get_id() ), 'excl' );

		$this->assertSame( 20.0, $statement->lines[0]->tax_rate );
		$this->assertSame( "VAT 20\u{00A0}%", $this->row( $statement, 'tax' )->label );
		$this->assertSame( 200, $this->row( $statement, 'tax' )->amount );
		$this->assertAddsUp( $statement );
	}

	public function test_zero_total_order(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$free  = OrderFactory::simple_product( 0.0 );
		$order = OrderFactory::order( [ [ $free ], [ $free, 0 ] ] );

		foreach ( [ 'incl', 'excl' ] as $mode ) {
			$statement = $this->build( $order, $mode );

			foreach ( $statement->lines as $line ) {
				$this->assertSame( 0, $line->unit );
				$this->assertSame( 0, $line->total );
				$this->assertNull( $line->tax_rate );
			}
			foreach ( $statement->rows as $row ) {
				if ( null !== $row->amount ) {
					$this->assertSame( 0, $row->amount, $row->key );
				}
			}
			$this->assertSame( [], $this->rows( $statement, 'rounding' ) );
			$this->assertSame( "0,00\u{00A0}\u{20AC}", Money::format( $this->row( $statement, 'total' )->amount, $statement->currency ) );
			$this->assertAddsUp( $statement );
		}
	}

	public function test_order_currency_is_used(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ], [ 'currency' => 'USD' ] );

		$statement = $this->build( $order, 'incl' );

		$this->assertSame( 'USD', $statement->currency );
		$formatted = Money::format( $this->row( $statement, 'total' )->amount, $statement->currency );
		$this->assertStringContainsString( '$', $formatted );
		$this->assertStringContainsString( '10,00', $formatted );
		$this->assertStringNotContainsString( '<', $formatted );
		$this->assertAddsUp( $statement );
	}

	public function test_money_to_minor_rounds_half_up(): void {
		$this->assertSame( 29, Money::to_minor( '0.285' ) );
		$this->assertSame( 101, Money::to_minor( 1.005 ) );
		$this->assertSame( -500, Money::to_minor( '-5.00' ) );
		$this->assertSame( 0, Money::to_minor( '' ) );
	}

	public function test_format_rate(): void {
		$this->assertSame( "20\u{00A0}%", StatementBuilder::format_rate( 20.0 ) );
		$this->assertSame( "5,5\u{00A0}%", StatementBuilder::format_rate( 5.5 ) );
		$this->assertSame( "2,1\u{00A0}%", StatementBuilder::format_rate( 2.1 ) );
	}

	public function test_incl_amounts_are_rounded_once(): void {
		update_option( 'woocommerce_tax_round_at_subtotal', 'yes' );
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 9.99 ) ] ], [ 'prices_include_tax' => true ] );

		$statement = $this->build( $order, 'incl' );

		$this->assertSame( 999, $statement->lines[0]->total );
		$this->assertSame( 999, $statement->lines[0]->unit );
		$this->assertSame( 999, $this->row( $statement, 'subtotal' )->amount );
		$this->assertSame( [], $this->rows( $statement, 'rounding' ) );
		$this->assertSame( 999, $this->row( $statement, 'total' )->amount );
		$this->assertAddsUp( $statement );
	}

	public function test_old_order_without_rate_percent_keeps_one_row_per_rate(): void {
		OrderFactory::tax_rates(
			[
				''             => 20.0,
				'reduced-rate' => 5.5,
			]
		);
		$order = OrderFactory::order(
			[
				[ OrderFactory::simple_product( 12.5 ), 2 ],
				[ OrderFactory::simple_product( 7.3, 'reduced-rate' ), 3 ],
			]
		);

		// Orders placed before WooCommerce 3.7 have no rate_percent meta (read back as 0.0),
		// and French shops name every rate "TVA".
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			wc_delete_order_item_meta( $tax->get_id(), 'rate_percent' );
		}
		$order = wc_get_order( $order->get_id() );

		$statement = $this->build( $order, 'excl' );

		$this->assertSame( 20.0, $statement->lines[0]->tax_rate );
		$this->assertSame( 5.5, $statement->lines[1]->tax_rate );

		$amounts = [];
		foreach ( $this->rows( $statement, 'tax' ) as $tax ) {
			$amounts[ $tax->label ] = $tax->amount;
		}
		$this->assertSame(
			[
				"VAT 20\u{00A0}%"  => 500,
				"VAT 5,5\u{00A0}%" => 120,
			],
			$amounts
		);
		$this->assertAddsUp( $statement );
	}

	public function test_variation_without_parent_name_does_not_repeat_attribute(): void {
		$variation = OrderFactory::variable_product( 'Conditionnement', '500 g', 12.0 );
		$order     = OrderFactory::order( [ [ $variation ] ] );
		$item_name = $order->get_items()[ array_key_first( $order->get_items() ) ]->get_name();

		$statement = $this->build( $order, 'incl', [ 'item_parent_name_enabled' => false ] );
		$line      = $statement->lines[0];

		$this->assertSame( $item_name, $line->name );
		$shown = $line->name . ' ' . implode( ' ', array_column( $line->attributes, 'value' ) );
		$this->assertSame( 1, substr_count( $shown, '500 g' ) );
		$this->assertAddsUp( $statement );
	}

	public function test_full_discount_coupon(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order(
			[ [ OrderFactory::simple_product( 10.0 ) ] ],
			[
				'prices_include_tax' => true,
				'coupon'             => [
					'code'   => 'offert',
					'type'   => 'percent',
					'amount' => 100,
				],
			]
		);

		foreach ( [ 'incl', 'excl' ] as $mode ) {
			$statement = $this->build( $order, $mode );
			$subtotal  = $this->row( $statement, 'subtotal' )->amount;

			$this->assertGreaterThan( 0, $subtotal );
			$this->assertSame( -$subtotal, $this->row( $statement, 'discount' )->amount );
			$this->assertSame( 0, $this->row( $statement, 'total' )->amount );
			$this->assertSame( [], $this->rows( $statement, 'rounding' ) );
			$this->assertAddsUp( $statement );
		}
	}

	/**
	 * Builds the statement with the default settings and a display mode.
	 *
	 * @param WC_Order             $order   Order.
	 * @param string               $display `incl` or `excl`.
	 * @param array<string, mixed> $content Content settings overrides.
	 */
	private function build( WC_Order $order, string $display, array $content = [] ): Statement {
		$settings                             = Module::settings();
		$settings['content']['price_display'] = $display;
		$settings['content']                  = array_merge( $settings['content'], $content );

		return StatementBuilder::build( $order, $settings );
	}

	/**
	 * Rows with a key.
	 *
	 * @param Statement $statement Statement.
	 * @param string    $key       Row key.
	 * @return TotalRow[]
	 */
	private function rows( Statement $statement, string $key ): array {
		return array_values( array_filter( $statement->rows, static fn( TotalRow $row ): bool => $row->key === $key ) );
	}

	/**
	 * The first row with a key, failing when there is none.
	 *
	 * @param Statement $statement Statement.
	 * @param string    $key       Row key.
	 */
	private function row( Statement $statement, string $key ): TotalRow {
		$rows = $this->rows( $statement, $key );
		$this->assertNotEmpty( $rows, "No {$key} row." );

		return $rows[0];
	}

	/**
	 * The invariant: the summed rows equal the grand total.
	 *
	 * @param Statement $statement Statement.
	 */
	private function assertAddsUp( Statement $statement ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit assertion naming.
		$this->assertSame( $this->row( $statement, 'total' )->amount, $statement->sum() );
	}
}
