<?php
/**
 * Invoice and credit note tools on the WooCommerce order screens.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Admin\Admin;
use Lumia\Factures\Modules\Invoices\Amounts\Money;
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\InvoiceCancellation;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use WC_Order;
use WC_Order_Refund;
use WP_Post;

/**
 * Meta box, list column, and the issue / restore / cancel actions, on both the HPOS and the legacy
 * order screens.
 *
 * The meta box sits inside the order edit form, and a form cannot nest: the actions are links
 * to `admin-post.php`, signed with a nonce. "Cancel invoice" posts a form printed in the footer,
 * outside the order form, once the design-system confirmation modal is confirmed.
 */
final class OrderTools {

	/**
	 * Admin-post action: issue the invoice.
	 */
	public const ACTION_ISSUE = 'lumia_factures_invoices_issue';

	/**
	 * Admin-post action: rebuild a missing or damaged PDF.
	 */
	public const ACTION_RESTORE = 'lumia_factures_invoices_restore';

	/**
	 * Admin-post action: issue a refund's credit note.
	 */
	public const ACTION_ISSUE_CREDIT_NOTE = 'lumia_factures_invoices_issue_credit_note';

	/**
	 * Admin-post action: rebuild a credit note's missing or damaged PDF.
	 */
	public const ACTION_RESTORE_CREDIT_NOTE = 'lumia_factures_invoices_restore_credit_note';

	/**
	 * Admin-post action: cancel the invoice of an unpaid order (InvoiceCancellation).
	 */
	public const ACTION_CANCEL = 'lumia_factures_invoices_cancel_invoice';

	/**
	 * DOM id of the cancel form printed in the footer.
	 */
	private const CANCEL_FORM = 'lumia-factures-cancel-invoice-form';

	/**
	 * Order whose meta box offered "Cancel invoice" in this request (its form goes in the footer).
	 */
	private static int $cancel_order_id = 0;

