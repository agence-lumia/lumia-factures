<?php
/**
 * A product line of a statement.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Amounts;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable product line. Amounts are before discount, in minor units, incl. or excl.
 * VAT depending on the statement's display mode. A line never carries a discount.
 */
final class Line {

	/**
	 * Constructor.
	 *
	 * @param string                                       $name       Display name.
	 * @param array<int, array{label: string, value: string}> $attributes Attributes and visible meta, plain text.
	 * @param string                                       $sku        SKU, '' when none.
	 * @param int|float                                    $quantity   Quantity.
	 * @param int                                          $unit       Unit price, minor units, before discount.
	 * @param int                                          $total      Line total, minor units, before discount.
	 * @param float|null                                   $tax_rate   Tax rate percent, null when untaxed.
	 * @param int                                          $product_id Product (or variation) id, 0 when none.
	 * @param int                                          $image_id   Image attachment id, 0 when none.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $attributes,
		public readonly string $sku,
		public readonly int|float $quantity,
		public readonly int $unit,
		public readonly int $total,
		public readonly ?float $tax_rate,
		public readonly int $product_id,
		public readonly int $image_id
	) {}
}
