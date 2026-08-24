<?php
/**
 * Aperçu du document dans l'administration.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Admin;

use Lumia\FacturePDF\Documents\Document;
use Lumia\FacturePDF\Documents\DocumentRegistry;
use Lumia\FacturePDF\Rendering\PdfEngine;
use Lumia\FacturePDF\Rendering\Renderer;
use Lumia\FacturePDF\Settings\Schema;
use Lumia\FacturePDF\Settings\SettingsRepository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produit un aperçu HTML et un PDF de test.
 *
 * L'aperçu HTML rend le même gabarit que le PDF, dans le navigateur : c'est
 * rapide, donc utilisable à chaque frappe pour régler couleurs et espacements.
 * Le PDF de test passe par Dompdf et fait seul foi sur le rendu final —
 * pagination, coupures de page et polices embarquées ne se comportent pas
 * comme dans un navigateur.
 */
final class PreviewController {

	/**
	 * Action AJAX de l'aperçu HTML.
	 */
	public const AJAX_ACTION = 'fpl_preview_html';

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_html' ) );
		add_action( 'admin_post_fpl_preview_pdf', array( $this, 'handle_pdf' ) );
	}

	/**
	 * Répond à la demande d'aperçu HTML.
	 */
	public function handle_html(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissions insuffisantes.', 'facture-pdf-lumia' ) ), 403 );
		}

		check_ajax_referer( 'fpl_preview', 'nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nettoyé par le dépôt de réglages.
		$overrides = isset( $_POST['fpl'] ) && is_array( $_POST['fpl'] ) ? wp_unslash( $_POST['fpl'] ) : array();
		$tab       = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$order_id  = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;

		$restore = $this->apply_overrides( $tab, (array) $overrides );

		$document = $this->resolve_document( $order_id );

		if ( null === $document ) {
			$restore();

			wp_send_json_error( array( 'message' => __( 'Impossible de construire un aperçu.', 'facture-pdf-lumia' ) ), 500 );
		}

		$html = Renderer::render( $document );

		$restore();

		if ( '' === $html ) {
			wp_send_json_error( array( 'message' => __( 'Le rendu a échoué.', 'facture-pdf-lumia' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'html'   => $html,
				'source' => $document->get_order()->get_id() > 0
					/* translators: %s: numéro de commande */
					? sprintf( __( 'Commande #%s', 'facture-pdf-lumia' ), $document->get_order()->get_order_number() )
					: __( 'Commande de démonstration', 'facture-pdf-lumia' ),
			)
		);
	}

	/**
	 * Génère et envoie un PDF de test.
	 */
	public function handle_pdf(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'facture-pdf-lumia' ) );
		}

		check_admin_referer( 'fpl_preview_pdf', 'fpl_preview_nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;

		$document = $this->resolve_document( $order_id );

		if ( null === $document ) {
			wp_die( esc_html__( 'Impossible de construire un aperçu.', 'facture-pdf-lumia' ) );
		}

		$pdf = PdfEngine::render( Renderer::render( $document ) );

		if ( '' === $pdf ) {
			wp_die( esc_html__( 'La génération du PDF de test a échoué. Consultez le journal dans WooCommerce → État → Journaux.', 'facture-pdf-lumia' ) );
		}

		nocache_headers();

		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'Content-Disposition: inline; filename="apercu-facture.pdf"' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flux binaire.
		echo $pdf;

		exit;
	}

	/**
	 * Construit le document à prévisualiser.
	 *
	 * Utilise la commande demandée si elle existe, sinon la commande de
	 * démonstration. Le document est systématiquement marqué en aperçu : il ne
	 * doit jamais attribuer de numéro ni écrire quoi que ce soit sur une vraie
	 * commande.
	 *
	 * @param int $order_id Identifiant de commande, 0 pour la démonstration.
	 */
	private function resolve_document( int $order_id ): ?Document {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order ) {
			$order = DemoOrder::build();

			// Une commande non enregistrée n'a pas de lignes de taxe liées à
			// des taux réels : sans cette injection, le bloc des totaux
			// perdrait sa ventilation de TVA et l'aperçu serait trompeur.
			add_filter( 'fpl_demo_tax_totals', array( DemoOrder::class, 'tax_totals' ) );
		}

		$document = DocumentRegistry::invoice( $order );

		if ( null === $document ) {
			return null;
		}

		return $document->set_preview( true );
	}

	/**
	 * Applique temporairement les valeurs non enregistrées du formulaire.
	 *
	 * C'est ce qui rend l'aperçu réellement « live » : sans cela il faudrait
	 * enregistrer avant chaque vérification, ce qui rendrait le réglage des
	 * couleurs pénible. Les valeurs passent par le nettoyage habituel du
	 * dépôt, donc rien d'inconnu ne peut entrer par cette voie.
	 *
	 * @param string               $tab       Onglet d'origine.
	 * @param array<string, mixed> $overrides Valeurs brutes du formulaire.
	 *
	 * @return callable Fonction retirant la surcharge.
	 */
	private function apply_overrides( string $tab, array $overrides ): callable {
		$schema = Schema::all();

		if ( '' === $tab || array() === $overrides || ! isset( $schema[ $tab ] ) ) {
			return static function (): void {};
		}

		$clean = SettingsRepository::instance()->sanitize_tab( $tab, $overrides );

		$filter = static function ( array $settings ) use ( $tab, $clean ): array {
			$settings[ $tab ] = array_merge( $settings[ $tab ] ?? array(), $clean );

			return $settings;
		};

		add_filter( 'fpl_settings', $filter );

		SettingsRepository::instance()->flush();

		return static function () use ( $filter ): void {
			remove_filter( 'fpl_settings', $filter );

			SettingsRepository::instance()->flush();
		};
	}

	/**
	 * Rend l'onglet Aperçu.
	 */
	public static function render(): void {
		printf(
			'<h2 class="fpl-panel__title">%s</h2><p class="fpl-panel__desc">%s</p>',
			esc_html__( 'Aperçu', 'facture-pdf-lumia' ),
			esc_html__( 'L\'aperçu HTML se rafraîchit à chaque modification et sert à régler rapidement couleurs et espacements. Seul le PDF de test reflète fidèlement le rendu final : pagination, coupures de page et polices embarquées ne se comportent pas comme dans un navigateur.', 'facture-pdf-lumia' )
		);

		echo '<div class="fpl-preview-toolbar">';

		printf(
			'<label for="fpl-preview-order">%s</label> <input type="number" id="fpl-preview-order" class="small-text" min="0" step="1" placeholder="%s"> <button type="button" class="button" id="fpl-preview-refresh">%s</button>',
			esc_html__( 'N° de commande :', 'facture-pdf-lumia' ),
			esc_attr__( 'démo', 'facture-pdf-lumia' ),
			esc_html__( 'Rafraîchir', 'facture-pdf-lumia' )
		);

		printf(
			'<form method="post" action="%s" target="_blank" class="fpl-preview-pdfform">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( 'fpl_preview_pdf', 'fpl_preview_nonce' );

		echo '<input type="hidden" name="action" value="fpl_preview_pdf">';
		echo '<input type="hidden" name="order_id" value="0" id="fpl-preview-order-mirror">';

		printf(
			'<button type="submit" class="button button-primary">%s</button>',
			esc_html__( 'Générer un PDF de test', 'facture-pdf-lumia' )
		);

		echo '</form>';

		printf( '<span class="fpl-preview-source" id="fpl-preview-source"></span>' );

		echo '</div>';

		printf(
			'<div class="fpl-preview-frame"><iframe id="fpl-preview-iframe" title="%s"></iframe><div class="fpl-preview-loading" id="fpl-preview-loading">%s</div></div>',
			esc_attr__( 'Aperçu de la facture', 'facture-pdf-lumia' ),
			esc_html__( 'Chargement…', 'facture-pdf-lumia' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Laisser le champ vide utilise une commande de démonstration à deux taux de TVA, avec remise, frais et adresse de livraison distincte. Aucun numéro de facture n\'est consommé par l\'aperçu.', 'facture-pdf-lumia' )
		);
	}
}
