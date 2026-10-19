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
use Lumia\Factures\Tests\Support\TestCase;

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
