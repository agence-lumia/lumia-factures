<?php
/**
 * Gapless counters.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * Sequences: atomic increment, keys, raise.
 */
class SequencesTest extends TestCase {

	public function test_next_starts_at_start_number(): void {
		$this->assertSame( 1, Sequences::next( 'invoice', 1 ) );
		$this->assertSame( 2, Sequences::next( 'invoice', 1 ) );
		$this->assertSame( 3, Sequences::next( 'invoice', 1 ) );
		$this->assertSame( 3, Sequences::current( 'invoice' ) );
	}

	public function test_next_honours_start_number_and_clamps_below_one(): void {
		$this->assertSame( 29185, Sequences::next( 'a', 29185 ) );
		$this->assertSame( 29186, Sequences::next( 'a', 29185 ) );
		$this->assertSame( 1, Sequences::next( 'b', 0 ) );
		$this->assertSame( 1, Sequences::next( 'c', -5 ) );
	}

	/**
	 * Regression test of legacy bug C1: $wpdb->insert_id was read after an UPDATE.
	 */
	public function test_next_reads_value_not_insert_id(): void {
		global $wpdb;

		$this->assertSame( 1, Sequences::next( 'invoice', 1 ) );

		// An unrelated INSERT leaves a different insert_id on the connection.
		add_option( 'lumia_factures_test_unrelated', 'x' );
		$this->assertNotSame( 1, (int) $wpdb->insert_id );

		$this->assertSame( 2, Sequences::next( 'invoice', 1 ) );

		add_option( 'lumia_factures_test_unrelated_2', 'y' );
		$this->assertSame( 3, Sequences::next( 'invoice', 1 ) );
	}

	public function test_key_per_period(): void {
		$time = (int) strtotime( '2026-10-19 12:00:00 UTC' );

		$off     = [
			'reset_enabled' => false,
			'reset_period'  => 'yearly',
		];
		$yearly  = [
			'reset_enabled' => true,
			'reset_period'  => 'yearly',
		];
		$monthly = [
			'reset_enabled' => true,
			'reset_period'  => 'monthly',
		];

		$this->assertSame( 'invoice', Sequences::key( 'invoice', $time, $off ) );
		$this->assertSame( 'invoice_2026', Sequences::key( 'invoice', $time, $yearly ) );
		$this->assertSame( 'invoice_2026_10', Sequences::key( 'invoice', $time, $monthly ) );
	}

	public function test_raise_never_lowers(): void {
		$this->assertTrue( Sequences::raise( 'invoice', 29184 ) );
		$this->assertSame( 29184, Sequences::current( 'invoice' ) );

		$this->assertFalse( Sequences::raise( 'invoice', 100 ) );
		$this->assertFalse( Sequences::raise( 'invoice', 29184 ) );
		$this->assertSame( 29184, Sequences::current( 'invoice' ) );

		$this->assertSame( 29185, Sequences::next( 'invoice', 1 ) );
	}

	public function test_all_lists_counters(): void {
		Sequences::next( 'invoice_2026', 1 );
		Sequences::next( 'invoice_2026', 1 );

		$found = array_values( array_filter( Sequences::all(), static fn( array $row ): bool => 'invoice_2026' === $row['key'] ) );

		$this->assertCount( 1, $found );
		$this->assertSame( 2, $found[0]['value'] );
		$this->assertNotSame( '', $found[0]['updated_at'] );
	}
}
