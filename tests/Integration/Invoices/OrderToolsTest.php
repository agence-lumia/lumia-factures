<?php
/**
 * Order screen tools: meta box, list column, admin download, issue and restore actions.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Admin\Download;
use Lumia\Factures\Modules\Invoices\Admin\OrderTools;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WPDieException;

/**
 * Order tools: capability, archive path, issue/restore helpers, columns, meta box.
 */
class OrderToolsTest extends TestCase {

	/**
	 * Archived files written by the test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		delete_option( 'lumia_factures_module_invoices' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
	}

	public function tear_down(): void {
		foreach ( $this->written as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		parent::tear_down();
	}

	public function test_file_for_requires_capability(): void {
		$order = $this->issued_order();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );

		$this->expectException( WPDieException::class );
		Download::file_for( $order->get_id() );
	}

	public function test_file_for_returns_archived_path(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );

		$this->assertSame( Archive::path( $doc ), Download::file_for( $order->get_id() ) );
	}

	public function test_file_for_unissued_order_is_404(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		try {
			Download::file_for( $order->get_id() );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 404, $e->getCode() );
		}
	}

	public function test_file_for_rebuilds_missing_archive(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );
		$old   = (string) Archive::path( $doc );
		wp_delete_file( $old );
		$this->assertFalse( Archive::verify( $doc ) );

		$path = Download::file_for( $order->get_id() );
		$new  = Register::find( 'invoice', $order->get_id() );
		$this->track( $new );

		$this->assertNotSame( $old, $path );
		$this->assertTrue( Archive::verify( $new ) );
		$this->assertSame( $doc->number, $new->number );
		$this->assertSame( $doc->issued_at, $new->issued_at );
	}

	public function test_issue_action_issues_invoice(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_status( 'processing' );
		remove_action( 'woocommerce_order_status_changed', [ \Lumia\Factures\Modules\Invoices\Documents\Issuer::class, 'on_status_changed' ], 10 );
		$order->save();
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );

		$this->assertSame( 'invoice_issued', OrderTools::issue_for( $order->get_id() ) );
		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $doc );
		$this->track( $doc );
		$this->assertSame( 'issued', $doc->status );

		$this->assertSame( 'invoice_not_issued', OrderTools::issue_for( 0 ) );
	}

	public function test_issue_action_refused_when_status_does_not_allow_it(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_status( 'pending' );
		$order->save();

		$this->assertSame( 'invoice_not_issued', OrderTools::issue_for( $order->get_id() ) );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
	}

	public function test_restore_action_refuses_intact_file(): void {
		$order = $this->issued_order();

		$this->assertSame( 'invoice_restore_refused', OrderTools::restore_for( $order->get_id() ) );
	}

	public function test_restore_action_rebuilds_missing_file(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );
		wp_delete_file( (string) Archive::path( $doc ) );

		$this->assertSame( 'invoice_restored', OrderTools::restore_for( $order->get_id() ) );
		$new = Register::find( 'invoice', $order->get_id() );
		$this->track( $new );
		$this->assertTrue( Archive::verify( $new ) );
	}

	public function test_download_url_carries_action_and_nonce(): void {
		$url = Download::url( 42 );

		$this->assertStringContainsString( 'action=lumia_factures_invoices_download', $url );
		$this->assertStringContainsString( 'order_id=42', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}

	public function test_column_added_on_hpos_and_legacy_screens(): void {
		OrderTools::register();
		$columns = [
			'cb'           => '',
			'order_status' => 'Status',
			'order_total'  => 'Total',
		];

		foreach ( [ 'manage_woocommerce_page_wc-orders_columns', 'manage_edit-shop_order_columns' ] as $hook ) {
			$result = apply_filters( $hook, $columns ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core WooCommerce hook names.
			$this->assertSame( [ 'cb', 'order_status', 'lumia_factures_invoice', 'order_total' ], array_keys( $result ), $hook );
		}
	}

	public function test_column_renders_linked_number_or_dash(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );

		ob_start();
		OrderTools::render_column( 'lumia_factures_invoice', $order );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( (string) $doc->number, $html );
		$this->assertStringContainsString( 'target="_blank" rel="noopener"', $html );
		$this->assertStringContainsString( 'lumia_factures_invoices_download', $html );

		$other = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		ob_start();
		OrderTools::render_column( 'lumia_factures_invoice', $other->get_id() );
		$this->assertSame( '&#8212;', ob_get_clean() );
	}

	public function test_metabox_shows_number_when_issued(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );

		$html = $this->capture_metabox( $order );

		$this->assertStringContainsString( (string) $doc->number, $html );
		$this->assertStringContainsString( 'lumia_factures_invoices_download', $html );
		$this->assertStringNotContainsString( 'lumia_factures_invoices_restore', $html );

		wp_delete_file( (string) Archive::path( $doc ) );
		$this->assertStringContainsString( 'lumia_factures_invoices_restore', $this->capture_metabox( $order ) );
	}

	public function test_metabox_offers_issue_or_explains_status(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_status( 'processing' );
		remove_action( 'woocommerce_order_status_changed', [ \Lumia\Factures\Modules\Invoices\Documents\Issuer::class, 'on_status_changed' ], 10 );
		$order->save();
		$this->assertStringContainsString( 'lumia_factures_invoices_issue', $this->capture_metabox( $order ) );

		$order->set_status( 'pending' );
		$order->save();
		$html = $this->capture_metabox( wc_get_order( $order->get_id() ) );
		$this->assertStringNotContainsString( 'lumia_factures_invoices_issue', $html );
		$this->assertStringContainsString( 'does not allow an invoice', $html );
	}

	public function test_notice_is_shown_only_on_order_screen_for_known_code(): void {
		$_GET['lumia_factures_notice']      = 'invoice_issued';
		$_GET['lumia_factures_notice_type'] = 'success';

		set_current_screen( 'dashboard' );
		ob_start();
		OrderTools::render_notice();
		$this->assertSame( '', ob_get_clean() );

		set_current_screen( 'shop_order' );
		ob_start();
		OrderTools::render_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice notice-success is-dismissible', $html );

		$_GET['lumia_factures_notice'] = 'bogus';
		ob_start();
		OrderTools::render_notice();
		$this->assertSame( '', ob_get_clean() );

		unset( $_GET['lumia_factures_notice'], $_GET['lumia_factures_notice_type'] );
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
	 * An order whose status change issued its invoice.
	 */
	private function issued_order(): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->update_status( 'processing' );
		$this->track( Register::find( 'invoice', $order->get_id() ) );

		return wc_get_order( $order->get_id() );
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
