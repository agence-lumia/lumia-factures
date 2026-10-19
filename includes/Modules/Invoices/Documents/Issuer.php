<?php
/**
 * Issues invoices: number, PDF, archive.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Documents;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Migration\LegacySource;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
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
	 * Order meta holding the reason of the last import gate note (`import` or `conflict`), so each note is written once per order.
	 */
	public const GATE_FLAG = '_lumia_factures_gate_noted';

	/**
	 * Age in seconds after which a reservation that never got a number is a crash leftover.
	 */
	public const STALE_AFTER = 600;

	/**
	 * Maximum counter values tried for one invoice when the numbers they give are already issued.
	 */
	private const MAX_NUMBER_ATTEMPTS = 20;

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
	 * Whether the order's status triggers an invoice, and the legacy import allows it.
	 *
	 * The legacy gate is applied after the filter, so no third-party code can bypass it: a number
	 * issued before the import ends could take a number the legacy plugin already printed, and a
	 * legacy-invoiced order must resolve to its imported invoice, never get a second one. Each
	 * refusal by the gate notes the order, once.
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
		 * It cannot bypass the legacy import gate (see Issuer::gated()).
		 *
		 * @param bool     $can   True when the order status is a trigger status.
		 * @param WC_Order $order Order.
		 */
		$can = (bool) apply_filters( 'lumia_factures_can_issue', $can, $order );

		if ( $can ) {
			$reason = self::gate_reason( $order );
			if ( '' !== $reason ) {
				self::guard( 'import gate note', $order->get_id(), static fn() => self::note_gate( $order, $reason ) );
				return false;
			}
		}

		return $can;
	}

	/**
	 * Whether the legacy import forbids numbering the order now.
	 *
	 * True while the Facture PDF Lumia import is not finished and, whatever its state, for an order
	 * carrying a legacy number without an issued invoice in the register (the import resolves those
	 * orders; one it reported as a conflict stays refused until handled by hand).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function gated( WC_Order $order ): bool {
		return '' !== self::gate_reason( $order );
	}

	/**
	 * Why the legacy import forbids numbering the order. Reads only (the order meta box uses it).
	 *
	 * @param WC_Order $order Order.
	 * @return string `import` (the import is not finished), `conflict` (a legacy number the import
	 *                could not record), '' when the order may be numbered.
	 */
	public static function gate_reason( WC_Order $order ): string {
		if ( Migration::blocks_issuing() ) {
			return 'import';
		}

		if ( '' === trim( (string) $order->get_meta( LegacySource::META_NUMBER ) ) ) {
			return '';
		}

		$doc = Register::find( self::TYPE, $order->get_id() );

		return null === $doc || 'issued' !== $doc->status ? 'conflict' : '';
	}

	/**
	 * Notes on the order why it got no invoice, once per reason (the flag holds the last reason noted).
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason `import` or `conflict`.
	 */
	private static function note_gate( WC_Order $order, string $reason ): void {
		if ( $order->get_id() <= 0 || (string) $order->get_meta( self::GATE_FLAG ) === $reason ) {
			return;
		}

		$order->update_meta_data( self::GATE_FLAG, $reason );
		$order->save();
		$order->add_order_note(
			'conflict' === $reason
				? __( 'Invoice not issued: this order has a Facture PDF Lumia invoice that could not be imported; see the migration report.', 'lumia-factures' )
				: __( 'Invoice not issued: the Facture PDF Lumia data is being imported.', 'lumia-factures' )
		);
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
			// Repairs the order meta when an earlier call failed after numbering.
			self::guard( 'order meta back-fill', $order_id, static fn() => self::sync_meta( $existing, $order ) );
			return $existing;
		}

		if ( ! self::can_issue( $order ) ) {
			return null;
		}

		$id = self::reserve( $order_id );
		if ( null === $id ) {
			// Another call holds the row: return its invoice once issued, never number twice.
			$current = Register::find( self::TYPE, $order_id );
			return null !== $current && 'issued' === $current->status ? $current : null;
		}

		$settings = Module::settings();
		$now      = time();
		$assigned = self::number( $id, $order, $settings['numbering'], $now );
		if ( null === $assigned ) {
			return null;
		}

		[ $number, $key, $sequence ] = $assigned;

		// The number is recorded: from here on every step is attempted even when an earlier one
		// fails (a third-party hook throwing on save, a broken render), and each failure is logged.
		$doc = new IssuedDocument( $id, self::TYPE, $order_id, 'issued', $number, $key, $sequence, $now, null, null );

		self::guard( 'order meta', $order_id, static fn() => self::sync_meta( $doc, $order ) );

		// Written before rendering, so a fatal error inside Dompdf still leaves the number on the order.
		/* translators: %s: invoice number */
		$issued_note = sprintf( __( 'Invoice %s issued.', 'lumia-factures' ), $number );
		self::guard( 'order note', $order_id, static fn() => $order->add_order_note( $issued_note ) );

		$outcome = 'render_failed';
		self::guard(
			'PDF',
			$order_id,
			static function () use ( $doc, $order, $order_id, $settings, &$outcome ): void {
				// Rendered from a fresh order: the one in hand may predate the number (legacy bug C2).
				$fresh = wc_get_order( $order_id );
				$pdf   = self::render( $doc, $fresh instanceof WC_Order ? $fresh : $order, $settings );
				if ( '' === $pdf ) {
					return;
				}

				$outcome = 'store_failed';
				$stored  = Archive::store( $pdf, (string) $doc->number, $doc->issued_at );
				if ( null === $stored ) {
					return;
				}

				if ( Register::attach_file( $doc->id, $stored['file'], $stored['checksum'] ) ) {
					$outcome = 'stored';
				} else {
					self::log( 'Archived PDF ' . $stored['file'] . ' of invoice ' . $doc->number . ' could not be recorded in the register.' );
				}
			}
		);

		if ( 'stored' !== $outcome ) {
			self::guard( 'PDF failure note', $order_id, static fn() => $order->add_order_note( self::pdf_failure_note( $outcome, $number ) ) );
		}

		$doc = Register::find( self::TYPE, $order_id ) ?? $doc;

		self::guard(
			'issued action',
			$order_id,
			static function () use ( $doc, $order ): void {
				/**
				 * Fires once, when an invoice has been numbered (its PDF may be missing if rendering failed).
				 *
				 * @param IssuedDocument $doc   Issued document.
				 * @param WC_Order       $order Order.
				 */
				do_action( 'lumia_factures_invoice_issued', $doc, $order );
			}
		);

		return $doc;
	}

	/**
	 * Reserves the order's row, first releasing a stale reservation left by a crashed request.
	 *
	 * @param int $order_id Order id.
	 * @return int|null Row id, null when another call holds the row.
	 */
	private static function reserve( int $order_id ): ?int {
		$id = Register::reserve( self::TYPE, $order_id );
		if ( null !== $id ) {
			return $id;
		}

		$current = Register::find( self::TYPE, $order_id );
		if ( null === $current || 'reserved' !== $current->status || null !== $current->number ) {
			return null;
		}

		if ( ! Register::release_stale( $current->id, self::STALE_AFTER ) ) {
			return null;
		}

		self::log( 'Released the stale invoice reservation ' . $current->id . ' of order ' . $order_id . ' (older than ' . self::STALE_AFTER . ' seconds, never numbered).' );

		return Register::reserve( self::TYPE, $order_id );
	}

	/**
	 * Writes the invoice number and date on the order when they are absent or different, and
	 * clears the import gate flag.
	 *
	 * @param IssuedDocument $doc   Issued document.
	 * @param WC_Order       $order Order.
	 */
	private static function sync_meta( IssuedDocument $doc, WC_Order $order ): void {
		$number = (string) $doc->number;
		$date   = (string) $doc->issued_at;
		$noted  = '' !== (string) $order->get_meta( self::GATE_FLAG );

		if ( ! $noted && (string) $order->get_meta( '_lumia_factures_invoice_number' ) === $number && (string) $order->get_meta( '_lumia_factures_invoice_date' ) === $date ) {
			return;
		}

		// The order is invoiced: the import gate flag has served its purpose.
		if ( $noted ) {
			$order->delete_meta_data( self::GATE_FLAG );
		}

		$order->update_meta_data( '_lumia_factures_invoice_number', $number );
		$order->update_meta_data( '_lumia_factures_invoice_date', $date );
		$order->save();
	}

	/**
	 * Gives the reserved row its number.
	 *
	 * A value whose number is already recorded on another invoice (a counter moved behind the
	 * issued numbers, e.g. by a reset policy change) is skipped silently and the next value is
	 * taken: that number is printed on the other invoice, so the printed series keeps no gap.
	 * Any other failure releases the row, notes the burned value on the order and stops.
	 *
	 * @param int                  $id        Reserved row id.
	 * @param WC_Order             $order     Order.
	 * @param array<string, mixed> $numbering The `numbering` settings.
	 * @param int                  $now       Issue timestamp.
	 * @return array{0: string, 1: string, 2: int}|null Number, counter key and value; null when not numbered.
	 */
	private static function number( int $id, WC_Order $order, array $numbering, int $now ): ?array {
		$order_id = $order->get_id();
		$key      = Sequences::key( self::TYPE, $now, $numbering );

		for ( $attempt = 1; $attempt <= self::MAX_NUMBER_ATTEMPTS; $attempt++ ) {
			$sequence = Sequences::next( $key, (int) ( $numbering['start_number'] ?? 1 ) );

			if ( 0 === $sequence ) {
				Register::release( $id );
				self::log( 'Invoice of order ' . $order_id . ' could not be numbered: no value from the sequence ' . $key . '.' );
				$order->add_order_note( __( 'Invoice could not be numbered.', 'lumia-factures' ) );
				return null;
			}

			try {
				$number = NumberFormat::build( (string) $numbering['format'], $sequence, $order, $now );
			} catch ( Throwable $e ) {
				Register::release( $id );
				self::log( 'Invoice number of order ' . $order_id . ' could not be built from sequence ' . $key . ' = ' . $sequence . '; this sequence value is skipped: ' . $e->getMessage() );
				$order->add_order_note(
					sprintf(
						/* translators: %d: sequence value */
						__( 'Invoice sequence value %d could not be turned into a number; this sequence value is skipped.', 'lumia-factures' ),
						$sequence
					)
				);
				return null;
			}

			if ( Register::assign( $id, $number, $key, $sequence, $now ) ) {
				return [ $number, $key, $sequence ];
			}

			if ( ! Register::number_exists( self::TYPE, $number ) ) {
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

			self::log( 'Invoice number ' . $number . ' (sequence ' . $key . ' = ' . $sequence . ') is already issued; order ' . $order_id . ' takes the next value.' );
		}

		Register::release( $id );
		self::log( 'Invoice of order ' . $order_id . ' could not be numbered: ' . self::MAX_NUMBER_ATTEMPTS . ' values of the sequence ' . $key . ' gave numbers already issued. Raise the counter above the issued numbers.' );
		$order->add_order_note( __( 'Invoice could not be numbered.', 'lumia-factures' ) );

		return null;
	}

	/**
	 * Order note written when the PDF of a numbered invoice could not be archived.
	 *
	 * @param string $outcome `store_failed` or `render_failed`.
	 * @param string $number  Invoice number.
	 */
	private static function pdf_failure_note( string $outcome, string $number ): string {
		if ( 'store_failed' === $outcome ) {
			/* translators: %s: invoice number */
			return sprintf( __( 'The PDF of invoice %s could not be stored; the PDF will be built on first download.', 'lumia-factures' ), $number );
		}

		/* translators: %s: invoice number */
		return sprintf( __( 'The PDF of invoice %s could not be generated; the PDF will be built on first download.', 'lumia-factures' ), $number );
	}

	/**
	 * Runs a step, logging instead of propagating a failure.
	 *
	 * @param string   $step     Step name for the log.
	 * @param int      $order_id Order id.
	 * @param callable $run      Step.
	 */
	private static function guard( string $step, int $order_id, callable $run ): void {
		try {
			$run();
		} catch ( Throwable $e ) {
			self::log( 'Invoice step "' . $step . '" failed for order ' . $order_id . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
		}
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

		$fresh = wc_get_order( $doc->order_id );
		$pdf   = self::render( $doc, $fresh instanceof WC_Order ? $fresh : $order, Module::settings() );
		if ( '' === $pdf ) {
			return false;
		}

		$stored = Archive::store( $pdf, (string) $doc->number, $doc->issued_at );
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
	 * Renders the document's PDF with its recorded number and date.
	 *
	 * @param IssuedDocument       $doc      Issued document.
	 * @param WC_Order             $order    Order, freshly loaded.
	 * @param array<string, mixed> $settings Invoice settings.
	 * @return string PDF bytes, '' on failure (logged).
	 */
	private static function render( IssuedDocument $doc, WC_Order $order, array $settings ): string {
		try {
			$pdf = Renderer::pdf( $order, new DocumentData( '', (string) $doc->number, $doc->issued_at ), $settings );
		} catch ( Throwable $e ) {
			self::log( 'PDF of invoice ' . $doc->number . ' could not be rendered: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			return '';
		}

		if ( '' === $pdf ) {
			self::log( 'PDF of invoice ' . $doc->number . ' could not be rendered.' );
		}

		return $pdf;
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
