<?php
/**
 * Register of documents.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * Register: reservation, assignment, release.
 */
class RegisterTest extends TestCase {

	public function test_reserve_is_unique_per_order(): void {
		$first = Register::reserve( 'invoice', 501 );

		$this->assertIsInt( $first );
		$this->assertNull( Register::reserve( 'invoice', 501 ) );
		$this->assertIsInt( Register::reserve( 'credit_note', 501 ) );
		$this->assertIsInt( Register::reserve( 'invoice', 502 ) );
	}

	public function test_find_returns_reserved_row(): void {
		$this->assertNull( Register::find( 'invoice', 503 ) );

		$id  = Register::reserve( 'invoice', 503 );
		$doc = Register::find( 'invoice', 503 );

		$this->assertNotNull( $doc );
		$this->assertSame( $id, $doc->id );
		$this->assertSame( 'reserved', $doc->status );
		$this->assertNull( $doc->number );
		$this->assertSame( 0, $doc->issued_at );
	}

	public function test_assign_issues_and_round_trips_timestamp(): void {
		$id   = (int) Register::reserve( 'invoice', 504 );
		$time = (int) strtotime( '2026-10-19 13:45:10 UTC' );

		$this->assertTrue( Register::assign( $id, 'FA-1', 'invoice', 1, $time ) );

		$doc = Register::find( 'invoice', 504 );
		$this->assertSame( 'issued', $doc->status );
		$this->assertSame( 'FA-1', $doc->number );
		$this->assertSame( 'invoice', $doc->sequence_key );
		$this->assertSame( 1, $doc->sequence );
		$this->assertSame( $time, $doc->issued_at );
	}

	public function test_assign_only_updates_a_reserved_row(): void {
		$id = (int) Register::reserve( 'invoice', 505 );

		$this->assertTrue( Register::assign( $id, 'FA-10', 'invoice', 10, time() ) );
		$this->assertFalse( Register::assign( $id, 'FA-11', 'invoice', 11, time() ) );
		$this->assertSame( 'FA-10', Register::find( 'invoice', 505 )->number );
		$this->assertFalse( Register::assign( 999999, 'FA-12', 'invoice', 12, time() ) );
	}

	public function test_assign_refuses_duplicate_number(): void {
		$a = (int) Register::reserve( 'invoice', 506 );
		$b = (int) Register::reserve( 'invoice', 507 );

		$this->assertTrue( Register::assign( $a, 'FA-DUP', 'invoice', 1, time() ) );
		$this->assertFalse( Register::assign( $b, 'FA-DUP', 'invoice', 2, time() ) );

		$doc = Register::find( 'invoice', 507 );
		$this->assertSame( 'reserved', $doc->status );
		$this->assertNull( $doc->number );
	}

	public function test_attach_file(): void {
		$id = (int) Register::reserve( 'invoice', 508 );
		Register::assign( $id, 'FA-F', 'invoice', 1, time() );

		$this->assertTrue( Register::attach_file( $id, '2026/FA-F.pdf', str_repeat( 'a', 64 ) ) );

		$doc = Register::find( 'invoice', 508 );
		$this->assertSame( '2026/FA-F.pdf', $doc->file );
		$this->assertSame( str_repeat( 'a', 64 ), $doc->checksum );
	}

	public function test_release_only_deletes_unnumbered_rows(): void {
		$reserved = (int) Register::reserve( 'invoice', 509 );
		Register::release( $reserved );
		$this->assertNull( Register::find( 'invoice', 509 ) );

		$issued = (int) Register::reserve( 'invoice', 510 );
		Register::assign( $issued, 'FA-R', 'invoice', 1, time() );
		Register::release( $issued );
		$this->assertNotNull( Register::find( 'invoice', 510 ) );
	}
}
