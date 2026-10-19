<?php
/**
 * Blocking notice of the legacy import, and its retry action.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Migration\Migration;

/**
 * While the Facture PDF Lumia import blocks invoice numbering, every admin screen shows why, the
 * import status and counts, and a "Run the import now" button (admin-post, nonce + capability).
 *
 * Printed on `all_admin_notices`, not `admin_notices`: lumia-tools moves `admin_notices` into its
 * notification drawer, which would hide a blocking notice.
 */
final class MigrationNotice {

	/**
	 * Admin-post action and nonce action.
	 */
	public const ACTION = 'lumia_factures_invoices_migrate';

	/**
	 * Registers the hooks (admin only).
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'all_admin_notices', [ self::class, 'render' ] );
	}

	/**
	 * Prints the outcome of a retry, then the blocking notice while numbering is blocked.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::render_outcome();

		if ( ! Migration::blocks_issuing() ) {
			return;
		}

		$state  = Migration::state();
		$report = $state['report'];

		echo '<div class="notice notice-error lumia-factures-migration-notice"><p><strong>';
		esc_html_e( 'Lümia Factures: no invoice is issued until the Facture PDF Lumia data is imported.', 'lumia-factures' );
		echo '</strong></p><p>';
		printf(
			/* translators: %s: import status */
			esc_html__( 'Import status: %s.', 'lumia-factures' ),
			esc_html( self::status_label( $state['status'] ) )
		);
		echo '</p>';

		if ( [] !== $report ) {
			echo '<p>';
			printf(
				/* translators: 1: invoices imported, 2: invoices already imported, 3: PDFs copied, 4: PDFs missing, 5: conflicts */
				esc_html__( 'Last run: %1$d invoices imported, %2$d already imported, %3$d PDFs copied, %4$d PDFs missing, %5$d conflicts.', 'lumia-factures' ),
				(int) ( $report['invoices_imported'] ?? 0 ),
				(int) ( $report['invoices_already'] ?? 0 ),
				(int) ( $report['files_copied'] ?? 0 ),
				(int) ( $report['files_missing'] ?? 0 ),
				is_array( $report['conflicts'] ?? null ) ? count( $report['conflicts'] ) : 0
			);
			echo '</p>';
		}

		if ( '' !== $state['error'] ) {
			echo '<p>';
			printf(
				/* translators: %s: error message */
				esc_html__( 'Last error: %s', 'lumia-factures' ),
				esc_html( $state['error'] )
			);
			echo '</p>';
		}

		printf(
			'<form method="post" action="%1$s"><p><input type="hidden" name="action" value="%2$s">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ACTION )
		);
		wp_nonce_field( self::ACTION );
		printf(
			'<button type="submit" class="button button-primary">%s</button></p></form></div>',
			esc_html__( 'Run the import now', 'lumia-factures' )
		);
	}

	/**
	 * Runs the import when it is due (a fresh legacy data lookup, no back-off delay).
	 *
	 * @return string Notice code: `migration_done`, `migration_conflicts`, `migration_failed`,
	 *                `migration_busy` (another run holds the lock) or `migration_nothing`.
	 */
	public static function migrate_for(): string {
		Migration::forget_no_data();

		if ( 'none' === Migration::state()['status'] ) {
			Migration::prepare();
		}

		// Manual: a failed import is retried at once, without the automatic back-off.
		Migration::maybe_run( true );

		return match ( Migration::state()['status'] ) {
			'done'                => 'migration_done',
			'done_with_conflicts' => 'migration_conflicts',
			'failed'              => 'migration_failed',
			'none'                => 'migration_nothing',
			default               => 'migration_busy',
		};
	}

	/**
	 * Admin-post handler: capability, nonce, run, redirect back with the outcome.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::ACTION );

		$code = self::migrate_for();
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url();
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'lumia_factures_notice' => $code ],
				remove_query_arg( [ 'lumia_factures_notice', 'lumia_factures_notice_type' ], $back )
			)
		);
		exit;
	}

	/**
	 * Prints the outcome of the last retry, read from the query string.
	 */
	private static function render_outcome(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no state change.
		$code     = isset( $_GET['lumia_factures_notice'] ) ? sanitize_key( wp_unslash( $_GET['lumia_factures_notice'] ) ) : '';
		$messages = [
			'migration_done'      => [ 'success', __( 'The Facture PDF Lumia data has been imported. Invoices are issued again.', 'lumia-factures' ) ],
			'migration_conflicts' => [ 'warning', __( 'The Facture PDF Lumia data has been imported, but some invoices were skipped: run "wp lumia-factures migration-status" or check the PHP error log.', 'lumia-factures' ) ],
			'migration_failed'    => [ 'error', __( 'The Facture PDF Lumia import failed. See the PHP error log, then try again.', 'lumia-factures' ) ],
			'migration_busy'      => [ 'warning', __( 'The Facture PDF Lumia import is already running. Reload this page in a few minutes.', 'lumia-factures' ) ],
			'migration_nothing'   => [ 'success', __( 'There is no Facture PDF Lumia data to import.', 'lumia-factures' ) ],
		];

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * Human label of a status.
	 *
	 * @param string $status Status.
	 */
	private static function status_label( string $status ): string {
		return match ( $status ) {
			'pending' => __( 'waiting to run', 'lumia-factures' ),
			'running' => __( 'running', 'lumia-factures' ),
			'failed'  => __( 'failed', 'lumia-factures' ),
			default   => __( 'not started', 'lumia-factures' ),
		};
	}
}
