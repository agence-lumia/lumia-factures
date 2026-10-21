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
 * `parent_id` the refunded order. It is issued on `woocommerce_order_refunded` (or by the
 * refund e-mail sent just before it): WooCommerce saves the refund, asks the gateway, and
 * deletes the refund when the gateway refuses it, before that hook. Every other path (sweep, invoice action, order screen, WP-CLI) goes
 * through issue_deferred(), which leaves alone a refund that may still be waiting on the gateway
 * (in_flight()). Reserving, numbering and archiving go through the shared Pipeline; the gate, the
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
	 * Option mapping the id of each order deleted for good while it had credit notes to the UTC
	 * timestamp of its deletion. Its register rows stay; an order given that id again (MySQL may
	 * reuse a freed AUTO_INCREMENT value) never shows nor serves them (belongs_to()). Not autoloaded.
	 */
	public const DELETED_ORDERS_OPTION = 'lumia_factures_deleted_orders';

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
	 * Option holding where each part of the sweep resumes (see scan()). Not autoloaded.
	 */
	public const SWEEP_CURSOR_OPTION = 'lumia_factures_credit_notes_sweep_cursor';

	/**
	 * Pending reasons the sweep retries: those that can lift without any hook issuing the credit
	 * note (the import finishing, a conflict fixed by hand, a filter changing its mind).
	 * `no_invoice` lifts when the invoice is issued, whose action issues the order's credit notes;
	 * `no_order` and `reused_id` never lift.
	 */
	private const SWEEP_RETRIED = [ 'import', 'conflict', 'vetoed' ];

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
	 * Ids of the orders being deleted for good in this request (see on_order_deleting()), each with
	 * whether it has credit note rows, recorded once the deletion succeeds (on_order_deleted()).
	 *
	 * @var array<int, bool>
	 */
	private static array $orders_deleting = [];

	/**
	 * Seconds a credit note's issue date may precede its refund's creation date and still be the
	 * refund's own (clocks of several web servers). A later refund given the id again is newer.
	 */
	private const CLOCK_TOLERANCE = 60;

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
	 * the row is not its own. So is an issued row older than the refund without the number: a
	 * refund's credit note is always issued after the refund is created, so it belongs to an
	 * earlier refund that had this id (an order deleted for good leaves no DELETED_META behind).
	 * Never writes.
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

		if ( 'issued' === $doc->status && $doc->issued_at > 0 ) {
			$created = $refund->get_date_created();
			if ( null !== $created && $doc->issued_at < $created->getTimestamp() - self::CLOCK_TOLERANCE ) {
				return null;
			}
		}

		return self::deleted_refund( $doc ) ? null : $doc;
	}

	/**
	 * Whether WooCommerce may still be processing the refund: `wc_create_refund()` saves it, then
	 * asks the gateway and deletes it when the gateway refuses. A refund created less than
	 * Issuer::STALE_AFTER seconds ago that carries neither a credit note number nor a pending flag
	 * (both written only once WooCommerce is done with it, or after that delay) is taken as such.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 */
	public static function in_flight( WC_Order_Refund $refund ): bool {
		if ( '' !== (string) $refund->get_meta( self::NUMBER_META ) || '' !== (string) $refund->get_meta( self::PENDING_META ) ) {
			return false;
		}

		$created = $refund->get_date_created();

		return null !== $created && $created->getTimestamp() > time() - Issuer::STALE_AFTER;
	}

	/**
	 * Issues the refund's credit note from a request that is not the refund's own (sweep, invoice
	 * action, order screen, WP-CLI): a refund that may still be waiting on the gateway is left
	 * alone, nothing written (in_flight()). An issued credit note is returned as is.
	 *
	 * Issuing it there would make it permanent: block_deletion() would then keep a refund the
	 * gateway refused, with a credit note for money never returned.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @return IssuedDocument|null As issue(); null too while the refund is in flight.
	 */
	public static function issue_deferred( WC_Order_Refund $refund ): ?IssuedDocument {
		$existing = self::find_for( $refund );
		if ( ( null === $existing || 'issued' !== $existing->status ) && self::in_flight( $refund ) ) {
			return null;
		}

		return self::issue( $refund );
	}

	/**
	 * Issues the refund's credit note. Idempotent: an issued credit note is returned as is.
	 *
	 * Called directly only once WooCommerce is done with the refund (`woocommerce_order_refunded`,
	 * the refund e-mail); every other path goes through issue_deferred().
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
	 *                `zero_amount` (nothing refunded, never pending), `in_flight` (created moments
	 *                ago, WooCommerce may still be asking the gateway: see in_flight()),
	 *                `no_order` (no refunded order),
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

		if ( self::in_flight( $refund ) ) {
			return 'in_flight';
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
	 * A refund still in flight is left to its own `woocommerce_order_refunded` (issue_deferred()).
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
					$results[ $refund_id ] = self::issue_deferred( $refund );
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
	 * Refunds the sweep retries: first those created since SINCE_OPTION that carry neither a
	 * credit note number nor a pending flag and refund something (missed by the hook), then the
	 * pending ones whose reason can lift without any hook issuing their credit note
	 * (SWEEP_RETRIED). A pending refund never takes the place of a refund the hook simply missed,
	 * and a refund still in flight (in_flight()) waits for a later run.
	 *
	 * The storages share one meta clause only (see pending_refund_ids()): each part reads its
	 * refunds by pages of SWEEP_PAGE_SIZE, SWEEP_PAGES at most per run, and leaves out in PHP what
	 * it does not retry. Each part resumes after the last refund the previous run read
	 * (SWEEP_CURSOR_OPTION) and starts over once it has read them all, so refunds that never
	 * become issuable (pending for good, refunds of 0) can delay a refund but never hide it.
	 * Moves the cursors.
	 *
	 * @param int $limit Maximum number of ids.
	 * @return int[]
	 */
	public static function sweep_ids( int $limit ): array {
		if ( $limit <= 0 ) {
			return [];
		}

		$missed = self::scan(
			'missed',
			[
				'meta_key'     => self::NUMBER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Daily sweep, bounded by SWEEP_PAGES.
				'meta_compare' => 'NOT EXISTS',
			],
			self::since(),
			static fn( WC_Order_Refund $refund ): bool => self::has_amount( $refund ) && '' === (string) $refund->get_meta( self::PENDING_META ) && ! self::in_flight( $refund ),
			$limit
		);

		if ( count( $missed ) >= $limit ) {
			return $missed;
		}

		$pending = self::scan(
			'pending',
			[
				'meta_key'     => self::PENDING_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Daily sweep, bounded by SWEEP_PAGES.
				'meta_compare' => 'EXISTS',
			],
			0,
			static fn( WC_Order_Refund $refund ): bool => in_array( (string) $refund->get_meta( self::PENDING_META ), self::SWEEP_RETRIED, true ),
			$limit - count( $missed )
		);

		return array_values( array_unique( array_merge( $missed, $pending ) ) );
	}

	/**
	 * One part of the sweep: the refunds matching the query and accepted by the callback, by
	 * increasing id, read from the part's cursor (the creation date and id of the last refund read)
	 * to the newest refund, then from $from back to the cursor. Saves where it stopped, or clears
	 * the cursor once every refund has been read.
	 *
	 * The cursor is a creation date: `wc_get_orders()` filters ids by list only, not by range.
	 *
	 * @param string                          $part   Cursor key (`missed`, `pending`).
	 * @param array<string, mixed>            $query  Meta clause of the part.
	 * @param int                             $from   Oldest creation date read (UTC timestamp, 0 for none).
	 * @param callable(WC_Order_Refund): bool $accept Whether the refund is retried.
	 * @param int                             $limit  Maximum number of ids.
	 * @return int[]
	 */
	private static function scan( string $part, array $query, int $from, callable $accept, int $limit ): array {
		$cursors = get_option( self::SWEEP_CURSOR_OPTION, [] );
		$cursors = is_array( $cursors ) ? $cursors : [];
		$start   = isset( $cursors[ $part ] ) && is_array( $cursors[ $part ] ) ? $cursors[ $part ] : [];
		$after   = [ max( 0, (int) ( $start['date'] ?? 0 ) ), max( 0, (int) ( $start['id'] ?? 0 ) ) ];

		// From the cursor to the newest refund, then from the oldest one back to the cursor.
		$passes = [ [ max( $from, $after[0] ), 0 ] ];
		if ( $after[0] > $from ) {
			$passes[] = [ $from, $after[0] ];
		}

		$ids      = [];
		$position = $after;
		$pages    = self::SWEEP_PAGES;

		foreach ( $passes as $pass => [ $lower, $upper ] ) {
			for ( $page = 1; ; $page++ ) {
				if ( $pages <= 0 ) {
					self::save_cursor( $cursors, $part, $position );
					return $ids;
				}
				--$pages;

				$args = $query + [
					'type'    => 'shop_order_refund',
					'limit'   => self::SWEEP_PAGE_SIZE,
					'paged'   => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'ids',
				];
				if ( $upper > 0 ) {
					$args['date_created'] = $lower > 0 ? $lower . '...' . $upper : '<=' . $upper;
				} elseif ( $lower > 0 ) {
					$args['date_created'] = '>=' . $lower;
				}

				$batch = self::ids( wc_get_orders( $args ) );
				foreach ( $batch as $refund_id ) {
					$refund = wc_get_order( $refund_id );
					if ( ! $refund instanceof WC_Order_Refund || in_array( $refund_id, $ids, true ) ) {
						continue;
					}

					$created = $refund->get_date_created();
					$stamp   = null !== $created ? $created->getTimestamp() : 0;

					// Read by the previous run already (same second as the cursor, lower or equal id).
					if ( 0 === $pass && $stamp === $after[0] && $refund_id <= $after[1] ) {
						continue;
					}

					$position = [ $stamp, $refund_id ];
					if ( $accept( $refund ) ) {
						$ids[] = $refund_id;
						if ( count( $ids ) >= $limit ) {
							self::save_cursor( $cursors, $part, $position );
							return $ids;
						}
					}
				}

				if ( count( $batch ) < self::SWEEP_PAGE_SIZE ) {
					break;
				}
			}
		}

		// Every refund was read: the next run starts over.
		self::save_cursor( $cursors, $part, [ 0, 0 ] );

		return $ids;
	}

	/**
	 * Saves the cursor of one part of the sweep (see scan()), [0, 0] clearing it.
	 *
	 * @param array<string, mixed> $cursors  Every cursor, as stored.
	 * @param string               $part     Cursor key.
	 * @param array{int, int}      $position Creation date and id of the last refund read.
	 */
	private static function save_cursor( array $cursors, string $part, array $position ): void {
		if ( $position[0] <= 0 && $position[1] <= 0 ) {
			unset( $cursors[ $part ] );
		} else {
			$cursors[ $part ] = [
				'date' => $position[0],
				'id'   => $position[1],
			];
		}

		update_option( self::SWEEP_CURSOR_OPTION, $cursors, false );
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
					self::issue_deferred( $refund );
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
	 * Records SINCE_OPTION when it is absent (first boot of the module, upgrade to 2.1). Autoloaded:
	 * read on every boot. Called only by Module::init(), which runs only when the requirements are
	 * met: a site activated next to Facture PDF Lumia 1.x records it once 1.x is gone, never while
	 * 1.x still handles the refunds.
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
		// Never recorded here: only a running module records it (ensure_since()).
		$since = get_option( self::SINCE_OPTION );

		return is_numeric( $since ) && (int) $since > 0 ? (int) $since : time();
	}

	/**
	 * Hook `woocommerce_before_delete_order`: the order is about to be deleted for good, its refunds
	 * with it. Both storages fire it before deleting anything; HPOS then deletes each refund through
	 * `woocommerce_pre_delete_order_refund`, which block_deletion() lets through for this order until
	 * `woocommerce_delete_order` (on_order_deleted()). Logs the credit notes that stay issued; the
	 * deletion itself is recorded by on_order_deleted() only, once it has succeeded.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $order    Order being deleted.
	 */
	public static function on_order_deleting( $order_id, $order = null ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || $order instanceof WC_Order_Refund ) {
			return;
		}

		self::$orders_deleting[ $order_id ] = false;

		try {
			$rows    = Register::for_parent( self::TYPE, $order_id );
			$numbers = [];
			foreach ( $rows as $doc ) {
				if ( 'issued' === $doc->status && null !== $doc->number ) {
					$numbers[] = $doc->number;
				}
			}

			self::$orders_deleting[ $order_id ] = [] !== $rows;

			if ( [] !== $numbers ) {
				Pipeline::log( 'Order ' . $order_id . ' is deleted for good with its refunds; credit notes ' . implode( ', ', $numbers ) . ' stay issued.' );
			}
		} catch ( Throwable $e ) {
			Pipeline::log( 'The deletion of order ' . $order_id . ' could not be logged: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
		}
	}

	/**
	 * Whether a credit note row belongs to this live order: its parent is the order and was not an
	 * earlier order deleted for good whose id the order was given again (DELETED_ORDERS_OPTION).
	 *
	 * Every customer surface checks it (DocumentList, CustomerDownload, so the Bricks tags and the
	 * e-mail links too): the rows of a deleted order keep its id, and nothing else ties them to it.
	 * The deletion is recorded only once it has succeeded (on_order_deleted()), so a recorded id was
	 * freed for good: only what was issued after it belongs to the live order. Never decided on the
	 * order's creation date, which a manager or an import can set to any value. Reads only.
	 *
	 * @param IssuedDocument $doc   Credit note row.
	 * @param WC_Order       $order Live order.
	 */
	public static function belongs_to( IssuedDocument $doc, WC_Order $order ): bool {
		if ( self::TYPE !== $doc->type || $doc->parent_id <= 0 || $doc->parent_id !== $order->get_id() ) {
			return false;
		}

		$deleted = get_option( self::DELETED_ORDERS_OPTION );
		if ( ! is_array( $deleted ) || ! isset( $deleted[ $doc->parent_id ] ) || ! is_numeric( $deleted[ $doc->parent_id ] ) ) {
			return true;
		}

		// A new order with the freed id: only what was issued for it after the deletion is its own.
		return $doc->issued_at > (int) $deleted[ $doc->parent_id ];
	}

	/**
	 * Records that an order with credit notes is deleted for good (DELETED_ORDERS_OPTION).
	 *
	 * @param int $order_id Order id.
	 */
	private static function record_deleted_order( int $order_id ): void {
		$deleted = get_option( self::DELETED_ORDERS_OPTION );
		$deleted = is_array( $deleted ) ? $deleted : [];

		$deleted[ $order_id ] = time();

		if ( false === get_option( self::DELETED_ORDERS_OPTION ) ) {
			add_option( self::DELETED_ORDERS_OPTION, $deleted, '', false );
		} else {
			update_option( self::DELETED_ORDERS_OPTION, $deleted, false );
		}
	}

	/**
	 * Hook `woocommerce_delete_order`: the order is gone, its refunds are protected again. Records
	 * the deletion of an order with credit notes (DELETED_ORDERS_OPTION) here, once it succeeded:
	 * recorded at `woocommerce_before_delete_order`, a deletion that then failed would hide the
	 * credit notes of an order that still exists.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function on_order_deleted( $order_id ): void {
		$order_id = (int) $order_id;
		if ( ! isset( self::$orders_deleting[ $order_id ] ) ) {
			return;
		}

		$has_rows = self::$orders_deleting[ $order_id ];
		unset( self::$orders_deleting[ $order_id ] );

		if ( ! $has_rows ) {
			return;
		}

		try {
			self::record_deleted_order( $order_id );
		} catch ( Throwable $e ) {
			Pipeline::log( 'The deletion of order ' . $order_id . ' could not be recorded: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
		}
	}

	/**
	 * Filter `woocommerce_pre_delete_order_refund`: refuses to delete a refund that carries a credit note.
	 *
	 * The credit note stays issued whatever happens to the refund; deleting the refund would leave
	 * the order out of step with its documents. Filter `lumia_factures_allow_credited_refund_deletion`
	 * lets the deletion through (the row and the file are kept, a note is written on the order).
	 * The refunds of an order being deleted for good always go (see on_order_deleting()).
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

		// HPOS deletes the refunds of an order deleted for good one by one, through this filter, before
		// the order rows: refusing would leave them in the orders table without their order (the
		// legacy storage deletes them with wp_delete_post(), without asking).
		if ( isset( self::$orders_deleting[ $refund->get_parent_id() ] ) ) {
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

			// The order goes too: nothing to note on it (on_order_deleting() logged its credit notes),
			// as with the legacy storage, which fires no refund hook then.
			if ( isset( self::$orders_deleting[ $doc->parent_id ] ) ) {
				self::$deletions_noted[ $refund_id ] = true;
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
