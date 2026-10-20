<?php
/**
 * WP-CLI commands of the invoices module.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Cli;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use Lumia\Factures\Core\Requirements;
use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\Pipeline;
use Lumia\Factures\Modules\Invoices\Migration\Importer;
use Lumia\Factures\Modules\Invoices\Migration\LegacySource;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Throwable;
use WC_Order;
use WC_Order_Refund;
use WP_CLI;

/**
 * Imports the Facture PDF Lumia (1.x) data, shows the import status and issues the credit notes
 * of refunds that have none.
 *
 * The logic lives in static methods returning data (`migrate_data()`, `status_rows()`,
 * `credit_notes_data()`), so the tests call them without WP-CLI; the subcommands only print.
 *
 * Registered by Core\Plugin whatever the requirements: `migrate --dry-run` and `migration-status`
 * only read, and must work while the legacy plugin is still active (the rollout dry run). A real
 * run is refused while a requirement fails.
 */
final class Command {

	/**
	 * Imports the Facture PDF Lumia data.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what the import would do, without writing anything.
	 *
	 * [--force]
	 * : Run again even when the import is already done (it imports nothing twice).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lumia-factures migrate --dry-run
	 *     wp lumia-factures migrate
	 *
	 * @param string[]             $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function migrate( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$result = self::migrate_data( ! empty( $assoc_args['dry-run'] ), ! empty( $assoc_args['force'] ) );

		switch ( $result['outcome'] ) {
			case 'no_data':
				WP_CLI::success( 'There is no Facture PDF Lumia data to import.' );
				return;
			case 'already_done':
				WP_CLI::success( 'The import is already done. Use --force to run it again.' );
				return;
			case 'busy':
				WP_CLI::error( 'Another import is running. Try again in a few minutes.' );
				// WP_CLI::error() exits.
			case 'blocked':
				WP_CLI::error( 'The import cannot run: ' . $result['error'] );
		}

		// A failed run has no report: no empty table.
		if ( [] !== $result['rows'] ) {
			self::print_result( $result );
		}

		match ( $result['outcome'] ) {
			'dry_run'             => WP_CLI::success( 'Dry run: nothing was written.' ),
			'done'                => WP_CLI::success( 'Import done.' ),
			'done_with_conflicts' => WP_CLI::warning( 'Import done, some invoices were skipped (see the conflicts above).' ),
			default               => WP_CLI::error( 'The import failed: ' . $result['error'] ),
		};
	}

	/**
	 * Shows the import status.
	 *
	 * @subcommand migration-status
	 *
	 * @param string[]             $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Flags (none).
	 */
	public function migration_status( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		WP_CLI\Utils\format_items( 'table', self::status_rows(), [ 'field', 'value' ] );

		$conflicts = Migration::state()['report']['conflicts'] ?? [];
		if ( is_array( $conflicts ) && [] !== $conflicts ) {
			WP_CLI::log( 'Conflicts:' );
			WP_CLI\Utils\format_items( 'table', $conflicts, [ 'order_id', 'number', 'reason' ] );
		}
	}

