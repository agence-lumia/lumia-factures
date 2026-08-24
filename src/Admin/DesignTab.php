<?php
/**
 * Compléments de l'onglet Design.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Admin;

use Lumia\FacturePDF\Settings\SettingsRepository;
use Lumia\FacturePDF\Settings\Variations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sélecteur de variations de design.
 */
final class DesignTab {

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_fpl_apply_variation', array( $this, 'handle_apply' ) );
	}

	/**
	 * Affiche les variations disponibles.
	 *
	 * Chaque variation est présentée avec un aperçu de sa palette : c'est
	 * l'information réellement utile pour choisir, bien plus qu'un nom.
	 */
	public static function render_variations(): void {
		$variations = Variations::all();

		if ( array() === $variations ) {
			return;
		}

		printf(
			'<h3 class="fpl-subhead">%s</h3><p class="fpl-panel__desc">%s</p>',
			esc_html__( 'Variations', 'facture-pdf-lumia' ),
			esc_html__( 'Applique un jeu de couleurs et de typographies en un clic. Les réglages restent modifiables ensuite, et votre CSS additionnel n\'est jamais écrasé.', 'facture-pdf-lumia' )
		);

		echo '<div class="fpl-variations">';

		foreach ( $variations as $key => $variation ) {
			$values = (array) ( $variation['values'] ?? array() );

			echo '<div class="fpl-variation">';

			echo '<div class="fpl-variation__swatches">';

			foreach ( array( 'color_primary', 'color_thead_bg', 'color_block_bg', 'color_border' ) as $swatch ) {
				if ( empty( $values[ $swatch ] ) ) {
					continue;
				}

				printf(
					'<span class="fpl-variation__swatch" style="background:%s"></span>',
					esc_attr( (string) $values[ $swatch ] )
				);
			}

			echo '</div>';

			printf( '<strong class="fpl-variation__label">%s</strong>', esc_html( (string) ( $variation['label'] ?? $key ) ) );

			if ( ! empty( $variation['description'] ) ) {
				printf( '<p class="fpl-variation__desc">%s</p>', esc_html( (string) $variation['description'] ) );
			}

			printf(
				'<form method="post" action="%s" onsubmit="return confirm(\'%s\');">',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_js( __( 'Les couleurs et polices actuelles seront remplacées. Continuer ?', 'facture-pdf-lumia' ) )
			);

			wp_nonce_field( 'fpl_apply_variation', 'fpl_variation_nonce' );

			echo '<input type="hidden" name="action" value="fpl_apply_variation">';

			printf( '<input type="hidden" name="variation" value="%s">', esc_attr( (string) $key ) );

			printf(
				'<button type="submit" class="button button-secondary">%s</button>',
				esc_html__( 'Appliquer', 'facture-pdf-lumia' )
			);

			echo '</form>';

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Applique la variation demandée.
	 */
	public function handle_apply(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'facture-pdf-lumia' ) );
		}

		check_admin_referer( 'fpl_apply_variation', 'fpl_variation_nonce' );

		$key = isset( $_POST['variation'] ) ? sanitize_key( wp_unslash( $_POST['variation'] ) ) : '';

		if ( '' === $key || ! Variations::apply( $key ) ) {
			Notices::add_error( __( 'Cette variation est introuvable.', 'facture-pdf-lumia' ) );
		} else {
			$variations = Variations::all();

			Notices::add_success(
				sprintf(
					/* translators: %s: nom de la variation */
					__( 'Variation « %s » appliquée.', 'facture-pdf-lumia' ),
					(string) ( $variations[ $key ]['label'] ?? $key )
				)
			);
		}

		// Le dépôt garde les réglages en mémoire pour la durée de la requête :
		// on le vide pour que la page rechargée lise bien les nouvelles valeurs.
		SettingsRepository::instance()->flush();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => SettingsPage::SLUG,
					'tab'  => 'design',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}
}
