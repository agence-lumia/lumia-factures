<?php
/**
 * Cancelling the invoice of an unpaid order.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Admin\OrderTools;
use Lumia\Factures\Modules\Invoices\Amounts\CreditNoteStatementBuilder;
use Lumia\Factures\Modules\Invoices\Amounts\Money;
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\InvoiceCancellation;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;
use WC_Payment_Gateway;
use WC_Product_Simple;
use WPDieException;

/**
 * InvoiceCancellation: a full refund of what is left, no money moved, no stock restored, credited
 * by one credit note; and the "Cancel invoice" action of the order screen.
 */
class InvoiceCancellationTest extends TestCase {

	private string $log_file = '';

	private string $previous_log = '';

	/**
	 * Archived files written by the test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

	/**
	 * Refunds the test gateway was asked for.
	 */
	public static int $gateway_calls = 0;

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		delete_option( 'lumia_factures_module_invoices' );
		delete_option( Migration::OPTION );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		self::$gateway_calls = 0;

		$gateway = new class() extends WC_Payment_Gateway {
			/**
			 * A gateway that supports refunds and counts them.
			 */
			public function __construct() {
				$this->id       = 'lf_counting';
				$this->enabled  = 'yes';
				$this->supports = [ 'products', 'refunds' ];
			}

			/**
			 * Counts the refund.
			 *
			 * @param int        $order_id Order id.
			 * @param float|null $amount   Amount.
			 * @param string     $reason   Reason.
			 */
			public function process_refund( $order_id, $amount = null, $reason = '' ): bool {
				++InvoiceCancellationTest::$gateway_calls;
				return true;
			}
		};
		WC()->payment_gateways()->payment_gateways['lf_counting'] = $gateway;

		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
	}

	public function tear_down(): void {
		unset( WC()->payment_gateways()->payment_gateways['lf_counting'], $_POST['order_id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		foreach ( array_unique( $this->written ) as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
		wp_delete_file( $this->log_file );
		parent::tear_down();
	}

	public function test_cancel_unpaid_invoiced_order_issues_full_credit_note(): void {
		[ $order, $product ] = $this->cancelled_invoiced_order();
		$stock               = $this->stock( $product );
		$invoice             = Register::find( 'invoice', $order->get_id() );

		$this->assertSame( '', InvoiceCancellation::refusal( $order ) );
		$refund = InvoiceCancellation::cancel( $order, 'Invoice cancelled' );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		$doc = $this->credit_note( $refund );
		$this->assertSame( 'issued', $doc->status );
		$this->assertSame( $order->get_id(), $doc->parent_id );
		$this->assertTrue( Archive::verify( $doc ) );

		$fresh = wc_get_order( $order->get_id() );
		$total = Money::to_minor( $fresh->get_total() );
		$this->assertSame( $total, Money::to_minor( $refund->get_amount() ) );
		$this->assertSame( 'Invoice cancelled', $refund->get_reason() );
		$this->assertSame( 'refunded', $fresh->get_status() );
		$this->assertSame( 0, Money::to_minor( $fresh->get_remaining_refund_amount() ) );

		// Every item is refunded, so the credit note mirrors the invoice line for line.
		$items = $refund->get_items();
		$this->assertCount( 1, $items );
		$this->assertSame( -2, (int) current( $items )->get_quantity() );

		$statement = CreditNoteStatementBuilder::build( $refund, $fresh, Module::settings() );
		$grand     = array_values( array_filter( $statement->rows, static fn( $row ): bool => $row->grand ) );
		$this->assertSame( $total, $grand[0]->amount );
		$this->assertSame( $total, $statement->sum() );

		$this->assertSame( 0, self::$gateway_calls, 'No money is moved.' );
		$this->assertSame( $stock, $this->stock( $product ), 'No stock is restored.' );
		$this->assertSame( $invoice->number, Register::find( 'invoice', $order->get_id() )->number, 'The invoice never changes.' );
	}

	public function test_cancel_after_partial_refund_credits_the_remainder(): void {
		[ $order ] = $this->cancelled_invoiced_order();
		$item      = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );

		$first = OrderFactory::refund( $order, [ $item->get_id() => OrderFactory::refund_line( $item, 1 ) ], null );
		$this->credit_note( $first );

		$fresh  = wc_get_order( $order->get_id() );
		$left   = Money::to_minor( $fresh->get_remaining_refund_amount() );
		$second = InvoiceCancellation::cancel( $fresh, 'Invoice cancelled' );
		$this->assertInstanceOf( WC_Order_Refund::class, $second );

		$doc = $this->credit_note( $second );
		$this->assertSame( 'issued', $doc->status );
		$this->assertSame( $left, Money::to_minor( $second->get_amount() ) );
		$this->assertSame(
			Money::to_minor( $fresh->get_total() ),
			Money::to_minor( $first->get_amount() ) + Money::to_minor( $second->get_amount() )
		);

		// Only the unit left is refunded.
		$items = $second->get_items();
		$this->assertCount( 1, $items );
		$this->assertSame( -1, (int) current( $items )->get_quantity() );

		$this->assertCount( 2, Register::for_parent( 'credit_note', $order->get_id() ) );
		$this->assertSame( 'nothing_left', InvoiceCancellation::refusal( wc_get_order( $order->get_id() ) ) );
	}

	public function test_cancel_after_amount_refund_goes_by_amount(): void {
		[ $order ] = $this->cancelled_invoiced_order();

		$first = OrderFactory::refund( $order, [], 5.0 );
		$this->credit_note( $first );

		$fresh  = wc_get_order( $order->get_id() );
		$second = InvoiceCancellation::cancel( $fresh, 'Invoice cancelled' );
		$this->assertInstanceOf( WC_Order_Refund::class, $second );
		$doc = $this->credit_note( $second );

		// The items do not say what the amount refund took: the rest goes by amount, never above it.
		$this->assertSame( [], $second->get_items() );
		$this->assertSame( Money::to_minor( $fresh->get_total() ) - 500, Money::to_minor( $second->get_amount() ) );
		$this->assertSame( 'issued', $doc->status );
		$this->assertSame( Money::to_minor( $second->get_amount() ), CreditNoteStatementBuilder::build( $second, $fresh, Module::settings() )->sum() );
	}

	public function test_cancel_refused_for_paid_order(): void {
		$order = $this->invoiced_order();
		$this->assertTrue( $order->is_paid() );

		$this->assertSame( 'paid', InvoiceCancellation::refusal( $order ) );
		$this->assertNull( InvoiceCancellation::cancel( $order, 'Invoice cancelled' ) );
		$this->assertSame( [], $order->get_refunds() );
		$this->assertStringNotContainsString( 'lumia_factures_invoices_cancel_invoice', $this->capture_metabox( $order ) );
	}

	public function test_cancel_refused_without_invoice(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->update_status( 'cancelled' );
		$order = wc_get_order( $order->get_id() );

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( 'no_invoice', InvoiceCancellation::refusal( $order ) );
		$this->assertNull( InvoiceCancellation::cancel( $order, 'Invoice cancelled' ) );
		$this->assertSame( [], wc_get_order( $order->get_id() )->get_refunds() );
	}

	public function test_cancel_refused_when_nothing_left(): void {
		[ $order ] = $this->cancelled_invoiced_order();
		$this->credit_note( OrderFactory::refund( $order, [], (float) $order->get_total() ) );
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 'nothing_left', InvoiceCancellation::refusal( $order ) );
		$this->assertNull( InvoiceCancellation::cancel( $order, 'Invoice cancelled' ) );
		$this->assertCount( 1, $order->get_refunds() );
	}

	public function test_cancel_refused_while_the_import_blocks_issuing(): void {
		[ $order ] = $this->cancelled_invoiced_order();
		update_option( Migration::OPTION, [ 'status' => 'pending' ] );

		try {
			$this->assertSame( 'import', InvoiceCancellation::refusal( $order ) );
			$this->assertNull( InvoiceCancellation::cancel( $order, 'Invoice cancelled' ) );
		} finally {
			delete_option( Migration::OPTION );
		}

		$this->assertSame( [], wc_get_order( $order->get_id() )->get_refunds() );
	}

	public function test_cancel_action_requires_nonce_and_capability(): void {
		[ $order ] = $this->cancelled_invoiced_order();
		$id        = $order->get_id();

		$_POST['order_id'] = (string) $id;

		$valid                = wp_create_nonce( 'lumia_factures_invoices_cancel_invoice_' . $id );
		$_POST['_wpnonce']    = $valid;
		$_REQUEST['_wpnonce'] = $valid;
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->assertDies( 403 );

		// Capability, missing, bad or other order's nonce.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		foreach ( [ null, 'badnonce', wp_create_nonce( 'lumia_factures_invoices_cancel_invoice_' . ( $id + 1 ) ) ] as $nonce ) {
			unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
			if ( null !== $nonce ) {
				$_POST['_wpnonce']    = $nonce;
				$_REQUEST['_wpnonce'] = $nonce;
			}
			$this->assertDies( 403 );
		}

		$this->assertSame( [], wc_get_order( $id )->get_refunds() );
		$this->assertSame( [], Register::for_parent( 'credit_note', $id ) );

		// The helper behind the handler, and the button and form of the order screen.
		$html = $this->capture_metabox( wc_get_order( $id ) );
		$this->assertStringContainsString( 'data-modal-form="lumia-factures-cancel-invoice-form"', $html );
		$this->assertStringContainsString( 'No money is moved: this records a full refund and issues a credit note that cancels invoice', $html );

		set_current_screen( 'shop_order' );
		ob_start();
		OrderTools::render_footer();
		$footer = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="lumia-factures-cancel-invoice-form"', $footer );
		$this->assertStringContainsString( 'id="lumia-factures-modal-overlay"', $footer );
		$this->assertStringContainsString( 'value="lumia_factures_invoices_cancel_invoice"', $footer );
		$this->assertStringNotContainsString( 'id="_wpnonce"', $footer );
		preg_match( '/name="_wpnonce" value="([^"]+)"/', $footer, $match );
		$this->assertSame( 1, wp_verify_nonce( $match[1], 'lumia_factures_invoices_cancel_invoice_' . $id ) );

		$this->track_all( $id );
		$this->assertSame( 'invoice_cancelled', OrderTools::cancel_for( $id ) );
		$this->track_all( $id );
	}

	public function test_cancel_twice_issues_one_credit_note(): void {
		[ $order ] = $this->cancelled_invoiced_order();
		$id        = $order->get_id();

		$this->assertSame( 'invoice_cancelled', OrderTools::cancel_for( $id ) );
		$this->track_all( $id );
		$this->assertSame( 'invoice_cancel_refused', OrderTools::cancel_for( $id ) );
		$this->assertNull( InvoiceCancellation::cancel( wc_get_order( $id ), 'Invoice cancelled' ) );

		$this->assertCount( 1, wc_get_order( $id )->get_refunds() );
		$this->assertCount( 1, Register::for_parent( 'credit_note', $id ) );
		$this->assertStringNotContainsString( 'data-modal-form="lumia-factures-cancel-invoice-form"', $this->capture_metabox( wc_get_order( $id ) ) );
	}

	public function test_cancel_waits_while_another_request_holds_the_lock(): void {
		[ $order ] = $this->cancelled_invoiced_order();
		add_option( 'lumia_factures_cancel_lock_' . $order->get_id(), time() . '-other', '', false );

		$this->assertNull( InvoiceCancellation::cancel( $order, 'Invoice cancelled' ) );
		$this->assertSame( [], wc_get_order( $order->get_id() )->get_refunds() );

		// A lock left by a request that died is taken over.
		update_option( 'lumia_factures_cancel_lock_' . $order->get_id(), ( time() - 120 ) . '-dead' );
		$refund = InvoiceCancellation::cancel( wc_get_order( $order->get_id() ), 'Invoice cancelled' );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->credit_note( $refund );
		$this->assertFalse( get_option( 'lumia_factures_cancel_lock_' . $order->get_id() ) );
	}

	/**
	 * Calls the cancel handler and expects it to die with this code.
	 *
	 * @param int $code HTTP code.
	 */
	private function assertDies( int $code ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit assertion style.
		try {
			OrderTools::handle_cancel();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $code, $e->getCode() );
		}
	}

	/**
	 * An order with two units of a stock-managed product, invoiced (processing), then cancelled
	 * unpaid, paid with the counting gateway.
	 *
	 * @return array{0: WC_Order, 1: WC_Product_Simple}
	 */
	private function cancelled_invoiced_order(): array {
		$product = OrderFactory::simple_product( 10.0, '', 'Croquettes' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$product->save();

		$order = OrderFactory::order( [ [ $product, 2 ] ] );
		$order->set_payment_method( 'lf_counting' );
		$order->save();
		$order->update_status( 'processing' );
		$this->track( Register::find( 'invoice', $order->get_id() ) );
		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'cancelled' );

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->is_paid() );
		$this->assertNotNull( Register::find( 'invoice', $order->get_id() ) );

		return [ $order, $product ];
	}

	/**
	 * An order whose status change issued its invoice (paid).
	 */
	private function invoiced_order(): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->update_status( 'processing' );
		$this->track( Register::find( 'invoice', $order->get_id() ) );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Current stock of a product.
	 *
	 * @param WC_Product_Simple $product Product.
	 */
	private function stock( WC_Product_Simple $product ): ?int {
		$fresh = wc_get_product( $product->get_id() );

		return null === $fresh ? null : $fresh->get_stock_quantity();
	}

	/**
	 * The issued credit note of a refund, remembered for tear_down.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 */
	private function credit_note( WC_Order_Refund $refund ): IssuedDocument {
		$doc = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $doc );
		$this->track( $doc );
		$this->assertSame( $doc->id, CreditNoteIssuer::find_for( wc_get_order( $refund->get_id() ) )->id );

		return $doc;
	}

	/**
	 * Remembers every credit note file of an order.
	 *
	 * @param int $order_id Order id.
	 */
	private function track_all( int $order_id ): void {
		foreach ( Register::for_parent( 'credit_note', $order_id ) as $doc ) {
			$this->track( $doc );
		}
	}

	/**
	 * Output of the meta box callback.
	 *
	 * @param WC_Order $order Order.
	 */
	private function capture_metabox( WC_Order $order ): string {
		ob_start();
		OrderTools::render_meta_box( $order );
		return (string) ob_get_clean();
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
