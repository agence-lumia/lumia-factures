<?php
/**
 * Live preview: no side effects, unsaved settings, access control.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Admin\Preview;
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Rendering\DocumentData;
use Lumia\Factures\Modules\Invoices\Rendering\Renderer;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WPDieException;

/**
 * The preview renders the posted settings against an order and leaves no trace.
 */
class PreviewTest extends TestCase {

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
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static fn() => static function ( $message ) {
				throw new WPDieException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double.
			}
		);
	}

	public function tear_down(): void {
		unset( $_REQUEST['nonce'], $_POST['nonce'], $_POST['order_id'], $_POST['lumia_factures_module_settings'] );
		foreach ( $this->written as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		parent::tear_down();
	}

	/**
	 * Runs the AJAX endpoint and returns the decoded JSON.
	 *
	 * @return array<string, mixed>
	 */
	private function call(): array {
		ob_start();
		try {
			Preview::handle();
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();

		return (array) json_decode( $out, true );
	}

	/**
	 * The markup of the document, without the inlined stylesheet (which always defines `.lf-watermark`).
	 *
	 * @param string $html Complete document.
	 */
	private function body( string $html ): string {
		return (string) preg_replace( '#<style>.*?</style>#s', '', $html );
	}

	/**
	 * Archived PDF files under the archive root.
	 *
	 * @return string[]
	 */
	private function pdf_files(): array {
		$root = Archive::root();
		if ( ! is_dir( $root ) ) {
			return [];
		}
		$found = [];
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'pdf' === strtolower( $file->getExtension() ) ) {
				$found[] = $file->getPathname();
			}
		}

		return $found;
	}

	public function test_preview_requires_nonce_and_capability(): void {
		$order             = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$_POST['order_id'] = (string) $order->get_id();

		// No nonce.
		try {
			Preview::handle();
			$this->fail( 'Expected the nonce check to stop the request.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( '-1', $e->getMessage() );
		}

		// Valid nonce, no capability.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$nonce             = wp_create_nonce( 'lumia_factures_admin_nonce' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		$json              = $this->call();
		$this->assertFalse( $json['success'] );
		$this->assertArrayNotHasKey( 'pdf', (array) $json['data'] );
	}

	public function test_preview_returns_pdf_without_side_effects(): void {
		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$key    = Sequences::key( Issuer::TYPE, time(), Module::settings()['numbering'] );
		$notes  = count( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
		$stored = get_option( 'lumia_factures_module_invoices' );
		$files  = $this->pdf_files();
		$rows   = $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->prefix . 'lumia_factures_documents' );

		$nonce                                   = wp_create_nonce( 'lumia_factures_admin_nonce' );
		$_POST['nonce']                          = $nonce;
		$_REQUEST['nonce']                       = $nonce;
		$_POST['order_id']                       = (string) $order->get_id();
		$_POST['lumia_factures_module_settings'] = [ 'content' => [ 'invoice_title' => 'Proforma' ] ];

		$json = $this->call();

		$this->assertTrue( $json['success'] );
		$this->assertStringStartsWith( '%PDF-', (string) base64_decode( $json['data']['pdf'], true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reads the PDF payload.
		$this->assertSame( '#' . $order->get_order_number(), $json['data']['order'] );
		$this->assertSame( $rows, $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->prefix . 'lumia_factures_documents' ) );
		$this->assertSame( 0, Sequences::current( $key ) );
		$this->assertCount( $notes, wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
		$this->assertSame( $files, $this->pdf_files() );
		$this->assertSame( $stored, get_option( 'lumia_factures_module_invoices' ) );
		$this->assertSame( '', (string) $order->get_meta( '_lumia_factures_invoice_number' ) );
	}

	public function test_preview_uses_unsaved_settings(): void {
		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$before = Module::settings();

		$html = Preview::render( [ 'content' => [ 'invoice_title' => 'Proforma' ] ], $order->get_id(), 'html' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'Proforma', $html );
		$this->assertStringContainsString( 'lf-watermark', $html );
		$this->assertSame( $before, Module::settings() );
	}

	public function test_issued_invoice_has_no_preview_watermark(): void {
		$captured = '';
		add_filter(
			'lumia_factures_invoice_html',
			static function ( $html ) use ( &$captured ) {
				$captured = (string) $html;

				return $html;
			}
		);

		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $doc );
		if ( null !== $doc->file ) {
			$this->written[] = Archive::root() . '/' . $doc->file;
		}

		$this->assertNotSame( '', $captured, 'The issuing path rendered no HTML.' );
		$this->assertStringContainsString( $doc->number, $captured );
		$captured = $this->body( $captured );
		$this->assertStringNotContainsString( 'lf-watermark', $captured );
		$this->assertStringNotContainsString( 'Preview', $captured );
	}

	public function test_renderer_without_preview_flag_has_no_watermark(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$html  = Renderer::html( $order, new DocumentData( '', 'FA-1', time(), false ), Module::settings() );

		$html = $this->body( $html );
		$this->assertStringNotContainsString( 'lf-watermark', $html );
		$this->assertStringNotContainsString( 'Preview', $html );
	}

	public function test_preview_number_is_the_next_sample_without_consuming_it(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$key   = Sequences::key( Issuer::TYPE, time(), [ 'reset_enabled' => false ] );
		Sequences::next( $key, 1 );
		Sequences::next( $key, 1 );

		$html = Preview::render(
			[
				'numbering' => [
					'format'        => 'PV-{number:4}',
					'reset_enabled' => '0',
				],
			],
			$order->get_id(),
			'html'
		);

		$this->assertStringContainsString( 'PV-0003', (string) $html );
		$this->assertSame( 2, Sequences::current( $key ) );
	}

	public function test_preview_without_orders_returns_error(): void {
		$result = Preview::render( [], 0, 'html' );

		$this->assertWPError( $result );
		$this->assertSame( 'Create a first order to preview the invoice.', $result->get_error_message() );
	}

	public function test_preview_refuses_unknown_order_and_refunds(): void {
		// A refund of an order never invoiced: no credit note to issue (restored after the test).
		remove_action( 'woocommerce_order_refunded', [ CreditNoteIssuer::class, 'on_refunded' ], 10 );

		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$refund = wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 1,
			]
		);

		$this->assertWPError( Preview::render( [], 999999, 'html' ) );
		$this->assertWPError( Preview::render( [], $refund->get_id(), 'html' ) );
	}
}
