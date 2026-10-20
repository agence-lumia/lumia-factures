<?php
/**
 * Issues credit notes: one numbered, archived document per refund of an invoiced order.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Documents;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Amounts\CreditNoteStatementBuilder;
use Lumia\Factures\Modules\Invoices\Amounts\Money;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Rendering\DocumentData;
use Lumia\Factures\Modules\Invoices\Rendering\Renderer;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Throwable;
use WC_Order;
use WC_Order_Refund;

/**
 * One refund, one credit note with its own gapless number series and a write-once archived PDF.
 *
 * A credit note is a register row of type `credit_note` whose `order_id` is the refund id and
 * `parent_id` the refunded order. It is issued on `woocommerce_order_refunded` only: WooCommerce
 * deletes the refund when the gateway refuses it, after `woocommerce_create_refund` and before
 * that hook. Reserving, numbering and archiving go through the shared Pipeline; the gate, the
 * refund meta, the notes and the hooks are the credit note's own. A credit note always cites the
 * invoice it credits: the order's invoice is issued first when the order may still get one,
 * otherwise the refund is flagged pending (PENDING_META) and issued once the invoice exists.
 */
final class CreditNoteIssuer {

	/**
	 * Document type of a credit note.
	 */
	public const TYPE = 'credit_note';

	/**
	 * Refund meta holding the credit note number.
	 */
	public const NUMBER_META = '_lumia_factures_credit_note_number';

	/**
	 * Refund meta holding the credit note issue date (UTC timestamp).
	 */
	public const DATE_META = '_lumia_factures_credit_note_date';

	/**
	 * Refund meta holding why the credit note was refused (see refusal()), written once per reason.
	 */
	public const PENDING_META = '_lumia_factures_credit_note_pending';

	/**
	 * Order meta listing the ids of its refunds deleted while they carried a credit note: a later
	 * refund given one of those ids again (MySQL may reuse a freed AUTO_INCREMENT value) never
	 * takes the old credit note over.
	 */
	public const DELETED_META = '_lumia_factures_deleted_refunds';

	/**
	 * Option holding the UTC timestamp from which credit notes are issued by themselves: the
	 * install or upgrade to 2.1. An order created before it gets no invoice for a credit note,
	 * and the sweep leaves the refunds made before it alone (they are issued on demand).
	 */
	public const SINCE_OPTION = 'lumia_factures_credit_notes_since';

	/**
	 * Daily cron hook retrying the refunds left without a credit note.
	 */
	public const SWEEP_HOOK = 'lumia_factures_credit_notes_sweep';

	/**
	 * Refunds handled by one sweep run at most.
	 */
	public const SWEEP_LIMIT = 20;

	/**
	 * Refunds without a credit note number read per page by the sweep.
	 */
	private const SWEEP_PAGE_SIZE = 100;

	/**
	 * Pages read at most by one sweep run.
	 */
	private const SWEEP_PAGES = 5;

	/**
	 * Prefix of the per-user transient listing the credit notes whose refund deletion was refused.
	 */
	private const KEPT_TRANSIENT = 'lumia_factures_refund_kept_';

	/**
	 * Refund ids whose deletion was already noted in this request (two hooks report an admin deletion).
	 *
	 * @var array<int, true>
	 */
	private static array $deletions_noted = [];

