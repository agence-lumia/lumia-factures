<?php
/**
 * Encart facture sur la fiche commande.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Admin;

use Lumia\FacturePDF\Documents\DocumentRegistry;
use Lumia\FacturePDF\Documents\Invoice;
use Lumia\FacturePDF\Frontend\DownloadHandler;
use Lumia\FacturePDF\Storage\FileStore;
use Lumia\FacturePDF\Storage\PdfGenerator;
use Lumia\FacturePDF\Support\Logger;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Affiche l'état de la facture sur la fiche commande, et une colonne dans la
 * liste des commandes.
 *
 * Enregistrée sur les deux écrans possibles (tables dédiées HPOS et articles
 * WordPress legacy) : selon la configuration du site, l'un ou l'autre est
 * actif, jamais les deux à la fois, mais le code ne doit pas en supposer un
 * en particulier.
 */
final class OrderMetabox {

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'admin_post_fpl_regenerate_invoice', array( $this, 'handle_regenerate' ) );

		// Colonne dans la liste des commandes, sur les deux écrans possibles.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Déclare l'encart, sur les deux écrans possibles.
	 */
	public function add_metabox(): void {
		$screens = array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) );

		foreach ( array_unique( array_filter( $screens ) ) as $screen ) {
			add_meta_box(
				'fpl-invoice',
				__( 'Facture PDF', 'facture-pdf-lumia' ),
				array( $this, 'render_metabox' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Rend le contenu de l'encart.
	 *
	 * @param \WP_Post|WC_Order $post_or_order Objet fourni par WordPress selon l'écran.
	 */
	public function render_metabox( $post_or_order ): void {
		$order = $this->resolve_order( $post_or_order );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$document = DocumentRegistry::invoice( $order );

		if ( null === $document ) {
			return;
		}

		wp_nonce_field( 'fpl_metabox', 'fpl_metabox_nonce' );

		if ( $document->exists() ) {
			printf(
				'<p class="fpl-metabox__number"><strong>%s</strong><br>%s</p>',
				esc_html( $document->get_number() ),
				esc_html( $document->get_formatted_date() )
			);

			$this->render_cache_status( $document );

			echo '<p class="fpl-metabox__actions">';

			printf(
				'<a href="%s" class="button" target="_blank" rel="noopener">%s</a> ',
				esc_url( DownloadHandler::url( $document ) ),
				esc_html__( 'Télécharger', 'facture-pdf-lumia' )
			);

			$this->render_regenerate_button( $order );

			echo '</p>';

			return;
		}

		if ( $document->is_available() ) {
			printf(
				'<p class="fpl-metabox__pending">%s</p>',
				esc_html__( 'Pas encore générée. Elle sera émise au premier téléchargement.', 'facture-pdf-lumia' )
			);

			$this->render_regenerate_button( $order, __( 'Générer maintenant', 'facture-pdf-lumia' ) );

			return;
		}

		printf(
			'<p class="fpl-metabox__unavailable description">%s</p>',
			esc_html__( 'Le statut actuel de la commande ne permet pas encore d\'émettre de facture.', 'facture-pdf-lumia' )
		);
	}

	/**
	 * Ligne d'état du cache, quand une génération existe déjà.
	 *
	 * @param Invoice $document Document concerné.
	 */
	private function render_cache_status( Invoice $document ): void {
		if ( ! FileStore::has_cache( $document ) ) {
			printf(
				'<p class="fpl-metabox__cache description">%s</p>',
				esc_html__( 'Fichier non mis en cache : régénéré à la demande.', 'facture-pdf-lumia' )
			);

			return;
		}

		$expires = FileStore::expires_at( $document );

		if ( null === $expires ) {
			return;
		}

		printf(
			'<p class="fpl-metabox__cache description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: date d'expiration du fichier en cache */
					__( 'Fichier en cache jusqu\'au %s.', 'facture-pdf-lumia' ),
					wp_date( (string) get_option( 'date_format', 'd/m/Y' ), $expires )
				)
			)
		);
	}

	/**
	 * Bouton de régénération.
	 *
	 * @param WC_Order $order Commande concernée.
	 * @param string   $label Libellé du bouton.
	 */
	private function render_regenerate_button( WC_Order $order, string $label = '' ): void {
		$label = '' !== $label ? $label : __( 'Régénérer', 'facture-pdf-lumia' );

		printf(
			'<form method="post" action="%s" style="display:inline;">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( 'fpl_regenerate_' . $order->get_id(), 'fpl_regenerate_nonce' );

		echo '<input type="hidden" name="action" value="fpl_regenerate_invoice">';

		printf( '<input type="hidden" name="order_id" value="%d">', (int) $order->get_id() );

		printf(
			'<button type="submit" class="button">%s</button>',
			esc_html( $label )
		);

		echo '</form>';
	}

	/**
	 * Traite la demande de (re)génération.
	 */
	public function handle_regenerate(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;

		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'facture-pdf-lumia' ) );
		}

		check_admin_referer( 'fpl_regenerate_' . $order_id, 'fpl_regenerate_nonce' );

		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Commande introuvable.', 'facture-pdf-lumia' ) );
		}

		$document = DocumentRegistry::invoice( $order );

		if ( null === $document || ! $document->is_available() ) {
			wp_die( esc_html__( 'Cette commande ne peut pas être facturée dans son état actuel.', 'facture-pdf-lumia' ) );
		}

		if ( ! $document->exists() ) {
			$document->assign_number();
		}

		PdfGenerator::contents( $document, true );

		Logger::info(
			'Facture régénérée depuis la fiche commande.',
			array( 'order' => $order_id, 'user' => get_current_user_id() )
		);

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );

		exit;
	}

	/**
	 * Ajoute la colonne à la liste des commandes.
	 *
	 * @param array<string, string> $columns Colonnes existantes.
	 *
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			// Juste après le statut : c'est l'endroit où l'information est la
			// plus utile à parcourir d'un coup d'œil sur la liste.
			if ( 'order_status' === $key ) {
				$new['fpl_invoice'] = __( 'Facture', 'facture-pdf-lumia' );
			}
		}

		if ( ! isset( $new['fpl_invoice'] ) ) {
			$new['fpl_invoice'] = __( 'Facture', 'facture-pdf-lumia' );
		}

		return $new;
	}

	/**
	 * Rend le contenu de la colonne.
	 *
	 * @param string     $column       Identifiant de colonne.
	 * @param int|\WP_Post $post_or_id Identifiant ou objet selon l'écran.
	 */
	public function render_column( $column, $post_or_id ): void {
		if ( 'fpl_invoice' !== $column ) {
			return;
		}

		$order_id = is_object( $post_or_id ) ? (int) $post_or_id->ID : (int) $post_or_id;
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			echo '&#8212;';

			return;
		}

		$document = DocumentRegistry::invoice( $order );

		if ( null === $document || ! $document->exists() ) {
			echo '<span class="fpl-column__empty" aria-hidden="true">&#8212;</span>';

			return;
		}

		printf(
			'<a href="%s" target="_blank" rel="noopener" class="fpl-column__link" title="%s">%s</a>',
			esc_url( DownloadHandler::url( $document ) ),
			esc_attr( $document->get_number() ),
			esc_html__( 'PDF', 'facture-pdf-lumia' )
		);
	}

	/**
	 * Normalise l'objet reçu en instance de commande.
	 *
	 * @param \WP_Post|WC_Order|mixed $post_or_order Objet fourni par WordPress.
	 */
	private function resolve_order( $post_or_order ): ?WC_Order {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}

		if ( is_object( $post_or_order ) && isset( $post_or_order->ID ) ) {
			$order = wc_get_order( (int) $post_or_order->ID );

			return $order instanceof WC_Order ? $order : null;
		}

		return null;
	}
}
