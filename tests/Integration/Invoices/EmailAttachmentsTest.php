<?php
/**
 * Invoice attachment on WooCommerce emails.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use RuntimeException;
use WC_Order;
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

	public function test_missing_archive_is_restored_before_attaching(): void {
		$order = $this->order();
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		wp_delete_file( (string) Archive::path( $doc ) );

		$result = $this->email( 'customer_completed_order', wc_get_order( $order->get_id() ) );

		$restored = Register::find( 'invoice', $order->get_id() );
		$this->track( $restored );
		$this->assertSame( $doc->number, $restored->number );
		$this->assertSame( [ Archive::path( $restored ) ], $result );
		$this->assertTrue( Archive::verify( $restored ) );
	}

	public function test_damaged_archive_that_cannot_be_restored_is_not_attached(): void {
		$order = $this->order();
		$order->update_status( 'processing' );
		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		file_put_contents( (string) Archive::path( $doc ), 'damaged' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test corrupts the archive.

		$fail = static function (): array {
			throw new RuntimeException( 'Render broken.' );
		};
		add_filter( 'lumia_factures_dompdf_options', $fail );
		$result = $this->email( 'customer_completed_order', wc_get_order( $order->get_id() ), [ '/tmp/other.pdf' ] );
		remove_filter( 'lumia_factures_dompdf_options', $fail );

		$this->assertSame( [ '/tmp/other.pdf' ], $result );
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
