<?php
/**
 * A row of the register of documents.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Documents;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable view of a register row.
 */
final class IssuedDocument {

	/**
	 * Constructor.
	 *
	 * @param int         $id           Row id.
	 * @param string      $type         Document type.
	 * @param int         $order_id     Order id (the refund id for a credit note).
	 * @param string      $status       `reserved` or `issued`.
	 * @param string|null $number       Printed number, null while reserved.
	 * @param string|null $sequence_key Counter key.
	 * @param int|null    $sequence     Counter value.
	 * @param int         $issued_at    UTC timestamp, 0 when not issued.
	 * @param string|null $file         Archived file, relative path.
	 * @param string|null $checksum     SHA-256 of the archived file.
	 * @param int         $parent_id    Order a credit note belongs to, 0 for an invoice.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly int $order_id,
		public readonly string $status,
		public readonly ?string $number,
		public readonly ?string $sequence_key,
		public readonly ?int $sequence,
		public readonly int $issued_at,
		public readonly ?string $file,
		public readonly ?string $checksum,
		public readonly int $parent_id = 0
	) {}
}
