<?php
/**
 * Document links in customer e-mails.
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
use Lumia\Factures\Modules\Invoices\Emails\EmailLinks;
use Lumia\Factures\Modules\Invoices\Front\CustomerDownload;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use RuntimeException;
use WC_Email;
use WC_Email_Customer_Refunded_Order;
use WC_Order;
use WC_Order_Refund;

/**
 * The links issue what they point to, carry the order key, and never break the e-mail.
 */
class EmailLinksTest extends TestCase {

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
		wp_set_current_user( 0 );

		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
	}

	public function tear_down(): void {
		// The mailer keeps one refund email object for the whole process: leave it as WooCommerce builds it.
		$email         = $this->email_object( 'WC_Email_Customer_Refunded_Order' );
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

	public function test_processing_email_issues_and_links_invoice_before_status_changed(): void {
		$this->assertSame( 20, has_action( 'woocommerce_email_order_meta', [ EmailLinks::class, 'render' ] ) );

		$key    = $this->invoice_key();
		$before = Sequences::current( $key );
		$order  = $this->processing_without_invoice();

		// Through the WooCommerce action, as the e-mail template fires it.
		ob_start();
		do_action( 'woocommerce_email_order_meta', $order, false, false, $this->email_object( 'WC_Email_Customer_Processing_Order' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
		$html = (string) ob_get_clean();

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->track( $doc );
		$this->assertSame( $before + 1, Sequences::current( $key ) );

		$this->assertStringContainsString( 'Invoice ' . $doc->number, $html );
		$this->assertStringContainsString( esc_url( CustomerDownload::url( wc_get_order( $order->get_id() ), true, true ) ), $html );

		// The status hook that follows finds the same invoice.
		$this->assertSame( $doc->number, Issuer::issue( wc_get_order( $order->get_id() ) )->number );
		$this->assertSame( $before + 1, Sequences::current( $key ) );
	}

	public function test_email_link_carries_key_when_triggered_by_admin(): void {
		$order = $this->invoiced_order();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->render( $order, $this->email_object( 'WC_Email_Customer_Completed_Order' ) );

		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $html );
		$this->assertStringContainsString( CustomerDownload::QUERY_VAR . '=' . $order->get_id(), $html );
	}

	public function test_sent_to_admin_prints_nothing(): void {
		$order = $this->processing_without_invoice();

		$this->assertSame( '', $this->render( $order, $this->email_object( 'WC_Email_Customer_Processing_Order' ), false, true ) );
		$this->assertSame( '', $this->render( $order, $this->email_object( 'WC_Email_New_Order' ), false, true ) );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
	}

	public function test_missing_email_argument_prints_nothing(): void {
		$order = $this->processing_without_invoice();

		$this->assertSame( '', $this->render( $order, null ) );
		ob_start();
		EmailLinks::render( $order, false, false, 'customer_processing_order' );
		$this->assertSame( '', ob_get_clean() );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
	}

	public function test_email_not_in_setting_prints_nothing(): void {
		update_option( 'lumia_factures_module_invoices', [ 'customer' => [ 'email_links' => [ 'customer_completed_order' ] ] ] );
		$order = $this->processing_without_invoice();

		$this->assertSame( '', $this->render( $order, $this->email_object( 'WC_Email_Customer_Processing_Order' ) ) );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );

		// An empty list turns every e-mail off.
		update_option( 'lumia_factures_module_invoices', [ 'customer' => [ 'email_links' => [] ] ] );
		$invoiced = $this->invoiced_order();
		$this->assertSame( '', $this->render( $invoiced, $this->email_object( 'WC_Email_Customer_Completed_Order' ) ) );

		// The filter can turn one off too.
		delete_option( 'lumia_factures_module_invoices' );
		add_filter( 'lumia_factures_show_customer_links', '__return_false' );
		$this->assertSame( '', $this->render( $invoiced, $this->email_object( 'WC_Email_Customer_Completed_Order' ) ) );
		remove_filter( 'lumia_factures_show_customer_links', '__return_false' );
		$this->assertNotSame( '', $this->render( $invoiced, $this->email_object( 'WC_Email_Customer_Completed_Order' ) ) );
	}

	public function test_plain_text_outputs_label_colon_url_without_html(): void {
		$order = $this->invoiced_order();
		$doc   = Register::find( 'invoice', $order->get_id() );

		$text = $this->render( $order, $this->email_object( 'WC_Email_Customer_Completed_Order' ), true );

		$this->assertStringContainsString( 'Invoice ' . $doc->number . ': ' . CustomerDownload::url( $order, true, true ), $text );
		$this->assertStringNotContainsString( '<', $text );
		$this->assertStringNotContainsString( '&#038;', $text );
	}

	public function test_refund_email_links_its_credit_note_issued_lazily(): void {
		$key    = $this->credit_key();
		$order  = $this->invoiced_order();
		$before = Sequences::current( $key );
		$refund = $this->refund_without_hook( $order, 5.0 );

		$html = $this->render( wc_get_order( $order->get_id() ), $this->refund_email( $refund, true ) );

		$doc = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->track( $doc );
		$this->assertSame( $before + 1, Sequences::current( $key ) );

		$this->assertStringContainsString( 'Credit note ' . $doc->number, $html );
		$this->assertStringContainsString( esc_url( CustomerDownload::credit_note_url( $doc, $order, true, true ) ), $html );
		$this->assertStringContainsString( 'Invoice ' . Register::find( 'invoice', $order->get_id() )->number, $html );
	}

	public function test_partial_refund_email_lists_only_that_refund_credit_note(): void {
		$order  = $this->invoiced_order();
		$first  = $this->refund( $order, 2.0 );
		$second = $this->refund( $order, 3.0 );
		$cn1    = Register::find( 'credit_note', $first->get_id() );
		$cn2    = Register::find( 'credit_note', $second->get_id() );

		$html = $this->render( wc_get_order( $order->get_id() ), $this->refund_email( $second, true ) );

		$this->assertStringContainsString( 'Invoice ' . Register::find( 'invoice', $order->get_id() )->number, $html );
		$this->assertStringContainsString( 'Credit note ' . $cn2->number, $html );
		$this->assertStringNotContainsString( (string) $cn1->number, $html );

		// A refund e-mail that names no refund lists the invoice alone.
		$email         = $this->refund_email( $second, true );
		$email->refund = false;
		$alone         = $this->render( wc_get_order( $order->get_id() ), $email );
		$this->assertStringNotContainsString( 'Credit note', $alone );
		$this->assertStringContainsString( 'Invoice ', $alone );
	}

	public function test_preview_dummy_order_never_issues(): void {
		$key    = $this->invoice_key();
		$before = Sequences::current( $key );
		$order  = $this->processing_without_invoice();

		// WooCommerce 9.6+ previews build a dummy order; it may carry the id of a real order.
		$dummy = new WC_Order();
		$dummy->set_id( $order->get_id() );
		$dummy->set_order_key( $order->get_order_key() );
		$dummy->set_status( 'processing' );

		add_filter( 'woocommerce_is_email_preview', '__return_true' );
		$html = $this->render( $dummy, $this->email_object( 'WC_Email_Customer_Processing_Order' ) );
		$real = $this->render( $order, $this->email_object( 'WC_Email_Customer_Processing_Order' ) );
		remove_filter( 'woocommerce_is_email_preview', '__return_true' );

		$this->assertSame( '', $html );
		$this->assertSame( '', $real );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( $before, Sequences::current( $key ) );
	}

	public function test_order_key_mismatch_with_db_never_issues(): void {
		$key    = $this->invoice_key();
		$before = Sequences::current( $key );
		$order  = $this->processing_without_invoice();

		$dummy = new WC_Order();
		$dummy->set_id( $order->get_id() );
		$dummy->set_order_key( 'wc_order_dummy' );
		$dummy->set_status( 'processing' );

		$this->assertSame( '', $this->render( $dummy, $this->email_object( 'WC_Email_Customer_Processing_Order' ) ) );

		// Without a key at all, the same.
		$keyless = new WC_Order();
		$keyless->set_id( $order->get_id() );
		$keyless->set_status( 'processing' );
		$this->assertSame( '', $this->render( $keyless, $this->email_object( 'WC_Email_Customer_Processing_Order' ) ) );

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( $before, Sequences::current( $key ) );
	}

	public function test_email_links_idempotent_with_attachments(): void {
		$invoice_key = $this->invoice_key();
		$credit_key  = $this->credit_key();
		$order       = $this->processing_without_invoice();
		$before      = Sequences::current( $invoice_key );

		// WooCommerce renders the body, then asks for the attachments, for each e-mail.
		$email       = $this->email_object( 'WC_Email_Customer_Processing_Order' );
		$html        = $this->render( wc_get_order( $order->get_id() ), $email );
		$attachments = apply_filters( 'woocommerce_email_attachments', [], 'customer_processing_order', wc_get_order( $order->get_id() ), $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
		$resent      = $this->render( wc_get_order( $order->get_id() ), $email );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		$this->assertSame( $before + 1, Sequences::current( $invoice_key ) );
		$this->assertSame( [ Archive::path( $doc ) ], $attachments );
		$this->assertStringContainsString( 'Invoice ' . $doc->number, $html );
		$this->assertSame( $html, $resent );

		// The refund e-mail: one credit note, linked and attached.
		$credit_before = Sequences::current( $credit_key );
		$refund        = $this->refund_without_hook( wc_get_order( $order->get_id() ), 5.0 );
		$refund_email  = $this->refund_email( $refund, true );
		$refund_html   = $this->render( wc_get_order( $order->get_id() ), $refund_email );
		$attached      = apply_filters( 'woocommerce_email_attachments', [], $refund_email->id, $refund_email->object, $refund_email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.

		// Then WooCommerce fires `woocommerce_order_refunded`: the hook finds it.
		do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.

		$credit = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $credit );
		$this->track( $credit );
		$this->assertSame( $credit_before + 1, Sequences::current( $credit_key ) );
		$this->assertSame( [ Archive::path( $credit ) ], $attached );
		$this->assertStringContainsString( 'Credit note ' . $credit->number, $refund_html );
		$this->assertSame( (string) $credit->number, (string) wc_get_order( $refund->get_id() )->get_meta( CreditNoteIssuer::NUMBER_META ) );
		$this->assertSame( $before + 1, Sequences::current( $invoice_key ) );
	}

	public function test_issue_failure_never_breaks_email(): void {
		$order = $this->processing_without_invoice();
		$fail  = static function (): bool {
			throw new RuntimeException( 'Broken filter.' );
		};
		add_filter( 'lumia_factures_can_issue', $fail );

		$level = ob_get_level();
		try {
			$html = $this->render( $order, $this->email_object( 'WC_Email_Customer_Processing_Order' ) );
		} finally {
			remove_filter( 'lumia_factures_can_issue', $fail );
		}

		$this->assertSame( '', $html );
		$this->assertSame( $level, ob_get_level() );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertStringContainsString( 'Broken filter.', (string) file_get_contents( $this->log_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// An issued invoice is still linked when the refund's credit note cannot be issued.
		$invoiced = $this->invoiced_order();
		$refund   = $this->refund_without_hook( $invoiced, 5.0 );
		$veto     = static function (): bool {
			throw new RuntimeException( 'Broken credit note filter.' );
		};
		add_filter( 'lumia_factures_can_issue_credit_note', $veto );
		try {
			$html = $this->render( wc_get_order( $invoiced->get_id() ), $this->refund_email( $refund, false ) );
		} finally {
			remove_filter( 'lumia_factures_can_issue_credit_note', $veto );
		}

		$this->assertStringContainsString( 'Invoice ' . Register::find( 'invoice', $invoiced->get_id() )->number, $html );
		$this->assertStringNotContainsString( 'Credit note', $html );
		$this->assertStringContainsString( 'Broken credit note filter.', (string) file_get_contents( $this->log_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Renders the documents block.
	 *
	 * @param mixed $order         Email object.
	 * @param mixed $email         Email instance.
	 * @param bool  $plain_text    Plain text version.
	 * @param bool  $sent_to_admin Sent to the shop.
	 */
	private function render( $order, $email, bool $plain_text = false, bool $sent_to_admin = false ): string {
		ob_start();
		EmailLinks::render( $order, $sent_to_admin, $plain_text, $email );

		return (string) ob_get_clean();
	}

	/**
	 * One of the mailer's e-mail objects.
	 *
	 * @param string $class_name WooCommerce e-mail class.
	 */
	private function email_object( string $class_name ): WC_Email {
		$email = WC()->mailer()->get_emails()[ $class_name ] ?? null;
		$this->assertInstanceOf( WC_Email::class, $email );

		return $email;
	}

	/**
	 * The mailer's refund e-mail, set up as WooCommerce does when it sends one.
	 *
	 * @param WC_Order_Refund $refund  Refund.
	 * @param bool            $partial Partial refund (WooCommerce renames the email).
	 */
	private function refund_email( WC_Order_Refund $refund, bool $partial ): WC_Email_Customer_Refunded_Order {
		$email = $this->email_object( 'WC_Email_Customer_Refunded_Order' );
		$this->assertInstanceOf( WC_Email_Customer_Refunded_Order::class, $email );
		$email->partial_refund = $partial;
		$email->id             = $partial ? 'customer_partially_refunded_order' : 'customer_refunded_order';
		$email->object         = wc_get_order( $refund->get_parent_id() );
		$email->refund         = wc_get_order( $refund->get_id() );

		return $email;
	}

	/**
	 * A saved order in a trigger status, reached without the status hook: no invoice yet.
	 */
	private function processing_without_invoice(): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0, '', 'Croquettes' ), 2 ] ] );
		remove_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10 );
		try {
			$order->set_status( 'processing' );
			$order->save();
		} finally {
			add_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10, 4 );
		}
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * An order moved to a trigger status, with its issued invoice.
	 */
	private function invoiced_order(): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0, '', 'Croquettes' ), 2 ] ] );
		$order->update_status( 'processing' );

		$invoice = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $invoice );
		$this->track( $invoice );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * A refund of an amount, its credit note issued by the hook and tracked.
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
	 * The invoice counter key of the current period.
	 */
	private function invoice_key(): string {
		return Sequences::key( 'invoice', time(), Module::settings()['numbering'] );
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
