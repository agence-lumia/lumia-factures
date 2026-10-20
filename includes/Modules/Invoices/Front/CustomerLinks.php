<?php
/**
 * The order's documents on the My Account pages and the thank-you page.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Modules\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Module;
use Throwable;
use WC_Order;
use WC_Order_Refund;

/**
 * Two placements, each behind its `customer` setting and the `lumia_factures_show_customer_links` filter:
 *
 * - `account_orders`: an "Invoice" button in the My Account orders list (filter
 *   `woocommerce_my_account_my_orders_actions`), when the invoice is available. Never a credit note.
 * - `order_details`: a "Documents" block after the order table (action
 *   `woocommerce_order_details_after_order_table`), which covers the classic "View order" page, the
 *   classic thank-you page and the Order Confirmation Totals block. `woocommerce_thankyou` is not
 *   hooked: the classic thank-you page prints the same order table, the block would come twice.
 *
 * The block is printed only for the order of the current WooCommerce endpoint (the order tracking
 * page shows the same table without one: nothing there), to a visitor CustomerDownload::can_access()
 * lets in, and once per order and request. Reads only: nothing is ever issued from a page.
 */
final class CustomerLinks {

	/**
	 * Key of the My Account orders action.
	 */
	public const ACTION = 'lumia-factures-invoice';

	/**
	 * Orders whose documents block was printed during this request.
	 *
	 * @var array<int, true>
	 */
	private static array $rendered = [];

	/**
	 * Registers both placements.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_my_account_my_orders_actions', [ self::class, 'add_orders_action' ], 20, 2 );
		add_action( 'woocommerce_order_details_after_order_table', [ self::class, 'render_details' ], 20 );
	}

	/**
	 * Adds the invoice button to a row of the My Account orders list.
	 *
	 * @param mixed $actions Actions of the row, keyed by slug.
	 * @param mixed $order   Order of the row.
	 * @return mixed
	 */
	public static function add_orders_action( $actions, $order ) {
		if ( ! is_array( $actions ) || $order instanceof WC_Order_Refund || ! $order instanceof WC_Order || $order->get_id() <= 0 ) {
			return $actions;
		}

		try {
			if ( ! self::shows( 'account_orders', $order ) || ! CustomerDownload::can_access( $order, null ) ) {
				return $actions;
			}

			$invoice = Register::find( Issuer::TYPE, $order->get_id() );
			if ( null === $invoice || ! Availability::is_available( $invoice ) ) {
				return $actions;
			}

			$actions[ self::ACTION ] = [
				'url'        => CustomerDownload::url( $order ),
				'name'       => __( 'Invoice', 'lumia-factures' ),
				/* translators: %s: invoice number. */
				'aria-label' => sprintf( __( 'Download invoice %s', 'lumia-factures' ), (string) $invoice->number ),
			];
		} catch ( Throwable $e ) {
			self::log( 'Invoice button of order ' . $order->get_id() . ' failed: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
		}

		return $actions;
	}

	/**
	 * Prints the documents block after the order table.
	 *
	 * @param mixed $order Order shown by WooCommerce.
	 */
	public static function render_details( $order ): void {
		if ( $order instanceof WC_Order_Refund || ! $order instanceof WC_Order ) {
			return;
		}

		$order_id = $order->get_id();
		if ( $order_id <= 0 || isset( self::$rendered[ $order_id ] ) ) {
			return;
		}

		$level = ob_get_level();
		try {
			// Only the order of the "View order" or "Order received" endpoint: never the order tracking page.
			if ( OrderContext::current_order_id() !== $order_id ) {
				return;
			}

			if ( ! self::shows( 'order_details', $order ) || ! CustomerDownload::can_access( $order, OrderContext::current_order_key() ) ) {
				return;
			}

			$documents = DocumentList::for_order( $order, false );
			if ( [] === $documents ) {
				return;
			}

			// Buffered: a template that fails half way prints nothing.
			ob_start();
			wc_get_template(
				'order-documents.php',
				[
					'order'     => $order,
					'documents' => $documents,
				],
				'lumia-factures/',
				LUMIA_FACTURES_TEMPLATES_DIR . 'front/'
			);
			$html = (string) ob_get_clean();
		} catch ( Throwable $e ) {
			// Drops the partial output of the template, never a buffer opened by WooCommerce.
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			self::log( 'Documents block of order ' . $order_id . ' failed: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			return;
		}

		self::$rendered[ $order_id ] = true;

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the template.
	}

	/**
	 * Whether a placement shows the order's documents: its `customer` setting (true when the
	 * placement has none), then the filter.
	 *
	 * @param string   $placement `account_orders`, `order_details`, `email` or `shortcode`.
	 * @param WC_Order $order     Order.
	 */
	public static function shows( string $placement, WC_Order $order ): bool {
		$customer = Module::settings()['customer'] ?? [];
		$show     = is_array( $customer ) && array_key_exists( $placement, $customer ) ? (bool) $customer[ $placement ] : true;

		/**
		 * Filters whether the order's document links are shown on a customer surface.
		 *
		 * @param bool     $show      Result of the settings.
		 * @param string   $placement `account_orders`, `order_details`, `email` or `shortcode`.
		 * @param WC_Order $order     Order.
		 */
		return (bool) apply_filters( 'lumia_factures_show_customer_links', $show, $placement, $order );
	}

	/**
	 * Forgets the orders already rendered. Tests only: a request renders an order's block once.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$rendered = [];
	}

	/**
	 * Writes an operational log line.
	 *
	 * @param string $message Message.
	 */
	private static function log( string $message ): void {
		error_log( '[lumia-factures] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
	}
}