	/**
	 * Hook `woocommerce_order_refunded`: issues the refund's credit note.
	 *
	 * Never throws: a failure is logged and must not break the refund.
	 *
	 * @param mixed $order_id  Order id (unused, read from the refund).
	 * @param mixed $refund_id Refund id.
	 */
	public static function on_refunded( $order_id, $refund_id = 0 ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Action signature.
		try {
			$refund = wc_get_order( (int) $refund_id );
			if ( $refund instanceof WC_Order_Refund ) {
				self::issue( $refund );
			}
		} catch ( Throwable $e ) {
			Pipeline::log( 'Credit note issuing failed for refund ' . (int) $refund_id . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
		}
	}

	/**
	 * Hook `lumia_factures_invoice_issued`: issues the credit notes the order's refunds were waiting for.
	 *
	 * @param mixed $doc   Issued invoice.
	 * @param mixed $order Its order.
	 */
	public static function on_invoice_issued( $doc, $order = null ): void {
		if ( ! $doc instanceof IssuedDocument || Issuer::TYPE !== $doc->type ) {
			return;
		}

		try {
			if ( ! $order instanceof WC_Order ) {
				$order = wc_get_order( $doc->order_id );
			}

			if ( $order instanceof WC_Order ) {
				self::issue_pending( $order );
			}
		} catch ( Throwable $e ) {
			Pipeline::log( 'Credit notes of order ' . $doc->order_id . ' could not be issued after its invoice: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
		}
	}

	/**
	 * The refund's credit note, as recorded in the register.
	 *
	 * The register row is the refund's only when it belongs to the refund's order and, when the
	 * refund carries a credit note number, that number is the row's. A refund without the number
	 * whose id the order recorded as deleted (DELETED_META) is a new refund given a freed id again:
	 * the row is not its own. Never writes.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @return IssuedDocument|null The row (reserved or issued), null when the refund has none.
	 */
	public static function find_for( WC_Order_Refund $refund ): ?IssuedDocument {
		$refund_id = $refund->get_id();
		if ( $refund_id <= 0 ) {
			return null;
		}

		$doc = Register::find( self::TYPE, $refund_id );
		if ( null === $doc || $doc->parent_id <= 0 || $doc->parent_id !== $refund->get_parent_id() ) {
			return null;
		}

		$recorded = (string) $refund->get_meta( self::NUMBER_META );
		if ( '' !== $recorded ) {
			return $recorded === (string) $doc->number ? $doc : null;
		}

		return self::deleted_refund( $doc ) ? null : $doc;
	}

	/**
	 * Issues the refund's credit note. Idempotent: an issued credit note is returned as is.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @return IssuedDocument|null The issued credit note, null when not issued (nothing refunded,
	 *                             refused by the gate, being issued by another call, or failure).
	 */
	public static function issue( WC_Order_Refund $refund ): ?IssuedDocument {
		$refund_id = $refund->get_id();
		if ( $refund_id <= 0 ) {
			return null;
		}

		$existing = self::find_for( $refund );
		if ( null !== $existing && 'issued' === $existing->status ) {
			// Repairs the refund meta when an earlier call failed after numbering.
			Pipeline::guard( self::TYPE, 'refund meta back-fill', $refund_id, static fn() => self::sync_meta( $existing, $refund ) );
			return $existing;
		}

		// A refund of nothing credits nothing: no credit note, and nothing pending either.
		if ( ! self::has_amount( $refund ) ) {
			return null;
		}

		$parent = self::parent( $refund );
		if ( null === $parent ) {
			self::refuse( $refund, 'no_order' );
			return null;
		}

		$reason = self::gate( $refund, $parent );
		if ( '' !== $reason ) {
			self::refuse( $refund, $reason );
			return null;
		}

		if ( ! self::invoiced( $parent ) ) {
			if ( ! self::follows_since( $parent ) ) {
				self::refuse( $refund, 'no_invoice' );
				return null;
			}

			// The invoice comes first. Its `lumia_factures_invoice_issued` action issues the credit
			// notes of the order (on_invoice_issued()), this refund's included.
			Issuer::issue( $parent );

			$nested = self::find_for( $refund );
			if ( null !== $nested && 'issued' === $nested->status ) {
				return $nested;
			}

			if ( ! self::invoiced( $parent ) ) {
				self::refuse( $refund, 'no_invoice' );
				return null;
			}
		}

		$parent_id = $parent->get_id();
		$id        = Pipeline::reserve( self::TYPE, $refund_id, $parent_id );
		if ( null === $id ) {
			// Another call holds the row: return its credit note once issued, never number twice.
			$current = self::find_for( $refund );
			return null !== $current && 'issued' === $current->status ? $current : null;
		}

		$settings = Module::settings();
		$now      = time();
		$notes    = Notes::credit_note( $refund_id );
		$assigned = Pipeline::number( self::TYPE, $id, $parent, $settings['credit_notes'], $now, $notes );
		if ( null === $assigned ) {
			return null;
		}

		[ $number, $key, $sequence ] = $assigned;

		// The number is recorded: from here on every step is attempted even when an earlier one
		// fails, and each failure is logged.
		$doc = new IssuedDocument( $id, self::TYPE, $refund_id, 'issued', $number, $key, $sequence, $now, null, null, $parent_id );

		Pipeline::guard( self::TYPE, 'refund meta', $refund_id, static fn() => self::sync_meta( $doc, $refund ) );

		$invoice     = Register::find( Issuer::TYPE, $parent_id );
		$issued_note = sprintf(
			/* translators: 1: credit note number, 2: refund id, 3: invoice number */
			__( 'Credit note %1$s issued for refund #%2$d (invoice %3$s).', 'lumia-factures' ),
			$number,
			$refund_id,
			null !== $invoice ? (string) $invoice->number : ''
		);
		// Written before rendering, so a fatal error inside Dompdf still leaves the number on the order.
		Pipeline::guard( self::TYPE, 'order note', $refund_id, static fn() => self::note( $parent, $issued_note ) );

		$outcome = Pipeline::archive(
			$doc,
			static function () use ( $doc, $refund, $parent, $settings ): string {
				// Rendered from a fresh refund and order: the objects in hand predate the number.
				$fresh_refund = wc_get_order( $refund->get_id() );
				$fresh_parent = wc_get_order( $parent->get_id() );
				return self::render(
					$doc,
					$fresh_refund instanceof WC_Order_Refund ? $fresh_refund : $refund,
					$fresh_parent instanceof WC_Order ? $fresh_parent : $parent,
					$settings
				);
			}
		);

		if ( 'stored' !== $outcome ) {
			Pipeline::guard( self::TYPE, 'PDF failure note', $refund_id, static fn() => self::note( $parent, $notes->pdf_failure( $outcome, $number ) ) );
		}

		$doc = Register::find( self::TYPE, $refund_id ) ?? $doc;

		Pipeline::guard(
			self::TYPE,
			'issued action',
			$refund_id,
			static function () use ( $doc, $refund, $parent ): void {
				/**
				 * Fires once, when a credit note has been numbered (its PDF may be missing if rendering failed).
				 *
				 * @param IssuedDocument  $doc    Issued credit note.
				 * @param WC_Order_Refund $refund Its refund.
				 * @param WC_Order        $parent The refunded order.
				 */
				do_action( 'lumia_factures_credit_note_issued', $doc, $refund, $parent );
			}
		);

		return $doc;
	}

	/**
	 * Why the refund has no credit note and would not get one now. Reads only (order screen, dry runs).
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @return string '' when the credit note is issued or would be issued now; otherwise
	 *                `zero_amount` (nothing refunded, never pending), `no_order` (no refunded order),
	 *                `import` (the Facture PDF Lumia import is not finished), `conflict` (the order
	 *                has a legacy invoice that could not be imported), `reused_id` (the register
	 *                holds the credit note of a deleted refund that had this id), `vetoed` (filter
	 *                `lumia_factures_can_issue_credit_note`), `no_invoice` (the order has no invoice
	 *                and gets none by itself).
	 */
	public static function refusal( WC_Order_Refund $refund ): string {
		$existing = self::find_for( $refund );
		if ( null !== $existing && 'issued' === $existing->status ) {
			return '';
		}

		if ( ! self::has_amount( $refund ) ) {
			return 'zero_amount';
		}

		$parent = self::parent( $refund );
		if ( null === $parent ) {
			return 'no_order';
		}

		$reason = self::gate( $refund, $parent );
		if ( '' !== $reason ) {
			return $reason;
		}

		// The gate passed, so Issuer::can_issue() writes nothing here (it notes gated orders only).
		if ( ! self::invoiced( $parent ) && ( ! self::follows_since( $parent ) || ! Issuer::can_issue( $parent ) ) ) {
			return 'no_invoice';
		}

		return '';
	}

	/**
	 * Issues the credit notes of every refund of the order that has none, by increasing refund id.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, IssuedDocument|null> Credit note (or null when not issued) keyed by refund id.
	 */
	public static function issue_pending( WC_Order $order ): array {
		$order_id = $order->get_id();
		if ( $order_id <= 0 ) {
			return [];
		}

		// Queried, not read through get_refunds(): its cache may predate a refund saved in this request.
		$ids = self::ids(
			wc_get_orders(
				[
					'type'    => 'shop_order_refund',
					'parent'  => $order_id,
					'limit'   => -1,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'ids',
				]
			)
		);

		$results = [];
		foreach ( $ids as $refund_id ) {
			$results[ $refund_id ] = null;

			try {
				$refund = wc_get_order( $refund_id );
				if ( $refund instanceof WC_Order_Refund ) {
					$results[ $refund_id ] = self::issue( $refund );
				}
			} catch ( Throwable $e ) {
				Pipeline::log( 'Credit note issuing failed for refund ' . $refund_id . ' of order ' . $order_id . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			}
		}

		return $results;
	}

	/**
	 * Refunds flagged pending (PENDING_META), by increasing id.
	 *
	 * @param int $limit Maximum number of ids.
	 * @return int[]
	 */
	public static function pending_refund_ids( int $limit ): array {
		if ( $limit <= 0 ) {
			return [];
		}

		// One meta clause through `meta_key` / `meta_compare`: the legacy order storage ignores
		// `meta_query` (WooCommerce 9.2+ only warns), this shortcut works in both storages.
		return self::ids(
			wc_get_orders(
				[
					'type'         => 'shop_order_refund',
					'limit'        => $limit,
					'orderby'      => 'ID',
					'order'        => 'ASC',
					'return'       => 'ids',
					'meta_key'     => self::PENDING_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Daily sweep and order screen notice, bounded by $limit.
					'meta_compare' => 'EXISTS',
				]
			)
		);
	}

	/**
	 * Refunds the sweep retries, by increasing id: first those created since SINCE_OPTION that
	 * carry neither a credit note number nor a pending flag and refund something, then the pending
	 * ones. A pending refund waits for something outside the sweep (the import, its invoice, a
	 * manager): it never takes the place of a refund the hook simply missed.
	 *
	 * The storages share one meta clause only (see pending_refund_ids()): the refunds without a
	 * number are read by pages of SWEEP_PAGE_SIZE, SWEEP_PAGES at most, and the refunds of 0 (never
	 * flagged, they would come back every day) and the pending ones are left out here.
	 *
	 * @param int $limit Maximum number of ids.
	 * @return int[]
	 */
	public static function sweep_ids( int $limit ): array {
		if ( $limit <= 0 ) {
			return [];
		}

		$ids   = [];
		$since = self::since();

		for ( $page = 1; $page <= self::SWEEP_PAGES; $page++ ) {
			$batch = self::ids(
				wc_get_orders(
					[
						'type'         => 'shop_order_refund',
						'limit'        => self::SWEEP_PAGE_SIZE,
						'paged'        => $page,
						'orderby'      => 'ID',
						'order'        => 'ASC',
						'return'       => 'ids',
						'date_created' => '>=' . $since,
						'meta_key'     => self::NUMBER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Daily sweep, bounded by SWEEP_PAGES.
						'meta_compare' => 'NOT EXISTS',
					]
				)
			);

			foreach ( $batch as $refund_id ) {
				$refund = wc_get_order( $refund_id );
				if ( $refund instanceof WC_Order_Refund && self::has_amount( $refund ) && '' === (string) $refund->get_meta( self::PENDING_META ) ) {
					$ids[] = $refund_id;
				}

				if ( count( $ids ) >= $limit ) {
					break 2;
				}
			}

			if ( count( $batch ) < self::SWEEP_PAGE_SIZE ) {
				break;
			}
		}

		return array_values( array_unique( array_merge( $ids, self::pending_refund_ids( $limit - count( $ids ) ) ) ) );
	}

	/**
	 * Cron `lumia_factures_credit_notes_sweep`: issues the credit notes of up to SWEEP_LIMIT refunds
	 * left without one (see sweep_ids()). Nothing runs while the import blocks issuing.
	 */
	public static function sweep(): void {
		try {
			if ( Migration::blocks_issuing() ) {
				return;
			}

			$ids = self::sweep_ids( self::SWEEP_LIMIT );
		} catch ( Throwable $e ) {
			Pipeline::log( 'The credit note sweep failed: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			return;
		}

		foreach ( $ids as $refund_id ) {
			try {
				$refund = wc_get_order( $refund_id );
				if ( $refund instanceof WC_Order_Refund ) {
					self::issue( $refund );
				}
			} catch ( Throwable $e ) {
				Pipeline::log( 'The credit note sweep failed for refund ' . $refund_id . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			}
		}
	}

	/**
	 * Schedules the daily sweep when it is not scheduled. Unscheduled on deactivation (the module's
	 * `cron` uninstall key, see Core\Deactivator).
	 */
	public static function schedule_sweep(): void {
		if ( false === wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::SWEEP_HOOK );
		}
	}

	/**
	 * Records SINCE_OPTION when it is absent (install, upgrade to 2.1). Autoloaded: read on every boot.
	 */
	public static function ensure_since(): void {
		if ( false === get_option( self::SINCE_OPTION ) ) {
			add_option( self::SINCE_OPTION, time(), '', true );
		}
	}

	/**
	 * UTC timestamp from which credit notes are issued by themselves (SINCE_OPTION).
	 *
	 * An invalid value counts as now: no older order or refund is taken by itself.
	 */
	public static function since(): int {
		self::ensure_since();

		$since = get_option( self::SINCE_OPTION );

		return is_numeric( $since ) && (int) $since > 0 ? (int) $since : time();
	}

	/**
	 * Filter `woocommerce_pre_delete_order_refund`: refuses to delete a refund that carries a credit note.
	 *
	 * The credit note stays issued whatever happens to the refund; deleting the refund would leave
	 * the order out of step with its documents. Filter `lumia_factures_allow_credited_refund_deletion`
	 * lets the deletion through (the row and the file are kept, a note is written on the order).
	 *
	 * @param mixed $check        Null to let WooCommerce decide, anything else short-circuits.
	 * @param mixed $refund       Refund being deleted.
	 * @param mixed $force_delete Whether the trash is bypassed (unused, refunds have none).
	 * @return mixed False to refuse, $check otherwise.
	 */
	public static function block_deletion( $check, $refund = null, $force_delete = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter signature.
		if ( null !== $check || ! $refund instanceof WC_Order_Refund ) {
			return $check;
		}

		$doc = self::find_for( $refund );
		if ( null === $doc || 'issued' !== $doc->status ) {
			return $check;
		}

		/**
		 * Filters whether a refund carrying a credit note may be deleted. The credit note, its
		 * register row and its PDF are kept either way.
		 *
		 * @param bool            $allow  False by default.
		 * @param WC_Order_Refund $refund Refund.
		 * @param IssuedDocument  $doc    Its credit note.
		 */
		if ( (bool) apply_filters( 'lumia_factures_allow_credited_refund_deletion', false, $refund, $doc ) ) {
			return $check;
		}

		Pipeline::log( 'Refund #' . $refund->get_id() . ' carries credit note ' . $doc->number . ': its deletion was refused.' );
		self::remember_kept( (string) $doc->number );

		return false;
	}

	/**
	 * Hooks `woocommerce_delete_order_refund` and `woocommerce_refund_deleted`: notes on the order
	 * that a refund carrying a credit note is gone, once, and records its id (DELETED_META).
	 *
	 * The order screen fires `woocommerce_refund_deleted` even when the deletion was refused: the
	 * refund must really be gone.
	 *
	 * @param mixed $refund_id Refund id.
	 * @param mixed $order_id  Order id (unused, read from the register).
	 */
	public static function on_refund_deleted( $refund_id, $order_id = 0 ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Action signature.
		$refund_id = (int) $refund_id;

		try {
			if ( $refund_id <= 0 || isset( self::$deletions_noted[ $refund_id ] ) ) {
				return;
			}

			$doc = Register::find( self::TYPE, $refund_id );
			if ( null === $doc || 'issued' !== $doc->status || $doc->parent_id <= 0 ) {
				return;
			}

			$current = wc_get_order( $refund_id );
			if ( $current instanceof WC_Order_Refund ) {
				return;
			}

			self::$deletions_noted[ $refund_id ] = true;

			$parent = wc_get_order( $doc->parent_id );
			if ( ! $parent instanceof WC_Order ) {
				return;
			}

			$deleted = $parent->get_meta( self::DELETED_META );
			$deleted = is_array( $deleted ) ? array_map( 'intval', $deleted ) : [];
			if ( ! in_array( $refund_id, $deleted, true ) ) {
				$deleted[] = $refund_id;
				$parent->update_meta_data( self::DELETED_META, $deleted );
				$parent->save();
			}

			$parent->add_order_note(
				sprintf(
					/* translators: 1: refund id, 2: credit note number */
					__( 'Refund #%1$d was deleted; credit note %2$s stays issued and the order no longer matches it.', 'lumia-factures' ),
					$refund_id,
					(string) $doc->number
				)
			);

			Pipeline::log( 'Refund #' . $refund_id . ' of order ' . $doc->parent_id . ' was deleted; credit note ' . $doc->number . ' stays issued.' );
		} catch ( Throwable $e ) {
			Pipeline::log( 'The deletion of refund ' . $refund_id . ' could not be noted: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
		}
	}

	/**
	 * Hook `all_admin_notices`: tells the current user which refund deletions were refused.
	 *
	 * The order screen deletes a refund over AJAX and ignores the answer, then reloads the items:
	 * the refund simply stays. The reason is kept for the user and printed on the next admin page.
	 */
	public static function render_deletion_notice(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$numbers = get_transient( self::KEPT_TRANSIENT . $user_id );
		if ( ! is_array( $numbers ) || [] === $numbers ) {
			return;
		}

		delete_transient( self::KEPT_TRANSIENT . $user_id );

		foreach ( array_unique( array_map( 'strval', $numbers ) ) as $number ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: credit note number */
						__( 'This refund carries credit note %s and cannot be deleted.', 'lumia-factures' ),
						$number
					)
				)
			);
		}
	}

	/**
	 * Rebuilds the archived PDF of an issued credit note whose file is missing or damaged.
	 *
	 * Number and date are kept; the new PDF goes to a new file, the damaged one is left in place.
	 * Refused when the archive is intact, or when the refund is gone or is no longer the one the
	 * credit note was issued for (its data cannot be rendered again).
	 *
	 * @param IssuedDocument $doc Issued credit note.
	 * @return bool True when a new file was archived and recorded.
	 */
	public static function restore( IssuedDocument $doc ): bool {
		if ( self::TYPE !== $doc->type || 'issued' !== $doc->status || null === $doc->number || $doc->parent_id <= 0 ) {
			return false;
		}

		$refund = wc_get_order( $doc->order_id );
		if ( ! $refund instanceof WC_Order_Refund ) {
			return false;
		}

		$own = self::find_for( $refund );
		if ( null === $own || $own->id !== $doc->id ) {
			return false;
		}

		if ( Archive::verify( $doc ) ) {
			return false;
		}

		$parent = wc_get_order( $doc->parent_id );
		if ( ! $parent instanceof WC_Order ) {
			return false;
		}

		$pdf = self::render( $doc, $refund, $parent, Module::settings() );
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
			Pipeline::log( 'Rebuilt PDF ' . $stored['file'] . ' of credit note ' . $doc->number . ' could not be recorded in the register.' );
			return false;
		}

		$parent->add_order_note(
			sprintf(
				/* translators: %s: credit note number */
				__( 'Credit note PDF %s was missing or damaged and has been rebuilt from the current order data.', 'lumia-factures' ),
				$doc->number
			)
		);

		return true;
	}

	/**
	 * Why the gate refuses the refund now, '' when it lets it through. Reads only.
	 *
	 * The legacy import gate comes before the filter, so no third-party code can bypass it.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @param WC_Order        $order  The refunded order.
	 * @return string `import`, `conflict`, `reused_id`, `vetoed` or ''.
	 */
	private static function gate( WC_Order_Refund $refund, WC_Order $order ): string {
		if ( Migration::blocks_issuing() ) {
			return 'import';
		}

		if ( 'conflict' === Issuer::gate_reason( $order ) ) {
			return 'conflict';
		}

		// The unique key (type, order_id) holds the credit note of a deleted refund that had this id:
		// this refund can never get one, and must never be handed the old one.
		$row = Register::find( self::TYPE, $refund->get_id() );
		if ( null !== $row && 'issued' === $row->status && null === self::find_for( $refund ) ) {
			return 'reused_id';
		}

		/**
		 * Filters whether a credit note may be issued for the refund. It can only veto: the legacy
		 * import gate is applied first and the order must have its invoice.
		 *
		 * @param bool            $can    True.
		 * @param WC_Order_Refund $refund Refund.
		 * @param WC_Order        $order  The refunded order.
		 */
		$can = (bool) apply_filters( 'lumia_factures_can_issue_credit_note', true, $refund, $order );

		return $can ? '' : 'vetoed';
	}

	/**
	 * Flags the refund pending with the reason, once per reason, and logs it.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @param string          $reason Reason (see refusal()).
	 */
	private static function refuse( WC_Order_Refund $refund, string $reason ): void {
		Pipeline::guard(
			self::TYPE,
			'pending flag',
			$refund->get_id(),
			static function () use ( $refund, $reason ): void {
				// Written on a fresh copy: the object in hand may predate a flag set by a nested call.
				$fresh = wc_get_order( $refund->get_id() );
				$fresh = $fresh instanceof WC_Order_Refund ? $fresh : $refund;

				if ( (string) $fresh->get_meta( self::PENDING_META ) === $reason ) {
					return;
				}

				$fresh->update_meta_data( self::PENDING_META, $reason );
				$fresh->save();

				Pipeline::log( 'Credit note of refund #' . $refund->get_id() . ' (order ' . $refund->get_parent_id() . ') not issued: ' . $reason . '.' );
			}
		);
	}

	/**
	 * Writes the credit note number and date on the refund when they are absent or different, and
	 * clears the pending flag.
	 *
	 * @param IssuedDocument  $doc    Issued credit note.
	 * @param WC_Order_Refund $refund Its refund.
	 */
	private static function sync_meta( IssuedDocument $doc, WC_Order_Refund $refund ): void {
		// Written on a fresh copy: the object in hand may predate the meta written by a nested call.
		$fresh = wc_get_order( $refund->get_id() );
		$fresh = $fresh instanceof WC_Order_Refund ? $fresh : $refund;

		$number  = (string) $doc->number;
		$date    = (string) $doc->issued_at;
		$pending = '' !== (string) $fresh->get_meta( self::PENDING_META );

		if ( ! $pending && (string) $fresh->get_meta( self::NUMBER_META ) === $number && (string) $fresh->get_meta( self::DATE_META ) === $date ) {
			return;
		}

		if ( $pending ) {
			$fresh->delete_meta_data( self::PENDING_META );
		}

		$fresh->update_meta_data( self::NUMBER_META, $number );
		$fresh->update_meta_data( self::DATE_META, $date );
		$fresh->save();
	}

	/**
	 * Adds a note to the refunded order, read again from storage.
	 *
	 * @param WC_Order $order Refunded order.
	 * @param string   $note  Note text.
	 */
	private static function note( WC_Order $order, string $note ): void {
		$fresh = wc_get_order( $order->get_id() );
		( $fresh instanceof WC_Order ? $fresh : $order )->add_order_note( $note );
	}

	/**
	 * Renders the credit note's PDF with its recorded number and date, the invoice it credits and
	 * the refund's amounts.
	 *
	 * @param IssuedDocument       $doc      Issued credit note.
	 * @param WC_Order_Refund      $refund   Its refund, freshly loaded.
	 * @param WC_Order             $order    The refunded order, freshly loaded.
	 * @param array<string, mixed> $settings Invoice settings.
	 * @return string PDF bytes, '' on failure (logged).
	 */
	private static function render( IssuedDocument $doc, WC_Order_Refund $refund, WC_Order $order, array $settings ): string {
		try {
			$data = DocumentData::credit_note( $doc, $refund );
			if ( null === $data ) {
				Pipeline::log( 'PDF of credit note ' . $doc->number . ' could not be rendered: refund #' . $refund->get_id() . ' or the invoice of order ' . $order->get_id() . ' does not match the register.' );
				return '';
			}

			$pdf = Renderer::pdf( $order, $data, $settings, CreditNoteStatementBuilder::build( $refund, $order, $settings ) );
		} catch ( Throwable $e ) {
			Pipeline::log( 'PDF of credit note ' . $doc->number . ' could not be rendered: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			return '';
		}

		if ( '' === $pdf ) {
			Pipeline::log( 'PDF of credit note ' . $doc->number . ' could not be rendered.' );
		}

		return $pdf;
	}

	/**
	 * Whether the refund refunds something (above 0 in minor units).
	 *
	 * @param WC_Order_Refund $refund Refund.
	 */
	private static function has_amount( WC_Order_Refund $refund ): bool {
		return Money::to_minor( $refund->get_amount() ) > 0;
	}

	/**
	 * The refunded order, null when the refund has none (or it is not an order).
	 *
	 * @param WC_Order_Refund $refund Refund.
	 */
	private static function parent( WC_Order_Refund $refund ): ?WC_Order {
		$parent_id = $refund->get_parent_id();
		$parent    = $parent_id > 0 ? wc_get_order( $parent_id ) : false;

		return $parent instanceof WC_Order ? $parent : null;
	}

	/**
	 * Whether the order has its issued invoice in the register (imported ones included).
	 *
	 * Read from the database on every call: issue() asks again after issuing the invoice.
	 *
	 * @phpstan-impure
	 * @param WC_Order $order Order.
	 */
	private static function invoiced( WC_Order $order ): bool {
		$invoice = Register::find( Issuer::TYPE, $order->get_id() );

		return null !== $invoice && 'issued' === $invoice->status && null !== $invoice->number && '' !== $invoice->number;
	}

	/**
	 * Whether the order was created since SINCE_OPTION, so a refund may issue its invoice by itself.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function follows_since( WC_Order $order ): bool {
		$created = $order->get_date_created();

		return null !== $created && $created->getTimestamp() >= self::since();
	}

	/**
	 * Whether the order recorded that a refund with the credit note's id was deleted (DELETED_META).
	 *
	 * @param IssuedDocument $doc Credit note row.
	 */
	private static function deleted_refund( IssuedDocument $doc ): bool {
		if ( 'issued' !== $doc->status ) {
			return false;
		}

		$parent = wc_get_order( $doc->parent_id );
		if ( ! $parent instanceof WC_Order ) {
			return false;
		}

		$deleted = $parent->get_meta( self::DELETED_META );

		return is_array( $deleted ) && in_array( $doc->order_id, array_map( 'intval', $deleted ), true );
	}

	/**
	 * Keeps the number of a credit note whose refund deletion was refused, for the current user's
	 * next admin page (render_deletion_notice()).
	 *
	 * @param string $number Credit note number.
	 */
	private static function remember_kept( string $number ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$numbers   = get_transient( self::KEPT_TRANSIENT . $user_id );
		$numbers   = is_array( $numbers ) ? $numbers : [];
		$numbers[] = $number;

		set_transient( self::KEPT_TRANSIENT . $user_id, $numbers, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Integer ids out of a `wc_get_orders()` result, sorted.
	 *
	 * @param mixed $result Result of `wc_get_orders()` with `return => ids`.
	 * @return int[]
	 */
	private static function ids( $result ): array {
		$ids = array_map( 'intval', is_array( $result ) ? $result : [] );
		sort( $ids );

		return $ids;
	}
}
