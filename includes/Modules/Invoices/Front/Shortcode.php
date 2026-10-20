<?php
/**
 * Invoice download shortcode.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use WC_Order;

/**
 * `[lumia_factures_invoice]`, and its legacy alias `[facture_pdf_lumia]`: a link to the invoice.
 *
 * Never a dead button: without an issued invoice or without access it renders nothing.
 */
final class Shortcode {

	/**
	 * Shortcode tag.
	 */
	public const TAG = 'lumia_factures_invoice';

	/**
	 * Facture PDF Lumia shortcode tag, kept so that existing pages keep working.
	 */
	public const LEGACY_TAG = 'facture_pdf_lumia';

	/**
	 * Registers both tags.
	 */
	public static function register(): void {
		add_shortcode( self::TAG, [ self::class, 'render' ] );
		add_shortcode( self::LEGACY_TAG, [ self::class, 'render' ] );
	}

	/**
	 * Renders the shortcode.
	 *
	 * @param array<string, string>|string $atts    Attributes.
	 * @param string|null                  $content Enclosed content (unused).
	 * @param string                       $tag     Tag used.
	 */
	public static function render( $atts = [], $content = null, string $tag = self::TAG ): string {
		unset( $content );

		$atts = shortcode_atts(
			[
				'order_id' => '0',
				'label'    => __( 'Download my invoice', 'lumia-factures' ),
				// The legacy alias keeps the class existing stylesheets target.
				'class'    => self::LEGACY_TAG === $tag ? 'fpl-download-button' : 'lumia-factures-download',
				'inline'   => '0',
			],
			(array) $atts,
			$tag
		);

		$order_id = absint( $atts['order_id'] );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : OrderContext::resolve();

		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$doc = Register::find( Issuer::TYPE, $order->get_id() );
		if ( null === $doc || 'issued' !== $doc->status ) {
			return '';
		}

		if ( ! CustomerDownload::can_access( $order, OrderContext::current_order_key() ) ) {
			return '';
		}

		return sprintf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( CustomerDownload::url( $order, '1' === $atts['inline'] ) ),
			esc_attr( $atts['class'] ),
			esc_html( $atts['label'] )
		);
	}
}
