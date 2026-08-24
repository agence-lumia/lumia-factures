<?php
/**
 * Bibliothèque de polices embarquées.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Rendering;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalogue des polices livrées avec le plugin.
 *
 * Dompdf ne sait pas charger une police depuis une URL distante : Google
 * Fonts et consorts sont hors-jeu. Chaque famille proposée doit donc être
 * présente sur le disque, en quatre variantes, avec un jeu de caractères
 * latin étendu — sans quoi les accents français sortent en carrés.
 *
 * Toutes les familles retenues sont sous licence SIL OFL ou Apache 2.0, donc
 * redistribuables dans un plugin commercial.
 */
final class FontLibrary {

	/**
	 * Variantes attendues pour chaque famille.
	 */
	private const VARIANTS = array( 'Regular', 'Italic', 'Bold', 'BoldItalic' );

	/**
	 * Familles disponibles.
	 *
	 * @return array<string, array{label: string, dir: string, stack: string, category: string}>
	 */
	public static function families(): array {
		$families = array(
			'Inter'           => array(
				'label'    => 'Inter',
				'dir'      => 'Inter',
				'stack'    => "'Inter', sans-serif",
				'category' => 'sans',
			),
			'SourceSans3'     => array(
				'label'    => 'Source Sans 3',
				'dir'      => 'SourceSans3',
				'stack'    => "'SourceSans3', sans-serif",
				'category' => 'sans',
			),
			'Lato'            => array(
				'label'    => 'Lato',
				'dir'      => 'Lato',
				'stack'    => "'Lato', sans-serif",
				'category' => 'sans',
			),
			'OpenSans'        => array(
				'label'    => 'Open Sans',
				'dir'      => 'OpenSans',
				'stack'    => "'OpenSans', sans-serif",
				'category' => 'sans',
			),
			'Roboto'          => array(
				'label'    => 'Roboto',
				'dir'      => 'Roboto',
				'stack'    => "'Roboto', sans-serif",
				'category' => 'sans',
			),
			'IBMPlexSans'     => array(
				'label'    => 'IBM Plex Sans',
				'dir'      => 'IBMPlexSans',
				'stack'    => "'IBMPlexSans', sans-serif",
				'category' => 'sans',
			),
			'PlayfairDisplay' => array(
				'label'    => 'Playfair Display',
				'dir'      => 'PlayfairDisplay',
				'stack'    => "'PlayfairDisplay', serif",
				'category' => 'serif',
			),
			'Merriweather'    => array(
				'label'    => 'Merriweather',
				'dir'      => 'Merriweather',
				'stack'    => "'Merriweather', serif",
				'category' => 'serif',
			),
		);

		/**
		 * Filtre les familles de polices disponibles.
		 *
		 * Une famille ajoutée par ce filtre doit fournir les quatre variantes
		 * (Regular, Italic, Bold, BoldItalic) au format TTF.
		 *
		 * @param array<string, array<string, string>> $families Familles.
		 */
		return (array) apply_filters( 'fpl_font_families', $families );
	}

	/**
	 * Options pour les listes déroulantes de réglages.
	 *
	 * @return array<string, string>
	 */
	public static function options(): array {
		$options = array();

		foreach ( self::families() as $key => $family ) {
			$options[ $key ] = $family['label'];
		}

		return $options;
	}

	/**
	 * Pile CSS d'une famille.
	 *
	 * @param string $key Identifiant de famille.
	 */
	public static function stack( string $key ): string {
		$families = self::families();

		if ( isset( $families[ $key ]['stack'] ) ) {
			return $families[ $key ]['stack'];
		}

		// Une police retirée du catalogue ne doit pas casser un document déjà
		// configuré : on retombe sur la police par défaut.
		return "'Inter', sans-serif";
	}

	/**
	 * Chemin absolu du dossier des polices.
	 */
	public static function directory(): string {
		/**
		 * Filtre le dossier des polices embarquées.
		 *
		 * @param string $directory Chemin absolu.
		 */
		return (string) apply_filters( 'fpl_fonts_directory', FPL_PLUGIN_DIR . 'assets/fonts' );
	}

	/**
	 * Déclarations `@font-face` des familles utilisées.
	 *
	 * On ne déclare que les familles réellement employées par le document :
	 * Dompdf analyse et met en cache chaque fichier déclaré, y compris ceux
	 * qui ne servent pas.
	 *
	 * @param array<int, string> $keys Identifiants de familles.
	 */
	public static function font_face_css( array $keys ): string {
		$families = self::families();
		$css      = '';

		foreach ( array_unique( array_filter( $keys ) ) as $key ) {
			if ( ! isset( $families[ $key ] ) ) {
				continue;
			}

			$dir = trailingslashit( self::directory() ) . $families[ $key ]['dir'];

			foreach ( self::VARIANTS as $variant ) {
				$file = $dir . '/' . $families[ $key ]['dir'] . '-' . $variant . '.ttf';

				if ( ! is_readable( $file ) ) {
					continue;
				}

				$css .= sprintf(
					"@font-face { font-family: '%s'; font-style: %s; font-weight: %s; src: url('%s') format('truetype'); }\n",
					$key,
					str_contains( $variant, 'Italic' ) ? 'italic' : 'normal',
					str_contains( $variant, 'Bold' ) ? 'bold' : 'normal',
					'file://' . $file
				);
			}
		}

		return $css;
	}

	/**
	 * Contrôle l'intégrité du dossier des polices.
	 *
	 * @return array<string, array<int, string>> Familles incomplètes et variantes manquantes.
	 */
	public static function missing_files(): array {
		$missing = array();

		foreach ( self::families() as $key => $family ) {
			$dir = trailingslashit( self::directory() ) . $family['dir'];

			foreach ( self::VARIANTS as $variant ) {
				$file = $dir . '/' . $family['dir'] . '-' . $variant . '.ttf';

				if ( ! is_readable( $file ) ) {
					$missing[ $key ][] = $variant;
				}
			}
		}

		return $missing;
	}
}
