<?php
/**
 * Chargement des ressources d'administration.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Charge CSS et JS, uniquement sur les écrans du plugin.
 */
final class Assets {

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Met en file les ressources.
	 *
	 * @param string $hook_suffix Écran courant.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'woocommerce_page_' . SettingsPage::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'fpl-admin',
			FPL_PLUGIN_URL . 'assets/admin/admin.css',
			array( 'wp-color-picker' ),
			FPL_VERSION
		);

		wp_enqueue_media();

		wp_enqueue_script(
			'fpl-alpine',
			FPL_PLUGIN_URL . 'assets/admin/alpine.min.js',
			array(),
			'3.14.1',
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_enqueue_script(
			'fpl-admin',
			FPL_PLUGIN_URL . 'assets/admin/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			FPL_VERSION,
			true
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

		wp_localize_script(
			'fpl-admin',
			'fplAdmin',
			array(
				// Consommé par l'aperçu en direct pour cibler le bon onglet.
				// L'état des champs, lui, est désormais rendu directement
				// dans l'attribut `x-data` du formulaire (voir SettingsPage) :
				// le poser ici puis le transférer en JS après coup créait une
				// course avec le démarrage automatique d'Alpine.
				'tab'     => $tab,
				'preview' => array(
					'url'    => admin_url( 'admin-ajax.php' ),
					'action' => PreviewController::AJAX_ACTION,
					'nonce'  => wp_create_nonce( 'fpl_preview' ),
				),
				'i18n'    => array(
					'selectImage' => __( 'Sélectionner une image', 'facture-pdf-lumia' ),
					'useImage'    => __( 'Utiliser cette image', 'facture-pdf-lumia' ),
				),
			)
		);
	}
}