	/**
	 * Registers the hooks (admin only).
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add_meta_box' ] );
		add_action( 'admin_post_' . self::ACTION_ISSUE, [ self::class, 'handle_issue' ] );
		add_action( 'admin_post_' . self::ACTION_RESTORE, [ self::class, 'handle_restore' ] );
		add_action( 'admin_post_' . self::ACTION_ISSUE_CREDIT_NOTE, [ self::class, 'handle_issue_credit_note' ] );
		add_action( 'admin_post_' . self::ACTION_RESTORE_CREDIT_NOTE, [ self::class, 'handle_restore_credit_note' ] );
		add_action( 'admin_post_' . self::ACTION_CANCEL, [ self::class, 'handle_cancel' ] );
		add_action( 'all_admin_notices', [ self::class, 'render_notice' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_footer', [ self::class, 'render_footer' ] );

		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ self::class, 'add_column' ] );
		add_filter( 'manage_edit-shop_order_columns', [ self::class, 'add_column' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ self::class, 'render_column' ], 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', [ self::class, 'render_column' ], 10, 2 );
	}

	/**
	 * Adds the meta box on both possible order screens.
	 */
	public static function add_meta_box(): void {
		foreach ( self::screens() as $screen ) {
			add_meta_box(
				'lumia-factures-invoice',
				__( 'Invoice', 'lumia-factures' ),
				[ self::class, 'render_meta_box' ],
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Loads the design-system modal (tokens, components, buttons, `admin.js`) on the order screens,
	 * for the "Cancel invoice" confirmation. The handles are the plugin screen's: never both on one page.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::on_order_screen() ) {
			return;
		}

		$css     = LUMIA_FACTURES_ASSETS_URL . 'admin/css/';
		$version = LUMIA_FACTURES_VERSION;

		wp_enqueue_style( 'lumia-factures-tokens', $css . 'tokens.css', [], $version );
		wp_enqueue_style( 'lumia-factures-components', $css . 'components.css', [ 'lumia-factures-tokens' ], $version );
		wp_enqueue_style( 'lumia-factures-buttons', $css . 'buttons.css', [ 'lumia-factures-components' ], $version );
		wp_enqueue_script( 'lumia-factures-admin', LUMIA_FACTURES_ASSETS_URL . 'admin/js/admin.js', [], $version, true );
		wp_localize_script(
			'lumia-factures-admin',
			'lumiaFacturesAdmin',
			[
				'i18n' => [
					'confirm' => __( 'Confirm', 'lumia-factures' ),
					'cancel'  => __( 'Cancel', 'lumia-factures' ),
				],
			]
		);
	}

	/**
	 * Meta box content: the invoice, then the credit notes of the order's refunds.
	 *
	 * @param WP_Post|WC_Order|mixed $post_or_order Object given by WordPress, depending on the screen.
	 */
	public static function render_meta_box( $post_or_order ): void {
		self::$cancel_order_id = 0;

		$order = self::resolve_order( $post_or_order );
		if ( null === $order ) {
			return;
		}

		self::render_invoice( $order );
		self::render_credit_notes( $order );
	}

	/**
	 * Footer of the order screens: the confirmation modal and the cancel form, when the meta box
	 * offered "Cancel invoice".
	 */
	public static function render_footer(): void {
		if ( self::$cancel_order_id <= 0 || ! self::on_order_screen() ) {
			return;
		}

		Admin::render_modal_overlay();

		printf(
			'<form id="%1$s" method="post" action="%2$s" hidden><input type="hidden" name="action" value="%3$s"><input type="hidden" name="order_id" value="%4$d">',
			esc_attr( self::CANCEL_FORM ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ACTION_CANCEL ),
			(int) self::$cancel_order_id
		);
		// Printed by hand: wp_nonce_field() gives the field the id `_wpnonce`, which the order form uses too.
		printf( '<input type="hidden" name="_wpnonce" value="%s">', esc_attr( wp_create_nonce( self::ACTION_CANCEL . '_' . self::$cancel_order_id ) ) );
		wp_referer_field();
		echo '</form>';
	}

	/**
	 * Adds the invoice column after the status.
	 *
	 * @param mixed $columns Existing columns.
	 * @return array<string, string>
	 */
	public static function add_column( $columns ): array {
		$columns = is_array( $columns ) ? $columns : [];
		$label   = __( 'Invoice', 'lumia-factures' );
		$new     = [];

		foreach ( $columns as $key => $title ) {
			$new[ $key ] = $title;
			if ( 'order_status' === $key ) {
				$new['lumia_factures_invoice'] = $label;
			}
		}

		if ( ! isset( $new['lumia_factures_invoice'] ) ) {
			$new['lumia_factures_invoice'] = $label;
		}

		return $new;
	}

	/**
	 * Column content: the invoice number, then the credit note numbers below it.
	 *
	 * @param mixed                $column     Column id.
	 * @param int|WP_Post|WC_Order $post_or_id Order id, post or order, depending on the screen.
	 */
	public static function render_column( $column, $post_or_id ): void {
		if ( 'lumia_factures_invoice' !== $column ) {
			return;
		}

		// The HPOS list passes the order itself: reading ->ID on it would raise a WooCommerce notice.
		$order = $post_or_id instanceof WC_Order ? $post_or_id : wc_get_order( $post_or_id );
		if ( ! $order instanceof WC_Order ) {
			echo '&#8212;';
			return;
		}

		$order_id = $order->get_id();
		$doc      = Register::find( Issuer::TYPE, $order_id );

		if ( null === $doc || 'issued' !== $doc->status ) {
			echo '&#8212;';
		} else {
			printf(
				'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
				esc_url( Download::url( $doc->order_id ) ),
				esc_html( (string) $doc->number )
			);
		}

		foreach ( Register::for_parent( CreditNoteIssuer::TYPE, $order_id ) as $credit_note ) {
			if ( 'issued' !== $credit_note->status || null === $credit_note->number ) {
				continue;
			}

			printf(
				'<br><small><a href="%1$s" target="_blank" rel="noopener">%2$s</a></small>',
				esc_url( Download::credit_note_url( $order_id, $credit_note->order_id ) ),
				esc_html( $credit_note->number )
			);
		}
	}

	/**
	 * Issues the invoice of an order.
	 *
	 * @param int $order_id Order id.
	 * @return string Notice code: `invoice_issued` or `invoice_not_issued`.
	 */
	public static function issue_for( int $order_id ): string {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return 'invoice_not_issued';
		}

		$doc = Issuer::issue( $order );

		return null !== $doc && 'issued' === $doc->status ? 'invoice_issued' : 'invoice_not_issued';
	}

