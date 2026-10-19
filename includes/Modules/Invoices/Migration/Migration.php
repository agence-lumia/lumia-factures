<?php
/**
 * Lifecycle of the legacy Facture PDF Lumia import.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

use Throwable;

/**
 * Runs the import once, by itself, and tells the issuer when numbering may start.
 *
 * State (option `lumia_factures_migration`): `none` (no legacy data, nothing to do), `pending`,
 * `running`, `done`, `done_with_conflicts` or `failed`, with the start and end times, the last
 * report and the last error. One run at a time: a lock row inserted with `INSERT IGNORE`, which
 * only one request can create. A lock or a `running` state older than STALE_AFTER comes from a
 * request that died mid-run: the run is retried (the importer resumes where it stopped).
 */
final class Migration {

	/**
	 * Option holding the state.
	 */
	public const OPTION = 'lumia_factures_migration';

	/**
	 * Option used as the run lock. Value: `{timestamp}-{token}`.
	 */
	public const LOCK = 'lumia_factures_migration_lock';

	/**
	 * Age in seconds after which a run lock or a `running` state is a crash leftover.
	 */
	public const STALE_AFTER = 15 * MINUTE_IN_SECONDS;

	/**
	 * Statuses after which invoices may be issued.
	 */
	private const FINISHED = [ 'done', 'done_with_conflicts' ];

	/**
	 * Current state. A `running` state older than STALE_AFTER is reported as `failed`.
	 *
	 * @return array{status: string, started_at: int, finished_at: int, report: array<string, mixed>, error: string}
	 */
	public static function state(): array {
		$state = self::normalize( get_option( self::OPTION, [] ) );

		if ( 'running' === $state['status'] && $state['started_at'] < time() - self::STALE_AFTER ) {
			$state['status'] = 'failed';
		}

		return $state;
	}

	/**
	 * Marks the import as pending when the site has legacy data and no import was ever planned.
	 * A site without legacy data keeps the status `none` and no option is written.
	 */
	public static function prepare(): void {
		if ( 'none' !== self::state()['status'] || ! LegacySource::has_data() ) {
			return;
		}

		self::save(
			[
				'status'      => 'pending',
				'started_at'  => 0,
				'finished_at' => 0,
				'report'      => [],
				'error'       => '',
			]
		);
	}

	/**
	 * Runs the import when it is pending or failed (a stale `running` state counts as failed).
	 */
	public static function maybe_run(): void {
		if ( ! in_array( self::state()['status'], [ 'pending', 'failed' ], true ) ) {
			return;
		}

		self::run();
	}

