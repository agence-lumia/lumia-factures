<?php
/**
 * Résolution des fichiers de template.
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
 * Localise les fichiers de template selon une cascade de surcharge.
 *
 * Ordre de résolution : thème enfant, thème parent, puis plugin. La cascade
 * s'applique fichier par fichier, ce qui permet de surcharger uniquement
 * `partials/totals.php` sans recopier tout le template — et donc de continuer
 * à bénéficier des corrections apportées aux autres fichiers lors des mises
 * à jour.
 */
final class TemplateLoader {

	/**
	 * Nom du dossier de surcharge dans le thème.
	 */
	public const THEME_DIR = 'facture-pdf-lumia';

	/**
	 * Template actif.
	 */
	public static function active_template(): string {
		$template = (string) SettingsRepository::instance()->get( 'design.template', 'default' );

		if ( '' === $template || ! self::template_exists( $template ) ) {
			return 'default';
		}

		return $template;
	}

	/**
	 * Un template porte-t-il bien ce nom quelque part dans la cascade ?
	 *
	 * @param string $template Nom du template.
	 */
	public static function template_exists( string $template ): bool {
		foreach ( self::roots() as $root ) {
			if ( is_readable( trailingslashit( $root ) . $template . '/invoice.php' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Chemin d'un fichier de template.
	 *
	 * @param string $file     Chemin relatif (ex. `invoice.php`, `partials/header.php`).
	 * @param string $template Template ciblé, actif par défaut.
	 *
	 * @return string Chemin absolu, ou chaîne vide si introuvable.
	 */
	public static function locate( string $file, string $template = '' ): string {
		$template = '' !== $template ? $template : self::active_template();
		$file     = ltrim( $file, '/' );
		$found    = '';

		foreach ( self::roots() as $root ) {
			$path = trailingslashit( $root ) . $template . '/' . $file;

			if ( is_readable( $path ) ) {
				$found = $path;

				break;
			}
		}

		// Un partial ajouté par une version récente peut manquer d'un template
		// personnalisé copié avant : on retombe sur celui du plugin plutôt que
		// de laisser un trou dans le document.
		if ( '' === $found && 'default' !== $template ) {
			$fallback = FPL_PLUGIN_DIR . 'templates/default/' . $file;

			if ( is_readable( $fallback ) ) {
				$found = $fallback;
			}
		}

		/**
		 * Filtre le chemin résolu d'un fichier de template.
		 *
		 * @param string $found    Chemin absolu, chaîne vide si introuvable.
		 * @param string $file     Chemin relatif demandé.
		 * @param string $template Template ciblé.
		 */
		return (string) apply_filters( 'fpl_template_path', $found, $file, $template );
	}

	/**
	 * Charge un fichier de template et retourne son rendu.
	 *
	 * @param string               $file    Chemin relatif.
	 * @param array<string, mixed> $context Données exposées au template.
	 */
	public static function render( string $file, array $context = array() ): string {
		$path = self::locate( $file );

		if ( '' === $path ) {
			return '';
		}

		// `extract` est volontaire ici : c'est ce qui permet aux templates
		// d'utiliser `$items` plutôt que `$context['items']`, et de rester
		// lisibles pour quelqu'un qui n'écrit pas de PHP au quotidien.
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $context, EXTR_SKIP );

		ob_start();

		include $path;

		return (string) ob_get_clean();
	}

	/**
	 * Contenu brut d'un fichier, sans exécution PHP.
	 *
	 * @param string $file Chemin relatif (typiquement `style.css`).
	 */
	public static function contents( string $file ): string {
		$path = self::locate( $file );

		if ( '' === $path ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return (string) file_get_contents( $path );
	}

	/**
	 * Templates disponibles, tous emplacements confondus.
	 *
	 * @return array<string, string> Identifiant => libellé.
	 */
	public static function available(): array {
		$templates = array();

		foreach ( array_reverse( self::roots() ) as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}

			$entries = glob( trailingslashit( $root ) . '*', GLOB_ONLYDIR );

			foreach ( (array) $entries as $entry ) {
				if ( ! is_readable( $entry . '/invoice.php' ) ) {
					continue;
				}

				$slug = basename( $entry );

				$templates[ $slug ] = self::label( $slug );
			}
		}

		/**
		 * Filtre la liste des templates disponibles.
		 *
		 * @param array<string, string> $templates Templates.
		 */
		return (array) apply_filters( 'fpl_available_templates', $templates );
	}

	/**
	 * Libellé lisible d'un template.
	 *
	 * @param string $slug Identifiant.
	 */
	private static function label( string $slug ): string {
		if ( 'default' === $slug ) {
			return __( 'Lumia (par défaut)', 'facture-pdf-lumia' );
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/**
	 * Racines de recherche, du plus prioritaire au moins prioritaire.
	 *
	 * @return array<int, string>
	 */
	private static function roots(): array {
		$roots = array();

		$child  = get_stylesheet_directory();
		$parent = get_template_directory();

		$roots[] = trailingslashit( $child ) . self::THEME_DIR;

		if ( $parent !== $child ) {
			$roots[] = trailingslashit( $parent ) . self::THEME_DIR;
		}

		$roots[] = FPL_PLUGIN_DIR . 'templates';

		/**
		 * Filtre les racines de recherche des templates.
		 *
		 * @param array<int, string> $roots Chemins absolus, par priorité décroissante.
		 */
		return (array) apply_filters( 'fpl_template_roots', $roots );
	}
}
