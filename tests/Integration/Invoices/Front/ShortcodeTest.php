<?php
/**
 * Invoice shortcode and its legacy alias.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Front\CustomerDownload;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Refund;

/**
 * The link is rendered for the order's customer (or a key holder) and never as a dead button.
 */
class ShortcodeTest extends TestCase {

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
		foreach ( [ 'view-order', 'order-received', 'key' ] as $var ) {
			set_query_var( $var, '' );
		}
		unset( $_GET['key'] );
		parent::tear_down();
	}

	public function test_shortcode_and_legacy_alias_render_link_for_owner_only(): void {
		$owner = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_customer_id( $owner );
		$order->save();
		$order->update_status( 'processing' );
		$doc             = Register::find( 'invoice', $order->get_id() );
		$this->written[] = Archive::root() . '/' . $doc->file;
		$id              = $order->get_id();

		// A guest without the key gets nothing, for both tags.
		$this->assertSame( '', do_shortcode( '[lumia_factures_invoice order_id="' . $id . '"]' ) );
		$this->assertSame( '', do_shortcode( '[facture_pdf_lumia order_id="' . $id . '"]' ) );

		// The owner gets the link.
		wp_set_current_user( $owner );
		$new = do_shortcode( '[lumia_factures_invoice order_id="' . $id . '" label="My <b>PDF</b>" class="btn"]' );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/?lumia_factures_invoice=' . $id . '&inline=0' ) ) . '"', $new );
		$this->assertStringContainsString( 'class="btn"', $new );
		$this->assertStringContainsString( '>My &lt;b&gt;PDF&lt;/b&gt;</a>', $new );

		$old = do_shortcode( '[facture_pdf_lumia order_id="' . $id . '" inline="0"]' );
		$this->assertStringContainsString( 'lumia_factures_invoice=' . $id, $old );
		$this->assertStringContainsString( 'class="fpl-download-button"', $old );
		$this->assertStringContainsString( 'Download my invoice', $old );
		$this->assertStringContainsString( 'inline=0', $old );
		$this->assertStringNotContainsString( 'inline=0', do_shortcode( '[facture_pdf_lumia order_id="' . $id . '" inline="1"]' ) );

		// Another customer sees nothing, unless the page carries the order key.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->assertSame( '', do_shortcode( '[lumia_factures_invoice order_id="' . $id . '"]' ) );
		$_GET['key'] = $order->get_order_key();
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), do_shortcode( '[lumia_factures_invoice order_id="' . $id . '"]' ) );
	}

	public function test_no_link_without_issued_invoice_or_order(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		grant_super_admin( get_current_user_id() );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		$this->assertSame( '', do_shortcode( '[lumia_factures_invoice order_id="' . $order->get_id() . '"]' ) );
		$this->assertSame( '', do_shortcode( '[lumia_factures_invoice]' ) );
		$this->assertSame( '', do_shortcode( '[lumia_factures_invoice order_id="99999999"]' ) );
	}

	public function test_documents_shortcode_renders_list_for_owner(): void {
		$owner  = $this->customer();
		$order  = $this->issued_order( $owner );
		$refund = $this->refund( $order, 2.0 );
		$id     = $order->get_id();
		wp_set_current_user( $owner );

		$html = do_shortcode( '[lumia_factures_documents order_id="' . $id . '"]' );

		$this->assertStringContainsString( 'lumia-factures-order-documents', $html );
		$this->assertStringContainsString( 'Invoice ' . Register::find( 'invoice', $id )->number, $html );
		$this->assertStringContainsString( 'Credit note ' . Register::find( 'credit_note', $refund->get_id() )->number, $html );
		$this->assertStringContainsString( esc_url( CustomerDownload::url( $order ) ), $html );
		$this->assertStringContainsString( 'lumia_factures_credit_note=' . $refund->get_id(), $html );
		$this->assertStringNotContainsString( 'key=', $html, 'The owner is let in by the session.' );

		// The placement filter can hide it.
		$hide = static fn( $show, $placement ) => 'shortcode' === $placement ? false : $show;
		add_filter( 'lumia_factures_show_customer_links', $hide, 10, 2 );
		try {
			$this->assertSame( '', do_shortcode( '[lumia_factures_documents order_id="' . $id . '"]' ) );
		} finally {
			remove_filter( 'lumia_factures_show_customer_links', $hide, 10 );
		}

		// Nothing issued: nothing printed.
		$other = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$other->set_customer_id( $owner );
		$other->save();
		$this->assertSame( '', do_shortcode( '[lumia_factures_documents order_id="' . $other->get_id() . '"]' ) );
	}

	public function test_documents_shortcode_empty_without_access_even_with_explicit_order_id(): void {
		$owner  = $this->customer();
		$order  = $this->issued_order( $owner );
		$refund = $this->refund( $order, 2.0 );
		$id     = $order->get_id();

		// A guest without the key, another customer, a wrong key: nothing.
		$this->assertSame( '', do_shortcode( '[lumia_factures_documents order_id="' . $id . '"]' ) );
		wp_set_current_user( $this->customer() );
		$this->assertSame( '', do_shortcode( '[lumia_factures_documents order_id="' . $id . '"]' ) );
		$_GET['key'] = 'wc_order_wrong';
		$this->assertSame( '', do_shortcode( '[lumia_factures_documents order_id="' . $id . '"]' ) );

		// The endpoint of the visitor's own order never lends its access to an explicit order_id.
		$mine = $this->issued_order( get_current_user_id() );
		set_query_var( 'view-order', $mine->get_id() );
		unset( $_GET['key'] );
		$this->assertSame( '', do_shortcode( '[lumia_factures_documents order_id="' . $id . '"]' ) );

		// A refund id is not an order.
		wp_set_current_user( $owner );
		set_query_var( 'view-order', '' );
		$this->assertSame( '', do_shortcode( '[lumia_factures_documents order_id="' . $refund->get_id() . '"]' ) );

		// The key holder gets the list, with the key in the links.
		wp_set_current_user( 0 );
		$_GET['key'] = $order->get_order_key();
		$html        = do_shortcode( '[lumia_factures_documents order_id="' . $id . '"]' );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $html );
		$this->assertStringContainsString( 'lumia_factures_credit_note=' . $refund->get_id(), $html );
	}

	public function test_documents_shortcode_resolves_order_from_endpoint_context(): void {
		$owner = $this->customer();
		$order = $this->issued_order( $owner );
		wp_set_current_user( $owner );

		$this->assertSame( '', do_shortcode( '[lumia_factures_documents]' ), 'No order in the context.' );

		set_query_var( 'view-order', $order->get_id() );
		$this->assertStringContainsString( 'Invoice ' . Register::find( 'invoice', $order->get_id() )->number, do_shortcode( '[lumia_factures_documents]' ) );

		set_query_var( 'view-order', '' );
		set_query_var( 'order-received', $order->get_id() );
		$this->assertStringContainsString( 'lumia_factures_invoice=' . $order->get_id(), do_shortcode( '[lumia_factures_documents]' ) );
	}

	public function test_existing_invoice_shortcodes_unchanged(): void {
		foreach ( [ 'lumia_factures_invoice', 'facture_pdf_lumia', 'lumia_factures_documents' ] as $tag ) {
			$this->assertTrue( shortcode_exists( $tag ), $tag );
		}

		$owner = $this->customer();
		$order = $this->issued_order( $owner );
		$this->refund( $order, 2.0 );
		$id = $order->get_id();
		wp_set_current_user( $owner );

		$this->assertSame(
			'<a href="' . esc_url( CustomerDownload::url( $order, false ) ) . '" class="lumia-factures-download">Download my invoice</a>',
			do_shortcode( '[lumia_factures_invoice order_id="' . $id . '"]' )
		);
		$this->assertSame(
			'<a href="' . esc_url( CustomerDownload::url( $order, false ) ) . '" class="fpl-download-button">Download my invoice</a>',
			do_shortcode( '[facture_pdf_lumia order_id="' . $id . '"]' )
		);
	}

	/**
	 * A customer account.
	 */
	private function customer(): int {
		return (int) self::factory()->user->create( [ 'role' => 'customer' ] );
	}

	/**
	 * An order of a customer with an issued, archived invoice.
	 *
	 * @param int $customer_id Customer id.
	 */
	private function issued_order( int $customer_id ): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_customer_id( $customer_id );
		$order->save();
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
