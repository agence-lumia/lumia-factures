<?php
/**
 * Pins the order storage mode the suite runs in, so the legacy run is provably legacy.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Core;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Refund;

/**
 * `npm test` runs on the default storage; `npm run test:legacy` forces the legacy posts storage.
 */
final class StorageModeTest extends TestCase {

	/**
	 * Archived files written by the test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

	private string $log_file = '';

	private string $previous_log = '';

	public function set_up(): void {
		parent::set_up();

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

	/**
	 * The storage in use matches the LUMIA_FACTURES_TEST_LEGACY_ORDERS switch.
	 */
	public function test_storage_mode_matches_the_run(): void {
		$legacy = '1' === getenv( 'LUMIA_FACTURES_TEST_LEGACY_ORDERS' );

		$this->assertSame( ! $legacy, OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	/**
	 * A saved order really lives in the table of the active storage.
	 */
	public function test_an_order_is_stored_where_the_mode_says(): void {
		global $wpdb;

		$order = wc_create_order();
		$order->save();
		$id = $order->get_id();

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->assertSame( 'shop_order', $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$wpdb->prefix}wc_orders WHERE id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test query on a core table.
		} else {
			$this->assertSame( 'shop_order', get_post_type( $id ) );
		}
	}

	/**
	 * A refund gets its credit note, and the refund meta lands in the table of the active storage.
	 */
	public function test_credit_note_issued_in_hpos_and_legacy(): void {
		global $wpdb;

		delete_option( 'lumia_factures_module_invoices' );
		delete_option( Migration::OPTION );

		$order  = $this->invoiced_order();
		$refund = OrderFactory::refund( $order, [], 4.0, 'Storage' );

		$doc = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $doc );
		$this->track( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->assertSame( $order->get_id(), $doc->parent_id );
		$this->assertTrue( Archive::verify( $doc ) );

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->assertSame( 'shop_order_refund', $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$wpdb->prefix}wc_orders WHERE id = %d", $refund->get_id() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test query on a core table.
			$this->assertSame( $doc->number, $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s", $refund->get_id(), CreditNoteIssuer::NUMBER_META ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test query on a core table.
			$this->assertSame( '', get_post_meta( $refund->get_id(), CreditNoteIssuer::NUMBER_META, true ) );
		} else {
			$this->assertSame( 'shop_order_refund', get_post_type( $refund->get_id() ) );
			$this->assertSame( $doc->number, get_post_meta( $refund->get_id(), CreditNoteIssuer::NUMBER_META, true ) );
		}

		$fresh = wc_get_order( $refund->get_id() );
		$this->assertInstanceOf( WC_Order_Refund::class, $fresh );
		$this->assertSame( $doc->id, CreditNoteIssuer::find_for( $fresh )->id );
	}

	/**
	 * The pending and sweep queries (refund type, meta, creation date, refunded amount) answer in
	 * both storages.
	 */
	public function test_pending_refunds_query_works_in_both_storages(): void {
		delete_option( 'lumia_factures_module_invoices' );
		delete_option( Migration::OPTION );

		$order  = $this->invoiced_order();
		$issued = OrderFactory::refund( $order, [], 2.0 );
		$this->track( Register::find( 'credit_note', $issued->get_id() ) );

		// Missed by the hook: no credit note, no flag.
		remove_action( 'woocommerce_order_refunded', [ CreditNoteIssuer::class, 'on_refunded' ], 10 );
		$missed = OrderFactory::refund( wc_get_order( $order->get_id() ), [], 1.5 );
		$zero   = OrderFactory::refund( wc_get_order( $order->get_id() ), [], 0.0 );
		add_action( 'woocommerce_order_refunded', [ CreditNoteIssuer::class, 'on_refunded' ], 10, 2 );

		// Past Issuer::STALE_AFTER: no longer in flight, so the sweep takes it.
		update_option( CreditNoteIssuer::SINCE_OPTION, time() - HOUR_IN_SECONDS );
		delete_option( CreditNoteIssuer::SWEEP_CURSOR_OPTION );
		$missed->set_date_created( time() - Issuer::STALE_AFTER - MINUTE_IN_SECONDS );
		$missed->save();

		update_option( Migration::OPTION, [ 'status' => 'pending' ] );
		$pending = OrderFactory::refund( wc_get_order( $order->get_id() ), [], 3.0 );
		$this->assertSame( 'import', wc_get_order( $pending->get_id() )->get_meta( CreditNoteIssuer::PENDING_META ) );

		$flagged = CreditNoteIssuer::pending_refund_ids( 50 );
		$this->assertContains( $pending->get_id(), $flagged );
		$this->assertNotContains( $issued->get_id(), $flagged );
		$this->assertNotContains( $missed->get_id(), $flagged );
		$this->assertNotContains( $zero->get_id(), $flagged );

		$swept = CreditNoteIssuer::sweep_ids( 50 );
		$this->assertContains( $missed->get_id(), $swept );
		$this->assertContains( $pending->get_id(), $swept );
		$this->assertNotContains( $issued->get_id(), $swept );
		$this->assertNotContains( $zero->get_id(), $swept );
		// Missed refunds first, then the pending ones.
		$this->assertLessThan( array_search( $pending->get_id(), $swept, true ), array_search( $missed->get_id(), $swept, true ) );

		$this->assertSame( [ $pending->get_id() ], array_values( array_intersect( CreditNoteIssuer::pending_refund_ids( 1 ), [ $pending->get_id() ] ) ) );
	}

	/**
	 * Deleting an order for good takes its credited refunds with it in both storages: HPOS deletes
	 * them one by one through `woocommerce_pre_delete_order_refund`, the legacy storage with
	 * wp_delete_post(). The credit notes and their archives stay; nothing is refused or noted.
	 */
	public function test_order_deleted_for_good_takes_its_credited_refunds_in_hpos_and_legacy(): void {
		global $wpdb;

		delete_option( 'lumia_factures_module_invoices' );
		delete_option( Migration::OPTION );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$order  = $this->invoiced_order();
		$first  = OrderFactory::refund( $order, [], 3.0, 'Deleted with its order' );
		$second = OrderFactory::refund( wc_get_order( $order->get_id() ), [], 2.0 );
		$docs   = Register::for_parent( 'credit_note', $order->get_id() );
		$this->assertCount( 2, $docs );
		array_map( [ $this, 'track' ], $docs );

		// A credited refund of another order, which must stay protected.
		$other        = $this->invoiced_order();
		$other_refund = OrderFactory::refund( $other, [], 1.0 );
		$this->track( Register::find( 'credit_note', $other_refund->get_id() ) );

		$this->assertTrue( wc_get_order( $order->get_id() )->delete( true ) );

		$this->assertFalse( wc_get_order( $order->get_id() ) );
		foreach ( [ $first, $second ] as $refund ) {
			$this->assertFalse( wc_get_order( $refund->get_id() ) );
			if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d", $refund->get_id() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test query on a core table.
			} else {
				$this->assertNull( get_post( $refund->get_id() ) );
			}
		}

		// The credit notes stay issued, files untouched.
		foreach ( $docs as $doc ) {
			$kept = Register::find( 'credit_note', $doc->order_id );
			$this->assertNotNull( $kept );
			$this->assertSame( $doc->id, $kept->id );
			$this->assertSame( $doc->number, $kept->number );
			$this->assertSame( $doc->file, $kept->file );
			$this->assertTrue( Archive::verify( $kept ) );
		}

		// Nothing refused: no notice for the manager, no refusal logged; the deletion is logged once.
		ob_start();
		CreditNoteIssuer::render_deletion_notice();
		$this->assertSame( '', ob_get_clean() );

		$log = (string) file_get_contents( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local log file.
		$this->assertStringNotContainsString( 'deletion was refused', $log );
		$this->assertStringNotContainsString( 'was deleted; credit note', $log );
		$this->assertSame( 1, substr_count( $log, sprintf( 'Order %1$d is deleted for good with its refunds; credit notes %2$s, %3$s stay issued.', $order->get_id(), $docs[0]->number, $docs[1]->number ) ) );

		// Another order's credited refund is still refused afterwards.
		$this->assertFalse( wc_get_order( $other_refund->get_id() )->delete( true ) );
		$this->assertInstanceOf( WC_Order_Refund::class, wc_get_order( $other_refund->get_id() ) );
	}

	/**
	 * An order moved to a trigger status, with its issued invoice.
	 */
	private function invoiced_order(): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ), 2 ] ] );
		$order->update_status( 'processing' );

		$invoice = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $invoice );
		$this->track( $invoice );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh );

		return $fresh;
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
