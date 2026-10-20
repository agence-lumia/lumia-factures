<?php
/**
 * Whether a customer link to an issued document would work.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use WC_Order;
use WC_Order_Refund;

/**
 * One answer for every customer surface (download endpoint, Bricks tags, lists): a link is shown only
 * when the endpoint would serve the PDF. Reads only: never numbers, notes nor saves anything.
 */
final class Availability {

	/**
	 * Whether the document is issued and its PDF can be served: the archive passes verification, or
	 * the PDF was never built and the endpoint can build it.
	 *
	 * @param IssuedDocument $doc Register row.
	 */
	public static function is_available( IssuedDocument $doc ): bool {
		if ( ! self::is_issued( $doc ) ) {
			return false;
		}

		return null === $doc->file ? self::can_build( $doc ) : Archive::verify( $doc );
	}

	/**
	 * Whether the endpoint may build the PDF of an issued document whose file was never recorded.
	 *
	 * An invoice needs its order; a credit note needs its refund, still the one it was issued for,
	 * and the refunded order. A recorded file (missing or damaged) is never built for a visitor.
	 *
	 * @param IssuedDocument $doc Register row.
	 */
	public static function can_build( IssuedDocument $doc ): bool {
		if ( ! self::is_issued( $doc ) || null !== $doc->file ) {
			return false;
		}

		if ( Issuer::TYPE === $doc->type ) {
			return wc_get_order( $doc->order_id ) instanceof WC_Order;
		}

		if ( CreditNoteIssuer::TYPE !== $doc->type || $doc->parent_id <= 0 ) {
			return false;
		}

		$refund = wc_get_order( $doc->order_id );
		if ( ! $refund instanceof WC_Order_Refund ) {
			return false;
		}

		$own = CreditNoteIssuer::find_for( $refund );

		return null !== $own && $own->id === $doc->id && wc_get_order( $doc->parent_id ) instanceof WC_Order;
	}

	/**
	 * Whether the row is an issued, numbered document.
	 *
	 * @param IssuedDocument $doc Register row.
	 */
	private static function is_issued( IssuedDocument $doc ): bool {
		return 'issued' === $doc->status && null !== $doc->number && '' !== $doc->number;
	}
}
