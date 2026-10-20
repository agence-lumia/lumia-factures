<?php
/**
 * Issuing credit notes.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\Deactivator;
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Migration\LegacySource;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Rendering\DocumentData;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;
use WC_Payment_Gateway;
use WP_Error;
use WP_REST_Request;

/**
 * CreditNoteIssuer: one credit note per refund of an invoiced order, its own number series,
 * a write-once archive, the gate, the pending flag, the deletion block and the sweep.
 */
class CreditNoteIssuerTest extends TestCase {

	private string $log_file = '';

	private string $previous_log = '';

	/**
	 * Archived files written by the test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'timezone_string', 'Europe/Paris' );
		delete_option( 'lumia_factures_module_invoices' );
		delete_option( Migration::OPTION );

		// Capture the plugin log lines instead of spilling them into the test output.
		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
	}

	public function tear_down(): void {
		foreach ( array_unique( $this->written ) as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
		wp_delete_file( $this->log_file );
		parent::tear_down();
	}

	public function test_refund_of_invoiced_order_issues_credit_note(): void {
		$calls  = [];
		$listen = static function ( IssuedDocument $doc, WC_Order_Refund $refund, WC_Order $refunded ) use ( &$calls ): void {
			$calls[] = [ $doc->number, $refund->get_id(), $refunded->get_id() ];
		};
		add_action( 'lumia_factures_credit_note_issued', $listen, 10, 3 );

		$html    = [];
		$capture = static function ( string $out, WC_Order $order, DocumentData $doc ) use ( &$html ): string {
			if ( $doc->is_credit_note() ) {
				$html[] = $out;
			}
			return $out;
		};
		add_filter( 'lumia_factures_invoice_html', $capture, 10, 3 );

		$order   = $this->invoiced_order();
		$invoice = Register::find( 'invoice', $order->get_id() );
		$next    = Sequences::current( $this->key() ) + 1;
		$refund  = $this->amount_refund( $order, 5.0, 'Damaged parcel' );

		remove_action( 'lumia_factures_credit_note_issued', $listen, 10 );
		remove_filter( 'lumia_factures_invoice_html', $capture, 10 );

		$doc = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $doc );
		$this->track( $doc );

		$this->assertSame( 'issued', $doc->status );
		$this->assertSame( $this->number( $next ), $doc->number );
		$this->assertSame( 'AV-{year}-{number:5}', Module::settings()['credit_notes']['format'] );
		$this->assertSame( $next, $doc->sequence );
		$this->assertSame( $this->key(), $doc->sequence_key );
		$this->assertSame( $refund->get_id(), $doc->order_id );
		$this->assertSame( $order->get_id(), $doc->parent_id );

		$path = Archive::path( $doc );
		$this->assertNotNull( $path );
		$this->assertStringStartsWith( '%PDF', (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertTrue( Archive::verify( $doc ) );

		// Rendered from fresh data: the number and the invoice it credits are printed.
		$this->assertCount( 1, $html );
		$this->assertStringContainsString( (string) $doc->number, $html[0] );
		$this->assertStringContainsString( 'Credit note for invoice ' . $invoice->number, $html[0] );

		$fresh = $this->refund_of( $refund );
		$this->assertSame( $doc->number, $fresh->get_meta( CreditNoteIssuer::NUMBER_META ) );
		$this->assertSame( (string) $doc->issued_at, (string) $fresh->get_meta( CreditNoteIssuer::DATE_META ) );
		$this->assertSame( '', $fresh->get_meta( CreditNoteIssuer::PENDING_META ) );

		$this->assertTrue( $this->has_note( $order, sprintf( 'Credit note %1$s issued for refund #%2$d (invoice %3$s).', $doc->number, $refund->get_id(), $invoice->number ) ) );
		$this->assertSame( [ [ $doc->number, $refund->get_id(), $order->get_id() ] ], $calls );

		$this->assertSame( $doc->id, CreditNoteIssuer::find_for( $fresh )->id );
		$this->assertSame( '', CreditNoteIssuer::refusal( $fresh ) );
	}

	public function test_two_refunds_give_two_consecutive_numbers(): void {
		$order = $this->invoiced_order();
		$next  = Sequences::current( $this->key() ) + 1;

		$first  = $this->amount_refund( $order, 3.0 );
		$second = $this->amount_refund( $order, 4.0 );

		$docs = Register::for_parent( 'credit_note', $order->get_id() );
		$this->assertCount( 2, $docs );
		array_map( [ $this, 'track' ], $docs );

		$this->assertSame( [ $first->get_id(), $second->get_id() ], array_map( static fn( IssuedDocument $doc ): int => $doc->order_id, $docs ) );
		$this->assertSame( [ $this->number( $next ), $this->number( $next + 1 ) ], array_map( static fn( IssuedDocument $doc ): ?string => $doc->number, $docs ) );
		$this->assertSame( $next + 1, Sequences::current( $this->key() ) );
	}

	public function test_concurrent_refunds_on_two_orders_never_share_a_number(): void {
		$order_a = $this->invoiced_order();
		$order_b = $this->invoiced_order();

		$this->without_refund_hook(
			function () use ( $order_a, $order_b, &$refund_a, &$refund_b ): void {
				$refund_a = $this->amount_refund( $order_a, 2.0 );
				$refund_b = $this->amount_refund( $order_b, 3.0 );
			}
		);

		$next   = Sequences::current( $this->key() ) + 1;
		$armed  = true;
		$nested = null;

		// Another request numbers refund B after refund A took its counter value, before A records it.
		$interleave = static function ( string $query ) use ( &$armed, &$nested, $refund_b ): string {
			if ( $armed && str_contains( $query, "SET status = 'issued'" ) ) {
				$armed  = false;
				$nested = CreditNoteIssuer::issue( wc_get_order( $refund_b->get_id() ) );
			}
			return $query;
		};
		add_filter( 'query', $interleave );

		$doc_a = CreditNoteIssuer::issue( $this->refund_of( $refund_a ) );

		remove_filter( 'query', $interleave );

		$this->assertNotNull( $doc_a );
		$this->assertNotNull( $nested );
		$this->track( $doc_a );
		$this->track( $nested );

		$this->assertSame( $this->number( $next ), $doc_a->number );
		$this->assertSame( $this->number( $next + 1 ), $nested->number );
		$this->assertSame( $order_a->get_id(), $doc_a->parent_id );
		$this->assertSame( $order_b->get_id(), $nested->parent_id );
		$this->assertSame( $next + 1, Sequences::current( $this->key() ) );
	}

	public function test_issue_is_idempotent(): void {
		$order  = $this->invoiced_order();
		$before = Sequences::current( $this->key() );

		$fired = 0;
		$count = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'lumia_factures_credit_note_issued', $count );

		// The refund e-mail goes out before `woocommerce_order_refunded` and issues the credit note.
		$early       = null;
		$email_issue = static function ( $order_id, $refund_id ) use ( &$early ): void {
			$early = CreditNoteIssuer::issue( wc_get_order( $refund_id ) );
		};
		add_action( 'woocommerce_order_partially_refunded', $email_issue, 10, 2 );

		$refund = $this->amount_refund( $order, 4.0 );

		remove_action( 'woocommerce_order_partially_refunded', $email_issue, 10 );

		// Then the hook (already run by wc_create_refund(), run again), then the invoice listener.
		CreditNoteIssuer::on_refunded( $order->get_id(), $refund->get_id() );
		CreditNoteIssuer::on_invoice_issued( Register::find( 'invoice', $order->get_id() ), wc_get_order( $order->get_id() ) );
		$again = CreditNoteIssuer::issue( $this->refund_of( $refund ) );

		remove_action( 'lumia_factures_credit_note_issued', $count );

		$this->assertNotNull( $early );
		$this->track( $early );
		$this->assertSame( $early->id, $again->id );
		$this->assertSame( $early->number, $again->number );
		$this->assertCount( 1, Register::for_parent( 'credit_note', $order->get_id() ) );
		$this->assertSame( $before + 1, Sequences::current( $this->key() ) );
		$this->assertSame( 1, $fired );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'issued for refund' ) );
	}

	public function test_archived_pdf_never_changes(): void {
		$order  = $this->invoiced_order();
		$refund = $this->amount_refund( $order, 5.0, 'First reason' );

		$doc = Register::find( 'credit_note', $refund->get_id() );
		$this->track( $doc );
		$bytes = (string) file_get_contents( (string) Archive::path( $doc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$fresh = $this->refund_of( $refund );
		$fresh->set_reason( 'Changed reason' );
		$fresh->save();
		$parent = wc_get_order( $order->get_id() );
		$parent->set_billing_last_name( 'Changed' );
		$parent->save();

		$again = CreditNoteIssuer::issue( $this->refund_of( $refund ) );
		CreditNoteIssuer::issue_pending( wc_get_order( $order->get_id() ) );
		CreditNoteIssuer::sweep();
		$this->assertFalse( CreditNoteIssuer::restore( $doc ) );

		$after = Register::find( 'credit_note', $refund->get_id() );
		$this->assertSame( $doc->number, $again->number );
		$this->assertSame( $doc->file, $after->file );
		$this->assertSame( $doc->checksum, $after->checksum );
		$this->assertSame( $doc->issued_at, $after->issued_at );
		$this->assertSame( $bytes, file_get_contents( (string) Archive::path( $after ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_refused_while_migration_blocks_issuing_sets_pending_and_sweep_issues_after(): void {
		$order = $this->invoiced_order();
		$key   = $this->key();
		$next  = Sequences::current( $key ) + 1;

		update_option( Migration::OPTION, [ 'status' => 'pending' ] );

		$refund = $this->amount_refund( $order, 5.0 );
		CreditNoteIssuer::issue( $this->refund_of( $refund ) );

		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );
		$this->assertSame( 'import', $this->refund_of( $refund )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertSame( 'import', CreditNoteIssuer::refusal( $this->refund_of( $refund ) ) );
		$this->assertContains( $refund->get_id(), CreditNoteIssuer::pending_refund_ids( 50 ) );
		// Written and logged once per reason.
		$this->assertSame( 1, substr_count( (string) file_get_contents( $this->log_file ), 'refund #' . $refund->get_id() . ' (order ' . $order->get_id() . ') not issued: import' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local log file.

		// The sweep waits while the import blocks issuing.
		CreditNoteIssuer::sweep();
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );
		$this->assertSame( $next - 1, Sequences::current( $key ) );

		update_option( Migration::OPTION, [ 'status' => 'done' ] );
		CreditNoteIssuer::sweep();

		$doc = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $doc );
		$this->track( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->assertSame( $this->number( $next ), $doc->number );
		$this->assertSame( '', $this->refund_of( $refund )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertSame( $doc->number, $this->refund_of( $refund )->get_meta( CreditNoteIssuer::NUMBER_META ) );
		$this->assertNotContains( $refund->get_id(), CreditNoteIssuer::pending_refund_ids( 50 ) );
	}

	public function test_refused_for_legacy_conflict_order(): void {
		// The import ended with conflicts: issuing is open, but this order kept its legacy number.
		update_option( Migration::OPTION, [ 'status' => 'done_with_conflicts' ] );

		$order = $this->order();
		$order->update_meta_data( LegacySource::META_NUMBER, 'F2024-0157' );
		$order->save();
		$order->update_status( 'processing' );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );

		$refund = $this->amount_refund( $order, 5.0 );

		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );
		$this->assertSame( 'conflict', $this->refund_of( $refund )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertSame( 'conflict', CreditNoteIssuer::refusal( $this->refund_of( $refund ) ) );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
	}

	public function test_refused_without_invoice_when_parent_not_issuable(): void {
		$key    = $this->key();
		$before = Sequences::current( $key );

		// Pending payment: not a trigger status, so the order cannot get its invoice.
		$order  = $this->order();
		$refund = $this->amount_refund( $order, 5.0 );

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );
		$this->assertSame( 'no_invoice', $this->refund_of( $refund )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertSame( 'no_invoice', CreditNoteIssuer::refusal( $this->refund_of( $refund ) ) );
		$this->assertSame( $before, Sequences::current( $key ) );
	}

	public function test_order_created_before_since_is_flagged_not_invoiced(): void {
		$invoice_key = Sequences::key( 'invoice', time(), Module::settings()['numbering'] );
		$before      = Sequences::current( $invoice_key );

		// An order of 2025, paid but never invoiced (the shop ran without the plugin then).
		$order = $this->order();
		$order->set_date_created( (int) gmmktime( 10, 0, 0, 6, 1, 2025 ) );
		$order->save();
		$this->without_invoice_hook( static fn() => $order->update_status( 'processing' ) );

		$refund = $this->amount_refund( wc_get_order( $order->get_id() ), 5.0 );

		// No invoice issued by itself for an order older than the credit notes: flagged instead.
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );
		$this->assertSame( 'no_invoice', $this->refund_of( $refund )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertSame( 'no_invoice', CreditNoteIssuer::refusal( $this->refund_of( $refund ) ) );
		$this->assertSame( $before, Sequences::current( $invoice_key ) );
	}

	public function test_refund_issues_missing_invoice_first_when_order_created_since(): void {
		// Paid after the credit notes started, but its invoice was never issued (hook missed).
		$order = $this->order();
		$this->without_invoice_hook( static fn() => $order->update_status( 'processing' ) );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( '', CreditNoteIssuer::refusal( $this->refund_without_hook( $order, 5.0 ) ) );

		$refund = $this->amount_refund( $order, 4.0 );

		$invoice = Register::find( 'invoice', $order->get_id() );
		$doc     = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $invoice );
		$this->assertNotNull( $doc );
		$this->track( $invoice );
		array_map( [ $this, 'track' ], Register::for_parent( 'credit_note', $order->get_id() ) );
		$this->assertSame( 'issued', $invoice->status );
		$this->assertSame( 'issued', $doc->status );
		$this->assertLessThan( $doc->id, $invoice->id );
	}

	public function test_partial_refund_before_invoice_gets_credit_note_when_invoice_issued(): void {
		$order  = $this->order();
		$refund = $this->amount_refund( $order, 3.0 );
		$this->assertSame( 'no_invoice', $this->refund_of( $refund )->get_meta( CreditNoteIssuer::PENDING_META ) );

		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'processing' );

		$invoice = Register::find( 'invoice', $order->get_id() );
		$doc     = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $invoice );
		$this->assertNotNull( $doc );
		$this->track( $invoice );
		$this->track( $doc );

		$this->assertSame( 'issued', $doc->status );
		$this->assertLessThan( $doc->id, $invoice->id );
		$this->assertSame( '', $this->refund_of( $refund )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertTrue( Archive::verify( $doc ) );
		$this->assertTrue( $this->has_note( $order, sprintf( 'Credit note %1$s issued for refund #%2$d (invoice %3$s).', $doc->number, $refund->get_id(), $invoice->number ) ) );
	}

	public function test_full_refund_of_never_invoiced_order_with_refunded_trigger(): void {
		update_option( 'lumia_factures_module_invoices', [ 'general' => [ 'trigger_statuses' => [ 'refunded' ] ] ] );
		$invoice_key = Sequences::key( 'invoice', time(), Module::settings()['numbering'] );
		$invoices    = Sequences::current( $invoice_key );
		$credits     = Sequences::current( $this->key() );

		$html    = [];
		$capture = static function ( string $out, WC_Order $order, DocumentData $doc ) use ( &$html ): string {
			if ( $doc->is_credit_note() ) {
				$html[] = $out;
			}
			return $out;
		};
		add_filter( 'lumia_factures_invoice_html', $capture, 10, 3 );

		$order = $this->order();
		$order->update_status( 'on-hold' );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );

		// A full refund moves the order to `refunded` inside wc_create_refund(), before
		// `woocommerce_order_refunded`: the invoice is issued, and its listener issues the credit note.
		$refund = $this->full_refund( wc_get_order( $order->get_id() ) );

		remove_filter( 'lumia_factures_invoice_html', $capture, 10 );

		$this->assertSame( 'refunded', wc_get_order( $order->get_id() )->get_status() );

		$invoice = Register::find( 'invoice', $order->get_id() );
		$doc     = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $invoice );
		$this->assertNotNull( $doc );
		$this->track( $invoice );
		$this->track( $doc );

		$this->assertSame( 'issued', $invoice->status );
		$this->assertSame( 'issued', $doc->status );
		$this->assertLessThan( $doc->id, $invoice->id );
		$this->assertLessThanOrEqual( $doc->issued_at, $invoice->issued_at );
		$this->assertCount( 1, Register::for_parent( 'credit_note', $order->get_id() ) );
		$this->assertSame( $invoices + 1, Sequences::current( $invoice_key ) );
		$this->assertSame( $credits + 1, Sequences::current( $this->key() ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'issued for refund' ) );
		$this->assertSame( '', $this->refund_of( $refund )->get_meta( CreditNoteIssuer::PENDING_META ) );

		// One render, citing the invoice issued just before.
		$this->assertCount( 1, $html );
		$this->assertStringContainsString( 'Credit note for invoice ' . $invoice->number, $html[0] );
	}

	public function test_gateway_refund_failure_leaves_no_credit_note(): void {
		$gateway = new class() extends WC_Payment_Gateway {
			/**
			 * A gateway that supports refunds and refuses every one.
			 */
			public function __construct() {
				$this->id       = 'lf_failing';
				$this->enabled  = 'yes';
				$this->supports = [ 'products', 'refunds' ];
			}

