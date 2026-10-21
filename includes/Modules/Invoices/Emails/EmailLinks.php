<?php
/**
 * Links to the order's documents in customer e-mails.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Modules\Invoices\Emails;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Front\DocumentList;
use Lumia\Factures\Modules\Invoices\Module;
use Throwable;
use WC_Email;
use WC_Order;
use WC_Order_Refund;

/**
 * Action `woocommerce_email_order_meta`: prints the links to the invoice and the credit notes of the
 * order in the configured customer e-mails (setting `customer.email_links`).
 *
 * WooCommerce renders the body before it asks for the attachments, so the e-mail may be the first to
 * need the documents: the invoice (and, for the refund e-mail, the refund's credit note) is issued
 * here, through the same idempotent calls as Attachments, and both find the same number. Nothing is
 * issued for an admin e-mail, a WooCommerce preview, or an order or refund that is not the stored
 * one. Any failure is logged and the e-mail always leaves.
 */
final class EmailLinks {

	/**
	 * E-mails that carry the links when the setting is not stored yet.
	 */
	public const DEFAULT_EMAILS = [ 'customer_processing_order', 'customer_completed_order', 'customer_invoice', EmailIds::REFUND ];

	/**
	 * Prints the documents block.
	 *
	 * @param mixed $order         Email object (an order for order emails).
	 * @param mixed $sent_to_admin True for an e-mail sent to the shop.
	 * @param mixed $plain_text    True for the plain text version.
	 * @param mixed $email         The WC_Email instance.
	 */
	public static function render( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( $sent_to_admin || ! $email instanceof WC_Email ) {
			return;
		}

		if ( $order instanceof WC_Order_Refund || ! $order instanceof WC_Order ) {
			return;
		}

		try {
			// WooCommerce renames the refund email while it sends a partial refund.
			$id = EmailIds::normalize( is_string( $email->id ) ? $email->id : '' );
			if ( ! self::shows( $id, $order ) ) {
				return;
			}

			if ( Attachments::is_preview() || ! Attachments::persisted_order( $order ) ) {
				return;
			}
		} catch ( Throwable $e ) {
			self::log( 'Document links of order ' . $order->get_id() . ' failed: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			return;
		}

		$refund         = null;
		$only_refund_id = null;
		if ( EmailIds::REFUND === $id ) {
			// The refund e-mail lists the invoice and this refund's credit note only.
			$refund         = self::refund( $email, $order );
			$only_refund_id = null !== $refund ? $refund->get_id() : 0;
		}

		// Idempotent: the e-mail can come before the status and refund hooks.
		try {
			Issuer::issue( $order );
			if ( null !== $refund ) {
				CreditNoteIssuer::issue( $refund );
			}
		} catch ( Throwable $e ) {
			self::log( 'Documents of order ' . $order->get_id() . ' could not be issued for the ' . $id . ' email: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
		}

		$level = ob_get_level();
		try {
			$fresh = wc_get_order( $order->get_id() );
			$list  = DocumentList::for_order( $fresh instanceof WC_Order ? $fresh : $order, true, $only_refund_id );
			if ( [] === $list ) {
				return;
			}

			// Buffered: a template that fails half way prints nothing.
			ob_start();
			wc_get_template(
				$plain_text ? 'emails/plain/order-documents.php' : 'emails/order-documents.php',
				[
					'order'     => $order,
					'documents' => $list,
					'email'     => $email,
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
			self::log( 'Document links of order ' . $order->get_id() . ' failed: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			return;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the template.
	}

	/**
	 * Whether the e-mail carries the links: its id is in `customer.email_links`, then the filter.
	 *
	 * @param string   $id    Canonical email id.
	 * @param WC_Order $order Order.
	 */
	private static function shows( string $id, WC_Order $order ): bool {
		$customer = Module::settings()['customer'] ?? [];
		$enabled  = is_array( $customer ) && array_key_exists( 'email_links', $customer ) ? $customer['email_links'] : self::DEFAULT_EMAILS;
		$show     = Attachments::enabled( $enabled, $id );

		/**
		 * Filters whether the order's document links are shown on a customer surface.
		 *
		 * @param bool     $show      Result of the settings.
		 * @param string   $placement `account_orders`, `order_details`, `email` or `shortcode`.
		 * @param WC_Order $order     Order.
		 */
		return (bool) apply_filters( 'lumia_factures_show_customer_links', $show, 'email', $order );
	}

	/**
	 * The refund of the refund e-mail, when it is the stored refund of this order.
	 *
	 * @param WC_Email $email The refund e-mail.
	 * @param WC_Order $order The refunded order.
	 */
	private static function refund( WC_Email $email, WC_Order $order ): ?WC_Order_Refund {
		$refund = isset( $email->refund ) ? $email->refund : null;
		if ( ! $refund instanceof WC_Order_Refund ) {
			return null;
		}

		if ( $refund->get_parent_id() !== $order->get_id() || ! Attachments::persisted_refund( $refund ) ) {
			return null;
		}

		return $refund;
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
