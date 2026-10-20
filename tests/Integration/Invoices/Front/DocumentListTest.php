<?php
/**
 * Read-only list of an order's customer documents.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Front\CustomerDownload;
use Lumia\Factures\Modules\Invoices\Front\DocumentList;
use Lumia\Factures\Modules\Invoices\Migration\LegacySource;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Refund;

/**
 * The invoice then the credit notes, only those a link can serve, and never a write.
 */
class DocumentListTest extends TestCase {

	/**
	 * Archived files written by a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		delete_option( 'lumia_factures_module_invoices' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		foreach ( $this->written as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		parent::tear_down();
	}

	public function test_lists_invoice_then_credit_notes_in_issue_order(): void {
		$order   = $this->issued_order();
		$first   = $this->refund( $order, 2.0 );
		$second  = $this->refund( $order, 3.0 );
		$invoice = Register::find( 'invoice', $order->get_id() );
		$cn1     = Register::find( 'credit_note', $first->get_id() );
		$cn2     = Register::find( 'credit_note', $second->get_id() );

		$list = DocumentList::for_order( wc_get_order( $order->get_id() ), false );

		$this->assertSame( [ 'invoice', 'credit_note', 'credit_note' ], array_column( $list, 'type' ) );
		$this->assertSame( [ $invoice->number, $cn1->number, $cn2->number ], array_column( $list, 'number' ) );
		$this->assertSame( [ 'Invoice', 'Credit note', 'Credit note' ], array_column( $list, 'label' ) );
		$this->assertSame( wp_date( (string) get_option( 'date_format' ), $invoice->issued_at ), $list[0]['date'] );
		$this->assertStringContainsString( CustomerDownload::QUERY_VAR . '=' . $order->get_id(), $list[0]['url'] );
		$this->assertStringContainsString( CustomerDownload::CREDIT_NOTE_VAR . '=' . $first->get_id(), $list[1]['url'] );

		// Issue order is the issue date, not the row id.
		$this->set_issued_at( $cn1, $cn2->issued_at + 60 );
		$list = DocumentList::for_order( wc_get_order( $order->get_id() ), false );
		$this->assertSame( [ $invoice->number, $cn2->number, $cn1->number ], array_column( $list, 'number' ) );
	}

	public function test_only_refund_id_filters_to_that_credit_note(): void {
		$order   = $this->issued_order();
		$first   = $this->refund( $order, 2.0 );
		$second  = $this->refund( $order, 3.0 );
		$invoice = Register::find( 'invoice', $order->get_id() );

		$list = DocumentList::for_order( wc_get_order( $order->get_id() ), true, $second->get_id() );

		$this->assertSame( [ $invoice->number, Register::find( 'credit_note', $second->get_id() )->number ], array_column( $list, 'number' ) );
		$this->assertNotContains( Register::find( 'credit_note', $first->get_id() )->number, array_column( $list, 'number' ) );

		// A refund id that is not one of the order's: the invoice alone.
		$this->assertSame( [ 'invoice' ], array_column( DocumentList::for_order( wc_get_order( $order->get_id() ), true, 0 ), 'type' ) );
	}

	public function test_excludes_reserved_and_damaged_rows(): void {
		$order   = $this->issued_order();
		$damaged = $this->refund( $order, 2.0 );
		file_put_contents( (string) Archive::path( Register::find( 'credit_note', $damaged->get_id() ) ), '%PDF damaged' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test corrupts the archive.

		$reserved = $this->refund_without_hook( $order, 3.0 );
		$this->assertNotNull( Register::reserve( 'credit_note', $reserved->get_id(), $order->get_id() ) );
		$this->assertSame( 'reserved', Register::find( 'credit_note', $reserved->get_id() )->status );

		$this->assertSame( [ 'invoice' ], array_column( DocumentList::for_order( wc_get_order( $order->get_id() ), false ), 'type' ) );

		// A damaged invoice is left out too.
		file_put_contents( (string) Archive::path( Register::find( 'invoice', $order->get_id() ) ), '%PDF damaged' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test corrupts the archive.
		$this->assertSame( [], DocumentList::for_order( wc_get_order( $order->get_id() ), false ) );
	}

	public function test_includes_null_file_invoice(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		Register::import( 'invoice', $order->get_id(), '202610000077', 'invoice_2026', 77, 1760000000 );
		$this->assertNull( Register::find( 'invoice', $order->get_id() )->file );

		$list = DocumentList::for_order( $order, false );

		$this->assertSame( [ '202610000077' ], array_column( $list, 'number' ) );
		// Listing builds nothing.
		$this->assertNull( Register::find( 'invoice', $order->get_id() )->file );
	}

	public function test_never_writes(): void {
		// An eligible order with no invoice yet: a trigger status reached without the status hook.
		$eligible = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		remove_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10 );
		$eligible->set_status( 'processing' );
		$eligible->save();
		add_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10, 4 );

		// An order the migration gate blocks (a legacy number the import did not record).
		$blocked = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$blocked->update_meta_data( LegacySource::META_NUMBER, 'FPL-0001' );
		$blocked->save();
		remove_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10 );
		$blocked->set_status( 'processing' );
		$blocked->save();
		add_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10, 4 );

		foreach ( [ $eligible, $blocked ] as $order ) {
			$before = $this->snapshot( $order );
			$rows   = $this->register_rows();

			$this->assertSame( [], DocumentList::for_order( wc_get_order( $order->get_id() ), false ) );
			$this->assertSame( [], DocumentList::for_order( wc_get_order( $order->get_id() ), true ) );
			$this->assertSame( [], DocumentList::for_order( wc_get_order( $order->get_id() ), true, 0 ) );

			$this->assertSame( $before, $this->snapshot( $order ) );
			$this->assertSame( $rows, $this->register_rows() );
			$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		}
	}

	public function test_with_key_true_puts_parent_key_in_every_url(): void {
		$order = $this->issued_order();
		$this->refund( $order, 2.0 );
		$this->refund( $order, 3.0 );
		$key = $order->get_order_key();

		// A manager gets in without the key: only $with_key puts it in.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$with = DocumentList::for_order( wc_get_order( $order->get_id() ), true );
		$this->assertCount( 3, $with );
		foreach ( $with as $entry ) {
			$this->assertStringContainsString( 'key=' . $key, $entry['url'] );
		}

		foreach ( DocumentList::for_order( wc_get_order( $order->get_id() ), false ) as $entry ) {
			$this->assertStringNotContainsString( 'key=', $entry['url'] );
		}
	}

	public function test_empty_when_nothing_issued(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		$this->assertSame( [], DocumentList::for_order( $order, false ) );
		$this->assertSame( [], DocumentList::for_order( $order, true ) );
		$this->assertSame( [], DocumentList::for_order( new WC_Order(), true ) );
	}

	/**
	 * What a write would change on the order: notes, meta, modification date.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	private function snapshot( WC_Order $order ): array {
		$fresh = wc_get_order( $order->get_id() );
		$meta  = [];
		foreach ( $fresh->get_meta_data() as $item ) {
			$data                 = $item->get_data();
			$meta[ $data['key'] ] = $data['value'];
		}
		ksort( $meta );

		return [
			'notes'    => count( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) ),
			'meta'     => $meta,
			'modified' => null !== $fresh->get_date_modified() ? $fresh->get_date_modified()->getTimestamp() : 0,
		];
	}

	/**
	 * Number of register rows.
	 */
	private function register_rows(): int {
		global $wpdb;

		$table = Installer::documents_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
	}

