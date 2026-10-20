<?php
/**
 * Resolves the WooCommerce order of the front-end context.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use WP_Post;

/**
 * Finds the order WooCommerce is showing, reliably.
 *
 * A "My account" page stays the current post in Bricks even when the URL shows the "View order"
 * endpoint, so the id handed over by a builder is never assumed to be an order id: the WooCommerce
 * query vars come first, then the configured endpoint in the request URI, then the subject itself.
 */
final class OrderContext {

	/**
	 * Resolves an order from the subject handed over by the renderer, then from the HTTP context.
	 *
	 * @param mixed $subject Order, post, id or object with an ID, as given by Bricks or WordPress.
	 */
	public static function resolve( mixed $subject = null ): ?WC_Order {
		if ( $subject instanceof WC_Order ) {
			return $subject;
		}

		// On a WooCommerce endpoint the id carried by the URL is the most reliable source. Bricks often
		// hands over the WP_Post of the "My account" page, whose ID may even equal another order's
		// under HPOS: the endpoint context wins.
		$context_id = self::current_order_id();
		if ( $context_id > 0 ) {
			$order = wc_get_order( $context_id );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}

		$candidate_id = self::extract_id( $subject );
		if ( $candidate_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $candidate_id );

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Id of the order carried by the current WooCommerce URL, 0 when there is none.
	 */
	public static function current_order_id(): int {
		$keys = [ 'view-order', 'order-received' ];

		foreach ( $keys as $key ) {
			$value = absint( get_query_var( $key, 0 ) );
			if ( $value > 0 ) {
				return $value;
			}
		}

		global $wp;
		if ( is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ) {
			foreach ( $keys as $key ) {
				if ( ! empty( $wp->query_vars[ $key ] ) ) {
					return absint( $wp->query_vars[ $key ] );
				}
			}
		}

		return self::order_id_from_request_uri();
	}

	/**
	 * Order key of guest pages (notably "order received").
	 */
	public static function current_order_key(): ?string {
		$key = (string) get_query_var( 'key', '' );

		if ( '' === $key && isset( $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce order key, only used for a read access check.
			$key = sanitize_text_field( wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$key = trim( $key );

		return '' !== $key ? $key : null;
	}

	/**
	 * Extracts a possible id from the object handed over by the renderer.
	 *
	 * @param mixed $subject Value given by WordPress or Bricks.
	 */
	private static function extract_id( mixed $subject ): int {
		if ( $subject instanceof WP_Post ) {
			return (int) $subject->ID;
		}

		if ( is_numeric( $subject ) ) {
			return (int) $subject;
		}

		if ( is_object( $subject ) && isset( $subject->ID ) && is_numeric( $subject->ID ) ) {
			return (int) $subject->ID;
		}

		return 0;
	}

	/**
	 * Last resort: reads the id from the endpoint path.
	 *
	 * Covers some Bricks renderings where the query vars are not injected yet. The slugs are not
	 * hard-coded: the endpoints configured in WooCommerce are honoured.
	 */
	private static function order_id_from_request_uri(): int {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return 0;
		}

		$path = (string) wp_parse_url( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		if ( '' === $path ) {
			return 0;
		}

		$endpoints = array_filter(
			[
				(string) get_option( 'woocommerce_myaccount_view_order_endpoint', 'view-order' ),
				(string) get_option( 'woocommerce_checkout_order_received_endpoint', 'order-received' ),
			]
		);

		foreach ( array_unique( $endpoints ) as $endpoint ) {
			$endpoint = trim( $endpoint, '/' );
			if ( '' === $endpoint ) {
				continue;
			}

			if ( preg_match( '~/(?:' . preg_quote( $endpoint, '~' ) . ')/(\d+)(?:/|$)~', $path, $matches ) ) {
				return absint( $matches[1] );
			}
		}

		return 0;
	}
}
