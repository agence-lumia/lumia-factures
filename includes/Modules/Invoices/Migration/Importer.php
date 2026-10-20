<?php
/**
 * Imports the legacy Facture PDF Lumia data into the invoice engine.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Database\Installer;
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
	 * Order meta flag: the "imported" order note was written.
	 */
	public const NOTED_META = '_lumia_factures_import_noted';

	/**
	 * Credit note formats the settings import tries, in order, when the legacy invoice format takes
	 * the prefix of the default one (`A{year}{number:5}` and `AV-`). No literal invoice prefix can
	 * start like both `AV` and `CN`, so one of them always keeps the legacy invoice numbering.
	 */
	private const CREDIT_NOTE_FALLBACKS = [ 'AVOIR-{year}-{number:5}', 'CN-{year}-{number:5}' ];

	/**
	 * True during a dry run on a site whose tables do not exist yet (the plugin never started,
	 * the legacy plugin still being active): the register reads as empty, the counters as 0.
	 *
	 * @var bool
	 */
	private static bool $no_tables = false;

	/**
	 * Runs the import.
	 *
	 * @param bool $dry_run True to compute the report without writing anything.
	 */
	public static function run( bool $dry_run ): Report {
		self::$no_tables = $dry_run && ! Installer::tables_exist();

		try {
			return self::import( $dry_run );
		} finally {
			self::$no_tables = false;
		}
	}

	/**
	 * Runs the import (see run()).
	 *
	 * @param bool $dry_run True to compute the report without writing anything.
	 */
	private static function import( bool $dry_run ): Report {
		$report = new Report();

		self::log( ( $dry_run ? 'Dry run of the' : 'Running the' ) . ' Facture PDF Lumia import.' );

		self::settings( $dry_run, $report );

		// Counters first: a run interrupted while importing must not leave a counter behind the
		// legacy numbers, or an invoice issued before the next run could print one of them again.
		// Every legacy sequence counts, conflicts and unloadable orders included.
		self::sequences( LegacySource::max_sequences(), $dry_run, $report );

		/**
		 * Filters the number of legacy orders imported per batch.
		 *
		 * @param int $size Orders per batch (200).
		 */
		$size = max( 1, (int) apply_filters( 'lumia_factures_legacy_import_batch_size', self::BATCH ) );

		// Highest legacy counter value seen per key, merged with a fresh read of the meta at the end.
		$highest = [];
		$seen    = [];
		$after   = 0;

		do {
			$batch = LegacySource::invoices( $after, $size );

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

			if ( [] !== $batch ) {
				/**
				 * Fires after each batch of legacy orders (progress reporting).
				 *
				 * @param Report $report  Report so far.
				 * @param int    $after   Last order id of the batch.
				 * @param bool   $dry_run Whether nothing is written.
				 */
				do_action( 'lumia_factures_legacy_import_batch_done', $report, $after, $dry_run );
			}
		} while ( [] !== $batch );

		// The legacy plugin may still number orders while the import runs (an in-flight request when
		// it is deactivated): its meta is read again, so a number written behind the cursor after
		// the first pass still raises the counter.
		foreach ( LegacySource::max_sequences() as $key => $value ) {
			$highest[ $key ] = max( $highest[ $key ] ?? 0, $value );
		}

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
	 * the defaults was never saved by the user and is replaced (Schema::left_at_defaults()). The
	 * `customer` group is left out of that comparison and kept (keep_customer_group()).
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
		if ( false !== $stored && ! Schema::left_at_defaults( $stored, [ 'customer' ] ) ) {
			$report->settings = 'skipped_existing';
			return;
		}

		$mapped    = LegacySettings::map( $legacy );
		$sanitized = self::sanitize_settings( $mapped['settings'] );
		$settings  = self::keep_customer_group( $sanitized['settings'], $stored );

		$report->settings = 'imported';
		$report->notes    = array_merge( $report->notes, $mapped['notes'], $sanitized['errors'], $sanitized['notes'] );

		if ( ! $dry_run ) {
			update_option( self::SETTINGS_OPTION, $settings );
		}
	}

	/**
	 * Puts the stored `customer` group back over the imported settings.
	 *
	 * 1.x had no such group, and Settings\Upgrader writes it before the import runs (page links off
	 * on a site with legacy data): it is not a user choice that blocks the import (left_at_defaults()
	 * leaves it out), and the import never resets it either.
	 *
	 * @param array<string, array<string, mixed>> $settings Imported settings.
	 * @param mixed                               $stored   Stored option (false when missing).
	 * @return array<string, array<string, mixed>>
	 */
	private static function keep_customer_group( array $settings, $stored ): array {
		if ( ! is_array( $stored ) || ! isset( $stored['customer'] ) || ! is_array( $stored['customer'] ) ) {
			return $settings;
		}

		foreach ( $stored['customer'] as $key => $value ) {
			if ( array_key_exists( $key, $settings['customer'] ) ) {
				$settings['customer'][ $key ] = $value;
			}
		}

		return $settings;
	}

	/**
	 * Sanitizes the mapped legacy settings, keeping the legacy invoice numbering policy.
	 *
	 * 1.x had no credit notes, so the credit note policy comes from the defaults. When the legacy
	 * invoice format takes the default credit note prefix, Schema::sanitize() would revert the
	 * invoice policy (a store numbered `A{year}{number:5}` would restart under `FA-`), so the first
	 * format of CREDIT_NOTE_FALLBACKS that leaves the invoice policy alone is used, with a note. A
	 * legacy invoice format refused on its own reverts whatever the credit note format: the default
	 * is kept.
	 *
	 * @param array<string, array<string, mixed>> $mapped Mapped legacy settings.
	 * @return array{settings: array<string, array<string, mixed>>, errors: string[], notes: string[]}
	 */
	private static function sanitize_settings( array $mapped ): array {
		$default = (string) Schema::defaults()['credit_notes']['format'];
		$chosen  = $default;
		$best    = Schema::sanitize( $mapped, Schema::defaults() );

		foreach ( self::CREDIT_NOTE_FALLBACKS as $format ) {
			if ( [] === $best['errors'] ) {
				break;
			}

			// The credit note format goes in as the current one, not as posted: it is never compared
			// to the invoice format of the defaults, which no document was issued with.
			$base                           = Schema::defaults();
			$base['credit_notes']['format'] = $format;

			$result = Schema::sanitize( $mapped, $base );
			if ( count( $result['errors'] ) < count( $best['errors'] ) ) {
				$best   = $result;
				$chosen = $format;
			}
		}

		$notes = [];
		if ( $default !== $chosen ) {
			$notes[] = sprintf(
				/* translators: 1: imported invoice number format, 2: default credit note number format, 3: credit note number format used instead */
				__( 'The invoice number format %1$s starts like the default credit note number format %2$s: credit notes will be numbered %3$s. Another format can be chosen in the Numbering tab.', 'lumia-factures' ),
				(string) $best['settings']['numbering']['format'],
				$default,
				$chosen
			);
		}

		return [
			'settings' => $best['settings'],
			'errors'   => $best['errors'],
			'notes'    => $notes,
		];
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
		$existing = self::$no_tables ? null : Register::find( Issuer::TYPE, $order_id );

		if ( null !== $existing ) {
			if ( $existing->number !== $number ) {
				self::conflict( $report, $order_id, $number, 'other_invoice', $existing->number );
				return;
			}

			// Imported by an earlier run: complete what an interrupted run may have left.
			++$report->invoices_already;
			if ( ! $dry_run ) {
				self::guard( $report, $order_id, 'order meta and note', static fn() => self::update_order( $order_id, $number, $existing->issued_at ) );
			}
			if ( null === $existing->file ) {
				self::file( $invoice, $existing->id, $existing->issued_at, $dry_run, $report, false );
			}
			return;
		}

		// Same answer as the unique keys of a real insert: a number already in the register,
		// or one this run already took for an earlier order (a duplicate in the legacy meta).
		if ( $dry_run && ( isset( $seen[ $number ] ) || ( ! self::$no_tables && Register::number_exists( Issuer::TYPE, $number ) ) ) ) {
			self::conflict( $report, $order_id, $number, 'number_used' );
			return;
		}

		$id = 0;
		if ( ! $dry_run ) {
			$id = Register::import( Issuer::TYPE, $order_id, $number, $invoice['sequence_key'], $invoice['sequence'], $invoice['issued_at'] );
			if ( null === $id ) {
				// Tell the refusals of the unique keys apart from a failed INSERT.
				$current = Register::find( Issuer::TYPE, $order_id );
				if ( null !== $current ) {
					self::conflict( $report, $order_id, $number, 'other_invoice', $current->number );
				} elseif ( Register::number_exists( Issuer::TYPE, $number ) ) {
					self::conflict( $report, $order_id, $number, 'number_used' );
				} else {
					self::conflict( $report, $order_id, $number, 'database_error' );
				}
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

		self::guard( $report, $order_id, 'order meta and note', static fn() => self::update_order( $order_id, $number, $invoice['issued_at'] ) );
		self::file( $invoice, $id, $invoice['issued_at'], false, $report, true );
	}

	/**
	 * Writes the invoice meta when absent or different and the "imported" order note once, then
	 * saves the order once: its hooks and webhooks fire a single time per imported order.
	 *
	 * The flag meta marks the note written, so a run resumed after an interruption adds a missing
	 * note and never a second one.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $number    Invoice number.
	 * @param int    $issued_at Issue date.
	 */
	private static function update_order( int $order_id, string $number, int $issued_at ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$changed = false;

		if ( (string) $order->get_meta( '_lumia_factures_invoice_number' ) !== $number || (string) $order->get_meta( '_lumia_factures_invoice_date' ) !== (string) $issued_at ) {
			$order->update_meta_data( '_lumia_factures_invoice_number', $number );
			$order->update_meta_data( '_lumia_factures_invoice_date', (string) $issued_at );
			$changed = true;
		}

		if ( '' === (string) $order->get_meta( self::NOTED_META ) ) {
			// add_order_note() writes its own comment, it does not save the order.
			/* translators: %s: invoice number */
			$order->add_order_note( sprintf( __( 'Invoice %s imported from Facture PDF Lumia.', 'lumia-factures' ), $number ) );
			$order->update_meta_data( self::NOTED_META, '1' );
			$changed = true;
		}

		if ( $changed ) {
			$order->save();
		}
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
	 * Raises each counter to the highest legacy value (legacy table or given values). Never lowers.
	 *
	 * Called before the invoices (from the meta tables) and after them (from the invoices read and
	 * the meta tables read again), so the second call raises only what the legacy plugin numbered
	 * during the run. A dry run records what would be raised.
	 *
	 * @param array<string, int> $highest Highest legacy value per key.
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
			// A dry run raises nothing: what it already planned counts as the current value.
			$current = self::$no_tables ? 0 : Sequences::current( $key );
			if ( $target <= max( $current, $report->sequences[ $key ] ?? 0 ) ) {
				continue;
			}

			if ( $dry_run || Sequences::raise( $key, $target ) ) {
				$report->sequences[ $key ] = $target;
			}
		}
	}

	/**
	 * Records a skipped legacy invoice: a translated reason in the report, an English line in the log.
	 *
	 * @param Report      $report   Report.
	 * @param int         $order_id Order id.
	 * @param string      $number   Legacy number.
	 * @param string      $code     `other_invoice` (the order already has a row), `number_used`
	 *                              (another order has the number) or `database_error`.
	 * @param string|null $other    For `other_invoice`: the number in the register (null for a reservation).
	 */
	private static function conflict( Report $report, int $order_id, string $number, string $code, ?string $other = null ): void {
		switch ( $code ) {
			case 'other_invoice':
				if ( null === $other ) {
					$reason = __( 'An invoice is being issued for this order.', 'lumia-factures' );
					$log    = 'an invoice is being issued for this order';
				} else {
					/* translators: %s: invoice number */
					$reason = sprintf( __( 'The order already has invoice %s.', 'lumia-factures' ), $other );
					$log    = 'the order already has invoice ' . $other;
				}
				break;

			case 'number_used':
				$reason = __( 'The number is already used by another invoice.', 'lumia-factures' );
				$log    = 'the number is already used by another invoice';
				break;

			default:
				$code   = 'database_error';
				$reason = __( 'The invoice could not be recorded (database error, see the PHP error log).', 'lumia-factures' );
				$log    = 'the register insert failed (database error)';
		}

		$report->conflict( $order_id, $number, $code, $reason );
		self::log( 'Legacy invoice ' . $number . ' of order ' . $order_id . ' was not imported: ' . $log . '.' );
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
	 * Logs a line.
	 *
	 * @param string $message Message.
	 */
	private static function log( string $message ): void {
		error_log( '[lumia-factures] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
	}
}
