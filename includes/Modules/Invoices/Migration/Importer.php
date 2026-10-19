<?php
/**
 * Imports the legacy Facture PDF Lumia data into the invoice engine.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Settings\Schema;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Throwable;
use WC_Order;

/**
 * Settings, register rows, order meta, legacy PDFs and counters, in that order.
 *
 * Idempotent and resumable: an invoice already in the register with the same number is
 * counted as already imported (its missing meta or file is completed), never imported twice;
 * counters are only raised. Legacy data is only read. A dry run goes through the same code
 * with every write skipped, so its report equals the one a real run would give.
 */
final class Importer {

	/**
	 * Orders read per batch.
	 */
	public const BATCH = 200;

	/**
	 * Option of the invoice settings.
	 */
	private const SETTINGS_OPTION = 'lumia_factures_module_invoices';

	/**
	 * Runs the import.
	 *
	 * @param bool $dry_run True to compute the report without writing anything.
	 */
	public static function run( bool $dry_run ): Report {
		$report = new Report();

		self::log( ( $dry_run ? 'Dry run of the' : 'Running the' ) . ' Facture PDF Lumia import.' );

		self::settings( $dry_run, $report );

		// Highest legacy counter value seen per key, conflicts included: every number the legacy
		// plugin printed must stay behind the counter, even one that could not be imported.
		$highest = [];
		$seen    = [];
		$after   = 0;

		do {
			$batch = LegacySource::invoices( $after, self::BATCH );

			foreach ( $batch as $invoice ) {
				$after = $invoice['order_id'];

				if ( '' !== $invoice['sequence_key'] && $invoice['sequence'] > 0 ) {
					$highest[ $invoice['sequence_key'] ] = max( $highest[ $invoice['sequence_key'] ] ?? 0, $invoice['sequence'] );
				}

				self::invoice( $invoice, $dry_run, $report, $seen );
			}

			// Every order loaded stays in the in-memory object cache: on a shop with tens of
			// thousands of invoices one run would exhaust the memory (Action Scheduler does the same).
			if ( ! wp_using_ext_object_cache() ) {
				wp_cache_flush();
			}
		} while ( [] !== $batch );

		self::sequences( $highest, $dry_run, $report );

		self::log(
			sprintf(
				'Facture PDF Lumia import %s: %d imported, %d already, %d files copied, %d missing, %d invalid, %d conflicts.',
				$dry_run ? 'planned' : 'done',
				$report->invoices_imported,
				$report->invoices_already,
				$report->files_copied,
				$report->files_missing,
				$report->files_invalid,
				count( $report->conflicts )
			)
		);

		return $report;
	}

	/**
	 * Imports the legacy settings unless the user already saved settings.
	 *
	 * The activator stores the defaults before the migration runs, so an option still equal to
	 * the defaults was never saved by the user and is replaced.
	 *
	 * @param bool   $dry_run Dry run.
	 * @param Report $report  Report.
	 */
	private static function settings( bool $dry_run, Report $report ): void {
		$legacy = LegacySource::settings();
		if ( null === $legacy ) {
			$report->settings = 'none';
			return;
		}

		$stored = get_option( self::SETTINGS_OPTION, false );
		if ( false !== $stored && Schema::defaults() != $stored ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Same values, key order and int/float equality do not matter.
			$report->settings = 'skipped_existing';
			return;
		}

		$mapped    = LegacySettings::map( $legacy );
		$sanitized = Schema::sanitize( $mapped['settings'], Schema::defaults() );

		$report->settings = 'imported';
		$report->notes    = array_merge( $report->notes, $mapped['notes'], $sanitized['errors'] );

		if ( ! $dry_run ) {
			update_option( self::SETTINGS_OPTION, $sanitized['settings'] );
		}
	}

