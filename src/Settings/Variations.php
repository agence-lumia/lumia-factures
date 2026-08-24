<?php
/**
 * Variations de design.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jeux de réglages prêts à l'emploi pour l'onglet Design.
 *
 * Une variation n'est pas un template : c'est un ensemble de valeurs qui
 * pré-remplit les réglages existants. L'utilisateur part d'une variation puis
 * ajuste ce qu'il veut — rien n'est verrouillé. C'est le compromis retenu au
 * cadrage : un seul template solide, décliné, plutôt que plusieurs templates
 * à maintenir en parallèle.
 */
final class Variations {

	/**
	 * Variations disponibles.
	 *
	 * @return array<string, array{label: string, description: string, values: array<string, mixed>}>
	 */
	public static function all(): array {
		$variations = array(
			'epure'     => array(
				'label'       => __( 'Épuré', 'facture-pdf-lumia' ),
				'description' => __( 'Noir et blanc, sans fond coloré. Le choix le plus sobre et le plus économe à l\'impression.', 'facture-pdf-lumia' ),
				'values'      => array(
					'color_primary'    => '#111111',
					'color_secondary'  => '#6b6b6b',
					'color_text'       => '#1a1a1a',
					'color_muted'      => '#6b6b6b',
					'color_thead_bg'   => '#111111',
					'color_thead_text' => '#ffffff',
					'color_row_alt'    => '#fafafa',
					'color_block_bg'   => '#f7f7f7',
					'color_border'     => '#dddddd',
					'font_heading'     => 'Inter',
					'font_body'        => 'Inter',
					'font_size'        => 9.5,
					'heading_scale'    => 1.6,
					'line_height'      => 1.45,
					'radius'           => 4,
					'border_width'     => 0.6,
					'density'          => 'normal',
				),
			),
			'classique' => array(
				'label'       => __( 'Classique', 'facture-pdf-lumia' ),
				'description' => __( 'Titres en serif, bleu nuit, allure institutionnelle. Adapté aux prestations de service et au B2B.', 'facture-pdf-lumia' ),
				'values'      => array(
					'color_primary'    => '#1a2b3c',
					'color_secondary'  => '#5a6b7c',
					'color_text'       => '#1f2933',
					'color_muted'      => '#66757f',
					'color_thead_bg'   => '#1a2b3c',
					'color_thead_text' => '#ffffff',
					'color_row_alt'    => '#f6f8fa',
					'color_block_bg'   => '#f2f5f8',
					'color_border'     => '#d4dce3',
					'font_heading'     => 'PlayfairDisplay',
					'font_body'        => 'SourceSans3',
					'font_size'        => 9.5,
					'heading_scale'    => 1.7,
					'line_height'      => 1.5,
					'radius'           => 2,
					'border_width'     => 0.6,
					'density'          => 'normal',
				),
			),
			'contraste' => array(
				'label'       => __( 'Contrasté', 'facture-pdf-lumia' ),
				'description' => __( 'Aplats marqués et titres larges. Lisible de loin, adapté aux factures consultées à l\'écran.', 'facture-pdf-lumia' ),
				'values'      => array(
					'color_primary'    => '#000000',
					'color_secondary'  => '#4a4a4a',
					'color_text'       => '#111111',
					'color_muted'      => '#5c5c5c',
					'color_thead_bg'   => '#000000',
					'color_thead_text' => '#ffffff',
					'color_row_alt'    => '#f0f0f0',
					'color_block_bg'   => '#ededed',
					'color_border'     => '#c4c4c4',
					'font_heading'     => 'IBMPlexSans',
					'font_body'        => 'IBMPlexSans',
					'font_size'        => 10,
					'heading_scale'    => 1.9,
					'line_height'      => 1.5,
					'radius'           => 0,
					'border_width'     => 1,
					'density'          => 'airy',
				),
			),
			'chaleureux' => array(
				'label'       => __( 'Chaleureux', 'facture-pdf-lumia' ),
				'description' => __( 'Terracotta et serif humaniste. Pensé pour l\'artisanat, la cosmétique et les marques de créateurs.', 'facture-pdf-lumia' ),
				'values'      => array(
					'color_primary'    => '#7a3b2e',
					'color_secondary'  => '#a46a55',
					'color_text'       => '#2b211d',
					'color_muted'      => '#7d6a62',
					'color_thead_bg'   => '#7a3b2e',
					'color_thead_text' => '#fdf8f5',
					'color_row_alt'    => '#faf4f0',
					'color_block_bg'   => '#f7efe9',
					'color_border'     => '#e2d3c9',
					'font_heading'     => 'Merriweather',
					'font_body'        => 'Lato',
					'font_size'        => 9.5,
					'heading_scale'    => 1.6,
					'line_height'      => 1.5,
					'radius'           => 8,
					'border_width'     => 0.6,
					'density'          => 'normal',
				),
			),
		);

		/**
		 * Filtre les variations de design proposées.
		 *
		 * @param array<string, array<string, mixed>> $variations Variations.
		 */
		return (array) apply_filters( 'fpl_design_variations', $variations );
	}

	/**
	 * Applique une variation aux réglages de design.
	 *
	 * Seules les clés déclarées par la variation sont écrasées : le template
	 * actif, les marges, la largeur des vignettes et surtout le CSS
	 * additionnel sont préservés. Une variation change l'apparence, elle
	 * n'efface pas le travail de personnalisation déjà fait.
	 *
	 * @param string $key Identifiant de variation.
	 */
	public static function apply( string $key ): bool {
		$variations = self::all();

		if ( ! isset( $variations[ $key ]['values'] ) ) {
			return false;
		}

		$settings = SettingsRepository::instance();
		$design   = (array) $settings->get( 'design', array() );
		$merged   = array_merge( $design, (array) $variations[ $key ]['values'] );

		$all           = $settings->all();
		$all['design'] = $merged;

		/**
		 * Déclenché après application d'une variation.
		 *
		 * @param string $key Identifiant de la variation appliquée.
		 */
		do_action( 'fpl_variation_applied', $key );

		return $settings->save( $all );
	}
}
