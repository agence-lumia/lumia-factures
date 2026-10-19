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
}
