<?php
/**
 * Read-only list of an order's customer documents.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use WC_Order;

/**
 * The documents a customer can open for an order: its invoice, then the credit notes of its refunds,
 * in the order they were issued. Every customer surface (e-mails, My Account, thank-you page,
 * shortcode, Bricks) reads this list.
 *
 * Reads only: never issues, notes nor saves anything (no Issuer::issue(), no Issuer::can_issue(), no
 * save()). A document is listed only when its link would work (Availability::is_available()).
 */
final class DocumentList {

	/**
	 * The order's available documents.
	 *
	 * @param WC_Order $order          Order (never a refund).
	 * @param bool     $with_key       True to put the order key in every URL (a link sent by e-mail).
	 * @param int|null $only_refund_id Keeps only the credit note of this refund (the invoice stays);
	 *                                 null for every credit note.
	 * @return list<array{type: string, label: string, number: string, date: string, url: string}>
	 */
	public static function for_order( WC_Order $order, bool $with_key, ?int $only_refund_id = null ): array {
		$order_id = $order->get_id();
		if ( $order_id <= 0 ) {
			return [];
		}

		$list = [];

		$invoice = Register::find( Issuer::TYPE, $order_id );
		if ( null !== $invoice && Availability::is_available( $invoice ) ) {
			$list[] = self::entry( $invoice, __( 'Invoice', 'lumia-factures' ), CustomerDownload::url( $order, true, $with_key ) );
		}

		$credit_notes = array_filter(
			Register::for_parent( CreditNoteIssuer::TYPE, $order_id ),
			static fn( IssuedDocument $doc ): bool => ( null === $only_refund_id || $doc->order_id === $only_refund_id ) && Availability::is_available( $doc )
		);

		// Issue order: by issue date, then by row id (a row reserved first may be numbered later).
		usort(
			$credit_notes,
			static fn( IssuedDocument $a, IssuedDocument $b ): int => [ $a->issued_at, $a->id ] <=> [ $b->issued_at, $b->id ]
		);

		foreach ( $credit_notes as $doc ) {
			$list[] = self::entry( $doc, __( 'Credit note', 'lumia-factures' ), CustomerDownload::credit_note_url( $doc, $order, true, $with_key ) );
		}

		return $list;
	}

	/**
	 * One entry of the list.
	 *
	 * @param IssuedDocument $doc   Issued document.
	 * @param string         $label Translated document type.
	 * @param string         $url   Download URL.
	 * @return array{type: string, label: string, number: string, date: string, url: string}
	 */
	private static function entry( IssuedDocument $doc, string $label, string $url ): array {
		return [
			'type'   => $doc->type,
			'label'  => $label,
			'number' => (string) $doc->number,
			'date'   => (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $doc->issued_at ),
			'url'    => $url,
		];
	}
}
