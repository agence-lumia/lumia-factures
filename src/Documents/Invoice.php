<?php
/**
 * Document Facture.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Facture client.
 *
 * Seul type de document implémenté en v1.
 */
final class Invoice extends Document {

	/**
	 * Identifiant machine.
	 */
	public const TYPE = 'invoice';

	/**
	 * Préfixe des metas de commande.
	 */
	public const META_PREFIX = '_fpl_invoice_';

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		$title = (string) $this->settings->get( 'content.invoice_title', __( 'Facture', 'facture-pdf-lumia' ) );

		if ( '' === trim( $title ) ) {
			$title = __( 'Facture', 'facture-pdf-lumia' );
		}

		/**
		 * Filtre le libellé du document.
		 *
		 * @param string  $title    Libellé.
		 * @param Invoice $document Instance.
		 */
		return (string) apply_filters( 'fpl_invoice_title', $title, $this );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta_prefix(): string {
		return self::META_PREFIX;
	}
}