	/**
	 * Issues the credit notes of refunds that have none (refunds made before 2.1, pending ones).
	 *
	 * Each credit note is dated the day it is issued; its PDF prints the refund date. Refunds are
	 * taken by increasing id.
	 *
	 * ## OPTIONS
	 *
	 * [--order=<id>]
	 * : Only the refunds of this order.
	 *
	 * [--since=<date>]
	 * : Only the refunds made on or after this date (Y-m-d, site time).
	 *
	 * [--pending]
	 * : Only the refunds flagged pending (their credit note was refused, see the reason).
	 *
	 * [--dry-run]
	 * : List the refunds and what would happen, without reserving or numbering anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lumia-factures issue-credit-notes --dry-run
	 *     wp lumia-factures issue-credit-notes --order=1234
	 *     wp lumia-factures issue-credit-notes --since=2026-01-01 --pending
	 *
	 * @subcommand issue-credit-notes
	 *
	 * @param string[]             $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function issue_credit_notes( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$result  = self::credit_notes_data(
			isset( $assoc_args['order'] ) ? (string) $assoc_args['order'] : '',
			isset( $assoc_args['since'] ) ? (string) $assoc_args['since'] : '',
			! empty( $assoc_args['pending'] ),
			$dry_run
		);

		switch ( $result['outcome'] ) {
			case 'blocked':
				WP_CLI::error( 'Credit notes cannot be issued: ' . $result['error'] );
				// WP_CLI::error() exits.
			case 'invalid':
				WP_CLI::error( $result['error'] );
				// WP_CLI::error() exits.
			case 'nothing':
				WP_CLI::success( 'No refund without a credit note matches.' );
				return;
		}

		WP_CLI\Utils\format_items( 'table', $result['rows'], [ 'refund_id', 'order_id', 'refunded_on', 'amount', 'number', 'status' ] );

		$counts = array_count_values( array_column( $result['rows'], 'status' ) );
		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'Dry run: nothing was written. %d credit note(s) would be issued.', $counts['would_issue'] ?? 0 ) );
			return;
		}

		$refused = count( $result['rows'] ) - ( $counts['issued'] ?? 0 ) - ( $counts['already_issued'] ?? 0 );
		$message = sprintf( '%d credit note(s) issued.', $counts['issued'] ?? 0 );
		if ( $refused > 0 ) {
			WP_CLI::warning( $message . ' ' . $refused . ' refund(s) got none (see their status).' );
			return;
		}

		WP_CLI::success( $message );
	}

	/**
	 * Issues the credit notes of the matching refunds, or lists what a run would do.
	 *
	 * The refunds taken are those without a credit note number (all of them, or with `$pending`
	 * those flagged pending), narrowed by order and by refund date. A real run goes through
	 * CreditNoteIssuer::issue(), so the gate, the pending flag and the numbering are the usual
	 * ones; a dry run only reads (CreditNoteIssuer::refusal()), it never reserves nor numbers.
	 *
	 * Refused (`blocked`) while the module's tables are missing, and for a real run while any
	 * requirement fails; a dry run only needs WooCommerce.
	 *
	 * @param string $order   Order id, '' for every order.
	 * @param string $since   Earliest refund date (Y-m-d, site time), '' for none.
	 * @param bool   $pending True for the pending refunds only.
	 * @param bool   $dry_run True to report without writing.
	 * @return array{outcome: string, rows: list<array{refund_id: int, order_id: int, refunded_on: string, amount: string, number: string, status: string}>, error: string}
	 *         `outcome`: `dry_run`, `done`, `nothing`, `blocked` or `invalid`. Row `status`:
	 *         `issued`, `already_issued`, `would_issue` (dry run), `failed`, or the refusal reason
	 *         (see CreditNoteIssuer::refusal()).
	 */
	public static function credit_notes_data( string $order, string $since, bool $pending, bool $dry_run ): array {
		$result = [
			'outcome' => '',
			'rows'    => [],
			'error'   => '',
		];

		$errors = Requirements::check();
		if ( $dry_run ) {
			$errors = array_intersect_key( $errors, [ 'woocommerce' => true ] );
		}
		if ( [] === $errors && ! Installer::tables_exist() ) {
			$errors['tables'] = 'The plugin tables do not exist yet: load the WordPress admin once so the plugin installs them.';
		}

		if ( [] !== $errors ) {
			$result['outcome'] = 'blocked';
			$result['error']   = implode( ' ', $errors );
			return $result;
		}

		$query = [
			'type'    => 'shop_order_refund',
			'limit'   => -1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'ids',
		];

		if ( '' !== $order ) {
			$order_id = ctype_digit( $order ) ? (int) $order : 0;
			if ( $order_id <= 0 || ! wc_get_order( $order_id ) instanceof WC_Order ) {
				$result['outcome'] = 'invalid';
				$result['error']   = 'Order ' . $order . ' not found.';
				return $result;
			}
			$query['parent'] = $order_id;
		}

		if ( '' !== $since ) {
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $since, wp_timezone() );
			if ( false === $date || $date->format( 'Y-m-d' ) !== $since ) {
				$result['outcome'] = 'invalid';
				$result['error']   = 'Invalid --since date "' . $since . '": expected Y-m-d.';
				return $result;
			}
			$query['date_created'] = '>=' . $date->getTimestamp();
		}

