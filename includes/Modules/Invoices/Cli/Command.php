<?php
/**
 * WP-CLI commands of the invoices module.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Cli;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Migration\Importer;
use Lumia\Factures\Modules\Invoices\Migration\LegacySource;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use WP_CLI;

/**
 * Imports the Facture PDF Lumia (1.x) data and shows the import status.
 *
 * The logic lives in static methods returning data (`migrate_data()`, `status_rows()`), so the
 * tests call them without WP-CLI; the subcommands only print.
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
	 * Runs the import, or its dry run.
	 *
	 * A dry run never takes the lock nor changes the state. A real run goes through
	 * Migration::run() (lock, state, report); without `$force` it only runs while the import is
	 * not done. Nothing runs on a site without legacy data, even with `$force`.
	 *
	 * @param bool $dry_run True to report without writing.
	 * @param bool $force   True to run even when the import is done.
	 * @return array{outcome: string, rows: array<int, array{field: string, value: string}>, conflicts: array<int, array{order_id: int, number: string, reason: string}>, notes: string[], error: string}
	 *         `outcome`: `dry_run`, `done`, `done_with_conflicts`, `failed`, `busy`, `already_done` or `no_data`.
	 */
	public static function migrate_data( bool $dry_run, bool $force ): array {
		$result = [
			'outcome'   => '',
			'rows'      => [],
			'conflicts' => [],
			'notes'     => [],
			'error'     => '',
		];

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
	 * @return array<int, array{field: string, value: string}>
	 */
	public static function status_rows(): array {
		$state = Migration::state();

		$rows = [
			self::row( 'Status', $state['status'] ),
			self::row( 'Issuing blocked', Migration::blocks_issuing() ? 'yes' : 'no' ),
			self::row( 'Started', self::date( $state['started_at'] ) ),
			self::row( 'Finished', self::date( $state['finished_at'] ) ),
		];

		if ( '' !== $state['error'] ) {
			$rows[] = self::row( 'Last error', $state['error'] );
		}

		if ( [] !== $state['report'] ) {
			$rows = array_merge( $rows, self::from_report( $state['report'] )['rows'] );
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
