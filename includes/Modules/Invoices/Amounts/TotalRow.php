<?php
/**
 * A row of the totals block.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Amounts;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable totals row.
 */
final class TotalRow {

	/**
	 * Constructor.
	 *
	 * @param string      $key           `subtotal`, `discount`, `coupons`, `shipping`, `fee`, `adjustment`
	 *                                   (credit note: amount refunded beyond its items), `rounding`,
	 *                                   `total_excl`, `tax` or `total`.
	 * @param string      $label         Display label.
	 * @param int|null    $amount        Minor units, null for `coupons`.
	 * @param string|null $text          Text value (the coupon codes).
	 * @param bool        $grand         The grand total.
	 * @param bool        $informational Shown but not part of the sum.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly ?int $amount,
		public readonly ?string $text = null,
		public readonly bool $grand = false,
		public readonly bool $informational = false
	) {}
}