		// One meta clause through `meta_key` / `meta_compare`: the legacy order storage ignores
		// `meta_query` (see CreditNoteIssuer::pending_refund_ids()).
		$query['meta_key']     = $pending ? CreditNoteIssuer::PENDING_META : CreditNoteIssuer::NUMBER_META; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WP-CLI command run by hand.
		$query['meta_compare'] = $pending ? 'EXISTS' : 'NOT EXISTS';

		$refunds = [];
		foreach ( self::ids( wc_get_orders( $query ) ) as $refund_id ) {
			$refund = wc_get_order( $refund_id );
			if ( $refund instanceof WC_Order_Refund ) {
				$refunds[ $refund_id ] = $refund;
			}
		}

		if ( [] === $refunds ) {
			$result['outcome'] = 'nothing';
			return $result;
		}

		// Read before any issuing: issuing an order's invoice issues its other refunds' credit notes
		// too (CreditNoteIssuer::on_invoice_issued()), which this run did, not an earlier one.
		$before = [];
		foreach ( $refunds as $refund_id => $refund ) {
			$existing = CreditNoteIssuer::find_for( $refund );
			if ( null !== $existing && 'issued' === $existing->status ) {
				$before[ $refund_id ] = (string) $existing->number;
			}
		}

		foreach ( $refunds as $refund_id => $refund ) {
			$number = $before[ $refund_id ] ?? '';

			if ( '' !== $number ) {
				// Issued without its refund meta (an interrupted call): issue() writes it back, never renumbers.
				if ( ! $dry_run ) {
					self::issue_credit_note( $refund, $number );
				}
				$status = 'already_issued';
			} elseif ( $dry_run ) {
				$reason = CreditNoteIssuer::refusal( $refund );
				$status = '' === $reason ? 'would_issue' : $reason;
			} else {
				$status = self::issue_credit_note( $refund, $number );
			}

			$created          = $refund->get_date_created();
			$result['rows'][] = [
				'refund_id'   => $refund_id,
				'order_id'    => $refund->get_parent_id(),
				'refunded_on' => null !== $created ? (string) wp_date( 'Y-m-d', $created->getTimestamp() ) : '-',
				'amount'      => wc_format_decimal( $refund->get_amount(), wc_get_price_decimals() ),
				'number'      => '' !== $number ? $number : '-',
				'status'      => $status,
			];
		}

		$result['outcome'] = $dry_run ? 'dry_run' : 'done';

