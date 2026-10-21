<?php
/**
 * The issuing steps shared by every numbered document type.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Documents;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Numbering\NumberFormat;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Throwable;
use WC_Order;

/**
 * Reserve, number, archive: the core of Issuer, for any document type.
 *
 * The register row is reserved first (unique per type and object), the counter is consumed
 * only then, right before the number is recorded: a second call for the same object never
 * reaches the counter. A counter value taken but not recorded is logged and noted on the
 * order, so a gap is always visible. Each issuer keeps its own gate, meta, notes and hooks.
 */
final class Pipeline {

	/**
	 * Maximum counter values tried for one document when the numbers they give are already issued.
	 */
	private const MAX_NUMBER_ATTEMPTS = 20;

	/**
	 * Reserves the object's row, first releasing a stale reservation left by a crashed request.
	 *
	 * @param string $type      Document type.
	 * @param int    $object_id Order id (the refund id for a credit note).
	 * @param int    $parent_id Order the document belongs to, 0 for none.
	 * @return int|null Row id, null when another call holds the row.
	 */
	public static function reserve( string $type, int $object_id, int $parent_id ): ?int {
		$id = Register::reserve( $type, $object_id, $parent_id );
		if ( null !== $id ) {
			return $id;
		}

		$current = Register::find( $type, $object_id );
		if ( null === $current || 'reserved' !== $current->status || null !== $current->number ) {
			return null;
		}

		if ( ! Register::release_stale( $current->id, Issuer::STALE_AFTER ) ) {
			return null;
		}

		self::log( 'Released the stale ' . self::name( $type ) . ' reservation ' . $current->id . ' of order ' . $object_id . ' (older than ' . Issuer::STALE_AFTER . ' seconds, never numbered).' );

		return Register::reserve( $type, $object_id, $parent_id );
	}

	/**
	 * Gives the reserved row its number.
	 *
	 * A value whose number is already recorded on another document of the same type (a counter
	 * moved behind the issued numbers, e.g. by a reset policy change) is skipped silently and the
	 * next value is taken: that number is printed on the other document, so the printed series
	 * keeps no gap. A number already used by another document type is refused before it is
	 * recorded and never skipped: the next values would give the same clash and burn the counter
	 * (the formats need distinct prefixes). Any refusal or failure releases the row, notes the
	 * burned value on the order and stops.
	 *
	 * @param string               $type      Document type.
	 * @param int                  $row_id    Reserved row id.
	 * @param WC_Order             $context   Order the number is built from and the notes go to.
	 * @param array<string, mixed> $numbering The numbering settings of the type (`format`, `start_number`, reset policy).
	 * @param int                  $now       Issue timestamp.
	 * @param Notes                $notes     Note texts of the type.
	 * @return array{0: string, 1: string, 2: int}|null Number, counter key and value; null when not numbered.
	 */
	public static function number( string $type, int $row_id, WC_Order $context, array $numbering, int $now, Notes $notes ): ?array {
		$order_id = $context->get_id();
		$label    = ucfirst( self::name( $type ) );
		$key      = Sequences::key( $type, $now, $numbering );

		for ( $attempt = 1; $attempt <= self::MAX_NUMBER_ATTEMPTS; $attempt++ ) {
			$sequence = Sequences::next( $key, (int) ( $numbering['start_number'] ?? 1 ) );

			if ( 0 === $sequence ) {
				Register::release( $row_id );
				self::log( $label . ' of order ' . $order_id . ' could not be numbered: no value from the sequence ' . $key . '.' );
				$context->add_order_note( $notes->not_numbered );
				return null;
			}

			try {
				$number = NumberFormat::build( (string) $numbering['format'], $sequence, $context, $now );
			} catch ( Throwable $e ) {
				Register::release( $row_id );
				self::log( $label . ' number of order ' . $order_id . ' could not be built from sequence ' . $key . ' = ' . $sequence . '; this sequence value is skipped: ' . $e->getMessage() );
				$context->add_order_note( sprintf( $notes->not_built, $sequence ) );
				return null;
			}

			// The unique key (type, number) cannot see another type: checked before recording.
			if ( Register::number_exists_in_other_type( $type, $number ) ) {
				Register::release( $row_id );
				self::log( $label . ' number ' . $number . ' (sequence ' . $key . ' = ' . $sequence . ') of order ' . $order_id . ' is already used by another document type; this sequence value is skipped. The number formats need distinct prefixes.' );
				$context->add_order_note( sprintf( $notes->other_type, $number, $sequence ) );
				return null;
			}

			if ( Register::assign( $row_id, $number, $key, $sequence, $now ) ) {
				return [ $number, $key, $sequence ];
			}

			if ( ! Register::number_exists( $type, $number ) ) {
				Register::release( $row_id );
				self::log( $label . ' number ' . $number . ' (sequence ' . $key . ' = ' . $sequence . ') could not be recorded for order ' . $order_id . '; this sequence value is skipped.' );
				$context->add_order_note( sprintf( $notes->not_recorded, $number, $sequence ) );
				return null;
			}

			self::log( $label . ' number ' . $number . ' (sequence ' . $key . ' = ' . $sequence . ') is already issued; order ' . $order_id . ' takes the next value.' );
		}

		Register::release( $row_id );
		self::log( $label . ' of order ' . $order_id . ' could not be numbered: ' . self::MAX_NUMBER_ATTEMPTS . ' values of the sequence ' . $key . ' gave numbers already issued. Raise the counter above the issued numbers.' );
		$context->add_order_note( $notes->not_numbered );

		return null;
	}

	/**
	 * Renders, stores and records the PDF of a numbered document, once.
	 *
	 * Never throws: a failure is logged and reported by the outcome, the document keeps its
	 * number and its file is built later (Issuer::restore()).
	 *
	 * @param IssuedDocument   $doc    Numbered document, no file yet.
	 * @param callable(): string $render Renders the PDF from fresh data; '' on failure.
	 * @return string `stored`, `store_failed` or `render_failed`.
	 */
	public static function archive( IssuedDocument $doc, callable $render ): string {
		$outcome = 'render_failed';

		self::guard(
			$doc->type,
			'PDF',
			$doc->order_id,
			static function () use ( $doc, $render, &$outcome ): void {
				$pdf = $render();
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
					self::log( 'Archived PDF ' . $stored['file'] . ' of ' . self::name( $doc->type ) . ' ' . $doc->number . ' could not be recorded in the register.' );
				}
			}
		);

		return $outcome;
	}

	/**
	 * Runs a step, logging instead of propagating a failure.
	 *
	 * @param string   $type      Document type.
	 * @param string   $step      Step name for the log.
	 * @param int      $object_id Order id (the refund id for a credit note).
	 * @param callable $run       Step.
	 */
	public static function guard( string $type, string $step, int $object_id, callable $run ): void {
		try {
			$run();
		} catch ( Throwable $e ) {
			self::log( ucfirst( self::name( $type ) ) . ' step "' . $step . '" failed for order ' . $object_id . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
		}
	}

	/**
	 * Logs a line.
	 *
	 * @param string $message Message.
	 */
	public static function log( string $message ): void {
		error_log( '[lumia-factures] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
	}

	/**
	 * The document type as written in a log line: `invoice`, `credit note`.
	 *
	 * @param string $type Document type.
	 */
	private static function name( string $type ): string {
		return str_replace( '_', ' ', $type );
	}
}
