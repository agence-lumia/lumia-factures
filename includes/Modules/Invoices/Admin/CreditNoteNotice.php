<?php
/**
 * Manager notice of the refunds still waiting for their credit note, and its issue action.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Amounts\Money;
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Throwable;
use WC_Order_Refund;

/**
 * Counts the refunds without a credit note that should have one: those created since
 * `lumia_factures_credit_notes_since` that refund something and carry no number, and those
 * flagged pending (`_lumia_factures_credit_note_pending`). Older refunds are issued on demand
 * only (order screen, WP-CLI) and never counted.
 *
 * Printed on `all_admin_notices`, not `admin_notices`: lumia-tools moves `admin_notices` into its
 * notification drawer.
 */
final class CreditNoteNotice {

	/**
	 * Admin-post action and nonce action.
	 */
	public const ACTION = 'lumia_factures_invoices_issue_pending_credit_notes';

	/**
	 * Most refunds read per query (the notice runs on every admin page).
	 */
	public const LIMIT = 100;

	/**
	 * Notice code of the outcome.
	 */
	private const OUTCOME = 'credit_notes_pending_done';

	/**
	 * Registers the hooks (admin only).
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'all_admin_notices', [ self::class, 'render' ] );
	}

	/**
	 * Ids of the refunds waiting for their credit note, by increasing id, at most LIMIT of each kind.
	 *
	 * Two single-clause meta queries (the legacy order storage ignores `meta_query`): the refunds
	 * created since the option without a number, filtered in PHP (something refunded, not issued),
	 * and the flagged ones.
	 *
	 * @return int[]
	 */
	public static function pending_ids(): array {
		$missed = wc_get_orders(
			[
				'type'         => 'shop_order_refund',
				'limit'        => self::LIMIT,
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'return'       => 'ids',
				'date_created' => '>=' . CreditNoteIssuer::since(),
				'meta_key'     => CreditNoteIssuer::NUMBER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded by LIMIT.
				'meta_compare' => 'NOT EXISTS',
			]
		);

		$ids = [];
		foreach ( self::ids( $missed ) as $refund_id ) {
			$refund = wc_get_order( $refund_id );
			if ( ! $refund instanceof WC_Order_Refund || Money::to_minor( $refund->get_amount() ) <= 0 ) {
				continue;
			}

			$doc = CreditNoteIssuer::find_for( $refund );
			if ( null === $doc || 'issued' !== $doc->status ) {
				$ids[] = $refund_id;
			}
		}

		$ids = array_values( array_unique( array_merge( $ids, CreditNoteIssuer::pending_refund_ids( self::LIMIT ) ) ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * Issues the credit notes of the pending refunds (see pending_ids()).
	 *
	 * @return array{issued: int, left: int} Credit notes issued, refunds still without one.
	 */
	public static function issue_pending(): array {
		$issued = 0;
		$ids    = self::pending_ids();

		foreach ( $ids as $refund_id ) {
			try {
				$refund = wc_get_order( $refund_id );
				$doc    = $refund instanceof WC_Order_Refund ? CreditNoteIssuer::issue( $refund ) : null;
				if ( null !== $doc && 'issued' === $doc->status ) {
					++$issued;
				}
			} catch ( Throwable $e ) {
				error_log( '[lumia-factures] Credit note issuing failed for refund ' . $refund_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
			}
		}

		return [
			'issued' => $issued,
			'left'   => count( self::pending_ids() ),
		];
	}

	/**
	 * Prints the outcome of the action, then the count of pending refunds with the issue button.
	 * Nothing while the import blocks issuing (its own notice explains why).
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::render_outcome();

		if ( Migration::blocks_issuing() ) {
			return;
		}

		$count = count( self::pending_ids() );
		if ( 0 === $count ) {
			return;
		}

		echo '<div class="notice notice-warning lumia-factures-credit-notes-notice"><p><strong>';
		printf(
			/* translators: %d: number of refunds */
			esc_html( _n( 'Lümia Factures: %d refund has no credit note yet.', 'Lümia Factures: %d refunds have no credit note yet.', $count, 'lumia-factures' ) ),
			(int) $count
		);
		echo '</strong></p><p>';
		esc_html_e( 'The reason is shown in the Invoice box of each order. Refunds made before credit notes existed are not counted: issue theirs from the order or with "wp lumia-factures issue-credit-notes".', 'lumia-factures' );
		echo '</p>';
		printf(
			'<form method="post" action="%1$s"><p><input type="hidden" name="action" value="%2$s">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ACTION )
		);
		// Printed by hand: wp_nonce_field() gives the field the id `_wpnonce`, which the order edit form uses too.
		printf( '<input type="hidden" name="_wpnonce" value="%s">', esc_attr( wp_create_nonce( self::ACTION ) ) );
		wp_referer_field();
		printf(
			'<button type="submit" class="button button-primary">%s</button></p></form></div>',
			esc_html__( 'Issue the pending credit notes', 'lumia-factures' )
		);
	}

	/**
	 * Admin-post handler: capability, nonce, issue, redirect back with the outcome.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::ACTION );

		$result = self::issue_pending();
		$back   = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url();
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'lumia_factures_notice' => self::OUTCOME,
					'lumia_factures_issued' => $result['issued'],
					'lumia_factures_left'   => $result['left'],
				],
				remove_query_arg( [ 'lumia_factures_notice', 'lumia_factures_notice_type', 'lumia_factures_issued', 'lumia_factures_left' ], $back )
			)
		);
		exit;
	}

	/**
	 * Integer ids out of a `wc_get_orders()` result.
	 *
	 * @param mixed $result Result of `wc_get_orders()` with `return => ids`.
	 * @return int[]
	 */
	private static function ids( $result ): array {
		return array_map( 'intval', is_array( $result ) ? $result : [] );
	}

	/**
	 * Prints the outcome of the last action, read from the query string.
	 */
	private static function render_outcome(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no state change.
		$code = isset( $_GET['lumia_factures_notice'] ) ? sanitize_key( wp_unslash( $_GET['lumia_factures_notice'] ) ) : '';
		if ( self::OUTCOME !== $code ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no state change.
		$issued = isset( $_GET['lumia_factures_issued'] ) ? absint( wp_unslash( $_GET['lumia_factures_issued'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no state change.
		$left = isset( $_GET['lumia_factures_left'] ) ? absint( wp_unslash( $_GET['lumia_factures_left'] ) ) : 0;

		$message = sprintf(
			/* translators: %d: number of credit notes */
			_n( '%d credit note issued.', '%d credit notes issued.', $issued, 'lumia-factures' ),
			$issued
		);
		if ( $left > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of refunds */
				_n( '%d refund still has no credit note.', '%d refunds still have no credit note.', $left, 'lumia-factures' ),
				$left
			);
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $left > 0 ? 'warning' : 'success' ),
			esc_html( $message )
		);
	}
}
