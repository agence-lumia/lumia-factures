<?php
/**
 * Fonctions publiques du plugin.
 *
 * Ces fonctions constituent l'API stable destinée aux thèmes et aux
 * extensions. Elles sont volontairement tolérantes : elles acceptent un
 * identifiant ou un objet commande, et renvoient une valeur vide plutôt que
 * de lever une erreur si le document n'existe pas — un gabarit de thème ne
 * doit jamais casser une page parce qu'une facture n'est pas encore émise.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

use Lumia\FacturePDF\Documents\DocumentRegistry;
use Lumia\FacturePDF\Frontend\DownloadHandler;
use Lumia\FacturePDF\Storage\AccessGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'fpl_has_invoice' ) ) {
	/**
	 * Une facture est-elle disponible pour cette commande ?
	 *
	 * @param int|WC_Order $order Commande ou identifiant.
	 */
	function fpl_has_invoice( $order ): bool {
		$invoice = DocumentRegistry::invoice( $order );

		if ( null === $invoice ) {
			return false;
		}

		return $invoice->is_available() && AccessGuard::can_access( $invoice );
	}
}

if ( ! function_exists( 'fpl_get_invoice_url' ) ) {
	/**
	 * URL de téléchargement de la facture.
	 *
	 * @param int|WC_Order $order  Commande ou identifiant.
	 * @param bool         $inline Ouvrir dans le navigateur au lieu de télécharger.
	 *
	 * @return string Chaîne vide si aucune facture n'est disponible.
	 */
	function fpl_get_invoice_url( $order, bool $inline = false ): string {
		$invoice = DocumentRegistry::invoice( $order );

		if ( null === $invoice || ! $invoice->is_available() ) {
			return '';
		}

		return DownloadHandler::url( $invoice, $inline );
	}
}

if ( ! function_exists( 'fpl_get_invoice_number' ) ) {
	/**
	 * Numéro de la facture, s'il a été attribué.
	 *
	 * N'attribue jamais de numéro : cette fonction est destinée à l'affichage
	 * et peut être appelée depuis n'importe quel gabarit, y compris sur des
	 * commandes qui ne doivent pas encore être facturées.
	 *
	 * @param int|WC_Order $order Commande ou identifiant.
	 */
	function fpl_get_invoice_number( $order ): string {
		$invoice = DocumentRegistry::invoice( $order );

		return null === $invoice ? '' : $invoice->get_number();
	}
}

if ( ! function_exists( 'fpl_get_invoice_date' ) ) {
	/**
	 * Date d'émission de la facture, au format demandé.
	 *
	 * @param int|WC_Order $order  Commande ou identifiant.
	 * @param string       $format Format de date, par défaut celui du site.
	 */
	function fpl_get_invoice_date( $order, string $format = '' ): string {
		$invoice = DocumentRegistry::invoice( $order );

		if ( null === $invoice ) {
			return '';
		}

		$timestamp = $invoice->get_date();

		if ( null === $timestamp ) {
			return '';
		}

		$format = '' !== $format ? $format : (string) get_option( 'date_format', 'd/m/Y' );

		return wp_date( $format, $timestamp );
	}
}
