<?php
/**
 * Data purge helpers used by uninstall.php.
 *
 * Nothing here runs unless the site owner opted in with LUMIA_FACTURES_REMOVE_ALL_DATA
 * (the gate lives in uninstall.php). The archive folder `uploads/lumia-factures/` is
 * deliberately never deleted, even with the constant: PDF files must be kept 10 years
 * and only the site owner may remove them by hand.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Order meta purge that works on both order storages.
 */
class Uninstaller {

	/**
	 * Deletes the given meta keys from every order, on legacy storage and on HPOS.
	 *
	 * delete_post_meta_by_key() only reaches wp_postmeta: with HPOS the order meta
	 * lives in {prefix}wc_orders_meta and would survive the purge.
	 *
	 * @param string[] $keys Meta keys.
	 */
	public static function delete_order_meta( array $keys ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off schema lookup on uninstall.
		$has_hpos_meta = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders_meta' ) ) ) === $wpdb->prefix . 'wc_orders_meta';

		foreach ( $keys as $key ) {
			delete_post_meta_by_key( $key );

			if ( $has_hpos_meta ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- HPOS meta table, no WordPress API deletes by key.
				$wpdb->delete( $wpdb->prefix . 'wc_orders_meta', [ 'meta_key' => $key ] );
			}
		}
	}
}
