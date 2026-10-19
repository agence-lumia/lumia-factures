<?php
/**
 * Issues invoices: number, PDF, archive.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Documents;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\NumberFormat;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Rendering\DocumentData;
use Lumia\Factures\Modules\Invoices\Rendering\Renderer;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Throwable;
use WC_Order;

/**
 * One order, one gapless number, one write-once archived PDF.
 *
 * The register row is reserved first (unique per order), the counter is consumed only
 * then, right before the number is recorded: a second call for the same order never
 * reaches the counter. A counter value taken but not recorded is logged and noted on
 * the order, so a gap is always visible.
 */
final class Issuer {

	/**
	 * Document type of an invoice.
	 */
	public const TYPE = 'invoice';

	/**
	 * Hook `woocommerce_order_status_changed`: issues the invoice when the new status is a trigger.
	 *
	 * An invoice failure never breaks the status change: everything is caught and logged.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 * @param mixed  $order    Order (WooCommerce passes it since 3.x).
	 */
	public static function on_status_changed( $order_id, $from, $to, $order = null ): void {
		try {
			if ( ! $order instanceof WC_Order ) {
				$order = wc_get_order( (int) $order_id );
			}

			if ( $order instanceof WC_Order ) {
				self::issue( $order );
			}
		} catch ( Throwable $e ) {
			self::log( 'Invoice issuing failed for order ' . (int) $order_id . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
		}
	}

	/**
	 * Whether the order's status triggers an invoice.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function can_issue( WC_Order $order ): bool {
		$general  = Module::settings()['general'] ?? [];
		$statuses = isset( $general['trigger_statuses'] ) && is_array( $general['trigger_statuses'] ) ? $general['trigger_statuses'] : [];
		$can      = in_array( $order->get_status(), $statuses, true );

		/**
		 * Filters whether an invoice may be issued for the order.
		 *
		 * @param bool     $can   True when the order status is a trigger status.
		 * @param WC_Order $order Order.
		 */
		return (bool) apply_filters( 'lumia_factures_can_issue', $can, $order );
	}

	/**
	 * Issues the order's invoice. Idempotent: an issued invoice is returned as is.
	 *
	 * @param WC_Order $order Order.
	 * @return IssuedDocument|null The issued document, null when not issued (not a trigger
	 *                             status, being issued by another call, or failure).
	 */
	public static function issue( WC_Order $order ): ?IssuedDocument {
		$order_id = $order->get_id();
		if ( $order_id <= 0 ) {
			return null;
		}

		$existing = Register::find( self::TYPE, $order_id );
		if ( null !== $existing && 'issued' === $existing->status ) {
			return $existing;
		}

		if ( ! self::can_issue( $order ) ) {
			return null;
		}

		$id = Register::reserve( self::TYPE, $order_id );
		if ( null === $id ) {
			// Another call holds the row: return its invoice once issued, never number twice.
			$current = Register::find( self::TYPE, $order_id );
			return null !== $current && 'issued' === $current->status ? $current : null;
		}

		$settings  = Module::settings();
		$numbering = $settings['numbering'];
		$now       = time();
		$key       = Sequences::key( self::TYPE, $now, $numbering );
		$sequence  = Sequences::next( $key, (int) ( $numbering['start_number'] ?? 1 ) );

		if ( 0 === $sequence ) {
			Register::release( $id );
			self::log( 'Invoice of order ' . $order_id . ' could not be numbered: no value from the sequence ' . $key . '.' );
			$order->add_order_note( __( 'Invoice could not be numbered.', 'lumia-factures' ) );
			return null;
		}

		$number = NumberFormat::build( (string) $numbering['format'], $sequence, $order, $now );

		if ( ! Register::assign( $id, $number, $key, $sequence, $now ) ) {
			Register::release( $id );
			self::log( 'Invoice number ' . $number . ' (sequence ' . $key . ' = ' . $sequence . ') could not be recorded for order ' . $order_id . '; this sequence value is skipped.' );
			$order->add_order_note(
				sprintf(
					/* translators: 1: invoice number, 2: sequence value */
					__( 'Invoice number %1$s (sequence %2$d) could not be recorded; this sequence value is skipped.', 'lumia-factures' ),
					$number,
					$sequence
				)
			);
			return null;
		}

		$order->update_meta_data( '_lumia_factures_invoice_number', $number );
		$order->update_meta_data( '_lumia_factures_invoice_date', (string) $now );
		$order->save();

		$doc = Register::find( self::TYPE, $order_id );
		if ( null === $doc ) {
			self::log( 'Invoice ' . $number . ' of order ' . $order_id . ' vanished from the register after numbering.' );
			return null;
		}

		// Rendered from a fresh order: the one in hand may predate the number (legacy bug C2).
		$fresh  = wc_get_order( $order_id );
		$fresh  = $fresh instanceof WC_Order ? $fresh : $order;
		$stored = self::render_and_store( $doc, $fresh, $settings );

		if ( null !== $stored && Register::attach_file( $doc->id, $stored['file'], $stored['checksum'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: invoice number */
					__( 'Invoice %s issued.', 'lumia-factures' ),
					$number
				)
			);
		} else {
			if ( null !== $stored ) {
				self::log( 'Archived PDF ' . $stored['file'] . ' of invoice ' . $number . ' could not be recorded in the register.' );
			}
			$order->add_order_note(
				sprintf(
					/* translators: %s: invoice number */
					__( 'Invoice %s issued. Its PDF could not be generated; the PDF will be built on first download.', 'lumia-factures' ),
					$number
				)
			);
		}

		$doc = Register::find( self::TYPE, $order_id ) ?? $doc;

		/**
		 * Fires once, when an invoice has been numbered (its PDF may be missing if rendering failed).
		 *
		 * @param IssuedDocument $doc   Issued document.
		 * @param WC_Order       $order Order.
		 */
		do_action( 'lumia_factures_invoice_issued', $doc, $order );

		return $doc;
	}

