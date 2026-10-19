<?php
/**
 * Money in integer minor units.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Amounts;

defined( 'ABSPATH' ) || exit;

/**
 * Converts WooCommerce amounts to minor units (cents) and formats them as plain text.
 */
final class Money {

	/**
	 * Converts an amount read from WooCommerce to minor units, half away from zero.
	 *
	 * The scaled value is first rounded to 6 decimals: 0.285 * 100 is 28.499999999999996
	 * in binary floating point and would otherwise round down to 28.
	 *
	 * @param float|string $amount Amount in major units.
	 */
	public static function to_minor( float|string $amount ): int {
		$scaled = (float) $amount * 10 ** wc_get_price_decimals();

		return (int) round( round( $scaled, 6 ) );
	}

	/**
	 * Formats an amount as plain text in a currency (the order's, never the shop's).
	 *
	 * The output is not escaped: templates escape it.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency ISO currency code.
	 */
	public static function format( int $minor, string $currency ): string {
		$decimals = wc_get_price_decimals();
		$html     = wc_price(
			$minor / 10 ** $decimals,
			[
				'currency' => $currency,
				'decimals' => $decimals,
			]
		);

		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