	/**
	 * Rebuilds the missing or damaged PDF of an issued invoice.
	 *
	 * @param int $order_id Order id.
	 * @return string Notice code: `invoice_restored` or `invoice_restore_refused`.
	 */
	public static function restore_for( int $order_id ): string {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$doc   = $order instanceof WC_Order ? Register::find( Issuer::TYPE, $order_id ) : null;

		if ( null === $doc || 'issued' !== $doc->status ) {
			return 'invoice_restore_refused';
		}

		return Issuer::restore( $doc, $order ) ? 'invoice_restored' : 'invoice_restore_refused';
	}

	/**
	 * Issues the credit note of a refund of the order.
	 *
	 * @param int $order_id  Order id, as posted.
	 * @param int $refund_id Refund id, as posted.
	 * @return string Notice code: `credit_note_issued` or `credit_note_not_issued` (also when the
	 *                refund does not belong to the order).
	 */
	public static function issue_credit_note_for( int $order_id, int $refund_id ): string {
		$refund = self::refund_of( $order_id, $refund_id );
		if ( null === $refund ) {
			return 'credit_note_not_issued';
		}

		$doc = CreditNoteIssuer::issue_deferred( $refund );

		return null !== $doc && 'issued' === $doc->status ? 'credit_note_issued' : 'credit_note_not_issued';
	}

	/**
	 * Rebuilds the missing or damaged PDF of a credit note of the order.
	 *
	 * @param int $order_id  Order id, as posted.
	 * @param int $refund_id Refund id, as posted.
	 * @return string Notice code: `credit_note_restored` or `credit_note_restore_refused`.
	 */
	public static function restore_credit_note_for( int $order_id, int $refund_id ): string {
		$refund = self::refund_of( $order_id, $refund_id );
		$doc    = null !== $refund ? CreditNoteIssuer::find_for( $refund ) : null;

		if ( null === $doc || 'issued' !== $doc->status ) {
			return 'credit_note_restore_refused';
		}

		return CreditNoteIssuer::restore( $doc ) ? 'credit_note_restored' : 'credit_note_restore_refused';
	}

	/**
	 * Cancels the invoice of an unpaid order: a full refund of what is left, credited by a credit note.
	 *
	 * @param int $order_id Order id.
	 * @return string Notice code: `invoice_cancelled`, `invoice_cancelled_pending` (the refund is
	 *                recorded, its credit note is not issued yet) or `invoice_cancel_refused`.
	 */
	public static function cancel_for( int $order_id ): string {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return 'invoice_cancel_refused';
		}

		$refund = InvoiceCancellation::cancel( $order, __( 'Invoice cancelled', 'lumia-factures' ) );
		if ( null === $refund ) {
			return 'invoice_cancel_refused';
		}

		$doc = CreditNoteIssuer::find_for( $refund );