	/**
	 * Rebuilds the archived PDF of an issued invoice whose file is missing or damaged.
	 *
	 * Number and date are kept. The new PDF goes to a new file; the damaged one is left in
	 * place, never deleted. Does nothing when the archive is intact.
	 *
	 * @param IssuedDocument $doc   Issued document.
	 * @param WC_Order       $order Its order.
	 * @return bool True when a new file was archived and recorded.
	 */
	public static function restore( IssuedDocument $doc, WC_Order $order ): bool {
		if ( 'issued' !== $doc->status || null === $doc->number || $doc->order_id !== $order->get_id() ) {
			return false;
		}

		if ( Archive::verify( $doc ) ) {
			return false;
		}

		$fresh  = wc_get_order( $doc->order_id );
		$fresh  = $fresh instanceof WC_Order ? $fresh : $order;
		$stored = self::render_and_store( $doc, $fresh, Module::settings() );

		if ( null === $stored ) {
			return false;
		}

		$recorded = null === $doc->file
			? Register::attach_file( $doc->id, $stored['file'], $stored['checksum'] )
			: Register::replace_file( $doc->id, $doc->file, $stored['file'], $stored['checksum'] );

		if ( ! $recorded ) {
			self::log( 'Rebuilt PDF ' . $stored['file'] . ' of invoice ' . $doc->number . ' could not be recorded in the register.' );
			return false;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: invoice number */
				__( 'Invoice PDF %s was missing or damaged and has been rebuilt from the current order data.', 'lumia-factures' ),
				$doc->number
			)
		);

		return true;
	}

	/**
	 * Renders the document's PDF and archives it.
	 *
	 * @param IssuedDocument       $doc      Issued document (number and date).
	 * @param WC_Order             $order    Order, freshly loaded.
	 * @param array<string, mixed> $settings Invoice settings.
	 * @return array{file: string, checksum: string}|null Null when rendering or storage failed.
	 */
	private static function render_and_store( IssuedDocument $doc, WC_Order $order, array $settings ): ?array {
		try {
			$pdf = Renderer::pdf( $order, new DocumentData( '', (string) $doc->number, $doc->issued_at ), $settings );
		} catch ( Throwable $e ) {
			self::log( 'PDF of invoice ' . $doc->number . ' could not be rendered: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			return null;
		}

		if ( '' === $pdf ) {
			self::log( 'PDF of invoice ' . $doc->number . ' could not be rendered.' );
			return null;
		}

		return Archive::store( $pdf, (string) $doc->number, $doc->issued_at );
	}

	/**
	 * Logs a line.
	 *
	 * @param string $message Message.
	 */
	private static function log( string $message ): void {
		error_log( '[lumia-factures] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
	}
}
