<?php
/**
 * Tests of the order meta purge used by uninstall.php.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Core;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\Uninstaller;
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order_Refund;

/**
 * The purge reaches the order meta on whichever storage the run uses.
 */
final class UninstallerTest extends TestCase {

	/**
	 * The two invoice meta keys are gone, an unrelated key stays.
	 */
	public function test_delete_order_meta_removes_invoice_meta_and_keeps_others(): void {
		$order = wc_create_order();
		$order->update_meta_data( '_lumia_factures_invoice_number', 'F-1' );
		$order->update_meta_data( '_lumia_factures_invoice_date', '2026-10-19' );
		$order->update_meta_data( '_other_plugin_meta', 'keep' );
		$order->save();

		Uninstaller::delete_order_meta( [ '_lumia_factures_invoice_number', '_lumia_factures_invoice_date' ] );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( '', (string) $fresh->get_meta( '_lumia_factures_invoice_number', true, 'edit' ) );
		$this->assertSame( '', (string) $fresh->get_meta( '_lumia_factures_invoice_date', true, 'edit' ) );
		$this->assertSame( 'keep', $fresh->get_meta( '_other_plugin_meta', true, 'edit' ) );
	}

	/**
	 * uninstall.php keeps everything by default; with LUMIA_FACTURES_REMOVE_ALL_DATA it removes the
	 * credit note meta, options and cron event, and never the archived PDF files.
	 *
	 * The constants cannot be undefined: separate process. The DROP TABLE statements (which the
	 * test framework already turns into DROP TEMPORARY TABLE) are turned into no-ops, so the
	 * tables survive for the rest of the suite; they are recorded instead.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_credit_note_meta_option_and_cron_removed_only_with_remove_all_data(): void {
		// The plugin log goes to a file: any output of a separate process fails the test.
		$log_file = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		ini_set( 'error_log', $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation, separate process.

		update_option( 'woocommerce_currency', 'EUR' );
		update_option( CreditNoteIssuer::SINCE_OPTION, time() - HOUR_IN_SECONDS );
		update_option( CreditNoteIssuer::SWEEP_CURSOR_OPTION, [ 'missed' => [ 1, 1 ] ] );
		update_option( 'lumia_factures_invoices_settings_version', 1 );
		CreditNoteIssuer::schedule_sweep();

		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ), 2 ] ] );
		$order->update_status( 'processing' );
		$refund = OrderFactory::refund( wc_get_order( $order->get_id() ), [], 5.0 );
		$doc    = Register::find( CreditNoteIssuer::TYPE, $refund->get_id() );
		$this->assertNotNull( $doc );
		$this->assertSame( 'issued', $doc->status );
		$path = Archive::path( $doc );
		$this->assertNotNull( $path );

		// A pending refund (refused while the import blocked issuing).
		update_option( Migration::OPTION, [ 'status' => 'pending' ] );
		$pending = OrderFactory::refund( wc_get_order( $order->get_id() ), [], 1.0 );
		delete_option( Migration::OPTION );
		$this->assertSame( 'import', wc_get_order( $pending->get_id() )->get_meta( CreditNoteIssuer::PENDING_META ) );

		$keys = Module::get_uninstall_keys();
		foreach ( [ CreditNoteIssuer::SINCE_OPTION, CreditNoteIssuer::SWEEP_CURSOR_OPTION, 'lumia_factures_invoices_settings_version' ] as $option ) {
			$this->assertContains( $option, $keys['options'] );
		}
		foreach ( [ CreditNoteIssuer::NUMBER_META, CreditNoteIssuer::DATE_META, CreditNoteIssuer::PENDING_META ] as $meta ) {
			$this->assertContains( $meta, $keys['meta'] );
		}
		$this->assertContains( CreditNoteIssuer::SWEEP_HOOK, $keys['cron'] );

		$drops  = [];
		$no_ddl = static function ( string $query ) use ( &$drops ): string {
			if ( 1 === preg_match( '/^\s*DROP\s+(TEMPORARY\s+)?TABLE/i', $query ) ) {
				$drops[] = $query;
				return 'SELECT 1';
			}
			return $query;
		};
		add_filter( 'query', $no_ddl );

		define( 'WP_UNINSTALL_PLUGIN', 'lumia-factures/lumia-factures.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.

		try {
			// By default: nothing is removed.
			include LUMIA_FACTURES_PLUGIN_DIR . 'uninstall.php';
			$this->assert_credit_note_data_kept( $refund, $pending, $doc->number );
			$this->assertSame( [], $drops );
			$this->assertTrue( is_file( $path ) );

			// Opted in: the meta, options and cron event go, the archived files stay.
			define( 'LUMIA_FACTURES_REMOVE_ALL_DATA', true );
			include LUMIA_FACTURES_PLUGIN_DIR . 'uninstall.php';
		} finally {
			remove_filter( 'query', $no_ddl );
		}

		wp_cache_flush();
		$fresh = wc_get_order( $refund->get_id() );
		$this->assertInstanceOf( WC_Order_Refund::class, $fresh );
		$this->assertSame( '', (string) $fresh->get_meta( CreditNoteIssuer::NUMBER_META ) );
		$this->assertSame( '', (string) $fresh->get_meta( CreditNoteIssuer::DATE_META ) );
		$this->assertSame( '', (string) wc_get_order( $pending->get_id() )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertFalse( get_option( CreditNoteIssuer::SINCE_OPTION ) );
		$this->assertFalse( get_option( CreditNoteIssuer::SWEEP_CURSOR_OPTION ) );
		$this->assertFalse( get_option( 'lumia_factures_invoices_settings_version' ) );
		$this->assertFalse( wp_next_scheduled( CreditNoteIssuer::SWEEP_HOOK ) );
		$this->assertCount( 1, array_filter( $drops, static fn( string $query ): bool => str_contains( $query, 'lumia_factures_documents' ) ) );
		$this->assertTrue( is_file( $path ) );

		wp_delete_file( $path );
		wp_delete_file( $log_file );
		$invoice = Register::find( 'invoice', $order->get_id() );
		if ( null !== $invoice && null !== Archive::path( $invoice ) ) {
			wp_delete_file( (string) Archive::path( $invoice ) );
		}
	}

	/**
	 * The credit note meta, options, cron event and register row are all still there.
	 *
	 * @param WC_Order_Refund $refund  Refund with a credit note.
	 * @param WC_Order_Refund $pending Pending refund.
	 * @param string|null     $number  Its credit note number.
	 */
	private function assert_credit_note_data_kept( WC_Order_Refund $refund, WC_Order_Refund $pending, ?string $number ): void {
		wp_cache_flush();
		$fresh = wc_get_order( $refund->get_id() );
		$this->assertInstanceOf( WC_Order_Refund::class, $fresh );
		$this->assertSame( (string) $number, (string) $fresh->get_meta( CreditNoteIssuer::NUMBER_META ) );
		$this->assertNotSame( '', (string) $fresh->get_meta( CreditNoteIssuer::DATE_META ) );
		$this->assertSame( 'import', (string) wc_get_order( $pending->get_id() )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertNotFalse( get_option( CreditNoteIssuer::SINCE_OPTION ) );
		$this->assertNotFalse( get_option( CreditNoteIssuer::SWEEP_CURSOR_OPTION ) );
		$this->assertNotFalse( get_option( 'lumia_factures_invoices_settings_version' ) );
		$this->assertNotFalse( wp_next_scheduled( CreditNoteIssuer::SWEEP_HOOK ) );
		$this->assertSame( $number, Register::find( CreditNoteIssuer::TYPE, $refund->get_id() )?->number );
	}

	/**
	 * An empty key list is a no-op.
	 */
	public function test_delete_order_meta_with_no_keys_deletes_nothing(): void {
		$order = wc_create_order();
		$order->update_meta_data( '_lumia_factures_invoice_number', 'F-2' );
		$order->save();

		Uninstaller::delete_order_meta( [] );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( 'F-2', $fresh->get_meta( '_lumia_factures_invoice_number', true, 'edit' ) );
	}
}
