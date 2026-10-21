<?php
/**
 * Data identifying the document being rendered.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Rendering;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use WC_Order_Refund;

/**
 * Immutable identity of a document: its type, title, number and date, and for a credit note the
 * invoice it credits and its refund. The order, the amounts and the settings are passed apart;
 * this only carries what the issuing step decides.
 */
final class DocumentData {

	/**
	 * Document type of a credit note (the register's `type`).
	 */
	public const CREDIT_NOTE = 'credit_note';

	/**
	 * Constructor.
	 *
	 * @param string $title            Document title; when not empty it wins over the configured one, '' to use the configured one.
	 * @param string $number           Document number, '' when none (preview).
	 * @param int    $date             Issue date as a Unix timestamp, 0 when none (preview).
	 * @param bool   $preview          The document is a preview, not an issued document.
	 * @param string $type             `invoice` or `credit_note`.
	 * @param string $reference_number Credit note: number of the invoice it credits, as recorded in the register.
	 * @param int    $reference_date   Credit note: issue date of that invoice, 0 when unknown.
	 * @param int    $refund_date      Credit note: date of the refund, 0 when unknown.
	 * @param string $reason           Credit note: reason entered with the refund, '' when none.
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $number,
		public readonly int $date,
		public readonly bool $preview = false,
		public readonly string $type = 'invoice',
		public readonly string $reference_number = '',
		public readonly int $reference_date = 0,
		public readonly int $refund_date = 0,
		public readonly string $reason = ''
	) {}

	/**
	 * Whether the document is a credit note.
	 */
	public function is_credit_note(): bool {
		return self::CREDIT_NOTE === $this->type;
	}

	/**
	 * Identity of an issued credit note: its number and date, the number and date of the invoice
	 * it credits as the register holds them (an imported invoice keeps its legacy number and date),
	 * the refund date and reason.
	 *
	 * The configured title is used (`''`). A credit note is never rendered without the invoice it
	 * credits (a mandatory mention): null when the order has no issued invoice.
	 *
	 * @param IssuedDocument  $credit_note Register row of the credit note.
	 * @param WC_Order_Refund $refund      Its refund.
	 * @return self|null Null when the row is not an issued credit note, does not belong to this
	 *                   refund and its order, or the order has no issued invoice.
	 */
	public static function credit_note( IssuedDocument $credit_note, WC_Order_Refund $refund ): ?self {
		if (
			self::CREDIT_NOTE !== $credit_note->type
			|| 'issued' !== $credit_note->status
			|| null === $credit_note->number
			|| '' === $credit_note->number
			|| $credit_note->parent_id <= 0
			|| $credit_note->order_id !== $refund->get_id()
			|| $credit_note->parent_id !== $refund->get_parent_id()
		) {
			return null;
		}

		$invoice = Register::find( Issuer::TYPE, $credit_note->parent_id );
		if ( null === $invoice || 'issued' !== $invoice->status || null === $invoice->number || '' === $invoice->number ) {
			return null;
		}

		$refunded = $refund->get_date_created();

		return new self(
			'',
			$credit_note->number,
			$credit_note->issued_at,
			false,
			self::CREDIT_NOTE,
			$invoice->number,
			$invoice->issued_at,
			$refunded ? $refunded->getTimestamp() : 0,
			trim( (string) $refund->get_reason() )
		);
	}
}
