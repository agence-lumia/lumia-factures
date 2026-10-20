<?php
/**
 * Number format validation.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Numbering\NumberFormat;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * NumberFormat::validate() rules.
 */
class NumberFormatTest extends TestCase {

	public function test_validate_requires_number_tag(): void {
		$this->assertIsString( NumberFormat::validate( 'FA-{year}', false, 'yearly' ) );
		$this->assertIsString( NumberFormat::validate( '', false, 'yearly' ) );
	}

	public function test_validate_rejects_unknown_tag(): void {
		$this->assertIsString( NumberFormat::validate( '{bogus}-{number}', false, 'yearly' ) );
	}

	public function test_validate_rejects_filename_characters(): void {
		$this->assertIsString( NumberFormat::validate( 'FA/{number}', false, 'yearly' ) );
		$this->assertIsString( NumberFormat::validate( 'FA:{number}', false, 'yearly' ) );
	}

	public function test_validate_requires_year_for_yearly_reset(): void {
		$this->assertIsString( NumberFormat::validate( 'FA-{number:5}', true, 'yearly' ) );
		$this->assertTrue( NumberFormat::validate( 'FA-{number:5}', false, 'yearly' ) );
	}

	public function test_validate_requires_month_for_monthly_reset(): void {
		$this->assertIsString( NumberFormat::validate( 'FA-{year}-{number}', true, 'monthly' ) );
		$this->assertTrue( NumberFormat::validate( 'FA-{year}{month}-{number}', true, 'monthly' ) );
	}

	public function test_validate_accepts_neonat_format(): void {
		$this->assertTrue( NumberFormat::validate( '{year}{month}{number:6}', true, 'yearly' ) );
	}

	public function test_build_neonat_format(): void {
		$order = wc_create_order();
		$time  = (int) strtotime( '2026-10-19 12:00:00 UTC' );

		$this->assertSame( '202610029185', NumberFormat::build( '{year}{month}{number:6}', 29185, $order, $time ) );
	}

	public function test_build_pads_and_two_digit_year(): void {
		$order = wc_create_order();
		$time  = (int) strtotime( '2026-03-05 12:00:00 UTC' );

		$this->assertSame( 'FA-26-03-05-0042', NumberFormat::build( 'FA-{year:2}-{month}-{day}-{number:4}', 42, $order, $time ) );
		$this->assertSame( '42', NumberFormat::build( '{number}', 42, $order, $time ) );
		$this->assertSame( $order->get_id() . '/' . $order->get_order_number(), NumberFormat::build( '{order_id}/{order_number}', 1, $order, $time ) );
		$this->assertSame( '0', NumberFormat::build( '{customer_id}', 1, $order, $time ) );
	}

	public function test_build_resolves_unknown_tag_through_filter(): void {
		$order = wc_create_order();
		$cb    = static fn( string $value, string $tag, string $arg ): string => 'shop' === $tag ? 'X' . $arg : $value;

		add_filter( 'lumia_factures_number_tag', $cb, 10, 3 );
		$this->assertSame( 'XA-7', NumberFormat::build( '{shop:A}-{number}', 7, $order, time() ) );
		remove_filter( 'lumia_factures_number_tag', $cb, 10 );
	}

	public function test_preview_uses_sample_values(): void {
		$this->assertSame( '14243-27-0003', NumberFormat::preview( '{order_number}-{customer_id}-{number:4}', 3 ) );
	}

	public function test_prefix_is_the_text_before_the_first_tag(): void {
		$this->assertSame( 'FA-', NumberFormat::prefix( 'FA-{year}-{number:5}' ) );
		$this->assertSame( 'AV-', NumberFormat::prefix( '  AV-{number}' ) );
		$this->assertSame( '', NumberFormat::prefix( '{year}{month}{number:6}' ) );
		$this->assertSame( 'F{x', NumberFormat::prefix( 'F{x{number}' ) );
	}

	public function test_prefixes_differ(): void {
		$this->assertTrue( NumberFormat::prefixes_differ( 'FA-{year}-{number:5}', 'AV-{year}-{number:5}' ) );
		$this->assertTrue( NumberFormat::prefixes_differ( 'FA-{number}', 'FAV-{number}' ) );
		$this->assertFalse( NumberFormat::prefixes_differ( 'FA-{number}', 'FA-{year}{number}' ) );
		$this->assertFalse( NumberFormat::prefixes_differ( 'FA-{number}', 'FA-AV-{number}' ) );
		$this->assertFalse( NumberFormat::prefixes_differ( 'FA-AV-{number}', 'FA-{number}' ) );
		$this->assertFalse( NumberFormat::prefixes_differ( 'FA-{number}', 'fa-{number}' ) );
		$this->assertFalse( NumberFormat::prefixes_differ( "\u{00C9}-{number}", 'e-{number}' ), 'Accents are folded like the register collation.' );
		$this->assertFalse( NumberFormat::prefixes_differ( 'FA-{number}', '{year}-{number}' ) );

		// Invoice format starting with a tag: the other prefix must not start with a digit.
		$this->assertTrue( NumberFormat::prefixes_differ( '{year}{month}{number:6}', 'AV-{year}-{number:5}' ) );
		$this->assertFalse( NumberFormat::prefixes_differ( '{year}-{number:5}', '2026-{number:5}' ) );
		$this->assertFalse( NumberFormat::prefixes_differ( '{year}{number}', '' ) );
	}

	public function test_number_starts(): void {
		$this->assertSame( [ 'FA-' ], NumberFormat::number_starts( 'FA-{year}-{number:5}' ) );
		$this->assertSame( [ '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ], NumberFormat::number_starts( '{year}{month}{number:6}' ) );
	}
}
