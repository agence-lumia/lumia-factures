<?php
/**
 * Pièce jointe de la facture aux emails WooCommerce.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Emails;

use Lumia\FacturePDF\Documents\DocumentRegistry;
use Lumia\FacturePDF\Settings\SettingsRepository;
use Lumia\FacturePDF\Storage\PdfGenerator;
use Lumia\FacturePDF\Support\Logger;
use Throwable;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ajoute le PDF de facture aux pièces jointes des emails WooCommerce
 * sélectionnés dans les réglages.
 *
 * Principe non négociable : un échec de génération ne doit jamais empêcher
 * l'email de partir. Un client qui ne reçoit pas sa confirmation de commande
 * est un problème bien plus grave qu'une facture manquante — celle-ci reste
 * téléchargeable depuis « Mon compte » ou régénérable depuis l'administration.
 */
final class Attacher {

	/**
	 * Accroche le filtre WooCommerce.
	 */
	public function register(): void {
		add_filter( 'woocommerce_email_attachments', array( $this, 'maybe_attach' ), 10, 4 );
	}

	/**
	 * Ajoute le PDF si les conditions sont réunies.
	 *
	 * @param array<int, string> $attachments Pièces jointes déjà prévues.
	 * @param string             $email_id    Identifiant de l'email WooCommerce.
	 * @param mixed              $object      Commande, ou tout autre objet selon l'email.
	 * @param mixed              $email       Instance de l'email en cours d'envoi.
	 *
	 * @return array<int, string>
	 */
	public function maybe_attach( $attachments, $email_id, $object, $email = null ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = array();
		}

		if ( ! $object instanceof WC_Order || ! is_string( $email_id ) || '' === $email_id ) {
			return $attachments;
		}

		$configured = (array) SettingsRepository::instance()->get( 'general.email_attachments', array() );

		if ( ! in_array( $email_id, $configured, true ) ) {
			return $attachments;
		}

		try {
			$document = DocumentRegistry::invoice( $object );

			if ( null === $document || ! $document->is_available() ) {
				return $attachments;
			}

			$path = PdfGenerator::file( $document );

			if ( null !== $path ) {
				$attachments[] = $path;
			}
		} catch ( Throwable $e ) {
			// L'email part sans pièce jointe plutôt que d'être bloqué : voir
			// la note de classe. L'échec reste journalisé pour être
			// diagnosticable après coup.
			Logger::error(
				'Échec de la jointe de facture à un email.',
				array(
					'email_id' => $email_id,
					'order'    => $object->get_id(),
					'message'  => $e->getMessage(),
				)
			);
		}

		return $attachments;
	}
}
