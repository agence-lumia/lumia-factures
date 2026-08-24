<?php
/**
 * Compilation des réglages de design en CSS.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Rendering;

use Lumia\FacturePDF\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Traduit les réglages de design en variables CSS.
 *
 * Le template ne contient aucune valeur en dur : couleurs, tailles, espaces
 * et rayons passent tous par une variable. Créer un nouveau template revient
 * donc à écrire une feuille de style consommant le même jeu de variables, et
 * modifier la charte d'un client ne demande jamais de toucher au code.
 *
 * Dompdf ne prend pas en charge `var()`. Les variables sont donc substituées
 * en PHP avant l'envoi au moteur : la feuille reste lisible et modifiable
 * comme du CSS moderne, mais Dompdf reçoit des valeurs littérales.
 */
final class StyleCompiler {

	/**
	 * Échelle de densité : multiplicateur appliqué aux espacements.
	 */
	private const DENSITIES = array(
		'compact' => 0.72,
		'normal'  => 1.0,
		'airy'    => 1.32,
	);

	/**
	 * Calcule le jeu de variables.
	 *
	 * @return array<string, string>
	 */
	public static function variables(): array {
		$settings = SettingsRepository::instance();

		$density_key = (string) $settings->get( 'design.density', 'normal' );
		$density     = self::DENSITIES[ $density_key ] ?? 1.0;

		$base_size = (float) $settings->get( 'design.font_size', 9.5 );
		$scale     = (float) $settings->get( 'design.heading_scale', 1.6 );
		$radius    = (int) $settings->get( 'design.radius', 4 );

		$variables = array(

			// Couleurs.
			'color-primary'       => (string) $settings->get( 'design.color_primary', '#111111' ),
			'color-secondary'     => (string) $settings->get( 'design.color_secondary', '#6b6b6b' ),
			'color-text'          => (string) $settings->get( 'design.color_text', '#1a1a1a' ),
			'color-muted'         => (string) $settings->get( 'design.color_muted', '#6b6b6b' ),
			'color-page'          => (string) $settings->get( 'design.color_page', '#ffffff' ),
			'color-border'        => (string) $settings->get( 'design.color_border', '#dddddd' ),
			'color-thead-bg'      => (string) $settings->get( 'design.color_thead_bg', '#111111' ),
			'color-thead-text'    => (string) $settings->get( 'design.color_thead_text', '#ffffff' ),
			'color-row-alt'       => (string) $settings->get( 'design.color_row_alt', '#fafafa' ),
			'color-block-bg'      => (string) $settings->get( 'design.color_block_bg', '#f7f7f7' ),

			// Typographie.
			'font-body'           => FontLibrary::stack( (string) $settings->get( 'design.font_body', 'Inter' ) ),
			'font-heading'        => FontLibrary::stack( (string) $settings->get( 'design.font_heading', 'Inter' ) ),
			'font-size'           => self::pt( $base_size ),
			'font-size-sm'        => self::pt( $base_size * 0.88 ),
			'font-size-xs'        => self::pt( $base_size * 0.78 ),
			'font-size-lg'        => self::pt( $base_size * 1.15 ),
			'font-size-title'     => self::pt( $base_size * $scale ),
			'font-size-h3'        => self::pt( $base_size * 1.05 ),
			'line-height'         => (string) round( (float) $settings->get( 'design.line_height', 1.45 ), 2 ),
			'heading-weight'      => (string) ( $settings->is_enabled( 'design.heading_bold', true ) ? 'bold' : 'normal' ),

			// Formes.
			'radius'              => self::px( $radius ),
			'radius-sm'           => self::px( max( 0, (int) round( $radius * 0.6 ) ) ),
			'border-width'        => self::pt( (float) $settings->get( 'design.border_width', 0.6 ) ),
			'border-width-strong' => self::pt( (float) $settings->get( 'design.border_width', 0.6 ) * 1.6 ),

			// Espacements, pilotés par la densité.
			'space-xs'            => self::pt( 2 * $density ),
			'space-sm'            => self::pt( 4 * $density ),
			'space-md'            => self::pt( 8 * $density ),
			'space-lg'            => self::pt( 14 * $density ),
			'space-xl'            => self::pt( 22 * $density ),
			'cell-padding-y'      => self::pt( 6 * $density ),
			'cell-padding-x'      => self::pt( 7 * $density ),

			// Mise en page.
			'logo-width'          => self::px( (int) $settings->get( 'company.logo_width', 180 ) ),
			'thumb-width'         => self::px( (int) $settings->get( 'design.thumb_width', 46 ) ),
			'margin-top'          => self::mm( (float) $settings->get( 'design.margin_top', 18 ) ),
			'margin-bottom'       => self::mm( (float) $settings->get( 'design.margin_bottom', 26 ) ),
			'margin-sides'        => self::mm( (float) $settings->get( 'design.margin_sides', 16 ) ),
		);

		/**
		 * Filtre les variables CSS calculées.
		 *
		 * @param array<string, string> $variables Variables, sans le préfixe `--fpl-`.
		 */
		return (array) apply_filters( 'fpl_css_variables', $variables );
	}

	/**
	 * Feuille de style finale, prête pour Dompdf.
	 *
	 * @param string $template_css CSS brut du template, avec `var(--fpl-*)`.
	 * @param string $custom_css   CSS additionnel saisi par l'utilisateur.
	 */
	public static function compile( string $template_css, string $custom_css = '' ): string {
		$variables = self::variables();

		// Le CSS personnalisé est concaténé en dernier : à spécificité égale,
		// il l'emporte toujours sur le template.
		$css = $template_css . "\n" . $custom_css;
		$css = self::resolve( $css, $variables );

		/**
		 * Filtre la feuille de style finale.
		 *
		 * @param string                $css       CSS résolu.
		 * @param array<string, string> $variables Variables utilisées.
		 */
		return (string) apply_filters( 'fpl_final_css', $css, $variables );
	}

	/**
	 * Remplace les appels `var(--fpl-*)` par leur valeur.
	 *
	 * Gère la valeur de repli : `var(--fpl-inconnue, 10pt)` donne `10pt`.
	 *
	 * @param string                $css       CSS source.
	 * @param array<string, string> $variables Variables disponibles.
	 */
	private static function resolve( string $css, array $variables ): string {
		$resolved = preg_replace_callback(
			'/var\(\s*--fpl-([a-z0-9-]+)\s*(?:,\s*([^()]*?)\s*)?\)/i',
			static function ( array $matches ) use ( $variables ): string {
				$name = $matches[1];

				if ( isset( $variables[ $name ] ) ) {
					return $variables[ $name ];
				}

				return $matches[2] ?? 'inherit';
			},
			$css
		);

		// Le bloc `:root` ne sert qu'à documenter les valeurs par défaut dans
		// la feuille source. Dompdf ne l'interprète pas, autant l'enlever.
		$resolved = (string) preg_replace( '/:root\s*\{[^}]*\}/s', '', (string) $resolved );

		return trim( $resolved );
	}

	/**
	 * Formate une valeur en points.
	 *
	 * @param float $value Valeur.
	 */
	private static function pt( float $value ): string {
		return round( $value, 2 ) . 'pt';
	}

	/**
	 * Formate une valeur en pixels.
	 *
	 * @param int $value Valeur.
	 */
	private static function px( int $value ): string {
		return $value . 'px';
	}

	/**
	 * Formate une valeur en millimètres.
	 *
	 * @param float $value Valeur.
	 */
	private static function mm( float $value ): string {
		return round( $value, 1 ) . 'mm';
	}
}
