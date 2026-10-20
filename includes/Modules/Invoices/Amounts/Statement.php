<?php
/**
 * Amounts statement of an order.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Amounts;

defined( 'ABSPATH' ) || exit;

/**
 * Lines and totals rows of a document. The summed rows always equal the grand total.
 */
final class Statement {

	/**
	 * Constructor.
	 *
	 * @param Line[]     $lines    Product lines.
	 * @param TotalRow[] $rows     Totals rows, in display order.
	 * @param string     $currency Order currency.
	 * @param bool       $incl_tax Amounts shown incl. VAT.
	 */
	public function __construct(
		public readonly array $lines,
		public readonly array $rows,
		public readonly string $currency,
		public readonly bool $incl_tax
	) {}

	/**
	 * Sum of the rows that add up to the total: not informational, not grand, with an amount.
	 */
	public function sum(): int {
		$sum = 0;
		foreach ( $this->rows as $row ) {
			if ( ! $row->informational && ! $row->grand && null !== $row->amount ) {
				$sum += $row->amount;
			}
		}

		return $sum;
	}
}
