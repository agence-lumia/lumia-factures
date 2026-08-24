<?php
/**
 * Point d'entrée public de téléchargement.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Frontend;

use Lumia\FacturePDF\Documents\Document;
use Lumia\FacturePDF\Documents\DocumentRegistry;
use Lumia\FacturePDF\Documents\Invoice;
use Lumia\FacturePDF\Storage\AccessGuard;
use Lumia\FacturePDF\Storage\FileStore;
use Lumia\FacturePDF\Storage\PdfGenerator;
use Lumia\FacturePDF\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sert les PDF au navigateur, après contrôle.
 *
 * Le fichier n'est jamais exposé par son URL réelle : le contenu est diffusé
 * par PHP après vérification. Une redirection vers le fichier contournerait
 * tout le contrôle d'accès, puisque l'URL obtenue resterait ensuite valide et
 * partageable.
 */
final class DownloadHandler {

	/**
	 * Paramètre déclenchant le téléchargement.
	 */
	public const QUERY_VAR = 'fpl_download';

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		// `init` plutôt que `template_redirect` : le téléchargement ne doit
		// pas dépendre de la résolution d'une requête WordPress, et doit
		// rester fonctionnel même sur une URL qui ne correspond à aucun
		// contenu.
		add_action( 'init', array( $this, 'maybe_handle' ), 20 );
	}

	/**
	 * Traite la requête si elle nous concerne.
	 */
	public function maybe_handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- l'autorisation repose sur la clé de commande ou la session, pas sur un nonce.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['document'] ) ? sanitize_key( wp_unslash( $_GET['document'] ) ) : Invoice::TYPE;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$disposition = isset( $_GET['inline'] ) ? 'inline' : 'attachment';

		if ( 0 === $order_id ) {
			$this->deny( __( 'Commande introuvable.', 'facture-pdf-lumia' ), 404 );
		}

		$document = DocumentRegistry::make( $type, $order_id );

		if ( ! $document instanceof Document ) {
			$this->deny( __( 'Document introuvable.', 'facture-pdf-lumia' ), 404 );
		}

		if ( ! AccessGuard::can_access( $document, $key ) ) {
			// Message volontairement identique à celui d'un document
			// inexistant : distinguer les deux cas permettrait d'énumérer les
			// commandes existantes.
			Logger::warning(
				'Accès refusé à un document.',
				array(
					'order' => $order_id,
					'type'  => $type,
				)
			);

			$this->deny( __( 'Document introuvable.', 'facture-pdf-lumia' ), 404 );
		}

		if ( ! AccessGuard::is_ready( $document ) ) {
			$this->deny( __( 'Ce document n\'est pas encore disponible.', 'facture-pdf-lumia' ), 403 );
		}

		// Le quota n'est consommé que si une génération est réellement
		// nécessaire : servir un fichier déjà en cache est peu coûteux et ne
		// doit pas être limité.
		if ( ! FileStore::has_cache( $document ) && ! AccessGuard::check_rate_limit() ) {
			$this->deny( __( 'Trop de demandes successives. Merci de réessayer dans une minute.', 'facture-pdf-lumia' ), 429 );
		}

		$contents = PdfGenerator::contents( $document );

		if ( '' === $contents ) {
			$this->deny( __( 'La génération du document a échoué.', 'facture-pdf-lumia' ), 500 );
		}

		$this->stream( $document, $contents, $disposition );
	}

	/**
	 * Envoie le PDF au navigateur.
	 *
	 * @param Document $document    Document servi.
	 * @param string   $contents    Contenu binaire.
	 * @param string   $disposition `attachment` ou `inline`.
	 */
	private function stream( Document $document, string $contents, string $disposition ): void {
		$filename = $document->get_filename() . '.pdf';

		nocache_headers();

		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . strlen( $contents ) );
		header( 'X-Content-Type-Options: nosniff' );
		header(
			sprintf(
				'Content-Disposition: %s; filename="%s"',
				'inline' === $disposition ? 'inline' : 'attachment',
				sanitize_file_name( $filename )
			)
		);

		// Un tampon actif ajouterait des octets parasites en tête de flux et
		// corromprait le PDF.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flux binaire.
		echo $contents;

		exit;
	}

	/**
	 * Interrompt la requête avec un message et un code HTTP.
	 *
	 * @param string $message Message affiché.
	 * @param int    $status  Code HTTP.
	 */
	private function deny( string $message, int $status ): void {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Document indisponible', 'facture-pdf-lumia' ),
			array( 'response' => $status )
		);
	}

	/**
	 * Construit l'URL de téléchargement d'un document.
	 *
	 * La clé de commande n'est ajoutée que pour un visiteur non connecté :
	 * inutile de la faire circuler quand la session suffit à autoriser
	 * l'accès.
	 *
	 * @param Document $document Document concerné.
	 * @param bool     $inline   Affichage dans le navigateur au lieu du téléchargement.
	 */
	public static function url( Document $document, bool $inline = false ): string {
		$args = array(
			self::QUERY_VAR => 1,
			'order'         => $document->get_order()->get_id(),
			'document'      => $document->get_type(),
		);

		if ( ! is_user_logged_in() ) {
			$args['key'] = $document->get_order()->get_order_key();
		}

		if ( $inline ) {
			$args['inline'] = 1;
		}

		/**
		 * Filtre l'URL de téléchargement d'un document.
		 *
		 * @param string   $url      URL construite.
		 * @param Document $document Document concerné.
		 */
		return (string) apply_filters(
			'fpl_download_url',
			add_query_arg( $args, home_url( '/' ) ),
			$document
		);
	}
}
