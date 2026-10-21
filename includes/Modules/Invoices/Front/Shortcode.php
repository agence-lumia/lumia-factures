<?php
/**
 * Invoice download and documents shortcodes.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Throwable;
use WC_Order;

/**
 * `[lumia_factures_invoice]`, and its legacy alias `[facture_pdf_lumia]`: a link to the invoice.
 * `[lumia_factures_documents]`: the documents block of the order pages (invoice, then credit notes).
 *
 * Never a dead button: without an issued document or without access it renders nothing.
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
	 * Tag of the documents list (invoice and credit notes).
	 */
	public const DOCUMENTS_TAG = 'lumia_factures_documents';

	/**
	 * Registers the tags.
	 */
	public static function register(): void {
		add_shortcode( self::TAG, [ self::class, 'render' ] );
		add_shortcode( self::LEGACY_TAG, [ self::class, 'render' ] );
		add_shortcode( self::DOCUMENTS_TAG, [ self::class, 'render_documents' ] );
	}

	/**
	 * Renders `[lumia_factures_documents order_id=""]`: the order's documents block.
	 *
	 * Without `order_id` the order comes from the WooCommerce page (OrderContext). An explicit
	 * `order_id` never bypasses the access check: it only names the order to check.
	 *
	 * @param array<string, string>|string $atts    Attributes.
	 * @param string|null                  $content Enclosed content (unused).
	 * @param string                       $tag     Tag used.
	 */
	public static function render_documents( $atts = [], $content = null, string $tag = self::DOCUMENTS_TAG ): string {
		unset( $content );

		$atts = shortcode_atts( [ 'order_id' => '0' ], (array) $atts, $tag );

		$order_id = absint( $atts['order_id'] );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : OrderContext::resolve();

		// A refund is not a WC_Order: its id never lists anything.
		if ( ! $order instanceof WC_Order || $order->get_id() <= 0 ) {
			return '';
		}

		try {
			if ( ! CustomerLinks::shows( 'shortcode', $order ) ) {
				return '';
			}
		} catch ( Throwable $e ) {
			self::log( 'Documents shortcode of order ' . $order->get_id() . ' failed: ' . $e->getMessage() );
			return '';
		}

		return self::documents( $order );
	}

	/**
	 * HTML of the order's documents the current visitor may open, '' when there is none.
	 *
	 * Each document is kept only when CustomerDownload::can_access() lets the visitor in for its type
	 * (the invoice and the credit notes have their own filters), with the key of the page. Reads only.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function documents( WC_Order $order ): string {
		try {
			$key    = OrderContext::current_order_key();
			$access = [
				Issuer::TYPE           => CustomerDownload::can_access( $order, $key ),
				CreditNoteIssuer::TYPE => CustomerDownload::can_access( $order, $key, CreditNoteIssuer::TYPE ),
			];
			if ( ! in_array( true, $access, true ) ) {
				return '';
			}

			$documents = array_values(
				array_filter(
					DocumentList::for_order( $order, false ),
					static fn( array $doc ): bool => $access[ $doc['type'] ] ?? false
				)
			);
		} catch ( Throwable $e ) {
			self::log( 'Documents list of order ' . $order->get_id() . ' failed: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			return '';
		}

		return (string) CustomerLinks::documents_html( $order, $documents );
	}

	/**
	 * Writes an operational log line.
	 *
	 * @param string $message Message.
	 */
	private static function log( string $message ): void {
		error_log( '[lumia-factures] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
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