	/**
	 * Imports one legacy invoice.
	 *
	 * @param array{order_id: int, number: string, sequence: int, sequence_key: string, issued_at: int, date_source: string, file: string} $invoice Legacy invoice.
	 * @param bool                                                                                                                          $dry_run Dry run.
	 * @param Report                                                                                                                        $report  Report.
	 * @param array<string, int>                                                                                                            $seen    Numbers planned by this run (dry run duplicate check), by number.
	 */
	private static function invoice( array $invoice, bool $dry_run, Report $report, array &$seen ): void {
		$order_id = $invoice['order_id'];
		$number   = $invoice['number'];
		$existing = Register::find( Issuer::TYPE, $order_id );

		if ( null !== $existing ) {
			if ( $existing->number !== $number ) {
				self::conflict( $report, $order_id, $number, self::reason_other_invoice( $existing->number ) );
				return;
			}

			// Imported by an earlier run: complete what an interrupted run may have left.
			++$report->invoices_already;
			if ( ! $dry_run ) {
				self::guard( $report, $order_id, 'order meta', static fn() => self::meta( $order_id, $number, $existing->issued_at ) );
			}
			if ( null === $existing->file ) {
				self::file( $invoice, $existing->id, $existing->issued_at, $dry_run, $report, false );
			}
			return;
		}

		// Same answer as the unique keys of a real insert: a number already in the register,
		// or one this run already took for an earlier order (a duplicate in the legacy meta).
		if ( $dry_run && ( isset( $seen[ $number ] ) || Register::number_exists( Issuer::TYPE, $number ) ) ) {
			self::conflict( $report, $order_id, $number, self::reason_number_used() );
			return;
		}

		$id = 0;
		if ( ! $dry_run ) {
			$id = Register::import( Issuer::TYPE, $order_id, $number, $invoice['sequence_key'], $invoice['sequence'], $invoice['issued_at'] );
			if ( null === $id ) {
				self::conflict( $report, $order_id, $number, self::reason_number_used() );
				return;
			}
		}

		$seen[ $number ] = $order_id;
		++$report->invoices_imported;

		if ( 'generated_at' === $invoice['date_source'] ) {
			$report->notes[] = sprintf(
				/* translators: 1: invoice number, 2: order id */
				__( 'Invoice %1$s of order %2$d had no invoice date: the date its PDF was generated was used.', 'lumia-factures' ),
				$number,
				$order_id
			);
		} elseif ( 'order_created' === $invoice['date_source'] ) {
			$report->notes[] = sprintf(
				/* translators: 1: invoice number, 2: order id */
				__( 'Invoice %1$s of order %2$d had no invoice date: the order creation date was used.', 'lumia-factures' ),
				$number,
				$order_id
			);
		}

		if ( $dry_run ) {
			self::file( $invoice, 0, $invoice['issued_at'], true, $report, true );
			return;
		}

		self::guard( $report, $order_id, 'order meta', static fn() => self::meta( $order_id, $number, $invoice['issued_at'] ) );
		self::file( $invoice, $id, $invoice['issued_at'], false, $report, true );
		self::guard(
			$report,
			$order_id,
			'order note',
			static function () use ( $order_id, $number ): void {
				$order = wc_get_order( $order_id );
				if ( $order instanceof WC_Order ) {
					/* translators: %s: invoice number */
					$order->add_order_note( sprintf( __( 'Invoice %s imported from Facture PDF Lumia.', 'lumia-factures' ), $number ) );
				}
			}
		);
	}

	/**
	 * Copies the legacy PDF of an invoice into the archive and records it.
	 *
	 * @param array{order_id: int, number: string, sequence: int, sequence_key: string, issued_at: int, date_source: string, file: string} $invoice   Legacy invoice.
	 * @param int                                                                                                                           $id        Register row id (0 in a dry run).
	 * @param int                                                                                                                           $issued_at Recorded issue date.
	 * @param bool                                                                                                                          $dry_run   Dry run.
	 * @param Report                                                                                                                        $report    Report.
	 * @param bool                                                                                                                          $count     Whether a missing or refused file is counted (only when the invoice is imported now).
	 */
	private static function file( array $invoice, int $id, int $issued_at, bool $dry_run, Report $report, bool $count ): void {
		$relative = ltrim( $invoice['file'], '/' );
		$source   = Archive::legacy_root() . '/' . $relative;

		if ( '' === $relative || ! file_exists( $source ) ) {
			if ( $count ) {
				++$report->files_missing;
			}
			return;
		}

		if ( $dry_run ) {
			if ( null !== Archive::read_legacy( $source ) ) {
				++$report->files_copied;
			} elseif ( $count ) {
				self::invalid( $invoice, $report );
			}
			return;
		}

		$stored = Archive::import_file( $source, $invoice['number'], $issued_at );
		if ( null === $stored ) {
			if ( $count ) {
				self::invalid( $invoice, $report );
			}
			return;
		}

		if ( Register::attach_file( $id, $stored['file'], $stored['checksum'] ) ) {
			++$report->files_copied;
			return;
		}

		// The row got a file in the meantime (a PDF built on first need): the copy is kept unrecorded, never deleted.
		self::log( 'Legacy PDF of invoice ' . $invoice['number'] . ' was copied to ' . $stored['file'] . ' but the register already had a file.' );
	}

