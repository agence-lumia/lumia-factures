<?php
/**
 * Canonical WooCommerce email ids.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Modules\Invoices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * The settings store one id per WooCommerce email, but WooCommerce renames one of its email objects.
 *
 * While it sends a partial refund, `WC_Email_Customer_Refunded_Order::trigger()` sets the object's id
 * to `customer_partially_refunded_order`, and the mailer keeps that object for the rest of the request
 * (or of the PHP process). Any id read from the objects or from an email hook goes through normalize().
 */
final class EmailIds {

	/**
	 * Id of the WooCommerce refund email, the one the settings store.
	 */
	public const REFUND = 'customer_refunded_order';

	/**
	 * Id the refund email takes while WooCommerce sends a partial refund.
	 */
	public const PARTIAL_REFUND = 'customer_partially_refunded_order';

	/**
	 * Canonical id of an email: the partial refund id becomes the refund email id.
	 *
	 * @param string $id Email id, as the email object or hook gives it.
	 */
	public static function normalize( string $id ): string {
		return self::PARTIAL_REFUND === $id ? self::REFUND : $id;
	}
}
