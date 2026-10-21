<?php
/**
 * The order's documents on the My Account pages and the thank-you page.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Front\CustomerDownload;
use Lumia\Factures\Modules\Invoices\Front\CustomerLinks;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Refund;
use WP_Block_Type_Registry;

/**
 * The invoice button of the orders list and the documents block of the order pages: only where the
 * visitor may open them, once per order, and never an issue.
 */
class CustomerLinksTest extends TestCase {

	/**
	 * Marker of the documents block.
	 */
	private const BLOCK = 'lumia-factures-order-documents';

	/**
	 * Archived files written by a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

	/**
	 * Temporary theme folder of the override test.
	 */
	private string $theme = '';

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		delete_option( 'lumia_factures_module_invoices' );
		wp_set_current_user( 0 );
		CustomerLinks::reset();
	}

	public function tear_down(): void {
		foreach ( $this->written as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		if ( '' !== $this->theme ) {
			wp_delete_file( $this->theme . '/lumia-factures/order-documents.php' );
			rmdir( $this->theme . '/lumia-factures' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture folder.
			rmdir( $this->theme ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture folder.
			wp_cache_delete( $this->template_cache_key(), 'woocommerce' );
		}
		foreach ( [ 'view-order', 'order-received', 'key' ] as $var ) {
			set_query_var( $var, '' );
		}
		unset( $_GET['key'] );
		CustomerLinks::reset();
		parent::tear_down();
	}

	public function test_orders_action_added_when_invoice_available(): void {
		$owner   = $this->customer();
		$order   = $this->issued_order( $owner );
		$invoice = Register::find( 'invoice', $order->get_id() );
		wp_set_current_user( $owner );

		$actions = wc_get_account_orders_actions( $order );

		$this->assertArrayHasKey( CustomerLinks::ACTION, $actions );
		$this->assertSame( CustomerDownload::url( $order ), $actions[ CustomerLinks::ACTION ]['url'] );
		$this->assertStringContainsString( CustomerDownload::QUERY_VAR . '=' . $order->get_id(), $actions[ CustomerLinks::ACTION ]['url'] );
		$this->assertSame( 'Invoice', $actions[ CustomerLinks::ACTION ]['name'] );
		$this->assertSame( 'Download invoice ' . $invoice->number, $actions[ CustomerLinks::ACTION ]['aria-label'] );
		// Registered at priority 20, after the WooCommerce actions.
		$this->assertSame( 20, has_filter( 'woocommerce_my_account_my_orders_actions', [ CustomerLinks::class, 'add_orders_action' ] ) );

		// The invoice only: a credit note never gets a button.
		$this->refund( $order, 2.0 );
		$after = wc_get_account_orders_actions( wc_get_order( $order->get_id() ) );
		$this->assertSame( $actions[ CustomerLinks::ACTION ], $after[ CustomerLinks::ACTION ] );
		foreach ( $after as $action ) {
			$this->assertStringNotContainsString( CustomerDownload::CREDIT_NOTE_VAR, (string) $action['url'] );
		}
	}

	public function test_orders_action_absent_when_not_issued_damaged_or_setting_off(): void {
		$owner = $this->customer();
		wp_set_current_user( $owner );

		// Not issued.
		$pending = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$pending->set_customer_id( $owner );
		$pending->save();
		$this->assertArrayNotHasKey( CustomerLinks::ACTION, wc_get_account_orders_actions( $pending ) );

		// Damaged archive.
		$damaged = $this->issued_order( $owner );
		file_put_contents( (string) Archive::path( Register::find( 'invoice', $damaged->get_id() ) ), '%PDF damaged' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test corrupts the archive.
		$this->assertArrayNotHasKey( CustomerLinks::ACTION, wc_get_account_orders_actions( $damaged ) );

		// Setting off.
		$order = $this->issued_order( $owner );
		$this->assertArrayHasKey( CustomerLinks::ACTION, wc_get_account_orders_actions( $order ) );
		$this->set_customer( 'account_orders', false );
		$this->assertArrayNotHasKey( CustomerLinks::ACTION, wc_get_account_orders_actions( $order ) );

		// Another customer's order (the filter can run outside the owner's list).
		$this->set_customer( 'account_orders', true );
		wp_set_current_user( $this->customer() );
		$this->assertArrayNotHasKey( CustomerLinks::ACTION, wc_get_account_orders_actions( $order ) );
	}

	public function test_details_block_lists_invoice_and_credit_notes(): void {
		$owner  = $this->customer();
		$order  = $this->issued_order( $owner );
		$first  = $this->refund( $order, 2.0 );
		$second = $this->refund( $order, 3.0 );
		wp_set_current_user( $owner );
		set_query_var( 'view-order', $order->get_id() );

		$html = $this->details( $order );

		$this->assertStringContainsString( self::BLOCK, $html );
		$this->assertStringContainsString( 'Documents', $html );
		$this->assertStringContainsString( 'Invoice ' . Register::find( 'invoice', $order->get_id() )->number, $html );
		$this->assertStringContainsString( 'Credit note ' . Register::find( 'credit_note', $first->get_id() )->number, $html );
		$this->assertStringContainsString( 'Credit note ' . Register::find( 'credit_note', $second->get_id() )->number, $html );
		$this->assertStringContainsString( esc_url( CustomerDownload::url( $order ) ), $html );
		// The owner is let in by the session: no key in the links.
		$this->assertStringNotContainsString( 'key=', $html );
		$this->assertSame( 20, has_action( 'woocommerce_order_details_after_order_table', [ CustomerLinks::class, 'render_details' ] ) );
	}

	public function test_details_block_hidden_for_guest_without_or_with_wrong_key(): void {
		$order = $this->issued_order( $this->customer() );
		set_query_var( 'view-order', $order->get_id() );

		// A guest without the key.
		$this->assertStringNotContainsString( self::BLOCK, $this->details( $order ) );

		// A guest with another order's key.
		$other       = $this->issued_order( 0 );
		$_GET['key'] = $other->get_order_key();
		$this->assertStringNotContainsString( self::BLOCK, $this->details( $order ) );

		// Another customer, without the key.
		unset( $_GET['key'] );
		wp_set_current_user( $this->customer() );
		$this->assertStringNotContainsString( self::BLOCK, $this->details( $order ) );
	}

	public function test_details_block_guest_with_valid_key_on_order_received(): void {
		$order = $this->issued_order( 0 );
		set_query_var( 'order-received', $order->get_id() );
		$_GET['key'] = $order->get_order_key();

		$html = $this->details( $order );

		$this->assertStringContainsString( self::BLOCK, $html );
		// A guest needs the key to open the link.
		$this->assertStringContainsString( esc_url( CustomerDownload::url( $order ) ), $html );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $html );
	}

	public function test_details_block_hidden_on_order_tracking_page(): void {
		$owner = $this->customer();
		$order = $this->issued_order( $owner );
		wp_set_current_user( $owner );

		// The order tracking shortcode prints the order table on a plain page, with no endpoint.
		ob_start();
		wc_get_template(
			'order/tracking.php',
			[
				'order' => $order,
				'notes' => [],
			]
		);
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( self::BLOCK, $html );
		$this->assertStringNotContainsString( self::BLOCK, $this->details( $order ) );

		// The endpoint of another order is not this order's page either.
		set_query_var( 'view-order', $this->issued_order( $owner )->get_id() );
		$this->assertStringNotContainsString( self::BLOCK, $this->details( $order ) );
	}

	public function test_details_block_rendered_once_per_order(): void {
		$owner = $this->customer();
		$order = $this->issued_order( $owner );
		wp_set_current_user( $owner );
		set_query_var( 'view-order', $order->get_id() );

		$html = $this->details( $order ) . $this->details( $order ) . $this->details( wc_get_order( $order->get_id() ) );

		$this->assertSame( 1, substr_count( $html, 'class="woocommerce-order-documents ' . self::BLOCK . '"' ) );
	}

	public function test_no_thankyou_hook_registered(): void {
		global $wp_filter;

		$hook = $wp_filter['woocommerce_thankyou'] ?? null;
		if ( null === $hook ) {
			$this->assertFalse( has_action( 'woocommerce_thankyou', [ CustomerLinks::class, 'render_details' ] ) );
			return;
		}

		foreach ( $hook->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				$class    = is_array( $function ) && isset( $function[0] ) ? ( is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0] ) : '';
				$this->assertStringStartsNotWith( 'Lumia\\Factures\\', $class );
			}
		}
	}

	public function test_render_never_issues(): void {
		$owner = $this->customer();
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_customer_id( $owner );
		$order->save();
		// An eligible order with no invoice yet: a trigger status reached without the status hook.
		remove_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10 );
		$order->set_status( 'processing' );
		$order->save();
		add_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10, 4 );

		wp_set_current_user( $owner );
		set_query_var( 'view-order', $order->get_id() );
		$notes = count( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
		$rows  = $this->register_rows();

		$this->assertStringNotContainsString( self::BLOCK, $this->details( wc_get_order( $order->get_id() ) ) );
		$this->assertArrayNotHasKey( CustomerLinks::ACTION, wc_get_account_orders_actions( wc_get_order( $order->get_id() ) ) );

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( $rows, $this->register_rows() );
		$this->assertSame( $notes, count( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) ) );
	}

	public function test_block_order_confirmation_totals_renders_documents(): void {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'woocommerce/order-confirmation-totals' ) );

		$owner = $this->customer();
		$order = $this->issued_order( $owner );
		$this->refund( $order, 2.0 );
		wp_set_current_user( $owner );
		set_query_var( 'order-received', $order->get_id() );
		$_GET['key'] = $order->get_order_key();

		$html = render_block(
			[
				'blockName'    => 'woocommerce/order-confirmation-totals',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);

		$this->assertStringContainsString( 'wc-block-order-confirmation-totals', $html );
		$this->assertSame( 1, substr_count( $html, 'class="woocommerce-order-documents ' . self::BLOCK . '"' ) );
		$this->assertStringContainsString( 'Invoice ' . Register::find( 'invoice', $order->get_id() )->number, $html );
		$this->assertStringContainsString( 'Credit note ', $html );
	}

	public function test_show_customer_links_filter_can_hide_each_placement(): void {
		$owner = $this->customer();
		$order = $this->issued_order( $owner );
		wp_set_current_user( $owner );
		set_query_var( 'view-order', $order->get_id() );

		$seen   = [];
		$hidden = '';
		add_filter(
			'lumia_factures_show_customer_links',
			static function ( $show, $placement, $subject ) use ( &$seen, &$hidden ) {
				$seen[] = [ $placement, $subject instanceof WC_Order ? $subject->get_id() : 0, $show ];

				return $placement === $hidden ? false : $show;
			},
			10,
			3
		);

		$hidden = 'account_orders';
		$this->assertArrayNotHasKey( CustomerLinks::ACTION, wc_get_account_orders_actions( $order ) );
		$this->assertStringContainsString( self::BLOCK, $this->details( $order ) );

		CustomerLinks::reset();
		$hidden = 'order_details';
		$this->assertArrayHasKey( CustomerLinks::ACTION, wc_get_account_orders_actions( $order ) );
		$this->assertStringNotContainsString( self::BLOCK, $this->details( $order ) );

		$this->assertContains( [ 'account_orders', $order->get_id(), true ], $seen );
		$this->assertContains( [ 'order_details', $order->get_id(), true ], $seen );

		// The setting reaches the filter as its first argument.
		$this->set_customer( 'order_details', false );
		$hidden = '';
		$seen   = [];
		$this->assertStringNotContainsString( self::BLOCK, $this->details( $order ) );
		$this->assertContains( [ 'order_details', $order->get_id(), false ], $seen );
	}

	public function test_template_overridable_from_theme_path(): void {
		$owner = $this->customer();
		$order = $this->issued_order( $owner );
		wp_set_current_user( $owner );
		set_query_var( 'view-order', $order->get_id() );

		$this->theme = untrailingslashit( get_temp_dir() ) . '/lumia-factures-theme-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->theme . '/lumia-factures' );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Theme override fixture.
			$this->theme . '/lumia-factures/order-documents.php',
			'<?php echo "THEME-OVERRIDE:" . count( $documents ) . ":" . esc_html( $documents[0]["number"] );'
		);
		$theme = $this->theme;
		add_filter( 'stylesheet_directory', static fn() => $theme );
		add_filter( 'template_directory', static fn() => $theme );
		wp_cache_delete( $this->template_cache_key(), 'woocommerce' );

		$html = $this->details( $order );

		$this->assertSame( 'THEME-OVERRIDE:1:' . Register::find( 'invoice', $order->get_id() )->number, trim( $html ) );
	}

	/**
	 * Output of the order details hook for an order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function details( WC_Order $order ): string {
		ob_start();
		do_action( 'woocommerce_order_details_after_order_table', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook, fired as the order details template does.

		return (string) ob_get_clean();
	}

	/**
	 * Cache key of the located template in WooCommerce (wc_get_template()).
	 */
	private function template_cache_key(): string {
		return sanitize_key( implode( '-', [ 'template', 'order-documents.php', 'lumia-factures/', LUMIA_FACTURES_TEMPLATES_DIR . 'front/', WC_VERSION ] ) );
	}

	/**
	 * Stores one `customer` setting.
	 *
	 * @param string $key   Setting.
	 * @param bool   $value Value.
	 */
	private function set_customer( string $key, bool $value ): void {
		$stored = get_option( 'lumia_factures_module_invoices', [] );
		$stored = is_array( $stored ) ? $stored : [];

		$stored['customer']         = isset( $stored['customer'] ) && is_array( $stored['customer'] ) ? $stored['customer'] : [];
		$stored['customer'][ $key ] = $value;
		update_option( 'lumia_factures_module_invoices', $stored );
	}

	/**
	 * A customer account.
	 */
	private function customer(): int {
		return (int) self::factory()->user->create( [ 'role' => 'customer' ] );
	}

	/**
	 * An order of a customer (0 for a guest) with an issued, archived invoice.
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
	 * Number of register rows.
	 */
	private function register_rows(): int {
		global $wpdb;

		$table = Installer::documents_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer.
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
