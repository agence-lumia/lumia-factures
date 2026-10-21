<?php
/**
 * Archived PDFs attached to WooCommerce emails: the invoice, or the credit note of a refund.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Modules\Invoices\Emails;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Throwable;
use WC_Order;
use WC_Order_Refund;

/**
 * Filter `woocommerce_email_attachments`: adds the archived invoice PDF to the configured emails,
 * and the archived credit note PDF of the refund to the refund email.
 *
 * Its guards (email list, preview, stored order and refund) are public: EmailLinks applies the same.
 */
class Attachments {

	/**
	 * Adds the archived PDF to the attachments of a configured email.
	 *
	 * The archived file itself is attached, never a copy. The refund email carries the refund's
	 * credit note, never the invoice. Nothing is issued for a WooCommerce email preview, nor for an
	 * order or a refund that is not the stored one. Any failure is logged and the email leaves with
	 * its original attachments.
	 *
	 * @param mixed $attachments Attachments so far.
	 * @param mixed $email_id    WooCommerce email id.
	 * @param mixed $subject     Email object (an order for order emails).
	 * @param mixed $email       The WC_Email instance (its `refund` property for the refund email).
	 * @return array<int|string, string>
	 */
	public static function attach( $attachments, $email_id = '', $subject = null, $email = null ): array {
		$attachments = is_array( $attachments ) ? $attachments : [];

		try {
			if ( $subject instanceof WC_Order_Refund || ! $subject instanceof WC_Order ) {
				return $attachments;
			}

			// WooCommerce renames the refund email while it sends a partial refund.
			$id = EmailIds::normalize( is_string( $email_id ) ? $email_id : '' );

			// The refund email never carries the invoice, even when a 2.0 save stored it in the
			// invoice list: the settings screen no longer offers it there.
			if ( EmailIds::REFUND === $id ) {
				return self::attach_credit_note( $attachments, $subject, $email );
			}

			if ( ! self::enabled( Module::settings()['general']['email_attachments'] ?? [], $id ) ) {
				return $attachments;
			}

			if ( self::is_preview() || ! self::persisted_order( $subject ) ) {
				return $attachments;
			}

			// Idempotent: the email can fire before the status hook.
			$doc = Issuer::issue( $subject );
			if ( null === $doc ) {
				return $attachments;
			}

			if ( ! Archive::verify( $doc ) ) {
				if ( null !== $doc->file ) {
					// A recorded archive that went missing or was altered is never rebuilt behind the
					// admin's back: the meta box "Rebuild missing PDF" button is the explicit way.
					self::log( 'The archived PDF of invoice ' . $doc->number . ' (' . $doc->file . ') is missing or damaged; it is not attached to the ' . $id . ' email. Rebuild it from the order screen.' );
					return $attachments;
				}

				// Never built (rendering or storage failed at issue time): built now, once.
				Issuer::restore( $doc, $subject );
				$doc = Register::find( Issuer::TYPE, $subject->get_id() );
				// A file that still fails verification is never attached.
				if ( null === $doc || ! Archive::verify( $doc ) ) {
					return $attachments;
				}
			}

			$attachments = self::add( $attachments, $doc );
		} catch ( Throwable $e ) {
			self::log( 'Invoice email attachment failed: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
		}

		return $attachments;
	}

	/**
	 * Adds the refund's credit note to the refund email, when the credit note list enables it.
	 *
	 * WooCommerce sends the refund email from `woocommerce_order_partially_refunded` /
	 * `woocommerce_order_fully_refunded`, after the gateway refunded the payment but before
	 * `woocommerce_order_refunded`. CreditNoteIssuer::issue() is idempotent: the hook, and a deferred
	 * or resent email, find the credit note this email issued.
	 *
	 * @param array<int|string, string> $attachments Attachments so far.
	 * @param WC_Order                  $order       The refunded order (the email object).
	 * @param mixed                     $email       The WC_Email instance.
	 * @return array<int|string, string>
	 */
	private static function attach_credit_note( array $attachments, WC_Order $order, $email ): array {
		if ( ! self::enabled( Module::settings()['credit_notes']['email_attachments'] ?? [], EmailIds::REFUND ) ) {
			return $attachments;
		}

		$refund = is_object( $email ) && isset( $email->refund ) ? $email->refund : null;
		if ( ! $refund instanceof WC_Order_Refund ) {
			self::log( 'The refund email of order ' . $order->get_id() . ' names no refund; no credit note is attached.' );
			return $attachments;
		}

		if ( $refund->get_parent_id() !== $order->get_id() ) {
			self::log( 'Refund ' . $refund->get_id() . ' does not belong to order ' . $order->get_id() . '; no credit note is attached to its email.' );
			return $attachments;
		}

		if ( self::is_preview() || ! self::persisted_order( $order ) || ! self::persisted_refund( $refund ) ) {
			return $attachments;
		}

		$doc = CreditNoteIssuer::issue( $refund );
		if ( null === $doc ) {
			return $attachments;
		}

		if ( ! Archive::verify( $doc ) ) {
			if ( null !== $doc->file ) {
				// Same rule as the invoice: a recorded archive is rebuilt only on the admin's request.
				self::log( 'The archived PDF of credit note ' . $doc->number . ' (' . $doc->file . ') is missing or damaged; it is not attached to the refund email. Rebuild it from the order screen.' );
				return $attachments;
			}

			// Never built (rendering or storage failed at issue time): built now, once.
			CreditNoteIssuer::restore( $doc );
			$doc = CreditNoteIssuer::find_for( $refund );
			if ( null === $doc || ! Archive::verify( $doc ) ) {
				return $attachments;
			}
		}

		return self::add( $attachments, $doc );
	}

	/**
	 * True when the stored email list holds the email (stored ids are normalized too).
	 *
	 * @param mixed  $enabled Stored list of email ids.
	 * @param string $id      Canonical email id.
	 */
	public static function enabled( $enabled, string $id ): bool {
		if ( ! is_array( $enabled ) ) {
			return false;
		}

		foreach ( $enabled as $stored ) {
			if ( is_string( $stored ) && EmailIds::normalize( $stored ) === $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * True while WooCommerce renders an email preview (WooCommerce 9.6+), built on a dummy order.
	 */
	public static function is_preview(): bool {
		return (bool) apply_filters( 'woocommerce_is_email_preview', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
	}

	/**
	 * True when the order is the stored one: same id and same order key in the database.
	 *
	 * A dummy order (an email preview, a third-party test email) may carry the id of a real order:
	 * it must never number that order.
	 *
	 * @param WC_Order $order Order given by the email.
	 */
	public static function persisted_order( WC_Order $order ): bool {
		if ( $order->get_id() <= 0 || '' === $order->get_order_key() ) {
			return false;
		}

		$stored = wc_get_order( $order->get_id() );

		return $stored instanceof WC_Order && $stored->get_order_key() === $order->get_order_key();
	}

	/**
	 * True when the refund is the stored one: same id and same refunded order in the database.
	 *
	 * @param WC_Order_Refund $refund Refund given by the email.
	 */
	public static function persisted_refund( WC_Order_Refund $refund ): bool {
		if ( $refund->get_id() <= 0 ) {
			return false;
		}

		$stored = wc_get_order( $refund->get_id() );

		return $stored instanceof WC_Order_Refund && $stored->get_parent_id() === $refund->get_parent_id();
	}

	/**
	 * Appends the document's archived file, once.
	 *
	 * @param array<int|string, string> $attachments Attachments so far.
	 * @param IssuedDocument            $doc         Verified document.
	 * @return array<int|string, string>
	 */
	private static function add( array $attachments, IssuedDocument $doc ): array {
		$path = Archive::path( $doc );
		if ( null !== $path && ! in_array( $path, $attachments, true ) ) {
			$attachments[] = $path;
		}

		return $attachments;
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