	/**
	 * Moves the issue date of a register row.
	 *
	 * @param IssuedDocument $doc       Document.
	 * @param int            $issued_at New issue date.
	 */
	private function set_issued_at( IssuedDocument $doc, int $issued_at ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reorders two documents issued in the same second.
		$wpdb->update( Installer::documents_table(), [ 'issued_at' => gmdate( 'Y-m-d H:i:s', $issued_at ) ], [ 'id' => $doc->id ] );
		$this->assertSame( $issued_at, Register::find( $doc->type, $doc->order_id )->issued_at );
	}

	/**
	 * An order with an issued, archived invoice.
	 */
	private function issued_order(): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->update_status( 'processing' );
		$this->track( Register::find( 'invoice', $order->get_id() ) );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * A refund of an amount with its credit note, remembered for tear_down.
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount.
	 */
	private function refund( WC_Order $order, float $amount ): WC_Order_Refund {
		$refund = OrderFactory::refund( wc_get_order( $order->get_id() ), [], $amount );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->track( $doc );

		return $refund;
	}

	/**
	 * A refund created without the `woocommerce_order_refunded` listener: no credit note yet.
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount.
	 */
	private function refund_without_hook( WC_Order $order, float $amount ): WC_Order_Refund {
		remove_action( 'woocommerce_order_refunded', [ CreditNoteIssuer::class, 'on_refunded' ], 10 );
		try {
			$refund = OrderFactory::refund( wc_get_order( $order->get_id() ), [], $amount );
		} finally {
			add_action( 'woocommerce_order_refunded', [ CreditNoteIssuer::class, 'on_refunded' ], 10, 2 );
		}
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );

		return $refund;
	}

	/**
	 * Remembers the archived file of a document for tear_down.
	 *
	 * @param IssuedDocument|null $doc Document.
	 */
	private function track( ?IssuedDocument $doc ): void {
		if ( null !== $doc && null !== $doc->file ) {
			$this->written[] = Archive::root() . '/' . $doc->file;
		}
	}
}
