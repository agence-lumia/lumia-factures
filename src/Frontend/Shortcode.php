<?php
/**
 * Shortcode de téléchargement de facture.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Frontend;

use Lumia\FacturePDF\Documents\DocumentRegistry;
use Lumia\FacturePDF\Storage\AccessGuard;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[facture_pdf_lumia]` — lien de téléchargement de la facture.
 *
 * Ne rend jamais un bouton mort : sans facture disponible ou sans
 * autorisation d'accès, le shortcode renvoie une chaîne vide plutôt qu'un
 * lien qui échouerait au clic.
 */
final class Shortcode {

	/**
	 * Nom du shortcode.
	 */
	public const TAG = 'facture_pdf_lumia';

	/**
	 * Accroche le shortcode.
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Rend le shortcode.
	 *
	 * @param array<string, string>|string $atts Attributs du shortcode.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'order_id' => '0',
				'label'    => __( 'Télécharger ma facture', 'facture-pdf-lumia' ),
				'class'    => 'fpl-download-button',
				'inline'   => '0',
			),
			(array) $atts,
			self::TAG
		);

		$order_id = absint( $atts['order_id'] );

		if ( 0 === $order_id ) {
			$order_id = self::detect_order_id_from_context();
		}

		if ( 0 === $order_id ) {
			return '';
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$document = DocumentRegistry::invoice( $order );

		if ( null === $document || ! $document->is_available() ) {
			return '';
		}

		if ( ! AccessGuard::can_access( $document, OrderContext::current_order_key() ) ) {
			return '';
		}

		$url = DownloadHandler::url( $document, '1' === $atts['inline'] );

		return sprintf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( $url ),
			esc_attr( $atts['class'] ),
			esc_html( $atts['label'] )
		);
	}

	/**
	 * Déduit l'identifiant de commande du contexte de page courant.
	 *
	 * Couvre les deux points où WooCommerce affiche naturellement une
	 * commande précise : la page de confirmation juste après l'achat, et la
	 * page « Voir la commande » dans le compte client.
	 */
	private static function detect_order_id_from_context(): int {
		return OrderContext::current_order_id();
	}
}
