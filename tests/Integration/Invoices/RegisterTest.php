<?php
/**
 * Register of documents.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
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

	public function test_attach_file_cannot_repoint_an_issued_invoice(): void {
		$id = (int) Register::reserve( 'invoice', 511 );
		Register::assign( $id, 'FA-A', 'invoice', 1, time() );

		$this->assertTrue( Register::attach_file( $id, 'first.pdf', str_repeat( 'a', 64 ) ) );
		$this->assertFalse( Register::attach_file( $id, 'second.pdf', str_repeat( 'b', 64 ) ) );

		$doc = Register::find( 'invoice', 511 );
		$this->assertSame( 'first.pdf', $doc->file );
		$this->assertSame( str_repeat( 'a', 64 ), $doc->checksum );
	}

	public function test_attach_file_refuses_a_reserved_row(): void {
		$id = (int) Register::reserve( 'invoice', 512 );

		$this->assertFalse( Register::attach_file( $id, 'x.pdf', str_repeat( 'a', 64 ) ) );
		$this->assertNull( Register::find( 'invoice', 512 )->file );
	}

	public function test_replace_file_with_matching_old_file(): void {
		$id = (int) Register::reserve( 'invoice', 513 );
		Register::assign( $id, 'FA-B', 'invoice', 1, time() );
		Register::attach_file( $id, 'old.pdf', str_repeat( 'a', 64 ) );

		$this->assertTrue( Register::replace_file( $id, 'old.pdf', 'new.pdf', str_repeat( 'c', 64 ) ) );

		$doc = Register::find( 'invoice', 513 );
		$this->assertSame( 'new.pdf', $doc->file );
		$this->assertSame( str_repeat( 'c', 64 ), $doc->checksum );
		$this->assertSame( 'FA-B', $doc->number );
	}

	public function test_replace_file_with_wrong_old_file_changes_nothing(): void {
		$id = (int) Register::reserve( 'invoice', 514 );
		Register::assign( $id, 'FA-C', 'invoice', 1, time() );
		Register::attach_file( $id, 'old.pdf', str_repeat( 'a', 64 ) );

		$this->assertFalse( Register::replace_file( $id, 'other.pdf', 'new.pdf', str_repeat( 'c', 64 ) ) );

		$doc = Register::find( 'invoice', 514 );
		$this->assertSame( 'old.pdf', $doc->file );
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

	public function test_import_inserts_an_issued_row(): void {
		$time = (int) strtotime( '2026-10-02 09:30:00 UTC' );
		$id   = Register::import( 'invoice', 520, '202610029241', 'invoice_2026', 29241, $time );

		$this->assertIsInt( $id );
		$doc = Register::find( 'invoice', 520 );
		$this->assertSame( $id, $doc->id );
		$this->assertSame( 'issued', $doc->status );
		$this->assertSame( '202610029241', $doc->number );
		$this->assertSame( 'invoice_2026', $doc->sequence_key );
		$this->assertSame( 29241, $doc->sequence );
		$this->assertSame( $time, $doc->issued_at );
		$this->assertNull( $doc->file );

		// The file is recorded once, like an invoice issued here.
		$this->assertTrue( Register::attach_file( $id, '2026/10/a.pdf', str_repeat( 'a', 64 ) ) );
		$this->assertFalse( Register::attach_file( $id, '2026/10/b.pdf', str_repeat( 'b', 64 ) ) );
	}

	public function test_import_refuses_duplicates_without_inserting(): void {
		$this->assertIsInt( Register::import( 'invoice', 521, 'FA-IMP-1', 'invoice_2026', 1, time() ) );

		// Same order, other number.
		$this->assertNull( Register::import( 'invoice', 521, 'FA-IMP-2', 'invoice_2026', 2, time() ) );
		$this->assertSame( 'FA-IMP-1', Register::find( 'invoice', 521 )->number );
		$this->assertFalse( Register::number_exists( 'invoice', 'FA-IMP-2' ) );

		// Same number, other order.
		$this->assertNull( Register::import( 'invoice', 522, 'FA-IMP-1', 'invoice_2026', 1, time() ) );
		$this->assertNull( Register::find( 'invoice', 522 ) );

		// An order with a reservation in progress.
		Register::reserve( 'invoice', 523 );
		$this->assertNull( Register::import( 'invoice', 523, 'FA-IMP-3', 'invoice_2026', 3, time() ) );
		$this->assertSame( 'reserved', Register::find( 'invoice', 523 )->status );

		$this->assertNull( Register::import( 'invoice', 524, '', 'invoice_2026', 4, time() ) );
	}

	public function test_reserve_stores_parent_id(): void {
		$id = Register::reserve( 'credit_note', 601, 600 );

		$this->assertIsInt( $id );
		$doc = Register::find( 'credit_note', 601 );
		$this->assertSame( $id, $doc->id );
		$this->assertSame( 601, $doc->order_id );
		$this->assertSame( 600, $doc->parent_id );
		$this->assertSame( 'reserved', $doc->status );

		// Kept through assign() and attach_file().
		Register::assign( (int) $id, 'AV-1', 'credit_note', 1, time() );
		Register::attach_file( (int) $id, 'av-1.pdf', str_repeat( 'a', 64 ) );
		$this->assertSame( 600, Register::find( 'credit_note', 601 )->parent_id );
	}

	public function test_reserve_without_parent_defaults_to_zero(): void {
		Register::reserve( 'invoice', 602 );
		Register::import( 'invoice', 603, 'FA-IMP-P', 'invoice_2026', 1, time() );

		$this->assertSame( 0, Register::find( 'invoice', 602 )->parent_id );
		$this->assertSame( 0, Register::find( 'invoice', 603 )->parent_id );

		// The value object defaults to 0 too.
		$doc = new IssuedDocument( 1, 'invoice', 1, 'issued', 'FA-1', 'invoice', 1, time(), null, null );
		$this->assertSame( 0, $doc->parent_id );
	}

	public function test_for_parent_lists_rows_in_id_order(): void {
		$second = (int) Register::reserve( 'credit_note', 612, 610 );
		$first  = (int) Register::reserve( 'credit_note', 611, 610 );
		$third  = (int) Register::reserve( 'credit_note', 613, 610 );
		Register::assign( $second, 'AV-2', 'credit_note', 2, time() );

		// Noise: another parent, the parent's own invoice, a row of another type with the same parent.
		Register::reserve( 'credit_note', 621, 620 );
		Register::reserve( 'invoice', 610 );
		Register::reserve( 'other', 614, 610 );

		$docs = Register::for_parent( 'credit_note', 610 );

		$this->assertSame( [ 0, 1, 2 ], array_keys( $docs ) );
		$this->assertSame( [ $second, $first, $third ], array_map( static fn ( IssuedDocument $doc ): int => $doc->id, $docs ) );
		$this->assertSame( [ 612, 611, 613 ], array_map( static fn ( IssuedDocument $doc ): int => $doc->order_id, $docs ) );
		$this->assertSame( [ 'issued', 'reserved', 'reserved' ], array_map( static fn ( IssuedDocument $doc ): string => $doc->status, $docs ) );
		foreach ( $docs as $doc ) {
			$this->assertSame( 610, $doc->parent_id );
		}

		$this->assertSame( [], Register::for_parent( 'credit_note', 630 ) );
		// 0 means "no parent": never every invoice.
		$this->assertSame( [], Register::for_parent( 'invoice', 0 ) );
	}

	public function test_one_credit_note_per_refund(): void {
		$this->assertIsInt( Register::reserve( 'credit_note', 631, 630 ) );

		// Same refund, same or other parent: refused at the unique key (type, order_id).
		$this->assertNull( Register::reserve( 'credit_note', 631, 630 ) );
		$this->assertNull( Register::reserve( 'credit_note', 631, 640 ) );

		$this->assertSame( 630, Register::find( 'credit_note', 631 )->parent_id );
		$this->assertCount( 1, Register::for_parent( 'credit_note', 630 ) );
		$this->assertSame( [], Register::for_parent( 'credit_note', 640 ) );
	}

	public function test_number_exists_in_other_type(): void {
		$invoice = (int) Register::reserve( 'invoice', 641 );
		Register::assign( $invoice, 'DOC-1', 'invoice', 1, time() );
		$credit_note = (int) Register::reserve( 'credit_note', 642, 641 );
		Register::assign( $credit_note, 'AV-1', 'credit_note', 1, time() );
		Register::reserve( 'credit_note', 643, 641 );

		$this->assertTrue( Register::number_exists_in_other_type( 'credit_note', 'DOC-1' ) );
		$this->assertFalse( Register::number_exists_in_other_type( 'invoice', 'DOC-1' ) );
		$this->assertTrue( Register::number_exists_in_other_type( 'invoice', 'AV-1' ) );
		$this->assertFalse( Register::number_exists_in_other_type( 'credit_note', 'AV-1' ) );
		$this->assertFalse( Register::number_exists_in_other_type( 'invoice', 'AV-2' ) );
		$this->assertFalse( Register::number_exists_in_other_type( 'credit_note', 'FA-404' ) );

		// The unique key (type, number) itself does not span types.
		$other = (int) Register::reserve( 'credit_note', 644, 641 );
		$this->assertTrue( Register::assign( $other, 'DOC-1', 'credit_note', 2, time() ) );
	}
}