		return $result;
	}

	/**
	 * Distinct positive integer ids out of a `wc_get_orders()` result, sorted.
	 *
	 * @param mixed $result Result of `wc_get_orders()` with `return => ids`.
	 * @return int[]
	 */
	private static function ids( $result ): array {
		$ids = array_filter( array_unique( array_map( 'intval', is_array( $result ) ? $result : [] ) ), static fn( int $id ): bool => $id > 0 );
		sort( $ids );

		return $ids;
	}

	/**
	 * Issues one refund's credit note.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @param string          $number Receives the credit note number when issued.
	 * @return string `issued`, the refusal reason, or `failed`.
	 */
	private static function issue_credit_note( WC_Order_Refund $refund, string &$number ): string {
		try {
			$doc = CreditNoteIssuer::issue( $refund );
			if ( null !== $doc && 'issued' === $doc->status ) {
				$number = (string) $doc->number;
				return 'issued';
			}

			$fresh  = wc_get_order( $refund->get_id() );
			$reason = CreditNoteIssuer::refusal( $fresh instanceof WC_Order_Refund ? $fresh : $refund );
		} catch ( Throwable $e ) {
			Pipeline::log( 'Credit note issuing failed for refund ' . $refund->get_id() . ' (WP-CLI): ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			$reason = '';
		}

		return '' !== $reason ? $reason : 'failed';
	}

	/**
	 * Runs the import, or its dry run.
	 *
	 * A dry run never takes the lock nor changes the state. A real run goes through
	 * Migration::run() (lock, state, report); without `$force` it only runs while the import is
	 * not done. Nothing runs on a site without legacy data, even with `$force`.
	 *
	 * A real run is refused (`blocked`) while a requirement fails: the legacy plugin still active
	 * would keep numbering behind the import. A dry run only reads, and only needs WooCommerce.
	 *
	 * @param bool $dry_run True to report without writing.
	 * @param bool $force   True to run even when the import is done.
	 *
	 * @return array{outcome: string, rows: array<int, array{field: string, value: string}>, conflicts: array<int, array{order_id: int, number: string, reason: string}>, notes: string[], error: string}
	 *         `outcome`: `dry_run`, `done`, `done_with_conflicts`, `failed`, `busy`, `blocked`,
	 *         `already_done` or `no_data`.
	 */
	public static function migrate_data( bool $dry_run, bool $force ): array {
		$result = [
			'outcome'   => '',
			'rows'      => [],
			'conflicts' => [],
			'notes'     => [],
			'error'     => '',
		];

		$errors = Requirements::check();
		if ( $dry_run ) {
			$errors = array_intersect_key( $errors, [ 'woocommerce' => true ] );
		}

		if ( [] !== $errors ) {
			$result['outcome'] = 'blocked';
			$result['error']   = implode( ' ', $errors );
			return $result;
		}

		// A fresh lookup (never the cached negative answer); --force does not import "nothing" either.
		Migration::forget_no_data();
		if ( ! LegacySource::has_data() ) {
			$result['outcome'] = 'no_data';
			return $result;
		}

		if ( $dry_run ) {
			$report = Importer::run( true )->to_array();

			return array_merge( $result, self::from_report( $report ), [ 'outcome' => 'dry_run' ] );
		}

		$state = Migration::state();
		if ( ! $force && in_array( $state['status'], [ 'done', 'done_with_conflicts' ], true ) ) {
			$result['outcome'] = 'already_done';
			return $result;
		}

		if ( 'none' === $state['status'] ) {
			Migration::prepare();
		}

		$report = Migration::run( $force );
		$state  = Migration::state();

		if ( null === $report ) {
			$result['outcome'] = 'failed' === $state['status'] ? 'failed' : 'busy';
			$result['error']   = $state['error'];
			return $result;
		}

		return array_merge( $result, self::from_report( $report->to_array() ), [ 'outcome' => $state['status'] ] );
	}

	/**
	 * Rows of the status table.
	 *
	 * Besides the state and the last report: the requirements that keep the plugin from starting
	 * (`-` for none), the current counters (none while the tables do not exist) and the highest
	 * legacy sequence per key, which the counter of the same key must have reached after the import.
	 *
	 * @return array<int, array{field: string, value: string}>
	 */
	public static function status_rows(): array {
		$state  = Migration::state();
		$by_key = Requirements::check();
		$errors = array_keys( $by_key );

		$rows = [
			self::row( 'Status', $state['status'] ),
			self::row( 'Blocked by', [] === $errors ? '-' : implode( ', ', $errors ) ),
			self::row( 'Issuing blocked', isset( $by_key['woocommerce'] ) ? 'unknown' : ( Migration::blocks_issuing() ? 'yes' : 'no' ) ),
			self::row( 'Started', self::date( $state['started_at'] ) ),
			self::row( 'Finished', self::date( $state['finished_at'] ) ),
		];

		if ( '' !== $state['error'] ) {
			$rows[] = self::row( 'Last error', $state['error'] );
		}

		if ( [] !== $state['report'] ) {
			$rows = array_merge( $rows, self::from_report( $state['report'] )['rows'] );
		}

		foreach ( Installer::tables_exist() ? Sequences::all() : [] as $sequence ) {
			$rows[] = self::row( 'Current counter ' . $sequence['key'], (string) $sequence['value'] );
		}

		$legacy = LegacySource::max_sequences();
		foreach ( LegacySource::sequences() as $key => $value ) {
			$legacy[ $key ] = max( $legacy[ $key ] ?? 0, $value );
		}
		ksort( $legacy );

		foreach ( $legacy as $key => $value ) {
			$rows[] = self::row( 'Highest legacy sequence ' . $key, (string) $value );
		}

		return $rows;
	}

	/**
	 * Table rows, conflicts and notes of a report.
	 *
	 * @param array<string, mixed> $report Report::to_array().
	 * @return array{rows: array<int, array{field: string, value: string}>, conflicts: array<int, array{order_id: int, number: string, reason: string}>, notes: string[]}
	 */
	private static function from_report( array $report ): array {
		$conflicts = is_array( $report['conflicts'] ?? null ) ? array_values( $report['conflicts'] ) : [];

		$rows = [
			self::row( 'Settings', (string) ( $report['settings'] ?? '' ) ),
			self::row( 'Invoices imported', (string) (int) ( $report['invoices_imported'] ?? 0 ) ),
			self::row( 'Invoices already imported', (string) (int) ( $report['invoices_already'] ?? 0 ) ),
			self::row( 'PDFs copied', (string) (int) ( $report['files_copied'] ?? 0 ) ),
			self::row( 'PDFs missing (built on first need)', (string) (int) ( $report['files_missing'] ?? 0 ) ),
			self::row( 'PDFs refused', (string) (int) ( $report['files_invalid'] ?? 0 ) ),
			self::row( 'Conflicts', (string) count( $conflicts ) ),
		];

		foreach ( is_array( $report['sequences'] ?? null ) ? $report['sequences'] : [] as $key => $value ) {
			$rows[] = self::row( 'Counter ' . $key, (string) (int) $value );
		}

		$notes = is_array( $report['notes'] ?? null ) ? array_map( 'strval', $report['notes'] ) : [];

		/** @var array<int, array{order_id: int, number: string, reason: string}> $conflicts */
		return [
			'rows'      => $rows,
			'conflicts' => $conflicts,
			'notes'     => array_values( $notes ),
		];
	}

	/**
	 * Prints the report table, the conflicts and the notes.
	 *
	 * @param array{rows: array<int, array{field: string, value: string}>, conflicts: array<int, array{order_id: int, number: string, reason: string}>, notes: string[]} $result Result.
	 */
	private static function print_result( array $result ): void {
		WP_CLI\Utils\format_items( 'table', $result['rows'], [ 'field', 'value' ] );

		if ( [] !== $result['conflicts'] ) {
			WP_CLI::log( 'Conflicts (not imported):' );
			WP_CLI\Utils\format_items( 'table', $result['conflicts'], [ 'order_id', 'number', 'reason' ] );
		}

		foreach ( $result['notes'] as $note ) {
			WP_CLI::log( '- ' . $note );
		}
	}

	/**
	 * One table row.
	 *
	 * @param string $field Label.
	 * @param string $value Value.
	 * @return array{field: string, value: string}
	 */
	private static function row( string $field, string $value ): array {
		return [
			'field' => $field,
			'value' => $value,
		];
	}

	/**
	 * Site-time date, or a dash.
	 *
	 * @param int $timestamp UTC timestamp, 0 for none.
	 */
	private static function date( int $timestamp ): string {
		return $timestamp > 0 ? (string) wp_date( 'Y-m-d H:i:s', $timestamp ) : '-';
	}
}