	/**
	 * Runs the import now, under the lock.
	 *
	 * @param bool $force True to run whatever the status (WP-CLI `--force`); otherwise only a
	 *                    pending or failed import runs.
	 * @return Report|null The report, null when nothing ran (another run holds the lock, the import
	 *                     is not due, or it failed: the error is in the state).
	 */
	public static function run( bool $force = false ): ?Report {
		$token = self::lock();
		if ( null === $token ) {
			return null;
		}

		try {
			// Read again under the lock: another request may have finished the import meanwhile.
			$state = self::state_from_db();
			if ( ! $force && ! in_array( $state['status'], [ 'pending', 'failed' ], true ) ) {
				return null;
			}

			$started = time();
			self::save(
				[
					'status'      => 'running',
					'started_at'  => $started,
					'finished_at' => 0,
					'report'      => $state['report'],
					'error'       => '',
				]
			);

			self::raise_limits();

			try {
				$report = Importer::run( false );
			} catch ( Throwable $e ) {
				self::log( 'The Facture PDF Lumia import failed: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
				self::save(
					[
						'status'      => 'failed',
						'started_at'  => $started,
						'finished_at' => time(),
						'report'      => $state['report'],
						'error'       => $e->getMessage(),
					]
				);
				return null;
			}

			self::save(
				[
					'status'      => $report->has_conflicts() ? 'done_with_conflicts' : 'done',
					'started_at'  => $started,
					'finished_at' => time(),
					'report'      => $report->to_array(),
					'error'       => '',
				]
			);

			return $report;
		} finally {
			self::unlock( $token );
		}
	}

	/**
	 * Whether invoice numbering must wait: legacy data exists and its import is not finished.
	 *
	 * Only `none` needs a look at the legacy data: every other unfinished status was set because
	 * legacy data was found.
	 */
	public static function blocks_issuing(): bool {
		$status = self::state()['status'];

		if ( in_array( $status, self::FINISHED, true ) ) {
			return false;
		}

		return 'none' !== $status || LegacySource::has_data();
	}

	/**
	 * Takes the run lock.
	 *
	 * `add_option()` is not atomic (it reads first, then inserts with ON DUPLICATE KEY UPDATE, so two
	 * requests can both succeed): the row is inserted with INSERT IGNORE, which only one request
	 * can do. A stale lock is taken over with a compare-and-swap UPDATE on its old value.
	 *
	 * @return string|null Lock value, null when another run holds it.
	 */
	private static function lock(): ?string {
		global $wpdb;

		$token = time() . '-' . wp_generate_password( 12, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock, the options API cannot do it.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK, $token ) );
		self::forget( self::LOCK );

		if ( 1 === $inserted ) {
			return $token;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass the cache.
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		if ( ! is_string( $current ) || (int) $current >= time() - self::STALE_AFTER ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on the stale value.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, self::LOCK, $current ) );
		self::forget( self::LOCK );

		if ( 1 !== $taken ) {
			return null;
		}

		self::log( 'Took over the stale Facture PDF Lumia import lock ' . $current . ' (older than ' . self::STALE_AFTER . ' seconds).' );

		return $token;
	}

	/**
	 * Releases the lock, only when it is still ours (a run slower than STALE_AFTER may have lost it).
	 *
	 * @param string $token Lock value.
	 */
	private static function unlock( string $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded delete of the lock row.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $token ) );
		self::forget( self::LOCK );
	}

	/**
	 * State read from the database, bypassing the object cache.
	 *
	 * @return array{status: string, started_at: int, finished_at: int, report: array<string, mixed>, error: string}
	 */
	private static function state_from_db(): array {
		self::forget( self::OPTION );

		return self::state();
	}

	/**
	 * Saves the state.
	 *
	 * @param array{status: string, started_at: int, finished_at: int, report: array<string, mixed>, error: string} $state State.
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Drops an option from the object cache, so the next read hits the database.
	 *
	 * @param string $option Option name.
	 */
	private static function forget( string $option ): void {
		wp_cache_delete( $option, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * Fills the state with its defaults.
	 *
	 * @param mixed $stored Stored option.
	 * @return array{status: string, started_at: int, finished_at: int, report: array<string, mixed>, error: string}
	 */
	private static function normalize( $stored ): array {
		$stored = is_array( $stored ) ? $stored : [];
		$status = isset( $stored['status'] ) && is_string( $stored['status'] ) ? $stored['status'] : 'none';

		if ( ! in_array( $status, [ 'none', 'pending', 'running', 'done', 'done_with_conflicts', 'failed' ], true ) ) {
			$status = 'none';
		}

		return [
			'status'      => $status,
			'started_at'  => isset( $stored['started_at'] ) ? (int) $stored['started_at'] : 0,
			'finished_at' => isset( $stored['finished_at'] ) ? (int) $stored['finished_at'] : 0,
			'report'      => isset( $stored['report'] ) && is_array( $stored['report'] ) ? $stored['report'] : [],
			'error'       => isset( $stored['error'] ) && is_string( $stored['error'] ) ? $stored['error'] : '',
		];
	}

	/**
	 * Gives the run room: admin memory limit, and at least 300 seconds when a limit applies.
	 * An unlimited execution time (CLI) is never lowered.
	 */
	private static function raise_limits(): void {
		wp_raise_memory_limit( 'admin' );

		$current = (int) ini_get( 'max_execution_time' );
		if ( $current > 0 && $current < 300 && function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- The import of a large shop needs it; it resumes if cut off.
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