	/**
	 * Counts a refused legacy PDF.
	 *
	 * @param array{order_id: int, number: string, file: string} $invoice Legacy invoice.
	 * @param Report                                             $report  Report.
	 */
	private static function invalid( array $invoice, Report $report ): void {
		++$report->files_invalid;
		$report->notes[] = sprintf(
			/* translators: 1: invoice number, 2: order id, 3: file path */
			__( 'The legacy PDF of invoice %1$s (order %2$d, %3$s) is not a readable PDF of the legacy folder: it was not imported, the PDF will be built on first need.', 'lumia-factures' ),
			$invoice['number'],
			$invoice['order_id'],
			$invoice['file']
		);
		self::log( 'Legacy PDF ' . $invoice['file'] . ' of invoice ' . $invoice['number'] . ' was refused.' );
	}

	/**
	 * Writes the invoice meta on the order when absent or different.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $number    Number.
	 * @param int    $issued_at Issue date.
	 */
	private static function meta( int $order_id, string $number, int $issued_at ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( (string) $order->get_meta( '_lumia_factures_invoice_number' ) === $number && (string) $order->get_meta( '_lumia_factures_invoice_date' ) === (string) $issued_at ) {
			return;
		}

		$order->update_meta_data( '_lumia_factures_invoice_number', $number );
		$order->update_meta_data( '_lumia_factures_invoice_date', (string) $issued_at );
		$order->save();
	}

	/**
	 * Raises each counter to the highest legacy value (legacy table or imported invoices). Never lowers.
	 *
	 * @param array<string, int> $highest Highest imported value per key.
	 * @param bool               $dry_run Dry run.
	 * @param Report             $report  Report.
	 */
	private static function sequences( array $highest, bool $dry_run, Report $report ): void {
		foreach ( LegacySource::sequences() as $key => $value ) {
			$highest[ $key ] = max( $highest[ $key ] ?? 0, $value );
		}

		ksort( $highest );

		foreach ( $highest as $key => $target ) {
			$key = (string) $key;
			if ( $target <= Sequences::current( $key ) ) {
				continue;
			}

			if ( $dry_run || Sequences::raise( $key, $target ) ) {
				$report->sequences[ $key ] = $target;
			}
		}
	}

	/**
	 * Records a skipped legacy invoice.
	 *
	 * @param Report $report   Report.
	 * @param int    $order_id Order id.
	 * @param string $number   Legacy number.
	 * @param string $reason   Reason (English, for the report and the log).
	 */
	private static function conflict( Report $report, int $order_id, string $number, string $reason ): void {
		$report->conflict( $order_id, $number, $reason );
		self::log( 'Legacy invoice ' . $number . ' of order ' . $order_id . ' was not imported: ' . $reason );
	}

	/**
	 * Runs a step, logging and noting a failure instead of stopping the run.
	 *
	 * @param Report   $report   Report.
	 * @param int      $order_id Order id.
	 * @param string   $step     Step name.
	 * @param callable $run      Step.
	 */
	private static function guard( Report $report, int $order_id, string $step, callable $run ): void {
		try {
			$run();
		} catch ( Throwable $e ) {
			self::log( 'Import of order ' . $order_id . ': ' . $step . ' failed: ' . $e->getMessage() );
			$report->notes[] = sprintf(
				/* translators: %d: order id */
				__( 'Order %d: part of the import could not be written (see the PHP error log).', 'lumia-factures' ),
				$order_id
			);
		}
	}

	/**
	 * Conflict reason: the number is already recorded for another order.
	 */
	private static function reason_number_used(): string {
		return __( 'The number is already used by another invoice.', 'lumia-factures' );
	}

	/**
	 * Conflict reason: the order already has another invoice in the register.
	 *
	 * @param string|null $number Number in the register (null for a reservation in progress).
	 */
	private static function reason_other_invoice( ?string $number ): string {
		if ( null === $number ) {
			return __( 'An invoice is being issued for this order.', 'lumia-factures' );
		}

		/* translators: %s: invoice number */
		return sprintf( __( 'The order already has invoice %s.', 'lumia-factures' ), $number );
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
