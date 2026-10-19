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
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * `npm test` runs on the default storage; `npm run test:legacy` forces the legacy posts storage.
 */
final class StorageModeTest extends TestCase {

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
}
