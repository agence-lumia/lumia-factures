<?php
/**
 * Génération du PDF via Dompdf.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Rendering;

use Dompdf\Dompdf;
use Dompdf\Options;
use Lumia\FacturePDF\Settings\SettingsRepository;
use Lumia\FacturePDF\Support\Logger;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enveloppe Dompdf pour le reste du plugin.
 *
 * Aucune autre classe du plugin ne doit référencer directement l'espace de
 * noms `Dompdf\` : si la version 3 est un jour remplacée par un autre moteur
 * (mPDF, un service externe), seule cette classe change.
 */
final class PdfEngine {

	/**
	 * Rend un document HTML en PDF.
	 *
	 * @param string $html Document HTML complet (voir Renderer::render()).
	 *
	 * @return string Contenu binaire du PDF, chaîne vide en cas d'échec.
	 */
	public static function render( string $html ): string {
		if ( ! class_exists( Dompdf::class ) ) {
			Logger::error( 'Dompdf est introuvable : exécuter `composer install` à la racine du plugin.' );

			return '';
		}

		if ( '' === trim( $html ) ) {
			return '';
		}

		self::ensure_memory();

		try {
			$dompdf = new Dompdf( self::options() );

			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->setPaper( 'a4', 'portrait' );

			/**
			 * Dernier point d'accroche avant le rendu, pour un réglage Dompdf
			 * que ce plugin n'exposerait pas (police par défaut, watermark…).
			 *
			 * @param Dompdf $dompdf Instance configurée.
			 */
			do_action( 'fpl_before_pdf_render', $dompdf );

			$dompdf->render();

			self::draw_pagination( $dompdf );

			return (string) $dompdf->output();
		} catch ( Throwable $e ) {
			Logger::error(
				'Échec de la génération PDF.',
				array(
					'message' => $e->getMessage(),
					'file'    => $e->getFile() . ':' . $e->getLine(),
				)
			);

			return '';
		}
	}

	/**
	 * Dessine la pagination sur chaque page, après le rendu.
	 *
	 * Passe par l'API canvas plutôt que par du CSS pour une raison précise :
	 * Dompdf n'implémente pas les boîtes de marge `@page` de la spécification
	 * CSS Paged Media, et dans un élément positionné il résout bien
	 * `counter(page)` mais renvoie 0 pour `counter(pages)` — le nombre total
	 * de pages n'étant pas connu au moment de la mise en page. `page_text()`
	 * est appelé une fois le document paginé, ce qui est le seul moment où
	 * les deux valeurs sont disponibles.
	 *
	 * @param Dompdf $dompdf Instance rendue.
	 */
	private static function draw_pagination( Dompdf $dompdf ): void {
		$settings = SettingsRepository::instance();

		if ( ! $settings->is_enabled( 'content.footer_pagination_enabled', true ) ) {
			return;
		}

		$canvas = $dompdf->getCanvas();

		if ( ! is_callable( array( $canvas, 'page_text' ) ) ) {
			return;
		}

		$margin_bottom = (float) $settings->get( 'design.margin_bottom', 26 );
		$font_size     = max( 6.0, (float) $settings->get( 'design.font_size', 9.5 ) - 2.5 );

		$text = sprintf(
			/* translators: 1: numéro de page, 2: nombre total de pages */
			__( 'Page %1$s / %2$s', 'facture-pdf-lumia' ),
			'{PAGE_NUM}',
			'{PAGE_COUNT}'
		);

		$font = $dompdf->getFontMetrics()->getFont( 'DejaVu Sans', 'normal' );

		// `page_text()` n'accepte pas d'alignement : la position horizontale
		// doit être calculée. La largeur est mesurée sur un texte de
		// substitution représentatif, les marqueurs {PAGE_NUM} étant
		// remplacés après coup par des valeurs bien plus courtes.
		$sample = str_replace( array( '{PAGE_NUM}', '{PAGE_COUNT}' ), array( '00', '00' ), $text );
		$width  = (float) $dompdf->getFontMetrics()->getTextWidth( $sample, $font, $font_size );
		$x      = max( 0.0, ( $canvas->get_width() - $width ) / 2 );

		// Sous le bloc de pied de page, dans la marge basse. La constante
		// correspond à la hauteur réservée au pied de page lui-même : la
		// pagination est le dernier élément de la page.
		$y = $canvas->get_height() - 14.0;

		$canvas->page_text( $x, $y, $text, $font, $font_size, array( 0.45, 0.45, 0.45 ) );
	}

	/**
	 * Options Dompdf.
	 */
	private static function options(): Options {
		$options = new Options();

		// Les images produits sont déjà encodées en base64 par ImageEncoder
		// avant d'arriver ici : aucun besoin d'autoriser Dompdf à aller
		// chercher des ressources sur le réseau, ce qui élimine à la fois un
		// risque SSRF et une source de lenteur.
		$options->setIsRemoteEnabled( false );
		$options->setIsHtml5ParserEnabled( true );
		$options->setDefaultFont( 'DejaVu Sans' );
		$options->setFontDir( self::font_cache_dir() );
		$options->setFontCache( self::font_cache_dir() );
		$options->setTempDir( get_temp_dir() );
		$options->setChroot(
			array(
				FPL_PLUGIN_DIR,
				trailingslashit( wp_upload_dir( null, false )['basedir'] ?? '' ),
			)
		);

		/**
		 * Filtre les options Dompdf avant instanciation.
		 *
		 * @param Options $options Options par défaut.
		 */
		$filtered = apply_filters( 'fpl_dompdf_options', $options );

		return $filtered instanceof Options ? $filtered : $options;
	}

	/**
	 * Dossier de cache des polices, propre au plugin.
	 *
	 * Séparé du cache par défaut de Dompdf (souvent dans `vendor/`, en
	 * lecture seule après déploiement) pour que l'enregistrement des
	 * polices embarquées fonctionne même sur un hébergement qui ne permet
	 * pas l'écriture dans le dossier du plugin.
	 */
	private static function font_cache_dir(): string {
		$dir = trailingslashit( get_temp_dir() ) . 'fpl-fonts';

		wp_mkdir_p( $dir );

		return $dir;
	}

	/**
	 * Relève la limite mémoire si l'hébergement le permet.
	 *
	 * Dompdf est gourmand, en particulier sur des factures à nombreuses
	 * lignes avec vignettes. On tente une hausse plutôt que de laisser une
	 * erreur fatale interrompre la génération au milieu du rendu.
	 */
	private static function ensure_memory(): void {
		$current = self::to_bytes( (string) ini_get( 'memory_limit' ) );

		if ( $current > 0 && $current < 256 * MB_IN_BYTES ) {
			// phpcs:ignore WordPress.PHP.IniSet.Risky
			@ini_set( 'memory_limit', '256M' );
		}
	}

	/**
	 * Convertit une valeur `memory_limit` en octets.
	 *
	 * @param string $value Valeur brute (ex. `128M`, `-1`).
	 */
	private static function to_bytes( string $value ): int {
		if ( '' === $value || '-1' === $value ) {
			return -1;
		}

		return (int) wp_convert_hr_to_bytes( $value );
	}
}
