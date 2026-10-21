<?php
/**
 * Order note texts the issuing pipeline writes for one document type.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Documents;

defined( 'ABSPATH' ) || exit;

/**
 * Translated note templates, so the shared pipeline names the right document on the order.
 *
 * Each placeholder is filled by Pipeline with sprintf(); the texts of an invoice are those
 * 2.0.1 wrote, word for word (IssuerTest::test_invoice_order_notes_unchanged).
 */
final class Notes {

	/**
	 * Constructor.
	 *
	 * @param string $not_numbered  No number could be given (no counter value, or every value tried was taken).
	 * @param string $not_built     A counter value could not be turned into a number. `%d`: sequence value.
	 * @param string $not_recorded  A number could not be recorded. `%1$s`: number, `%2$d`: sequence value.
	 * @param string $other_type    A number is already used by another document type. `%1$s`: number, `%2$d`: sequence value.
	 * @param string $store_failed  The PDF could not be stored. `%s`: number.
	 * @param string $render_failed The PDF could not be generated. `%s`: number.
	 */
	public function __construct(
		public readonly string $not_numbered,
		public readonly string $not_built,
		public readonly string $not_recorded,
		public readonly string $other_type,
		public readonly string $store_failed,
		public readonly string $render_failed
	) {}

	/**
	 * The notes of an invoice.
	 */
	public static function invoice(): self {
		return new self(
			__( 'Invoice could not be numbered.', 'lumia-factures' ),
			/* translators: %d: sequence value */
			__( 'Invoice sequence value %d could not be turned into a number; this sequence value is skipped.', 'lumia-factures' ),
			/* translators: 1: invoice number, 2: sequence value */
			__( 'Invoice number %1$s (sequence %2$d) could not be recorded; this sequence value is skipped.', 'lumia-factures' ),
			/* translators: 1: invoice number, 2: sequence value */
			__( 'Invoice number %1$s (sequence %2$d) is already used by another document type; this sequence value is skipped and the invoice is not numbered. Give each document type its own number prefix.', 'lumia-factures' ),
			/* translators: %s: invoice number */
			__( 'The PDF of invoice %s could not be stored; the PDF will be built on first download.', 'lumia-factures' ),
			/* translators: %s: invoice number */
			__( 'The PDF of invoice %s could not be generated; the PDF will be built on first download.', 'lumia-factures' )
		);
	}

	/**
	 * The notes of a credit note, written on the refunded order.
	 *
	 * @param int $refund_id Refund the credit note is issued for (named when no number exists yet).
	 */
	public static function credit_note( int $refund_id ): self {
		return new self(
			/* translators: %d: refund id */
			sprintf( __( 'Credit note for refund #%d could not be numbered.', 'lumia-factures' ), $refund_id ),
			/* translators: %d: sequence value */
			__( 'Credit note sequence value %d could not be turned into a number; this sequence value is skipped.', 'lumia-factures' ),
			/* translators: 1: credit note number, 2: sequence value */
			__( 'Credit note number %1$s (sequence %2$d) could not be recorded; this sequence value is skipped.', 'lumia-factures' ),
			/* translators: 1: credit note number, 2: sequence value */
			__( 'Credit note number %1$s (sequence %2$d) is already used by another document type; this sequence value is skipped and the credit note is not numbered. Give each document type its own number prefix.', 'lumia-factures' ),
			/* translators: %s: credit note number */
			__( 'The PDF of credit note %s could not be stored; the PDF will be built on first download.', 'lumia-factures' ),
			/* translators: %s: credit note number */
			__( 'The PDF of credit note %s could not be generated; the PDF will be built on first download.', 'lumia-factures' )
		);
	}

	/**
	 * The note written when the PDF of a numbered document could not be archived.
	 *
	 * @param string $outcome `store_failed` or `render_failed` (Pipeline::archive()).
	 * @param string $number  Document number.
	 */
	public function pdf_failure( string $outcome, string $number ): string {
		return sprintf( 'store_failed' === $outcome ? $this->store_failed : $this->render_failed, $number );
	}
}