			/**
			 * Refuses the refund.
			 *
			 * @param int        $order_id Order id.
			 * @param float|null $amount   Amount.
			 * @param string     $reason   Reason.
			 * @return WP_Error
			 */
			public function process_refund( $order_id, $amount = null, $reason = '' ) {
				return new WP_Error( 'lf_declined', 'Declined by the test gateway.' );
			}
		};
		WC()->payment_gateways()->payment_gateways['lf_failing'] = $gateway;

		$order = $this->invoiced_order();
		$order->set_payment_method( 'lf_failing' );
		$order->save();
		$before = Sequences::current( $this->key() );

		$fired = 0;
		$count = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'woocommerce_order_refunded', $count );

		$result = wc_create_refund(
			[
				'order_id'       => $order->get_id(),
				'amount'         => 4.0,
				'reason'         => 'Gateway refuses',
				'refund_payment' => true,
			]
		);

		remove_action( 'woocommerce_order_refunded', $count );
		unset( WC()->payment_gateways()->payment_gateways['lf_failing'] );

		$this->assertWPError( $result );
		$this->assertSame( 0, $fired );
		$this->assertSame( [], $this->refund_ids( $order ) );
		$this->assertSame( [], Register::for_parent( 'credit_note', $order->get_id() ) );
		$this->assertSame( $before, Sequences::current( $this->key() ) );
	}

	public function test_zero_amount_refund_issues_nothing_and_is_not_pending(): void {
		$order  = $this->invoiced_order();
		$before = Sequences::current( $this->key() );

		$refund = OrderFactory::refund( $order, [], 0.0, 'Nothing refunded' );
		CreditNoteIssuer::issue( $this->refund_of( $refund ) );
		CreditNoteIssuer::issue_pending( wc_get_order( $order->get_id() ) );

		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );
		$this->assertSame( '', $this->refund_of( $refund )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertSame( 'zero_amount', CreditNoteIssuer::refusal( $this->refund_of( $refund ) ) );
		$this->assertSame( $before, Sequences::current( $this->key() ) );
		$this->assertNotContains( $refund->get_id(), CreditNoteIssuer::pending_refund_ids( 50 ) );
		$this->assertNotContains( $refund->get_id(), CreditNoteIssuer::sweep_ids( 50 ) );
	}

	public function test_filter_can_veto_but_not_bypass_migration_gate(): void {
		$order = $this->invoiced_order();

		$seen = [];
		$veto = static function ( bool $can, WC_Order_Refund $refund, WC_Order $refunded ) use ( &$seen ): bool {
			$seen[] = [ $can, $refund->get_id(), $refunded->get_id() ];
			return false;
		};
		add_filter( 'lumia_factures_can_issue_credit_note', $veto, 10, 3 );
		$vetoed = $this->amount_refund( $order, 3.0 );
		remove_filter( 'lumia_factures_can_issue_credit_note', $veto, 10 );

		$this->assertNull( Register::find( 'credit_note', $vetoed->get_id() ) );
		$this->assertSame( 'vetoed', $this->refund_of( $vetoed )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertSame( [ true, $vetoed->get_id(), $order->get_id() ], $seen[0] );

		// While the import blocks issuing, a filter answering true changes nothing.
		update_option( Migration::OPTION, [ 'status' => 'pending' ] );
		add_filter( 'lumia_factures_can_issue_credit_note', '__return_true', 99 );

		$blocked = $this->amount_refund( $order, 2.0 );
		$this->assertNull( CreditNoteIssuer::issue( $this->refund_of( $vetoed ) ) );

		remove_filter( 'lumia_factures_can_issue_credit_note', '__return_true', 99 );

		$this->assertNull( Register::find( 'credit_note', $blocked->get_id() ) );
		$this->assertNull( Register::find( 'credit_note', $vetoed->get_id() ) );
		$this->assertSame( 'import', $this->refund_of( $blocked )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertSame( 'import', $this->refund_of( $vetoed )->get_meta( CreditNoteIssuer::PENDING_META ) );
	}

	public function test_numbering_skips_numbers_already_issued_and_never_reuses_after_release(): void {
		$order = $this->invoiced_order();
		$key   = $this->key();
		$next  = Sequences::current( $key ) + 1;

		// Another credit note already prints the number the next value gives.
		$other = (int) Register::reserve( 'credit_note', 987650, 987651 );
		$this->assertTrue( Register::assign( $other, $this->number( $next ), $key, $next, time() ) );

		$first = $this->amount_refund( $order, 2.0 );
		$doc   = Register::find( 'credit_note', $first->get_id() );
		$this->track( $doc );
		$this->assertSame( $this->number( $next + 1 ), $doc->number );
		$this->assertFalse( $this->has_note_containing( $order, 'skipped' ) );

		// A record failure burns the value with a note and releases the row.
		$break = static function ( string $query ): string {
			return str_contains( $query, "SET status = 'issued'" ) ? 'SELECT 0 FROM DUAL WHERE 1 = 0' : $query;
		};
		add_filter( 'query', $break );
		$second = $this->amount_refund( $order, 2.0 );
		remove_filter( 'query', $break );

		$this->assertNull( Register::find( 'credit_note', $second->get_id() ) );
		$this->assertSame( $next + 2, Sequences::current( $key ) );
		$this->assertTrue(
			$this->has_note(
				$order,
				sprintf( 'Credit note number %1$s (sequence %2$d) could not be recorded; this sequence value is skipped.', $this->number( $next + 2 ), $next + 2 )
			)
		);

		// The next call takes the next value: the burned one is never reused.
		$again = CreditNoteIssuer::issue( $this->refund_of( $second ) );
		$this->assertNotNull( $again );
		$this->track( $again );
		$this->assertSame( $this->number( $next + 3 ), $again->number );
		$this->assertSame( $next + 3, Sequences::current( $key ) );
	}

	public function test_reused_refund_id_with_other_parent_is_refused_never_returns_stale_row(): void {
		$order_a = $this->invoiced_order();
		$order_b = $this->invoiced_order();
		$refund  = $this->refund_without_hook( $order_b, 3.0 );

		// The register still holds the credit note of a deleted refund of order A that had this id.
		$stale = (int) Register::reserve( 'credit_note', $refund->get_id(), $order_a->get_id() );
		$this->assertTrue( Register::assign( $stale, 'AV-1999-00007', 'credit_note_1999', 7, (int) gmmktime( 12, 0, 0, 1, 15, 1999 ) ) );
		$before = Sequences::current( $this->key() );

		$this->assertNull( CreditNoteIssuer::find_for( $refund ) );
		$this->assertNull( CreditNoteIssuer::issue( $this->refund_of( $refund ) ) );
		CreditNoteIssuer::on_refunded( $order_b->get_id(), $refund->get_id() );
		CreditNoteIssuer::issue_pending( wc_get_order( $order_b->get_id() ) );

		$this->assertSame( 'reused_id', $this->refund_of( $refund )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertSame( 'reused_id', CreditNoteIssuer::refusal( $this->refund_of( $refund ) ) );
		$this->assertSame( '', $this->refund_of( $refund )->get_meta( CreditNoteIssuer::NUMBER_META ) );
		$this->assertSame( $before, Sequences::current( $this->key() ) );
		$this->assertFalse( $this->has_note_containing( $order_b, 'issued for refund' ) );

		// The stale row is untouched.
		$row = Register::find( 'credit_note', $refund->get_id() );
		$this->assertSame( $stale, $row->id );
		$this->assertSame( $order_a->get_id(), $row->parent_id );
		$this->assertSame( 'AV-1999-00007', $row->number );

		// And it is never the refund's to rebuild.
		$this->assertFalse( CreditNoteIssuer::restore( $row ) );
	}

	public function test_refund_id_recorded_as_deleted_never_gets_the_old_credit_note(): void {
		$order  = $this->invoiced_order();
		$refund = $this->amount_refund( $order, 3.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		$this->track( $doc );

		// A refund carrying another number is not the row's.
		$other = $this->refund_of( $refund );
		$other->update_meta_data( CreditNoteIssuer::NUMBER_META, 'AV-OTHER' );
		$this->assertNull( CreditNoteIssuer::find_for( $other ) );

		// A refund of the same order given the id of a deleted credited refund (MySQL reused the
		// freed AUTO_INCREMENT value): it carries no number, and the order recorded the deletion.
		$reused = $this->refund_of( $refund );
		$reused->delete_meta_data( CreditNoteIssuer::NUMBER_META );
		$reused->delete_meta_data( CreditNoteIssuer::DATE_META );
		$reused->save();
		$this->assertSame( $doc->id, CreditNoteIssuer::find_for( $this->refund_of( $refund ) )->id );

		$parent = wc_get_order( $order->get_id() );
		$parent->update_meta_data( CreditNoteIssuer::DELETED_META, [ $refund->get_id() ] );
		$parent->save();

		$before = Sequences::current( $this->key() );
		$this->assertNull( CreditNoteIssuer::find_for( $this->refund_of( $refund ) ) );
		$this->assertNull( CreditNoteIssuer::issue( $this->refund_of( $refund ) ) );
		$this->assertSame( 'reused_id', $this->refund_of( $refund )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertSame( $before, Sequences::current( $this->key() ) );
		$this->assertSame( $doc->number, Register::find( 'credit_note', $refund->get_id() )->number );
	}

	public function test_refund_deletion_blocked_while_credit_note_exists(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$order  = $this->invoiced_order();
		$refund = $this->amount_refund( $order, 3.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		$this->track( $doc );

		$this->assertFalse( $this->refund_of( $refund )->delete( true ) );
		$this->assertInstanceOf( WC_Order_Refund::class, wc_get_order( $refund->get_id() ) );

		// The order screen fires its own hook after delete(), whatever delete() answered.
		do_action( 'woocommerce_refund_deleted', $refund->get_id(), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook, fired as the order screen does.
		$this->assertFalse( $this->has_note_containing( $order, 'was deleted' ) );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( CreditNoteIssuer::DELETED_META ) );

		$kept = Register::find( 'credit_note', $refund->get_id() );
		$this->assertSame( $doc->number, $kept->number );
		$this->assertTrue( Archive::verify( $kept ) );

		// The manager is told why, once, on the next admin page.
		ob_start();
		CreditNoteIssuer::render_deletion_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( sprintf( 'This refund carries credit note %s and cannot be deleted.', $doc->number ), $notice );

		ob_start();
		CreditNoteIssuer::render_deletion_notice();
		$this->assertSame( '', ob_get_clean() );

		// A refund without a credit note is deleted as usual.
		$plain = OrderFactory::refund( wc_get_order( $order->get_id() ), [], 0.0 );
		$this->assertTrue( $this->refund_of( $plain )->delete( true ) );
	}

	public function test_deletion_allowed_by_filter_keeps_row_and_file_and_adds_note(): void {
		$order  = $this->invoiced_order();
		$refund = $this->amount_refund( $order, 3.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		$this->track( $doc );

		add_filter( 'lumia_factures_allow_credited_refund_deletion', '__return_true' );
		$deleted = $this->refund_of( $refund )->delete( true );
		remove_filter( 'lumia_factures_allow_credited_refund_deletion', '__return_true' );

		// The order screen fires its own hook too: one note.
		do_action( 'woocommerce_refund_deleted', $refund->get_id(), $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook, fired as the order screen does.

		$this->assertTrue( $deleted );
		$this->assertFalse( wc_get_order( $refund->get_id() ) );

		$kept = Register::find( 'credit_note', $refund->get_id() );
		$this->assertSame( $doc->id, $kept->id );
		$this->assertSame( $doc->number, $kept->number );
		$this->assertSame( $doc->file, $kept->file );
		$this->assertSame( $doc->checksum, $kept->checksum );
		$this->assertTrue( Archive::verify( $kept ) );

		$text = sprintf( 'Refund #%1$d was deleted; credit note %2$s stays issued and the order no longer matches it.', $refund->get_id(), $doc->number );
		$this->assertSame( 1, $this->count_notes_containing( $order, $text ) );
		$this->assertSame( [ $refund->get_id() ], wc_get_order( $order->get_id() )->get_meta( CreditNoteIssuer::DELETED_META ) );
	}

	public function test_rest_api_refund_issues_credit_note(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$order = $this->invoiced_order();
		$next  = Sequences::current( $this->key() ) + 1;

		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order->get_id() . '/refunds' );
		$request->set_param( 'amount', '4.00' );
		$request->set_param( 'reason', 'Refunded through the API' );
		$request->set_param( 'api_refund', false );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$refund_id = (int) $response->get_data()['id'];

		$doc = Register::find( 'credit_note', $refund_id );
		$this->assertNotNull( $doc );
		$this->track( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->assertSame( $this->number( $next ), $doc->number );
		$this->assertSame( $order->get_id(), $doc->parent_id );
		$this->assertTrue( Archive::verify( $doc ) );
	}

	public function test_restore_rebuilds_missing_file_only_and_refuses_when_refund_deleted_or_reused(): void {
		$order  = $this->invoiced_order();
		$refund = $this->amount_refund( $order, 3.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		$this->track( $doc );

		// Intact: nothing to do.
		$this->assertFalse( CreditNoteIssuer::restore( $doc ) );
		$this->assertSame( $doc->file, Register::find( 'credit_note', $refund->get_id() )->file );

		wp_delete_file( (string) Archive::path( $doc ) );
		$this->assertTrue( CreditNoteIssuer::restore( $doc ) );

		$restored = Register::find( 'credit_note', $refund->get_id() );
		$this->track( $restored );
		$this->assertSame( $doc->number, $restored->number );
		$this->assertSame( $doc->issued_at, $restored->issued_at );
		$this->assertNotSame( $doc->file, $restored->file );
		$this->assertTrue( Archive::verify( $restored ) );
		$this->assertTrue( $this->has_note( $order, sprintf( 'Credit note PDF %s was missing or damaged and has been rebuilt from the current order data.', $doc->number ) ) );

		// A refund that now belongs to another order is not the one the credit note was issued for.
		$path = (string) Archive::path( $restored );
		file_put_contents( $path, '%PDF damaged' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$other = $this->invoiced_order();
		$moved = $this->refund_of( $refund );
		$moved->set_parent_id( $other->get_id() );
		$moved->save();
		$this->assertFalse( CreditNoteIssuer::restore( $restored ) );
		$moved->set_parent_id( $order->get_id() );
		$moved->save();

		// A deleted refund cannot be rendered again.
		add_filter( 'lumia_factures_allow_credited_refund_deletion', '__return_true' );
		$this->assertTrue( $this->refund_of( $refund )->delete( true ) );
		remove_filter( 'lumia_factures_allow_credited_refund_deletion', '__return_true' );
		$this->assertFalse( CreditNoteIssuer::restore( $restored ) );

		// The damaged file is never replaced in place.
		$this->assertSame( '%PDF damaged', file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( $restored->file, Register::find( 'credit_note', $refund->get_id() )->file );
	}

	public function test_sweep_takes_refunds_since_and_pending_ones_only(): void {
		$order = $this->invoiced_order();
		$old   = $this->refund_without_hook( $order, 1.0 );
		$new   = $this->refund_without_hook( $order, 2.0 );

		// The first refund predates the credit notes: left to an explicit request.
		$backdated = $this->refund_of( $old );
		$backdated->set_date_created( time() - DAY_IN_SECONDS );
		$backdated->save();
		update_option( CreditNoteIssuer::SINCE_OPTION, time() - HOUR_IN_SECONDS );

		$ids = CreditNoteIssuer::sweep_ids( 50 );
		$this->assertContains( $new->get_id(), $ids );
		$this->assertNotContains( $old->get_id(), $ids );

		CreditNoteIssuer::sweep();
		$issued = Register::find( 'credit_note', $new->get_id() );
		$this->assertNotNull( $issued );
		$this->track( $issued );
		$this->assertNull( Register::find( 'credit_note', $old->get_id() ) );
		$this->assertNotContains( $new->get_id(), CreditNoteIssuer::sweep_ids( 50 ) );

		// A pending refund is swept whatever its date.
		$pending = $this->refund_of( $old );
		$pending->update_meta_data( CreditNoteIssuer::PENDING_META, 'import' );
		$pending->save();
		$this->assertContains( $old->get_id(), CreditNoteIssuer::sweep_ids( 50 ) );

		CreditNoteIssuer::sweep();
		$late = Register::find( 'credit_note', $old->get_id() );
		$this->assertNotNull( $late );
		$this->track( $late );
		$this->assertSame( 'issued', $late->status );
		$this->assertSame( '', $this->refund_of( $old )->get_meta( CreditNoteIssuer::PENDING_META ) );
	}

	public function test_sweep_is_scheduled_daily_and_unscheduled_on_deactivation(): void {
		$this->assertSame( 10, has_action( CreditNoteIssuer::SWEEP_HOOK, [ CreditNoteIssuer::class, 'sweep' ] ) );

		CreditNoteIssuer::schedule_sweep();
		CreditNoteIssuer::schedule_sweep();
		$this->assertNotFalse( wp_next_scheduled( CreditNoteIssuer::SWEEP_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( CreditNoteIssuer::SWEEP_HOOK ) );

		Deactivator::deactivate();
		$this->assertFalse( wp_next_scheduled( CreditNoteIssuer::SWEEP_HOOK ) );
	}

	public function test_since_is_recorded_once(): void {
		delete_option( CreditNoteIssuer::SINCE_OPTION );
		$start = time();

		CreditNoteIssuer::ensure_since();
		$since = (int) get_option( CreditNoteIssuer::SINCE_OPTION );
		$this->assertGreaterThanOrEqual( $start, $since );

		// Never moved once recorded (Module::init() and on_activate() call it on every boot).
		update_option( CreditNoteIssuer::SINCE_OPTION, 1700000000 );
		CreditNoteIssuer::ensure_since();
		$this->assertSame( 1700000000, CreditNoteIssuer::since() );

		// An unreadable value counts as now: nothing older is taken by itself.
		update_option( CreditNoteIssuer::SINCE_OPTION, 'garbage' );
		$this->assertGreaterThanOrEqual( $start, CreditNoteIssuer::since() );
	}

	/**
	 * A saved pending order with one line.
	 */
	private function order(): WC_Order {
		return OrderFactory::order( [ [ OrderFactory::simple_product( 10.0, '', 'Croquettes' ), 2 ] ] );
	}

	/**
	 * An order moved to a trigger status, with its issued invoice.
	 */
	private function invoiced_order(): WC_Order {
		$order = $this->order();
		$order->update_status( 'processing' );

		$invoice = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $invoice );
		$this->assertSame( 'issued', $invoice->status );
		$this->track( $invoice );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * A refund of an amount, no item (no payment refunded, no stock restored); its credit note,
	 * if any, is remembered for tear_down.
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount.
	 * @param string   $reason Reason.
	 */
	private function amount_refund( WC_Order $order, float $amount, string $reason = '' ): WC_Order_Refund {
		$refund = OrderFactory::refund( wc_get_order( $order->get_id() ), [], $amount, $reason );
		$this->track( Register::find( 'credit_note', $refund->get_id() ) );

		return $refund;
	}

	/**
	 * A refund of every product line of the order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function full_refund( WC_Order $order ): WC_Order_Refund {
		$lines = [];
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$lines[ $item->get_id() ] = OrderFactory::refund_line( $item );
			}
		}

		$refund = OrderFactory::refund( $order, $lines, null );
		$this->track( Register::find( 'credit_note', $refund->get_id() ) );

		return $refund;
	}

	/**
	 * A refund of an amount created without the `woocommerce_order_refunded` listener: no credit note.
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount.
	 */
	private function refund_without_hook( WC_Order $order, float $amount ): WC_Order_Refund {
		$refund = null;
		$this->without_refund_hook(
			function () use ( $order, $amount, &$refund ): void {
				$refund = OrderFactory::refund( wc_get_order( $order->get_id() ), [], $amount );
			}
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );

		return $refund;
	}

	/**
	 * Runs a callback with the credit note listener of `woocommerce_order_refunded` removed.
	 *
	 * @param callable $run Callback.
	 */
	private function without_refund_hook( callable $run ): void {
		remove_action( 'woocommerce_order_refunded', [ CreditNoteIssuer::class, 'on_refunded' ], 10 );
		try {
			$run();
		} finally {
			add_action( 'woocommerce_order_refunded', [ CreditNoteIssuer::class, 'on_refunded' ], 10, 2 );
		}
	}

	/**
	 * Runs a callback with the invoice listener of `woocommerce_order_status_changed` removed.
	 *
	 * @param callable $run Callback.
	 */
	private function without_invoice_hook( callable $run ): void {
		remove_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10 );
		try {
			$run();
		} finally {
			add_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10, 4 );
		}
	}

	/**
	 * The refund as stored now.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 */
	private function refund_of( WC_Order_Refund $refund ): WC_Order_Refund {
		$fresh = wc_get_order( $refund->get_id() );
		$this->assertInstanceOf( WC_Order_Refund::class, $fresh );

		return $fresh;
	}

	/**
	 * Ids of the order's refunds, read from storage.
	 *
	 * @param WC_Order $order Order.
	 * @return int[]
	 */
	private function refund_ids( WC_Order $order ): array {
		return array_map(
			'intval',
			wc_get_orders(
				[
					'type'   => 'shop_order_refund',
					'parent' => $order->get_id(),
					'limit'  => -1,
					'return' => 'ids',
				]
			)
		);
	}

	/**
	 * Current counter key of credit notes.
	 */
	private function key(): string {
		return Sequences::key( 'credit_note', time(), Module::settings()['credit_notes'] );
	}

	/**
	 * The credit note number the default format gives a sequence value this year.
	 *
	 * @param int $sequence Sequence value.
	 */
	private function number( int $sequence ): string {
		return sprintf( 'AV-%s-%05d', wp_date( 'Y' ), $sequence );
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

	/**
	 * Whether the order has a note with exactly this text.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $text  Note text.
	 */
	private function has_note( WC_Order $order, string $text ): bool {
		foreach ( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) as $note ) {
			if ( $text === $note->content ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the order has a note containing this text.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $text  Part of the note.
	 */
	private function has_note_containing( WC_Order $order, string $text ): bool {
		return $this->count_notes_containing( $order, $text ) > 0;
	}

	/**
	 * How many notes of the order contain this text.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $text  Part of the note.
	 */
	private function count_notes_containing( WC_Order $order, string $text ): int {
		$count = 0;
		foreach ( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) as $note ) {
			if ( str_contains( $note->content, $text ) ) {
				++$count;
			}
		}
		return $count;
	}
}
