<?php
/**
 * Invoice and credit note attachments on WooCommerce emails.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use RuntimeException;
use WC_Email_Customer_Refunded_Order;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;

/**
 * Attachments: the archived PDF leaves with the configured emails, and never blocks them.
 */
class EmailAttachmentsTest extends TestCase {

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

		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
	}

	public function tear_down(): void {
		// The mailer keeps one refund email object for the whole process: leave it as WooCommerce builds it.
		$email         = $this->refund_email_object();
		$email->id     = 'customer_refunded_order';
		$email->refund = false;

		foreach ( $this->written as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
		wp_delete_file( $this->log_file );
		parent::tear_down();
	}

	public function test_configured_email_gets_archived_pdf(): void {
		$order = $this->order();
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );

		$result = $this->email( 'customer_completed_order', wc_get_order( $order->get_id() ), [ '/tmp/other.pdf' ] );

		$this->assertSame( [ '/tmp/other.pdf', Archive::path( $doc ) ], $result );
	}

	public function test_unconfigured_email_gets_nothing(): void {
		$order = $this->order();
		$order->update_status( 'processing' );
		$this->track( Register::find( 'invoice', $order->get_id() ) );

		$this->assertSame( [], $this->email( 'new_order', wc_get_order( $order->get_id() ) ) );
	}

	public function test_email_before_status_hook_issues_once(): void {
		$key    = Sequences::key( 'invoice', time(), Module::settings()['numbering'] );
		$before = Sequences::current( $key );
		$order  = $this->order();

		// Reach a trigger status without the status hook: the email is the first caller.
		remove_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10 );
		$order->set_status( 'processing' );
		$order->save();
		add_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10, 4 );

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );

		$result = $this->email( 'customer_processing_order', wc_get_order( $order->get_id() ) );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $doc );
		$this->track( $doc );
		$this->assertSame( [ Archive::path( $doc ) ], $result );
		$this->assertSame( $before + 1, Sequences::current( $key ) );

		// The status path now finds the existing invoice.
		$again = Issuer::issue( wc_get_order( $order->get_id() ) );
		$this->assertSame( $doc->number, $again->number );
		$this->assertSame( $before + 1, Sequences::current( $key ) );
	}

	public function test_never_built_archive_is_built_before_attaching(): void {
		$fail = static function (): array {
			throw new RuntimeException( 'Render broken.' );
		};
		add_filter( 'lumia_factures_dompdf_options', $fail );
		$order = $this->order();
		$order->update_status( 'processing' );
		remove_filter( 'lumia_factures_dompdf_options', $fail );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNull( $doc->file );

		$result = $this->email( 'customer_completed_order', wc_get_order( $order->get_id() ) );

		$built = Register::find( 'invoice', $order->get_id() );
		$this->track( $built );
		$this->assertSame( $doc->number, $built->number );
		$this->assertTrue( Archive::verify( $built ) );
		$this->assertSame( [ Archive::path( $built ) ], $result );
	}

	public function test_missing_recorded_archive_is_neither_rebuilt_nor_attached(): void {
		$order = $this->order();
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		wp_delete_file( (string) Archive::path( $doc ) );

		$result = $this->email( 'customer_completed_order', wc_get_order( $order->get_id() ), [ '/tmp/other.pdf' ] );

		$this->assertSame( [ '/tmp/other.pdf' ], $result );
		$after = Register::find( 'invoice', $order->get_id() );
		$this->assertSame( $doc->file, $after->file );
		$this->assertSame( $doc->checksum, $after->checksum );
		$this->assertStringContainsString( 'is missing or damaged', (string) file_get_contents( $this->log_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_damaged_archive_is_neither_rebuilt_nor_attached(): void {
		$order = $this->order();
		$order->update_status( 'processing' );
		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		file_put_contents( (string) Archive::path( $doc ), 'damaged' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test corrupts the archive.

		$result = $this->email( 'customer_completed_order', wc_get_order( $order->get_id() ), [ '/tmp/other.pdf' ] );

		$this->assertSame( [ '/tmp/other.pdf' ], $result );
		$after = Register::find( 'invoice', $order->get_id() );
		$this->assertSame( $doc->file, $after->file );
		$this->assertSame( 'damaged', file_get_contents( (string) Archive::path( $after ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_exception_does_not_block_email(): void {
		$fail = static function (): bool {
			throw new RuntimeException( 'Broken filter.' );
		};
		add_filter( 'lumia_factures_can_issue', $fail );

		$order = $this->order();
		$order->set_status( 'processing' );

		$result = $this->email( 'customer_completed_order', $order, [ '/tmp/other.pdf' ] );

		remove_filter( 'lumia_factures_can_issue', $fail );

		$this->assertSame( [ '/tmp/other.pdf' ], $result );
		$this->assertStringContainsString( 'Broken filter.', (string) file_get_contents( $this->log_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_refund_object_is_ignored(): void {
		$order = $this->order();
		$order->update_status( 'processing' );
		$this->track( Register::find( 'invoice', $order->get_id() ) );

		$refund = new WC_Order_Refund();

		$this->assertSame( [], $this->email( 'customer_completed_order', $refund ) );
		$this->assertSame( [], $this->email( 'customer_completed_order', null ) );
	}

	public function test_full_refund_email_attaches_credit_note(): void {
		$order  = $this->invoiced_order();
		$refund = $this->full_refund( $order );

		$doc = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $doc );
		$this->assertTrue( Archive::verify( $doc ) );

		$result = $this->refund_email( $refund, false, [ '/tmp/other.pdf' ] );

		$this->assertSame( [ '/tmp/other.pdf', Archive::path( $doc ) ], $result );
	}

	public function test_partial_refund_email_id_normalized_and_attaches_credit_note(): void {
		$order  = $this->invoiced_order();
		$refund = $this->amount_refund( $order, 5.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $doc );

		$result = $this->refund_email( $refund, true );

		$this->assertSame( 'customer_partially_refunded_order', $this->refund_email_object()->id );
		$this->assertSame( [ Archive::path( $doc ) ], $result );
	}

	public function test_refund_email_never_attaches_invoice_even_if_stored_in_invoice_emails(): void {
		update_option(
			'lumia_factures_module_invoices',
			[ 'general' => [ 'email_attachments' => [ 'customer_completed_order', 'customer_refunded_order', 'customer_partially_refunded_order' ] ] ]
		);

		$order   = $this->invoiced_order();
		$invoice = Register::find( 'invoice', $order->get_id() );
		$refund  = $this->amount_refund( $order, 5.0 );
		$doc     = Register::find( 'credit_note', $refund->get_id() );

		$full    = $this->refund_email( $refund, false );
		$partial = $this->refund_email( $refund, true );

		$this->assertSame( [ Archive::path( $doc ) ], $full );
		$this->assertSame( [ Archive::path( $doc ) ], $partial );
		$this->assertNotContains( Archive::path( $invoice ), $full );

		// Without the credit note in its own list, the refund email carries nothing at all.
		update_option(
			'lumia_factures_module_invoices',
			[
				'general'      => [ 'email_attachments' => [ 'customer_refunded_order' ] ],
				'credit_notes' => [ 'email_attachments' => [] ],
			]
		);

		$this->assertSame( [], $this->refund_email( $refund, false ) );
		$this->assertSame( [], $this->refund_email( $refund, true ) );
	}

	public function test_refund_email_not_in_credit_note_list_attaches_nothing(): void {
		update_option( 'lumia_factures_module_invoices', [ 'credit_notes' => [ 'email_attachments' => [] ] ] );
		$key    = $this->credit_key();
		$order  = $this->invoiced_order();
		$before = Sequences::current( $key );
		$refund = $this->refund_without_hook( $order, 5.0 );

		$this->assertSame( [ '/tmp/other.pdf' ], $this->refund_email( $refund, true, [ '/tmp/other.pdf' ] ) );
		$this->assertSame( [], $this->refund_email( $refund, false ) );

		// The email issued nothing: the credit note waits for its hook or the sweep.
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );
		$this->assertSame( $before, Sequences::current( $key ) );
	}

	public function test_refund_email_without_refund_object_attaches_nothing(): void {
		$key    = $this->credit_key();
		$order  = $this->invoiced_order();
		$before = Sequences::current( $key );
		$refund = $this->refund_without_hook( $order, 5.0 );

		$email         = $this->refund_email_object();
		$email->id     = 'customer_refunded_order';
		$email->object = wc_get_order( $order->get_id() );
		$email->refund = false;

		$result = apply_filters( 'woocommerce_email_attachments', [ '/tmp/other.pdf' ], $email->id, $email->object, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.

		$this->assertSame( [ '/tmp/other.pdf' ], $result );
		$this->assertSame( [], $this->email( 'customer_refunded_order', wc_get_order( $order->get_id() ) ) );
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );
		$this->assertSame( $before, Sequences::current( $key ) );
		$this->assertStringContainsString( 'names no refund', (string) file_get_contents( $this->log_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_refund_email_for_another_order_or_in_preview_issues_nothing(): void {
		$key    = $this->credit_key();
		$order  = $this->invoiced_order();
		$other  = $this->invoiced_order();
		$before = Sequences::current( $key );
		$refund = $this->refund_without_hook( $order, 5.0 );

		// A refund of another order.
		$this->assertSame( [], $this->refund_email( $refund, true, [], $other ) );

		// A WooCommerce email preview (WooCommerce 9.6+).
		add_filter( 'woocommerce_is_email_preview', '__return_true' );
		$preview = $this->refund_email( $refund, true );
		remove_filter( 'woocommerce_is_email_preview', '__return_true' );
		$this->assertSame( [], $preview );

		// A dummy refund that carries the id of the real one but names another order.
		$dummy = new WC_Order_Refund();
		$dummy->set_id( $refund->get_id() );
		$dummy->set_parent_id( $other->get_id() );
		$this->assertSame( [], $this->refund_email( $dummy, true, [], $other ) );

		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );
		$this->assertSame( $before, Sequences::current( $key ) );
	}

	public function test_email_issues_credit_note_before_woocommerce_order_refunded_and_hook_reuses_it(): void {
		$key    = $this->credit_key();
		$order  = $this->invoiced_order();
		$before = Sequences::current( $key );

		// WooCommerce sends the refund email from `woocommerce_order_partially_refunded`, before
		// `woocommerce_order_refunded` (where CreditNoteIssuer listens at priority 10).
		$sent  = [];
		$send  = function ( $order_id, $refund_id ) use ( &$sent ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Action signature.
			$stored = wc_get_order( (int) $refund_id );
			$this->assertInstanceOf( WC_Order_Refund::class, $stored );
			$sent[] = $this->refund_email( $stored, true );
		};
		$seen  = [];
		$check = static function ( $order_id, $refund_id ) use ( &$seen ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Action signature.
			$seen[] = Register::find( 'credit_note', (int) $refund_id );
		};
		add_action( 'woocommerce_order_partially_refunded', $send, 10, 2 );
		add_action( 'woocommerce_order_refunded', $check, 5, 2 );

		try {
			$refund = OrderFactory::refund( wc_get_order( $order->get_id() ), [], 5.0 );
		} finally {
			remove_action( 'woocommerce_order_partially_refunded', $send, 10 );
			remove_action( 'woocommerce_order_refunded', $check, 5 );
		}

		$doc = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $doc );
		$this->track( $doc );

		// The email issued the credit note and attached it.
		$this->assertCount( 1, $sent );
		$this->assertSame( [ Archive::path( $doc ) ], $sent[0] );
		$this->assertCount( 1, $seen );
		$this->assertNotNull( $seen[0] );
		$this->assertSame( 'issued', $seen[0]->status );
		$this->assertSame( $doc->number, $seen[0]->number );

		// The hook found it: one number used, the same one recorded on the refund.
		$this->assertSame( $before + 1, Sequences::current( $key ) );
		$this->assertSame( $doc->number, CreditNoteIssuer::issue( $this->refund_of( $refund ) )->number );
		$this->assertSame( $before + 1, Sequences::current( $key ) );
		$this->assertSame( (string) $doc->number, (string) $this->refund_of( $refund )->get_meta( CreditNoteIssuer::NUMBER_META ) );
	}

	public function test_deferred_transactional_emails_still_attach_the_same_credit_note(): void {
		$key   = $this->credit_key();
		$order = $this->invoiced_order();

		// Deferred emails leave at the end of the request, after `woocommerce_order_refunded` issued
		// the credit note: they are rebuilt from the stored order and refund.
		$refund = $this->full_refund( $order );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $doc );
		$after = Sequences::current( $key );

		$first  = $this->refund_email( $this->refund_of( $refund ), false );
		$resent = $this->refund_email( $this->refund_of( $refund ), false );

		$this->assertSame( [ Archive::path( $doc ) ], $first );
		$this->assertSame( $first, $resent );
		$this->assertSame( $after, Sequences::current( $key ) );
		$this->assertSame( $doc->number, Register::find( 'credit_note', $refund->get_id() )->number );
	}

	public function test_never_built_credit_note_archive_is_built_before_attaching(): void {
		$order = $this->invoiced_order();

		$fail = static function (): array {
			throw new RuntimeException( 'Render broken.' );
		};
		add_filter( 'lumia_factures_dompdf_options', $fail );
		$refund = $this->amount_refund( $order, 5.0 );
		remove_filter( 'lumia_factures_dompdf_options', $fail );

		$doc = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $doc );
		$this->assertNull( $doc->file );

		$result = $this->refund_email( $refund, true );

		$built = Register::find( 'credit_note', $refund->get_id() );
		$this->track( $built );
		$this->assertSame( $doc->number, $built->number );
		$this->assertTrue( Archive::verify( $built ) );
		$this->assertSame( [ Archive::path( $built ) ], $result );
	}

	public function test_missing_recorded_credit_note_archive_is_neither_rebuilt_nor_attached(): void {
		$order  = $this->invoiced_order();
		$refund = $this->amount_refund( $order, 5.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		wp_delete_file( (string) Archive::path( $doc ) );

		$this->assertSame( [], $this->refund_email( $refund, true ) );

		$after = Register::find( 'credit_note', $refund->get_id() );
		$this->assertSame( $doc->file, $after->file );
		$this->assertSame( $doc->checksum, $after->checksum );
		$this->assertStringContainsString( 'credit note ' . $doc->number, (string) file_get_contents( $this->log_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Runs the WooCommerce filter.
	 *
	 * @param string $email_id    Email id.
	 * @param mixed  $subject      Email object.
	 * @param array  $attachments Initial attachments.
	 * @return mixed
	 */
	private function email( string $email_id, $subject, array $attachments = [] ) {
		return apply_filters( 'woocommerce_email_attachments', $attachments, $email_id, $subject, null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
	}

	/**
	 * A saved pending order with one line.
	 */
	private function order(): WC_Order {
		return OrderFactory::order( [ [ OrderFactory::simple_product( 10.0, '', 'Croquettes' ), 2 ] ] );
	}

	/**
	 * Runs the WooCommerce filter as the refund email does, from the mailer's refund email object.
	 *
	 * @param WC_Order_Refund $refund      Refund.
	 * @param bool            $partial     Partial refund (WooCommerce renames the email).
	 * @param array           $attachments Initial attachments.
	 * @param WC_Order|null   $order       Email object, the refunded order by default.
	 * @return mixed
	 */
	private function refund_email( WC_Order_Refund $refund, bool $partial, array $attachments = [], ?WC_Order $order = null ) {
		$email                 = $this->refund_email_object();
		$email->partial_refund = $partial;
		$email->id             = $partial ? 'customer_partially_refunded_order' : 'customer_refunded_order';
		$email->object         = $order ?? wc_get_order( $refund->get_parent_id() );
		$email->refund         = $refund;

		return apply_filters( 'woocommerce_email_attachments', $attachments, $email->id, $email->object, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
	}

	/**
	 * The mailer's refund email object.
	 */
	private function refund_email_object(): WC_Email_Customer_Refunded_Order {
		$email = WC()->mailer()->get_emails()['WC_Email_Customer_Refunded_Order'] ?? null;
		$this->assertInstanceOf( WC_Email_Customer_Refunded_Order::class, $email );

		return $email;
	}

	/**
	 * An order moved to a trigger status, with its issued invoice.
	 */
	private function invoiced_order(): WC_Order {
		$order = $this->order();
		$order->update_status( 'processing' );

		$invoice = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $invoice );
		$this->track( $invoice );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * A refund of an amount, its credit note tracked.
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount.
	 */
	private function amount_refund( WC_Order $order, float $amount ): WC_Order_Refund {
		$refund = OrderFactory::refund( wc_get_order( $order->get_id() ), [], $amount );
		$this->track( Register::find( 'credit_note', $refund->get_id() ) );

		return $refund;
	}

	/**
	 * A refund of every product line of the order, its credit note tracked.
	 *
	 * @param WC_Order $order Order.
	 */
	private function full_refund( WC_Order $order ): WC_Order_Refund {
		$fresh = wc_get_order( $order->get_id() );
		$lines = [];
		foreach ( $fresh->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$lines[ $item->get_id() ] = OrderFactory::refund_line( $item );
			}
		}

		$refund = OrderFactory::refund( $fresh, $lines, null );
		$this->track( Register::find( 'credit_note', $refund->get_id() ) );

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
	 * The credit note counter key of the current period.
	 */
	private function credit_key(): string {
		return Sequences::key( 'credit_note', time(), Module::settings()['credit_notes'] );
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
