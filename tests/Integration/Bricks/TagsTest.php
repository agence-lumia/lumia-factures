<?php
/**
 * Bricks dynamic tags and their Facture PDF Lumia aliases.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Bricks;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The hooks under test are Bricks'.

use Lumia\Factures\Modules\Bricks\Tags;
use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Front\CustomerDownload;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Refund;

/**
 * The tags are rendered through the Bricks filters, called directly: Bricks itself is not needed.
 */
class TagsTest extends TestCase {

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
		Tags::register();
	}

	public function tear_down(): void {
		foreach ( $this->written as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		unset( $_GET['key'] );
		parent::tear_down();
	}

	public function test_tags_render_for_order_context(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$this->assertSame( (string) $doc->number, apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_number}', $order, 'text' ) );
		$this->assertSame( (string) $doc->number, apply_filters( 'bricks/dynamic_data/render_tag', 'lumia_factures_invoice_number', $order, 'text' ) );
		$this->assertSame( 'available', apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_status}', $order, 'text' ) );
		$this->assertStringContainsString( 'lumia_factures_invoice=' . $order->get_id(), apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_url}', $order, 'text' ) );

		// Inside a larger content, through both content filters.
		$html     = '<a href="{lumia_factures_invoice_url}">N {lumia_factures_invoice_number}</a>';
		$expected = '<a href="' . CustomerDownload::url( $order ) . '">N ' . $doc->number . '</a>';
		$this->assertSame( $expected, apply_filters( 'bricks/dynamic_data/render_content', $html, $order, 'text' ) );
		$this->assertSame( $expected, apply_filters( 'bricks/frontend/render_data', $html, $order ) );

		// A tag that is not ours, and structured values, go through untouched.
		$this->assertSame( '{post_title}', apply_filters( 'bricks/dynamic_data/render_tag', '{post_title}', $order, 'text' ) );
		$this->assertSame( [ 'a' => 1 ], apply_filters( 'bricks/dynamic_data/render_tag', [ 'a' => 1 ], $order, 'text' ) );
		$this->assertSame( 'no tag', apply_filters( 'bricks/dynamic_data/render_content', 'no tag', $order, 'text' ) );

		// The picker lists our names, in their group, and not the legacy ones.
		$list  = apply_filters( 'bricks/dynamic_tags_list', [ [ 'name' => '{post_title}' ] ] );
		$names = wp_list_pluck( $list, 'name' );
		$this->assertSame(
			[
				'{post_title}',
				'{lumia_factures_invoice_url}',
				'{lumia_factures_invoice_number}',
				'{lumia_factures_invoice_status}',
				'{lumia_factures_credit_note_url}',
				'{lumia_factures_credit_note_number}',
				'{lumia_factures_credit_note_status}',
				'{lumia_factures_documents}',
			],
			$names
		);
		$this->assertSame( 'Lümia Factures', $list[1]['group'] );
	}

	public function test_url_inside_a_content_is_escaped(): void {
		$order = $this->issued_order();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$raw    = 'https://example.com/?a=1&b="x"';
		$filter = static fn() => $raw;
		add_filter( 'lumia_factures_customer_download_url', $filter );
		try {
			$html = apply_filters( 'bricks/dynamic_data/render_content', '<a href="{fpl_invoice_url}">PDF</a>', $order, 'text' );
		} finally {
			remove_filter( 'lumia_factures_customer_download_url', $filter );
		}

		$this->assertSame( '<a href="' . esc_url( $raw ) . '">PDF</a>', $html );
		$this->assertStringNotContainsString( '"x"', $html );
	}

	public function test_legacy_aliases_render_same_values(): void {
		$order = $this->issued_order();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		foreach ( [ 'url', 'number', 'status' ] as $suffix ) {
			$this->assertSame(
				apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_' . $suffix . '}', $order, 'text' ),
				apply_filters( 'bricks/dynamic_data/render_tag', '{fpl_invoice_' . $suffix . '}', $order, 'text' )
			);
		}

		$this->assertSame(
			apply_filters( 'bricks/dynamic_data/render_content', '{lumia_factures_invoice_url} {lumia_factures_invoice_status}', $order, 'text' ),
			apply_filters( 'bricks/dynamic_data/render_content', '{fpl_invoice_url} {fpl_invoice_status}', $order, 'text' )
		);
		$this->assertNotSame( '', apply_filters( 'bricks/dynamic_data/render_tag', '{fpl_invoice_url}', $order, 'text' ) );
		$this->assertNotContains( '{fpl_invoice_url}', wp_list_pluck( apply_filters( 'bricks/dynamic_tags_list', [] ), 'name' ) );
	}

	public function test_url_empty_without_access(): void {
		$order = $this->issued_order();

		// A guest without the key.
		foreach ( [ 'url', 'number' ] as $suffix ) {
			$this->assertSame( '', apply_filters( 'bricks/dynamic_data/render_tag', '{fpl_invoice_' . $suffix . '}', $order, 'text' ) );
		}
		$this->assertSame( 'none', apply_filters( 'bricks/dynamic_data/render_tag', '{fpl_invoice_status}', $order, 'text' ), 'A condition on the status hides the element.' );

		// Another customer.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->assertSame( '', apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_url}', $order, 'text' ) );

		// A guest with the key of the page (order received).
		wp_set_current_user( 0 );
		$_GET['key'] = $order->get_order_key();
		$url         = apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_url}', $order, 'text' );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $url );

		// No order in the context.
		$this->assertSame( '', apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_url}', null, 'text' ) );
	}

	public function test_status_values(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$status = static fn( WC_Order $order ) => apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_status}', $order, 'text' );
		$url    = static fn( WC_Order $order ) => apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_url}', $order, 'text' );

		// Not in a trigger status: nothing to issue.
		$pending = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$this->assertSame( 'none', $status( $pending ) );

		// In a trigger status but not issued, here while the legacy import is pending: still none
		// (a link would lead to "Invoice not found"). A front render never notes nor saves the order.
		$saves = 0;
		$count = static function () use ( &$saves ): void {
			++$saves;
		};
		update_option( Migration::OPTION, [ 'status' => 'pending' ] );
		add_action( 'woocommerce_update_order', $count );
		try {
			$pending->set_status( 'processing' );
			$this->assertSame( 'none', $status( $pending ) );
			$this->assertSame( '', $url( $pending ) );
		} finally {
			remove_action( 'woocommerce_update_order', $count );
			delete_option( Migration::OPTION );
		}
		$this->assertSame( 0, $saves );
		$this->assertSame( [], wc_get_order_notes( [ 'order_id' => $pending->get_id() ] ) );
		$this->assertNull( Register::find( 'invoice', $pending->get_id() ) );

		// Same once the import is done: `pending` is never produced.
		$this->assertSame( 'none', $status( $pending ) );

		// Issued: available, even if the order later leaves the trigger statuses.
		$issued = $this->issued_order();
		$this->assertSame( 'available', $status( $issued ) );
		$issued->set_status( 'cancelled' );
		$this->assertSame( 'available', $status( $issued ) );
		$this->assertSame( (string) Register::find( 'invoice', $issued->get_id() )->number, apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_number}', $issued, 'text' ) );

		// Recorded file missing or damaged: never rebuilt for a visitor, so no link.
		wp_delete_file( (string) Archive::path( Register::find( 'invoice', $issued->get_id() ) ) );
		$this->assertSame( 'none', $status( $issued ) );
		$this->assertSame( '', $url( $issued ) );

		// Imported without a PDF (never built): the download endpoint builds it, so it is available.
		$imported = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$this->assertNotNull( Register::import( 'invoice', $imported->get_id(), 'FA-LEGACY-1', 'invoice_legacy', 1, time() ) );
		$this->assertSame( 'available', $status( $imported ) );
		$this->assertStringContainsString( 'lumia_factures_invoice=' . $imported->get_id(), $url( $imported ) );
	}

	public function test_new_tags_resolve_inside_content(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 2.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$html     = '<a href="{lumia_factures_credit_note_url}">{lumia_factures_credit_note_number}</a> {lumia_factures_credit_note_status}';
		$expected = '<a href="' . esc_url( CustomerDownload::credit_note_url( $doc, $order ) ) . '">' . $doc->number . '</a> available';
		$this->assertSame( $expected, apply_filters( 'bricks/dynamic_data/render_content', $html, $order, 'text' ) );
		$this->assertSame( $expected, apply_filters( 'bricks/frontend/render_data', $html, $order ) );

		// The documents tag alone in a content: its name shares no prefix with the invoice tags.
		$list = apply_filters( 'bricks/dynamic_data/render_content', '<div>{lumia_factures_documents}</div>', $order, 'text' );
		$this->assertStringContainsString( 'lumia-factures-order-documents', $list );
		$this->assertStringNotContainsString( '{lumia_factures_documents}', $list );
		$this->assertSame( $list, apply_filters( 'bricks/frontend/render_data', '<div>{lumia_factures_documents}</div>', $order ) );

		// Unknown names sharing our prefixes stay untouched.
		$this->assertSame( '{lumia_factures_credit_note_foo} {lumia_factures_invoice_foo}', apply_filters( 'bricks/dynamic_data/render_content', '{lumia_factures_credit_note_foo} {lumia_factures_invoice_foo}', $order, 'text' ) );
		$this->assertSame( '{lumia_factures_credit_note_foo}', apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_credit_note_foo}', $order, 'text' ) );
	}

	public function test_credit_note_tags_resolve_latest_available_credit_note(): void {
		$order  = $this->issued_order();
		$first  = Register::find( 'credit_note', $this->refund( $order, 2.0 )->get_id() );
		$second = Register::find( 'credit_note', $this->refund( $order, 3.0 )->get_id() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$tag = static fn( string $name ) => apply_filters( 'bricks/dynamic_data/render_tag', '{' . $name . '}', $order, 'text' );

		$this->assertSame( (string) $second->number, $tag( 'lumia_factures_credit_note_number' ) );
		$this->assertSame( CustomerDownload::credit_note_url( $second, $order ), $tag( 'lumia_factures_credit_note_url' ) );
		$this->assertSame( 'available', $tag( 'lumia_factures_credit_note_status' ) );

		// The latest one's archive is damaged: never offered, the previous one is.
		wp_delete_file( (string) Archive::path( $second ) );
		$this->assertSame( (string) $first->number, $tag( 'lumia_factures_credit_note_number' ) );
		$this->assertSame( CustomerDownload::credit_note_url( $first, $order ), $tag( 'lumia_factures_credit_note_url' ) );
		$this->assertSame( 'available', $tag( 'lumia_factures_credit_note_status' ) );

		// The invoice tags are not affected by the credit notes.
		$this->assertSame( (string) Register::find( 'invoice', $order->get_id() )->number, $tag( 'lumia_factures_invoice_number' ) );
	}

	public function test_credit_note_status_none_when_no_credit_note(): void {
		$order = $this->issued_order();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$this->assertSame( 'none', apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_credit_note_status}', $order, 'text' ) );
		$this->assertSame( '', apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_credit_note_url}', $order, 'text' ) );
		$this->assertSame( '', apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_credit_note_number}', $order, 'text' ) );
		$this->assertSame( 'none', apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_credit_note_status}', null, 'text' ), 'No order in the context.' );
	}

	public function test_documents_tag_renders_list_html(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 2.0 );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$html = apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_documents}', $order, 'text' );

		$this->assertStringContainsString( 'lumia-factures-order-documents', $html );
		$this->assertStringContainsString( 'Invoice ' . Register::find( 'invoice', $order->get_id() )->number, $html );
		$this->assertStringContainsString( 'Credit note ' . Register::find( 'credit_note', $refund->get_id() )->number, $html );
		$this->assertStringContainsString( esc_url( CustomerDownload::url( $order ) ), $html );

		// Nothing issued: empty, never an empty table.
		$this->assertSame( '', apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_documents}', OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] ), 'text' ) );
	}

	public function test_new_tags_do_not_write(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		// An eligible order with no invoice yet: a trigger status reached without the status hook.
		remove_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10 );
		$order->set_status( 'processing' );
		$order->save();
		add_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10, 4 );
		$order = wc_get_order( $order->get_id() );

		$saves    = 0;
		$count    = static function () use ( &$saves ): void {
			++$saves;
		};
		$notes    = count( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
		$modified = (string) $order->get_date_modified();
		$rows     = $this->register_rows();

		add_action( 'woocommerce_update_order', $count );
		try {
			foreach ( [ 'pending', 'done' ] as $migration ) {
				update_option( Migration::OPTION, [ 'status' => $migration ] );
				$content = apply_filters( 'bricks/dynamic_data/render_content', '{lumia_factures_credit_note_url}{lumia_factures_credit_note_number}{lumia_factures_credit_note_status}{lumia_factures_documents}', $order, 'text' );
				$this->assertSame( 'none', $content );
				foreach ( [ 'credit_note_url', 'credit_note_number', 'credit_note_status', 'documents' ] as $suffix ) {
					apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_' . $suffix . '}', $order, 'text' );
				}
			}
		} finally {
			remove_action( 'woocommerce_update_order', $count );
			delete_option( Migration::OPTION );
		}

		$this->assertSame( 0, $saves );
		$this->assertSame( $notes, count( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) ) );
		$this->assertSame( $modified, (string) wc_get_order( $order->get_id() )->get_date_modified() );
		$this->assertSame( $rows, $this->register_rows() );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
	}

	public function test_credit_note_tags_hidden_without_access(): void {
		$order = $this->issued_order();
		$this->refund( $order, 2.0 );
		$tag = static fn( string $name ) => apply_filters( 'bricks/dynamic_data/render_tag', '{' . $name . '}', $order, 'text' );

		// A guest without the key, then another customer.
		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'customer' ] ) ] as $user ) {
			wp_set_current_user( $user );
			$this->assertSame( '', $tag( 'lumia_factures_credit_note_url' ) );
			$this->assertSame( '', $tag( 'lumia_factures_credit_note_number' ) );
			$this->assertSame( 'none', $tag( 'lumia_factures_credit_note_status' ) );
			$this->assertSame( '', $tag( 'lumia_factures_documents' ) );
		}

		// The credit note filter alone can refuse them, the invoice tags staying.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$deny = static fn( $can, $subject, $key, $type ) => 'credit_note' === $type ? false : $can;
		add_filter( 'lumia_factures_can_access_document', $deny, 10, 4 );
		try {
			$this->assertSame( 'none', $tag( 'lumia_factures_credit_note_status' ) );
			$this->assertSame( '', $tag( 'lumia_factures_credit_note_url' ) );
			$this->assertSame( 'available', $tag( 'lumia_factures_invoice_status' ) );
			$this->assertStringNotContainsString( 'Credit note', $tag( 'lumia_factures_documents' ) );
			$this->assertStringContainsString( 'Invoice', $tag( 'lumia_factures_documents' ) );
		} finally {
			remove_filter( 'lumia_factures_can_access_document', $deny, 10 );
		}

		// A guest with the key of the page.
		wp_set_current_user( 0 );
		$_GET['key'] = $order->get_order_key();
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $tag( 'lumia_factures_credit_note_url' ) );
		$this->assertSame( 'available', $tag( 'lumia_factures_credit_note_status' ) );
	}

	public function test_legacy_fpl_tags_unchanged(): void {
		$order = $this->issued_order();
		$this->refund( $order, 2.0 );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$doc = Register::find( 'invoice', $order->get_id() );

		$this->assertSame( CustomerDownload::url( $order ), apply_filters( 'bricks/dynamic_data/render_tag', '{fpl_invoice_url}', $order, 'text' ) );
		$this->assertSame( (string) $doc->number, apply_filters( 'bricks/dynamic_data/render_tag', 'fpl_invoice_number', $order, 'text' ) );
		$this->assertSame( 'available', apply_filters( 'bricks/dynamic_data/render_tag', '{fpl_invoice_status}', $order, 'text' ) );
		$this->assertSame(
			'<a href="' . esc_url( CustomerDownload::url( $order ) ) . '">' . $doc->number . '</a> available',
			apply_filters( 'bricks/dynamic_data/render_content', '<a href="{fpl_invoice_url}">{fpl_invoice_number}</a> {fpl_invoice_status}', $order, 'text' )
		);

		// No new legacy alias: the credit note and documents tags have no `fpl_` name.
		foreach ( [ '{fpl_credit_note_url}', '{fpl_credit_note_number}', '{fpl_credit_note_status}', '{fpl_documents}' ] as $name ) {
			$this->assertSame( $name, apply_filters( 'bricks/dynamic_data/render_tag', $name, $order, 'text' ) );
			$this->assertSame( $name, apply_filters( 'bricks/dynamic_data/render_content', $name, $order, 'text' ) );
		}
		$names = wp_list_pluck( apply_filters( 'bricks/dynamic_tags_list', [] ), 'name' );
		foreach ( $names as $name ) {
			$this->assertStringStartsWith( '{lumia_factures_', $name );
		}
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
		if ( null !== $doc->file ) {
			$this->written[] = Archive::root() . '/' . $doc->file;
		}

		return $refund;
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
	 * An order with an issued, archived invoice.
	 */
	private function issued_order(): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->update_status( 'processing' );
		$doc = Register::find( 'invoice', $order->get_id() );
		if ( null !== $doc && null !== $doc->file ) {
			$this->written[] = Archive::root() . '/' . $doc->file;
		}

		return wc_get_order( $order->get_id() );
	}
}