		return null !== $doc && 'issued' === $doc->status ? 'invoice_cancelled' : 'invoice_cancelled_pending';
	}

	/**
	 * Admin-post handler: issue.
	 */
	public static function handle_issue(): void {
		$order_id = self::authorize( 'issue' );
		self::redirect( $order_id, self::issue_for( $order_id ) );
	}

	/**
	 * Admin-post handler: restore.
	 */
	public static function handle_restore(): void {
		$order_id = self::authorize( 'restore' );
		self::redirect( $order_id, self::restore_for( $order_id ) );
	}

	/**
	 * Admin-post handler: issue a credit note.
	 */
	public static function handle_issue_credit_note(): void {
		[ $order_id, $refund_id ] = self::authorize_refund( 'issue_credit_note' );
		self::redirect( $order_id, self::issue_credit_note_for( $order_id, $refund_id ) );
	}

	/**
	 * Admin-post handler: rebuild a credit note's PDF.
	 */
	public static function handle_restore_credit_note(): void {
		[ $order_id, $refund_id ] = self::authorize_refund( 'restore_credit_note' );
		self::redirect( $order_id, self::restore_credit_note_for( $order_id, $refund_id ) );
	}

	/**
	 * Admin-post handler: cancel the invoice (posted form).
	 */
	public static function handle_cancel(): void {
		self::check_capability();

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;

		check_admin_referer( self::ACTION_CANCEL . '_' . $order_id );

		self::redirect( $order_id, self::cancel_for( $order_id ) );
	}

	/**
	 * Core admin notice shown on the order edit screen after an action.
	 */
	public static function render_notice(): void {
		if ( ! isset( $_GET['lumia_factures_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no state change.
			return;
		}

		if ( ! self::on_order_screen() ) {
			return;
		}

		$code     = sanitize_key( wp_unslash( $_GET['lumia_factures_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = self::messages();
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$type = isset( $_GET['lumia_factures_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['lumia_factures_notice_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = in_array( $type, [ 'error', 'warning' ], true ) ? $type : 'success';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $messages[ $code ] )
		);
	}

	/**
	 * The invoice part of the meta box.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function render_invoice( WC_Order $order ): void {
		$order_id = $order->get_id();
		$doc      = Register::find( Issuer::TYPE, $order_id );

		if ( null !== $doc && 'issued' === $doc->status ) {
			printf(
				'<p><strong>%1$s</strong><br>%2$s</p>',
				esc_html( (string) $doc->number ),
				esc_html( self::date( $doc->issued_at ) )
			);

			printf(
				'<p><a href="%1$s" class="button" target="_blank" rel="noopener">%2$s</a>',
				esc_url( Download::url( $order_id ) ),
				esc_html__( 'Download PDF', 'lumia-factures' )
			);

			if ( ! Archive::verify( $doc ) ) {
				printf(
					' <a href="%1$s" class="button">%2$s</a>',
					esc_url( self::action_url( 'restore', $order_id ) ),
					esc_html__( 'Rebuild missing PDF', 'lumia-factures' )
				);
			}

			echo '</p>';

			if ( '' === InvoiceCancellation::refusal( $order ) ) {
				self::$cancel_order_id = $order_id;
				printf(
					'<p><button type="button" class="button" data-modal-confirm data-modal-danger data-modal-form="%1$s" data-modal-title="%2$s" data-modal-message="%3$s" data-modal-confirm-label="%4$s">%4$s</button></p>',
					esc_attr( self::CANCEL_FORM ),
					/* translators: %s: invoice number */
					esc_attr( sprintf( __( 'Cancel invoice %s?', 'lumia-factures' ), (string) $doc->number ) ),
					/* translators: %s: invoice number */
					esc_attr( sprintf( __( 'No money is moved: this records a full refund and issues a credit note that cancels invoice %s.', 'lumia-factures' ), (string) $doc->number ) ),
					esc_attr__( 'Cancel invoice', 'lumia-factures' )
				);
			}

			return;
		}

		// Checked before can_issue(), which would note the refusal on the order just for viewing it.
		$gate = Issuer::gate_reason( $order );
		if ( '' !== $gate ) {
			printf(
				'<p class="description">%s</p>',
				'conflict' === $gate
					? esc_html__( 'No invoice can be issued for this order: its Facture PDF Lumia invoice could not be imported (conflict). See the migration report.', 'lumia-factures' )
					: esc_html__( 'No invoice can be issued for this order until the Facture PDF Lumia data is imported.', 'lumia-factures' )
			);
			return;
		}

		if ( Issuer::can_issue( $order ) ) {
			printf(
				'<p>%1$s</p><p><a href="%2$s" class="button button-primary">%3$s</a></p>',
				esc_html__( 'No invoice has been issued for this order yet.', 'lumia-factures' ),
				esc_url( self::action_url( 'issue', $order_id ) ),
				esc_html__( 'Issue invoice now', 'lumia-factures' )
			);
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The current order status does not allow an invoice yet.', 'lumia-factures' )
		);
	}

	/**
	 * The credit notes part of the meta box: each refund with its credit note (or why it has none),
	 * then the credit notes whose refund was deleted (read from the register).
	 *
	 * @param WC_Order $order Order.
	 */
	private static function render_credit_notes( WC_Order $order ): void {
		$order_id = $order->get_id();

		// Queried, not read through get_refunds(): its cache may predate a refund saved in this request.
		$refund_ids = wc_get_orders(
			[
				'type'    => 'shop_order_refund',
				'parent'  => $order_id,
				'limit'   => -1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			]
		);

		$items = [];
		$shown = [];
		foreach ( self::ids( $refund_ids ) as $refund_id ) {
			$refund = wc_get_order( $refund_id );
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}

			$doc = CreditNoteIssuer::find_for( $refund );
			if ( null !== $doc && 'issued' === $doc->status ) {
				$shown[ $doc->id ] = true;
			}

			$items[] = self::refund_item( $order, $refund, $doc );
		}

		foreach ( Register::for_parent( CreditNoteIssuer::TYPE, $order_id ) as $doc ) {
			if ( 'issued' !== $doc->status || null === $doc->number || isset( $shown[ $doc->id ] ) ) {
				continue;
			}

			$items[] = sprintf(
				'<strong>%1$s</strong> &middot; %2$s<br><span class="description">%3$s</span><br><a href="%4$s" class="button button-small" target="_blank" rel="noopener">%5$s</a>',
				esc_html( $doc->number ),
				esc_html( self::date( $doc->issued_at ) ),
				/* translators: %d: refund id */
				esc_html( sprintf( __( 'Refund #%d deleted', 'lumia-factures' ), $doc->order_id ) ),
				esc_url( Download::credit_note_url( $order_id, $doc->order_id ) ),
				esc_html__( 'Download PDF', 'lumia-factures' )
			);
		}

		if ( [] === $items ) {
			return;
		}

		printf( '<h4>%s</h4><ul class="lumia-factures-credit-notes">', esc_html__( 'Credit notes', 'lumia-factures' ) );
		foreach ( $items as $item ) {
			echo '<li>' . $item . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped where it is built.
		}
		echo '</ul>';
	}

	/**
	 * One refund of the meta box: its credit note, or why it has none and the issue button.
	 *
	 * @param WC_Order            $order  Order.
	 * @param WC_Order_Refund     $refund Refund.
	 * @param IssuedDocument|null $doc    Its credit note row (find_for()).
	 * @return string Escaped HTML.
	 */
	private static function refund_item( WC_Order $order, WC_Order_Refund $refund, ?IssuedDocument $doc ): string {
		$order_id  = $order->get_id();
		$refund_id = $refund->get_id();
		$head      = sprintf(
			/* translators: 1: refund id, 2: refunded amount */
			__( 'Refund #%1$d (%2$s)', 'lumia-factures' ),
			$refund_id,
			Money::format( Money::to_minor( $refund->get_amount() ), $order->get_currency() )
		);

		if ( null !== $doc && 'issued' === $doc->status ) {
			$html = sprintf(
				'<strong>%1$s</strong> &middot; %2$s<br><span class="description">%3$s</span><br><a href="%4$s" class="button button-small" target="_blank" rel="noopener">%5$s</a>',
				esc_html( (string) $doc->number ),
				esc_html( self::date( $doc->issued_at ) ),
				esc_html( $head ),
				esc_url( Download::credit_note_url( $order_id, $refund_id ) ),
				esc_html__( 'Download PDF', 'lumia-factures' )
			);

			if ( ! Archive::verify( $doc ) ) {
				$html .= sprintf(
					' <a href="%1$s" class="button button-small">%2$s</a>',
					esc_url( self::refund_action_url( 'restore_credit_note', $order_id, $refund_id ) ),
					esc_html__( 'Rebuild missing PDF', 'lumia-factures' )
				);
			}

			return $html;
		}

		$reason = CreditNoteIssuer::refusal( $refund );
		$html   = esc_html( $head );

		if ( '' === $reason ) {
			$pending = (string) $refund->get_meta( CreditNoteIssuer::PENDING_META );
			$html   .= '<br><span class="description">' . esc_html(
				'' !== $pending
					? sprintf(
						/* translators: %s: why the credit note was not issued */
						__( 'No credit note yet (was: %s)', 'lumia-factures' ),
						self::reason_label( $pending )
					)
					: __( 'No credit note yet.', 'lumia-factures' )
			) . '</span>';
			$html .= sprintf(
				'<br><a href="%1$s" class="button button-small">%2$s</a>',
				esc_url( self::refund_action_url( 'issue_credit_note', $order_id, $refund_id ) ),
				esc_html__( 'Issue credit note', 'lumia-factures' )
			);

			return $html;
		}

		return $html . '<br><span class="description">' . esc_html( self::reason_label( $reason ) ) . '</span>';
	}

	/**
	 * Human label of a credit note refusal reason (CreditNoteIssuer::refusal()).
	 *
	 * @param string $reason Reason code.
	 */
	private static function reason_label( string $reason ): string {
		return match ( $reason ) {
			'zero_amount' => __( 'Nothing was refunded: no credit note.', 'lumia-factures' ),
			'in_flight'   => __( 'This refund was just created: its credit note can be issued in a few minutes.', 'lumia-factures' ),
			'no_order'    => __( 'The refunded order no longer exists.', 'lumia-factures' ),
			'import'      => __( 'Waiting for the Facture PDF Lumia import.', 'lumia-factures' ),
			'conflict'    => __( 'The Facture PDF Lumia invoice of this order could not be imported (conflict).', 'lumia-factures' ),
			'reused_id'   => __( 'This refund reuses the id of a deleted refund that carries a credit note: no credit note can be issued for it.', 'lumia-factures' ),
			'vetoed'      => __( 'Refused by the lumia_factures_can_issue_credit_note filter.', 'lumia-factures' ),
			'no_invoice'  => __( 'The order has no invoice: issue the invoice first.', 'lumia-factures' ),
			default       => $reason,
		};
	}

	/**
	 * Date of a document, in the site format.
	 *
	 * @param int $timestamp UTC timestamp.
	 */
	private static function date( int $timestamp ): string {
		return (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp );
	}

	/**
	 * The refund, when it is a refund of this order.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 */
	private static function refund_of( int $order_id, int $refund_id ): ?WC_Order_Refund {
		$refund = $order_id > 0 && $refund_id > 0 ? wc_get_order( $refund_id ) : null;

		return $refund instanceof WC_Order_Refund && $refund->get_parent_id() === $order_id ? $refund : null;
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
	 * Order screen ids: legacy post type and HPOS page.
	 *
	 * @return string[]
	 */
	private static function screens(): array {
		return array_values( array_unique( array_filter( [ 'shop_order', wc_get_page_screen_id( 'shop-order' ) ] ) ) );
	}

	/**
	 * Whether the current screen is an order screen.
	 */
	private static function on_order_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return null !== $screen && in_array( $screen->id, self::screens(), true );
	}

	/**
	 * Notice messages by code.
	 *
	 * @return array<string, string>
	 */
	private static function messages(): array {
		return [
			'invoice_issued'              => __( 'The invoice has been issued.', 'lumia-factures' ),
			'invoice_not_issued'          => __( 'The invoice could not be issued.', 'lumia-factures' ),
			'invoice_restored'            => __( 'The invoice PDF has been rebuilt.', 'lumia-factures' ),
			'invoice_restore_refused'     => __( 'The invoice PDF could not be rebuilt.', 'lumia-factures' ),
			'credit_note_issued'          => __( 'The credit note has been issued.', 'lumia-factures' ),
			'credit_note_not_issued'      => __( 'The credit note could not be issued. The reason is shown in the Invoice box.', 'lumia-factures' ),
			'credit_note_restored'        => __( 'The credit note PDF has been rebuilt.', 'lumia-factures' ),
			'credit_note_restore_refused' => __( 'The credit note PDF could not be rebuilt.', 'lumia-factures' ),
			'invoice_cancelled'           => __( 'The invoice has been cancelled by a credit note.', 'lumia-factures' ),
			'invoice_cancelled_pending'   => __( 'The full refund has been recorded, but its credit note is not issued yet. The reason is shown in the Invoice box.', 'lumia-factures' ),
			'invoice_cancel_refused'      => __( 'The invoice could not be cancelled.', 'lumia-factures' ),
		];
	}

	/**
	 * Notice type of a code.
	 *
	 * @param string $code Notice code.
	 */
	private static function notice_type( string $code ): string {
		if ( in_array( $code, [ 'invoice_not_issued', 'invoice_restore_refused', 'credit_note_not_issued', 'credit_note_restore_refused', 'invoice_cancel_refused' ], true ) ) {
			return 'error';
		}

		return 'invoice_cancelled_pending' === $code ? 'warning' : 'success';
	}

	/**
	 * Ends the request with a 403 unless the user manages WooCommerce.
	 */
	private static function check_capability(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * Verifies capability and nonce of an order admin-post action.
	 *
	 * @param string $name `issue` or `restore`.
	 * @return int Order id.
	 */
	private static function authorize( string $name ): int {
		self::check_capability();

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

		check_admin_referer( 'lumia_factures_invoices_' . $name . '_' . $order_id );

		return $order_id;
	}

	/**
	 * Verifies capability and nonce (signed per refund) of a credit note admin-post action.
	 *
	 * @param string $name `issue_credit_note` or `restore_credit_note`.
	 * @return array{int, int} Order id and refund id, as posted (their link is checked by the action).
	 */
	private static function authorize_refund( string $name ): array {
		self::check_capability();

		$order_id  = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$refund_id = isset( $_GET['refund_id'] ) ? absint( wp_unslash( $_GET['refund_id'] ) ) : 0;

		check_admin_referer( 'lumia_factures_invoices_' . $name . '_' . $refund_id );

		return [ $order_id, $refund_id ];
	}

	/**
	 * Redirects to the order edit screen with the notice, then exits.
	 *
	 * @param int    $order_id Order id.
	 * @param string $code     Notice code.
	 */
	private static function redirect( int $order_id, string $code ): void {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$back  = wp_get_referer();
		if ( ! $back ) {
			$back = $order instanceof WC_Order ? $order->get_edit_order_url() : admin_url();
		}

		$url = add_query_arg(
			[
				'lumia_factures_notice'      => $code,
				'lumia_factures_notice_type' => self::notice_type( $code ),
			],
			remove_query_arg( [ 'lumia_factures_notice', 'lumia_factures_notice_type' ], $back )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Signed admin-post URL of an order action.
	 *
	 * @param string $name     `issue` or `restore`.
	 * @param int    $order_id Order id.
	 */
	private static function action_url( string $name, int $order_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => 'lumia_factures_invoices_' . $name,
					'order_id' => $order_id,
				],
				admin_url( 'admin-post.php' )
			),
			'lumia_factures_invoices_' . $name . '_' . $order_id
		);
	}

	/**
	 * Signed admin-post URL of a credit note action, its nonce bound to the refund.
	 *
	 * @param string $name      `issue_credit_note` or `restore_credit_note`.
	 * @param int    $order_id  Order id.
	 * @param int    $refund_id Refund id.
	 */
	private static function refund_action_url( string $name, int $order_id, int $refund_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'    => 'lumia_factures_invoices_' . $name,
					'order_id'  => $order_id,
					'refund_id' => $refund_id,
				],
				admin_url( 'admin-post.php' )
			),
			'lumia_factures_invoices_' . $name . '_' . $refund_id
		);
	}

	/**
	 * Normalizes the object given by WordPress to an order.
	 *
	 * @param WP_Post|WC_Order|mixed $post_or_order Object given by WordPress.
	 */
	private static function resolve_order( $post_or_order ): ?WC_Order {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}

		if ( $post_or_order instanceof WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order instanceof WC_Order ? $order : null;
		}

		return null;
	}
}
