<?php
/**
 * Credit note statement.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Amounts\CreditNoteStatementBuilder;
use Lumia\Factures\Modules\Invoices\Amounts\Money;
use Lumia\Factures\Modules\Invoices\Amounts\Statement;
use Lumia\Factures\Modules\Invoices\Amounts\StatementBuilder;
use Lumia\Factures\Modules\Invoices\Amounts\TotalRow;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Item_Tax;
use WC_Order_Refund;
use WC_Tax;

/**
 * CreditNoteStatementBuilder: positive amounts that add up to the refunded amount, VAT of the parent order.
 */
class CreditNoteStatementTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_currency_pos', 'right_space' );
		update_option( 'woocommerce_price_decimal_sep', ',' );
		update_option( 'woocommerce_price_thousand_sep', ' ' );
		update_option( 'woocommerce_price_num_decimals', '2' );
		update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
	}

	public function test_full_refund_by_lines(): void {
		OrderFactory::tax_rates(
			[
				''             => 20.0,
				'reduced-rate' => 5.5,
			]
		);
		$order = OrderFactory::order(
			[
				[ OrderFactory::simple_product( 12.5, '', 'Pelle' ), 2 ],
				[ OrderFactory::simple_product( 10.0, 'reduced-rate', 'Graines' ) ],
			],
			[
				'shipping' => 5,
				'fee'      => [
					'name'   => 'Frais de dossier',
					'amount' => 3,
				],
			]
		);
		$this->assertSame( 5015, Money::to_minor( $order->get_total() ) );

		$refund = OrderFactory::refund( $order, $this->all_lines( $order ), null );

		[ $incl, $excl ] = $this->assertAddsUp( $refund );

		$this->assertCount( 2, $incl->lines );
		$this->assertSame( 'Pelle', $incl->lines[0]->name );
		$this->assertSame( 2, $incl->lines[0]->quantity );
		$this->assertSame( 1500, $incl->lines[0]->unit );
		$this->assertSame( 3000, $incl->lines[0]->total );
		$this->assertSame( 20.0, $incl->lines[0]->tax_rate );
		$this->assertSame( 1, $incl->lines[1]->quantity );
		$this->assertSame( 1055, $incl->lines[1]->total );
		$this->assertSame( 5.5, $incl->lines[1]->tax_rate );

		$this->assertSame( [ 'subtotal', 'shipping', 'fee', 'total', 'total_excl', 'tax', 'tax' ], $this->keys( $incl ) );
		$this->assertSame( 4055, $this->row( $incl, 'subtotal' )->amount );
		$this->assertSame( 600, $this->row( $incl, 'shipping' )->amount );
		$this->assertSame( 'Frais de dossier', $this->row( $incl, 'fee' )->label );
		$this->assertSame( 360, $this->row( $incl, 'fee' )->amount );
		$this->assertSame( 'Total refunded', $this->row( $incl, 'total' )->label );
		$this->assertSame( 5015, $this->row( $incl, 'total' )->amount );
		$this->assertSame( 4300, $this->row( $incl, 'total_excl' )->amount );
		$this->assertSame(
			[
				"VAT 20\u{00A0}%"  => 660,
				"VAT 5,5\u{00A0}%" => 55,
			],
			$this->taxes( $incl )
		);

		$this->assertSame( [ 'subtotal', 'shipping', 'fee', 'total_excl', 'tax', 'tax', 'total' ], $this->keys( $excl ) );
		$this->assertSame( 1250, $excl->lines[0]->unit );
		$this->assertSame( 2500, $excl->lines[0]->total );
		$this->assertSame( 3500, $this->row( $excl, 'subtotal' )->amount );
		$this->assertSame( 500, $this->row( $excl, 'shipping' )->amount );
		$this->assertSame( 300, $this->row( $excl, 'fee' )->amount );
		$this->assertSame( 4300, $this->row( $excl, 'total_excl' )->amount );
		$this->assertSame( $this->taxes( $incl ), $this->taxes( $excl ) );
	}

	public function test_line_refund_on_two_rate_order_shows_only_the_refunded_rate(): void {
		$ids      = OrderFactory::tax_rates(
			[
				''             => 20.0,
				'reduced-rate' => 5.5,
			]
		);
		$order    = OrderFactory::order(
			[
				[ OrderFactory::simple_product( 12.5, '', 'Pelle' ), 2 ],
				[ OrderFactory::simple_product( 10.0, 'reduced-rate', 'Graines' ) ],
			],
			[ 'shipping' => 5 ]
		);
		$item     = $this->product_item( $order );
		$shipping = $this->shipping_item( $order );

		// The order screen posts every rate of the order on each row: 0 for 5.5 % on the 20 % rows.
		$line = OrderFactory::refund_line( $item );
		$this->assertSame(
			[
				$ids['']             => 5.0,
				$ids['reduced-rate'] => 0.0,
			],
			$line['refund_tax']
		);
		$refund = OrderFactory::refund(
			$order,
			[
				$item->get_id()     => $line,
				$shipping->get_id() => OrderFactory::refund_line( $shipping ),
			],
			null
		);

		// WooCommerce keeps the 0 and records a 5.5 % tax item at 0 on the refund.
		$recorded = [];
		foreach ( $refund->get_items( 'tax' ) as $tax_item ) {
			$this->assertInstanceOf( WC_Order_Item_Tax::class, $tax_item );
			$recorded[ (int) $tax_item->get_rate_id() ] = Money::to_minor( $tax_item->get_tax_total() ) + Money::to_minor( $tax_item->get_shipping_tax_total() );
		}
		$this->assertSame( -600, $recorded[ $ids[''] ] ?? null );
		$this->assertSame( 0, $recorded[ $ids['reduced-rate'] ] ?? null, 'No 5.5 % tax item at 0: the order screen path is no longer covered.' );

		[ $incl, $excl ] = $this->assertAddsUp( $refund );

		$this->assertSame( [ 'subtotal', 'shipping', 'total', 'total_excl', 'tax' ], $this->keys( $incl ) );
		$this->assertSame( [ 'subtotal', 'shipping', 'total_excl', 'tax', 'total' ], $this->keys( $excl ) );
		foreach ( [ $incl, $excl ] as $statement ) {
			$this->assertSame( [ "VAT 20\u{00A0}%" => 600 ], $this->taxes( $statement ) );
			$this->assertSame( 3600, $this->row( $statement, 'total' )->amount );
		}
	}

	public function test_real_zero_rate_keeps_its_row_as_on_the_invoice(): void {
		OrderFactory::tax_rates(
			[
				''          => 20.0,
				'zero-rate' => 0.0,
			]
		);
		$order = OrderFactory::order(
			[
				[ OrderFactory::simple_product( 10.0 ) ],
				[ OrderFactory::simple_product( 8.0, 'zero-rate', 'Livre' ) ],
			]
		);
		$items = array_values( $order->get_items() );
		$book  = $items[1] ?? null;
		$this->assertInstanceOf( WC_Order_Item_Product::class, $book );

		$this->assertSame(
			[
				"VAT 0\u{00A0}%"  => 0,
				"VAT 20\u{00A0}%" => 200,
			],
			$this->taxes( StatementBuilder::build( $order, Module::settings() ) )
		);

		$refund = OrderFactory::refund( $order, [ $book->get_id() => OrderFactory::refund_line( $book ) ], null );

		foreach ( $this->assertAddsUp( $refund ) as $statement ) {
			$this->assertSame( [ "VAT 0\u{00A0}%" => 0 ], $this->taxes( $statement ) );
			$this->assertSame( 800, $this->row( $statement, 'total' )->amount );
		}
	}

	public function test_partial_quantity_refund(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 12.5 ), 3 ] ] );
		$item  = $this->product_item( $order );

		$refund = OrderFactory::refund( $order, [ $item->get_id() => OrderFactory::refund_line( $item, 1 ) ], null );

		[ $incl, $excl ] = $this->assertAddsUp( $refund );

		$this->assertSame( 1, $incl->lines[0]->quantity );
		$this->assertSame( 1500, $incl->lines[0]->unit );
		$this->assertSame( 1500, $incl->lines[0]->total );
		$this->assertSame( 1250, $excl->lines[0]->unit );
		$this->assertSame( 1250, $excl->lines[0]->total );
		$this->assertSame( [ "VAT 20\u{00A0}%" => 250 ], $this->taxes( $excl ) );
		$this->assertSame( 1500, $this->row( $excl, 'total' )->amount );
	}

	public function test_shipping_only_refund(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order    = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ], [ 'shipping' => 5.90 ] );
		$shipping = $this->shipping_item( $order );

		$refund = OrderFactory::refund( $order, [ $shipping->get_id() => OrderFactory::refund_line( $shipping ) ], null );

		[ $incl, $excl ] = $this->assertAddsUp( $refund );

		$this->assertSame( [], $incl->lines );
		$this->assertSame( [ 'shipping', 'total', 'total_excl', 'tax' ], $this->keys( $incl ) );
		$this->assertSame( 708, $this->row( $incl, 'shipping' )->amount );
		$this->assertSame( 590, $this->row( $excl, 'shipping' )->amount );
		$this->assertSame( [ "VAT 20\u{00A0}%" => 118 ], $this->taxes( $excl ) );
	}

	public function test_fee_refund(): void {
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
		$fee   = $this->fee_item( $order );

		$refund = OrderFactory::refund( $order, [ $fee->get_id() => OrderFactory::refund_line( $fee ) ], null );

		[ $incl, $excl ] = $this->assertAddsUp( $refund );

		$this->assertSame( [], $incl->lines );
		$this->assertSame( 'Frais de dossier', $this->row( $incl, 'fee' )->label );
		$this->assertSame( 360, $this->row( $incl, 'fee' )->amount );
		$this->assertSame( 300, $this->row( $excl, 'fee' )->amount );
		$this->assertSame( [ "VAT 20\u{00A0}%" => 60 ], $this->taxes( $excl ) );
	}

	public function test_amount_only_refund_single_rate(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		$refund = OrderFactory::refund( $order, [], 6.0 );

		[ $incl, $excl ] = $this->assertAddsUp( $refund );

		$this->assertSame( [], $incl->lines );
		$this->assertSame( [ 'adjustment', 'total', 'total_excl', 'tax' ], $this->keys( $incl ) );
		$this->assertSame( 'Other refunded amount', $this->row( $incl, 'adjustment' )->label );
		$this->assertSame( 600, $this->row( $incl, 'adjustment' )->amount );
		$this->assertSame( 500, $this->row( $incl, 'total_excl' )->amount );
		$this->assertSame( [ "VAT 20\u{00A0}%" => 100 ], $this->taxes( $incl ) );

		$this->assertSame( [ 'adjustment', 'total_excl', 'tax', 'total' ], $this->keys( $excl ) );
		$this->assertSame( 500, $this->row( $excl, 'adjustment' )->amount );
		$this->assertSame( [ "VAT 20\u{00A0}%" => 100 ], $this->taxes( $excl ) );
	}

	public function test_amount_only_refund_two_rates_allocated_exactly(): void {
		OrderFactory::tax_rates(
			[
				''             => 20.0,
				'reduced-rate' => 5.5,
			]
		);
		// Paid incl. VAT: 30.00 at 20 % and 21.10 at 5.5 %.
		$order = OrderFactory::order(
			[
				[ OrderFactory::simple_product( 25.0 ) ],
				[ OrderFactory::simple_product( 20.0, 'reduced-rate' ) ],
			]
		);

		$refund = OrderFactory::refund( $order, [], 10.0 );

		[ $incl, $excl ] = $this->assertAddsUp( $refund );

		// 10.00 split 30.00 / 21.10: 5.8708 and 4.1292, the cent left over goes to the largest
		// remainder: 5.87 at 20 % (0.98 VAT) and 4.13 at 5.5 % (0.22 VAT).
		$taxes = [
			"VAT 20\u{00A0}%"  => 98,
			"VAT 5,5\u{00A0}%" => 22,
		];
		$this->assertSame( 1000, $this->row( $incl, 'adjustment' )->amount );
		$this->assertSame( $taxes, $this->taxes( $incl ) );
		$this->assertSame( 880, $this->row( $incl, 'total_excl' )->amount );

		$this->assertSame( 489 + 391, $this->row( $excl, 'adjustment' )->amount );
		$this->assertSame( $taxes, $this->taxes( $excl ) );
		$this->assertSame( 880, $this->row( $excl, 'total_excl' )->amount );
	}

	public function test_negative_adjustment_when_amount_below_line_sum(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ), 2 ] ] );
		$item  = $this->product_item( $order );

		// Both items returned (24.00) but 20.00 refunded.
		$refund = OrderFactory::refund( $order, [ $item->get_id() => OrderFactory::refund_line( $item ) ], 20.0 );

		[ $incl, $excl ] = $this->assertAddsUp( $refund );

		$this->assertSame( 2400, $this->row( $incl, 'subtotal' )->amount );
		$this->assertSame( -400, $this->row( $incl, 'adjustment' )->amount );
		$this->assertSame( [], $this->rows( $incl, 'rounding' ) );
		$this->assertSame( 2000, $this->row( $incl, 'total' )->amount );

		// -4.00 incl. VAT is -3.33 excl. VAT and -0.67 VAT.
		$this->assertSame( 2000, $this->row( $excl, 'subtotal' )->amount );
		$this->assertSame( -333, $this->row( $excl, 'adjustment' )->amount );
		$this->assertSame( 1667, $this->row( $excl, 'total_excl' )->amount );
		$this->assertSame( [ "VAT 20\u{00A0}%" => 333 ], $this->taxes( $excl ) );
		$this->assertSame( [], $this->rows( $excl, 'rounding' ) );
	}

	public function test_adjustment_in_excl_mode_moves_its_vat_to_tax_rows(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ), 2 ] ] );
		$item  = $this->product_item( $order );

		// One item returned (12.00) and 6.00 more refunded.
		$refund = OrderFactory::refund( $order, [ $item->get_id() => OrderFactory::refund_line( $item, 1 ) ], 18.0 );

		[ $incl, $excl ] = $this->assertAddsUp( $refund );

		$this->assertSame( 600, $this->row( $incl, 'adjustment' )->amount );
		$this->assertSame( [ "VAT 20\u{00A0}%" => 300 ], $this->taxes( $incl ) );
		$this->assertSame( 1500, $this->row( $incl, 'total_excl' )->amount );

		$this->assertSame( [ 'subtotal', 'adjustment', 'total_excl', 'tax', 'total' ], $this->keys( $excl ) );
		$this->assertSame( 1000, $this->row( $excl, 'subtotal' )->amount );
		$this->assertSame( 500, $this->row( $excl, 'adjustment' )->amount );
		$this->assertSame( 1500, $this->row( $excl, 'total_excl' )->amount );
		$this->assertSame( [ "VAT 20\u{00A0}%" => 300 ], $this->taxes( $excl ) );
		$this->assertFalse( $this->row( $excl, 'tax' )->informational );
	}

	public function test_gap_of_one_or_two_minor_units_is_rounding_not_adjustment(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ), 3 ] ] );
		$item  = $this->product_item( $order );

		foreach ( [
			1 => 12.01,
			2 => 12.02,
		] as $gap => $amount ) {
			$refund = OrderFactory::refund( $order, [ $item->get_id() => OrderFactory::refund_line( $item, 1 ) ], $amount );

			foreach ( $this->assertAddsUp( $refund ) as $statement ) {
				$this->assertSame( [], $this->rows( $statement, 'adjustment' ) );
				$this->assertSame( $gap, $this->row( $statement, 'rounding' )->amount );
			}
		}
	}

	public function test_gap_above_two_is_adjustment(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ), 2 ] ] );
		$item  = $this->product_item( $order );

		$refund = OrderFactory::refund( $order, [ $item->get_id() => OrderFactory::refund_line( $item, 1 ) ], 12.03 );

		[ $incl, $excl ] = $this->assertAddsUp( $refund );

		$this->assertSame( 3, $this->row( $incl, 'adjustment' )->amount );
		$this->assertSame( [], $this->rows( $incl, 'rounding' ) );

		// 0.03 incl. VAT: 0.02 excl. VAT and 0.01 VAT (0.005 rounded half up).
		$this->assertSame( 2, $this->row( $excl, 'adjustment' )->amount );
		$this->assertSame( [ "VAT 20\u{00A0}%" => 201 ], $this->taxes( $excl ) );
		$this->assertSame( [], $this->rows( $excl, 'rounding' ) );
	}

	public function test_rate_taken_from_parent_after_tax_rate_changed_or_deleted(): void {
		global $wpdb;

		$ids   = OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ), 3 ] ] );
		$item  = $this->product_item( $order );

		$wpdb->update( $wpdb->prefix . 'woocommerce_tax_rates', [ 'tax_rate' => '10.0000' ], [ 'tax_rate_id' => $ids[''] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- simulates a later change of the shop rate.
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$changed = OrderFactory::refund( $order, [ $item->get_id() => OrderFactory::refund_line( $item, 1 ) ], null );
		$this->restamp_taxes( $changed, 10.0, 'TVA 10' );

		WC_Tax::_delete_tax_rate( $ids[''] );
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$deleted = OrderFactory::refund( $order, [ $item->get_id() => OrderFactory::refund_line( $item, 1 ) ], null );
		$this->restamp_taxes( $deleted, 0.0, 'Tax' );
		$amount = OrderFactory::refund( $order, [], 12.0 );

		foreach ( [ $changed, $deleted ] as $refund ) {
			foreach ( $this->assertAddsUp( $refund ) as $statement ) {
				$this->assertSame( 20.0, $statement->lines[0]->tax_rate );
				$this->assertSame( [ "VAT 20\u{00A0}%" => 200 ], $this->taxes( $statement ) );
			}
		}

		[ $incl, $excl ] = $this->assertAddsUp( $amount );
		$this->assertSame( [ "VAT 20\u{00A0}%" => 200 ], $this->taxes( $incl ) );
		$this->assertSame( 1000, $this->row( $excl, 'adjustment' )->amount );
		$this->assertSame( [ "VAT 20\u{00A0}%" => 200 ], $this->taxes( $excl ) );
	}

	public function test_no_discount_or_coupon_rows_on_credit_note(): void {
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

		$refund = OrderFactory::refund( $order, $this->all_lines( $order ), null );

		$this->assertAddsUp( $refund );
		foreach ( [ 'incl', 'excl' ] as $mode ) {
			$statement = $this->build( $refund, $mode, [ 'total_discount_code_enabled' => true ] );

			$this->assertSame( [], $this->rows( $statement, 'discount' ), $mode );
			$this->assertSame( [], $this->rows( $statement, 'coupons' ), $mode );
			$this->assertSame( [], $this->rows( $statement, 'adjustment' ), $mode );
			$this->assertSame( 3000, $this->row( $statement, 'total' )->amount, $mode );
			$this->assertSame( $this->row( $statement, 'total' )->amount, $statement->sum(), $mode );
		}

		// The line shows what was refunded: the price after the discount.
		$this->assertSame( 3000, $this->build( $refund, 'incl' )->lines[0]->total );
		$this->assertSame( 2500, $this->build( $refund, 'excl' )->lines[0]->total );
	}

	public function test_untaxed_parent_shows_no_vat(): void {
		update_option( 'woocommerce_calc_taxes', 'no' );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ), 2 ] ] );
		$item  = $this->product_item( $order );

		$by_line   = OrderFactory::refund( $order, [ $item->get_id() => OrderFactory::refund_line( $item, 1 ) ], null );
		$by_amount = OrderFactory::refund( $order, [], 5.0 );

		foreach ( $this->assertAddsUp( $by_line ) as $statement ) {
			$this->assertNull( $statement->lines[0]->tax_rate );
			$this->assertSame( 1000, $statement->lines[0]->total );
			$this->assertSame( [], $this->rows( $statement, 'tax' ) );
		}
		foreach ( $this->assertAddsUp( $by_amount ) as $statement ) {
			$this->assertSame( 500, $this->row( $statement, 'adjustment' )->amount );
			$this->assertSame( [], $this->rows( $statement, 'tax' ) );
			$this->assertSame( 500, $this->row( $statement, 'total_excl' )->amount );
		}
	}

	public function test_tax_only_line_refund(): void {
		$ids   = OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$item  = $this->product_item( $order );

		$refund = OrderFactory::refund(
			$order,
			[
				$item->get_id() => [
					'qty'          => 0,
					'refund_total' => 0,
					'refund_tax'   => [ $ids[''] => 2.0 ],
				],
			],
			null
		);

		[ $incl, $excl ] = $this->assertAddsUp( $refund );

		// No quantity: the cell stays empty and the unit price is the line total.
		$this->assertSame( 0, $incl->lines[0]->quantity );
		$this->assertSame( 200, $incl->lines[0]->unit );
		$this->assertSame( 200, $incl->lines[0]->total );
		$this->assertSame( 20.0, $incl->lines[0]->tax_rate );
		$this->assertSame( [], $this->rows( $incl, 'adjustment' ) );

		$this->assertSame( 0, $excl->lines[0]->unit );
		$this->assertSame( 0, $excl->lines[0]->total );
		$this->assertSame( [ "VAT 20\u{00A0}%" => 200 ], $this->taxes( $excl ) );
		$this->assertSame( 200, $this->row( $excl, 'total' )->amount );
	}

	public function test_statement_currency_is_parent_currency(): void {
		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ], [ 'currency' => 'USD' ] );
		$refund = OrderFactory::refund( $order, [], 4.0 );
		$refund->set_currency( 'GBP' );
		$refund->save();
		$refund = wc_get_order( $refund->get_id() );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		foreach ( $this->assertAddsUp( $refund ) as $statement ) {
			$this->assertSame( 'USD', $statement->currency );
		}
	}

	public function test_sum_of_credit_notes_equals_total_refunded(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order    = OrderFactory::order( [ [ OrderFactory::simple_product( 12.5 ), 3 ] ], [ 'shipping' => 5.90 ] );
		$product  = $this->product_item( $order );
		$shipping = $this->shipping_item( $order );

		$refunds = [
			OrderFactory::refund( $order, [ $product->get_id() => OrderFactory::refund_line( $product, 1 ) ], null ),
			OrderFactory::refund( $order, [ $shipping->get_id() => OrderFactory::refund_line( $shipping ) ], null ),
			OrderFactory::refund( $order, [], 7.77 ),
		];
		$parent  = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $parent );
		$this->assertSame( 1500 + 708 + 777, Money::to_minor( $parent->get_total_refunded() ) );

		$sums = [
			'incl' => 0,
			'excl' => 0,
		];
		foreach ( $refunds as $refund ) {
			[ $incl, $excl ] = $this->assertAddsUp( $refund );
			$sums['incl']   += (int) $this->row( $incl, 'total' )->amount;
			$sums['excl']   += (int) $this->row( $excl, 'total' )->amount;
		}

		$this->assertSame( Money::to_minor( $parent->get_total_refunded() ), $sums['incl'] );
		$this->assertSame( Money::to_minor( $parent->get_total_refunded() ), $sums['excl'] );
	}

	/**
	 * Builds the credit note statement of a refund with the default settings and a display mode.
	 *
	 * @param WC_Order_Refund      $refund  Refund.
	 * @param string               $display `incl` or `excl`.
	 * @param array<string, mixed> $content Content settings overrides.
	 */
	private function build( WC_Order_Refund $refund, string $display, array $content = [] ): Statement {
		$settings                             = Module::settings();
		$settings['content']['price_display'] = $display;
		$settings['content']                  = array_merge( $settings['content'], $content );

		$parent = wc_get_order( $refund->get_parent_id() );
		$this->assertInstanceOf( WC_Order::class, $parent );

		return CreditNoteStatementBuilder::build( $refund, $parent, $settings );
	}

	/**
	 * The invariant, in both display modes: the total row, the summed rows and the refunded amount are
	 * the same figure.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @return array{0: Statement, 1: Statement} The incl. VAT then the excl. VAT statement.
	 */
	private function assertAddsUp( WC_Order_Refund $refund ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit assertion naming.
		$statements = [];
		foreach ( [ 'incl', 'excl' ] as $mode ) {
			$statement = $this->build( $refund, $mode );
			$total     = $this->row( $statement, 'total' );

			$this->assertTrue( $total->grand, $mode );
			$this->assertSame( $total->amount, $statement->sum(), $mode );
			$this->assertSame( $total->amount, Money::to_minor( $refund->get_amount() ), $mode );
			$this->assertSame( $total->amount, -Money::to_minor( $refund->get_total() ), $mode );
			$this->assertSame( 'incl' === $mode, $statement->incl_tax );
			foreach ( $statement->lines as $line ) {
				$this->assertGreaterThanOrEqual( 0, $line->quantity, $mode );
			}

			$statements[] = $statement;
		}

		return [ $statements[0], $statements[1] ];
	}

	/**
	 * Writes on a refund's tax items what WooCommerce stamps them with when the refund is made after
	 * the shop rate changed (or was deleted): `update_taxes()` reads the rate of the day. Within one
	 * PHP process WooCommerce serves the rate from a per-request cache, so the test writes it itself.
	 *
	 * @param WC_Order_Refund $refund  Refund.
	 * @param float           $percent Rate percent of the day.
	 * @param string          $label   Rate label of the day.
	 */
	private function restamp_taxes( WC_Order_Refund $refund, float $percent, string $label ): void {
		$items = $refund->get_items( 'tax' );
		$this->assertNotEmpty( $items );
		foreach ( $items as $tax_item ) {
			$this->assertInstanceOf( WC_Order_Item_Tax::class, $tax_item );
			$tax_item->set_rate_percent( $percent );
			$tax_item->set_label( $label );
			$tax_item->save();
		}
	}

	/**
	 * Every product, shipping and fee item of an order, refunded in full.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array<string, mixed>>
	 */
	private function all_lines( WC_Order $order ): array {
		$lines = [];
		foreach ( $order->get_items( [ 'line_item', 'shipping', 'fee' ] ) as $item ) {
			if ( $item instanceof WC_Order_Item_Product || $item instanceof WC_Order_Item_Shipping || $item instanceof WC_Order_Item_Fee ) {
				$lines[ $item->get_id() ] = OrderFactory::refund_line( $item );
			}
		}

		return $lines;
	}

	/**
	 * The first product item of an order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function product_item( WC_Order $order ): WC_Order_Item_Product {
		$items = array_values( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $items[0] ?? null );

		return $items[0];
	}

	/**
	 * The first shipping item of an order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function shipping_item( WC_Order $order ): WC_Order_Item_Shipping {
		$items = array_values( $order->get_shipping_methods() );
		$this->assertInstanceOf( WC_Order_Item_Shipping::class, $items[0] ?? null );

		return $items[0];
	}

	/**
	 * The first fee item of an order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function fee_item( WC_Order $order ): WC_Order_Item_Fee {
		$items = array_values( $order->get_fees() );
		$this->assertInstanceOf( WC_Order_Item_Fee::class, $items[0] ?? null );

		return $items[0];
	}

	/**
	 * Row keys in statement order.
	 *
	 * @param Statement $statement Statement.
	 * @return string[]
	 */
	private function keys( Statement $statement ): array {
		return array_map( static fn( TotalRow $row ): string => $row->key, $statement->rows );
	}

	/**
	 * VAT rows as amounts keyed by label, sorted by label.
	 *
	 * @param Statement $statement Statement.
	 * @return array<string, int|null>
	 */
	private function taxes( Statement $statement ): array {
		$taxes = [];
		foreach ( $this->rows( $statement, 'tax' ) as $row ) {
			$taxes[ $row->label ] = $row->amount;
		}
		ksort( $taxes );

		return $taxes;
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
}
