<?php
/**
 * Availability of a customer link to an issued document.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Front\Availability;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Refund;

/**
 * A link is available only when the endpoint would serve the PDF: verified archive, or a never-built
 * PDF the endpoint can build. Reads only.
 */
class AvailabilityTest extends TestCase {

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

	public function test_verified_invoice_and_credit_note_are_available(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );

		$this->assertTrue( Availability::is_available( Register::find( 'invoice', $order->get_id() ) ) );
		$this->assertTrue( Availability::is_available( Register::find( 'credit_note', $refund->get_id() ) ) );
		$this->assertFalse( Availability::can_build( Register::find( 'invoice', $order->get_id() ) ), 'A recorded file is never built.' );
	}

	public function test_damaged_or_missing_recorded_file_is_not_available(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );

		$invoice = Register::find( 'invoice', $order->get_id() );
		file_put_contents( (string) Archive::path( $invoice ), '%PDF damaged' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertFalse( Availability::is_available( $invoice ) );

		$credit = Register::find( 'credit_note', $refund->get_id() );
		wp_delete_file( (string) Archive::path( $credit ) );
		$this->assertFalse( Availability::is_available( $credit ) );
	}

	public function test_never_built_invoice_is_available_while_its_order_exists(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		Register::import( 'invoice', $order->get_id(), '202610000077', 'invoice_2026', 77, 1760000000 );
		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNull( $doc->file );

		$this->assertTrue( Availability::is_available( $doc ) );
		$this->assertTrue( Availability::can_build( $doc ) );
		// Reads only: nothing built.
		$this->assertNull( Register::find( 'invoice', $order->get_id() )->file );

		$order->delete( true );
		$this->assertFalse( Availability::is_available( $doc ) );
	}

	public function test_never_built_credit_note_is_available_only_while_its_refund_exists(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$doc    = $this->forget_file( Register::find( 'credit_note', $refund->get_id() ) );

		$this->assertTrue( Availability::is_available( $doc ) );
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() )->file );

		add_filter( 'lumia_factures_allow_credited_refund_deletion', '__return_true' );
		$this->assertTrue( wc_get_order( $refund->get_id() )->delete( true ) );
		remove_filter( 'lumia_factures_allow_credited_refund_deletion', '__return_true' );

		$this->assertFalse( Availability::is_available( $doc ) );
		$this->assertFalse( Availability::can_build( $doc ) );
	}

	public function test_reserved_row_is_not_available(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$this->assertNotNull( Register::reserve( 'invoice', $order->get_id() ) );
		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertSame( 'reserved', $doc->status );

		$this->assertFalse( Availability::is_available( $doc ) );
		$this->assertFalse( Availability::can_build( $doc ) );
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
		$this->track( $doc );

		return $refund;
	}

	/**
	 * Turns an issued document into one whose PDF was never built (NULL file).
	 *
	 * @param IssuedDocument $doc Document.
	 * @return IssuedDocument The row as stored now.
	 */
	private function forget_file( IssuedDocument $doc ): IssuedDocument {
		global $wpdb;

		$path = Archive::path( $doc );
		if ( null !== $path ) {
			wp_delete_file( $path );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- simulates a PDF that was never archived.
		$wpdb->update(
			Installer::documents_table(),
			[
				'file'     => null,
				'checksum' => null,
			],
			[ 'id' => $doc->id ]
		);
		$fresh = Register::find( $doc->type, $doc->order_id );
		$this->assertNull( $fresh->file );

		return $fresh;
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
