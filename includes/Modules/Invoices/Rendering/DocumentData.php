<?php
/**
 * Data identifying the document being rendered.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Rendering;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable identity of a document: its title, number and date. The order and the settings
 * are passed apart; this only carries what the issuing step decides.
 */
final class DocumentData {

	/**
	 * Constructor.
	 *
	 * @param string $title   Document title, '' to use the configured one.
	 * @param string $number  Document number, '' when none (preview).
	 * @param int    $date    Issue date as a Unix timestamp, 0 when none (preview).
	 * @param bool   $preview The document is a preview, not an issued invoice.
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $number,
		public readonly int $date,
		public readonly bool $preview = false
	) {}
}
