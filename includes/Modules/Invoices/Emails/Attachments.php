<?php
/**
 * Invoice PDF attached to WooCommerce emails.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Modules\Invoices\Emails;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Settings\Schema;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Throwable;
use WC_Order;
use WC_Order_Refund;

/**
 * Filter `woocommerce_email_attachments`: adds the archived invoice PDF to the configured emails.
 */
class Attachments {

	/**
	 * Adds the archived PDF to the attachments of a configured email.
	 *
	 * The archived file itself is attached, never a copy. Any failure is logged and the
	 * email leaves with its original attachments.
	 *
	 * @param mixed $attachments Attachments so far.
	 * @param mixed $email_id    WooCommerce email id.
	 * @param mixed $subject     Email object (an order for order emails).
	 * @param mixed $email       The WC_Email instance (unused).
	 * @return array<int|string, string>
	 */
	public static function attach( $attachments, $email_id = '', $subject = null, $email = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$attachments = is_array( $attachments ) ? $attachments : [];

		try {
			if ( $subject instanceof WC_Order_Refund || ! $subject instanceof WC_Order ) {
				return $attachments;
			}

			// The refund email never carries the invoice, even when a 2.0 save stored it in the list:
			// the settings screen no longer offers it there.
			if ( in_array( (string) $email_id, Schema::REFUND_EMAILS, true ) ) {
				return $attachments;
			}

			$enabled = Module::settings()['general']['email_attachments'] ?? [];
			if ( ! is_array( $enabled ) || ! in_array( (string) $email_id, $enabled, true ) ) {
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
					error_log( '[lumia-factures] The archived PDF of invoice ' . $doc->number . ' (' . $doc->file . ') is missing or damaged; it is not attached to the ' . $email_id . ' email. Rebuild it from the order screen.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
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

			$path = Archive::path( $doc );
			if ( null !== $path && ! in_array( $path, $attachments, true ) ) {
				$attachments[] = $path;
			}
		} catch ( Throwable $e ) {
			error_log( '[lumia-factures] Invoice email attachment failed: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
		}

		return $attachments;
	}
}
