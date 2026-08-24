<?php
/**
 * Export et import des réglages au format JSON.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Settings;

use Lumia\FacturePDF\Rendering\ImageEncoder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sérialise et restaure la configuration du plugin.
 *
 * C'est le mécanisme central de la réutilisabilité multi-sites : configurer
 * une charte une fois, l'exporter, puis l'importer sur chaque nouveau site
 * client plutôt que de tout ressaisir. L'export peut exclure les données
 * propres au site (logo, SIRET, adresse…) pour ne transférer que la partie
 * réutilisable — design, structure de contenu, mentions légales.
 */
final class PortManager {

	/**
	 * Version du format d'export.
	 *
	 * Indépendante de la version du plugin : seule une évolution de la
	 * *structure* de l'export doit l'incrémenter, pas chaque nouvelle
	 * fonctionnalité.
	 */
	private const FORMAT_VERSION = 1;

	/**
	 * Onglets exclus par défaut d'un export « portable ».
	 *
	 * `company` contient les données propres au site (logo, SIRET, adresse) ;
	 * `numbering` contient un compteur en cours dont la valeur n'a de sens
	 * que pour la boutique d'origine.
	 *
	 * @var array<int, string>
	 */
	private const SITE_SPECIFIC_TABS = array( 'company', 'numbering' );

	/**
	 * Construit le contenu exportable.
	 *
	 * @param bool $include_site_specific Inclure les données propres au site.
	 *
	 * @return array<string, mixed>
	 */
	public static function export( bool $include_site_specific = true ): array {
		$settings = SettingsRepository::instance()->all();

		if ( ! $include_site_specific ) {
			foreach ( self::SITE_SPECIFIC_TABS as $tab ) {
				unset( $settings[ $tab ] );
			}
		}

		// Un compteur en cours n'a de sens que sur son site d'origine :
		// l'exporter laisserait croire à l'import qu'il faut reprendre la
		// numérotation à cette valeur précise sur un tout autre site.
		unset( $settings['numbering']['start_number'] );

		$payload = array(
			'plugin'          => 'facture-pdf-lumia',
			'format_version'  => self::FORMAT_VERSION,
			'plugin_version'  => defined( 'FPL_VERSION' ) ? FPL_VERSION : '',
			'exported_at'     => gmdate( 'c' ),
			'site_specific'   => $include_site_specific,
			'settings'        => $settings,
		);

		// Un identifiant de média n'a aucun sens d'un site à l'autre : sans ce
		// transfert, `company.logo_id` importé pointerait vers une image
		// inexistante — ou pire, vers une image différente qui porte le même
		// identifiant par coïncidence sur le site de destination.
		if ( $include_site_specific ) {
			$logo = self::logo_payload();

			if ( null !== $logo ) {
				$payload['logo'] = $logo;
			}
		}

		/**
		 * Filtre le contenu exporté avant sérialisation.
		 *
		 * @param array<string, mixed> $payload               Export complet.
		 * @param bool                 $include_site_specific Données de site incluses.
		 */
		return (array) apply_filters( 'fpl_export_payload', $payload, $include_site_specific );
	}

	/**
	 * Sérialise l'export en JSON lisible.
	 *
	 * @param bool $include_site_specific Inclure les données propres au site.
	 */
	public static function export_json( bool $include_site_specific = true ): string {
		$json = wp_json_encode( self::export( $include_site_specific ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false !== $json ? $json : '{}';
	}

	/**
	 * Nom de fichier suggéré pour le téléchargement.
	 */
	public static function export_filename(): string {
		return sprintf( 'facture-pdf-lumia-%s.json', gmdate( 'Y-m-d' ) );
	}

	/**
	 * Analyse un JSON importé sans rien appliquer.
	 *
	 * Étape séparée de `apply()` à dessein : l'admin affiche un résumé des
	 * changements et demande confirmation avant d'écraser quoi que ce soit.
	 *
	 * @param string $json Contenu JSON brut.
	 *
	 * @return array{valid: bool, error: string, payload: array<string, mixed>, tabs: array<int, string>}
	 */
	public static function parse( string $json ): array {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return array(
				'valid'   => false,
				'error'   => __( 'Le fichier n\'est pas un JSON valide.', 'facture-pdf-lumia' ),
				'payload' => array(),
				'tabs'    => array(),
			);
		}

		if ( ( $data['plugin'] ?? '' ) !== 'facture-pdf-lumia' ) {
			return array(
				'valid'   => false,
				'error'   => __( 'Ce fichier ne provient pas de Facture PDF Lumia.', 'facture-pdf-lumia' ),
				'payload' => array(),
				'tabs'    => array(),
			);
		}

		$format_version = (int) ( $data['format_version'] ?? 0 );

		if ( $format_version > self::FORMAT_VERSION ) {
			return array(
				'valid'   => false,
				'error'   => __( 'Ce fichier a été exporté par une version plus récente du plugin. Mettez le plugin à jour avant de l\'importer.', 'facture-pdf-lumia' ),
				'payload' => array(),
				'tabs'    => array(),
			);
		}

		if ( ! is_array( $data['settings'] ?? null ) ) {
			return array(
				'valid'   => false,
				'error'   => __( 'Le fichier ne contient aucun réglage exploitable.', 'facture-pdf-lumia' ),
				'payload' => array(),
				'tabs'    => array(),
			);
		}

		return array(
			'valid'   => true,
			'error'   => '',
			'payload' => $data,
			'tabs'    => array_keys( $data['settings'] ),
		);
	}

	/**
	 * Applique un import déjà validé par `parse()`.
	 *
	 * Chaque onglet est nettoyé par le même chemin que la sauvegarde d'un
	 * formulaire (`SettingsRepository::sanitize_tab()`) : un fichier importé
	 * n'a pas plus de raison d'échapper au nettoyage qu'une saisie manuelle,
	 * qu'il vienne d'un autre site de confiance ou d'ailleurs.
	 *
	 * @param array<string, mixed> $payload                Résultat de `parse()['payload']`.
	 * @param bool                 $include_site_specific   Appliquer aussi les onglets propres au site.
	 *
	 * @return array<int, string> Onglets effectivement appliqués.
	 */
	public static function apply( array $payload, bool $include_site_specific = false ): array {
		$incoming    = (array) ( $payload['settings'] ?? array() );
		$repository  = SettingsRepository::instance();
		$current     = $repository->all();
		$applied     = array();

		foreach ( $incoming as $tab => $tab_settings ) {
			if ( ! is_array( $tab_settings ) ) {
				continue;
			}

			if ( ! $include_site_specific && in_array( $tab, self::SITE_SPECIFIC_TABS, true ) ) {
				continue;
			}

			$clean = $repository->sanitize_tab( (string) $tab, $tab_settings );

			$current[ $tab ] = array_merge( $current[ $tab ] ?? array(), $clean );

			$applied[] = (string) $tab;
		}

		if ( array() === $applied ) {
			return array();
		}

		// Le logo importé est recréé comme un nouvel attachement local : le
		// `logo_id` brut venu du fichier JSON, issu du nettoyage générique de
		// `sanitize_tab()`, est un identifiant numérique qui ne correspond à
		// rien de connu sur ce site. On le remplace par l'identifiant réel de
		// l'attachement qu'on vient de créer, ou on le remet à zéro s'il n'y
		// a pas de logo à transférer.
		if ( $include_site_specific && in_array( 'company', $applied, true ) ) {
			$new_logo_id = isset( $payload['logo'] ) ? self::import_logo( (array) $payload['logo'] ) : 0;

			$current['company']['logo_id'] = $new_logo_id;
		}

		$repository->save( $current );

		/**
		 * Déclenché après application réussie d'un import.
		 *
		 * @param array<int, string> $applied Onglets appliqués.
		 */
		do_action( 'fpl_settings_imported', $applied );

		return $applied;
	}

	/**
	 * Encode le logo courant pour l'export.
	 *
	 * Passe par `ImageEncoder::for_attachment()` plutôt que par une lecture
	 * de fichier directe : le repli vers une récupération distante (lot 4)
	 * s'applique donc ici aussi si le fichier local est introuvable.
	 *
	 * @return array{mime: string, data: string}|null
	 */
	private static function logo_payload(): ?array {
		$attachment_id = (int) SettingsRepository::instance()->get( 'company.logo_id', 0 );

		if ( $attachment_id <= 0 ) {
			return null;
		}

		$data_uri = ImageEncoder::for_attachment( $attachment_id, 0, 'logo (export)' );

		if ( '' === $data_uri || ! preg_match( '#^data:([a-z0-9/+.\-]+);base64,(.+)$#i', $data_uri, $matches ) ) {
			return null;
		}

		return array(
			'mime' => $matches[1],
			'data' => $matches[2],
		);
	}

	/**
	 * Recrée le logo importé comme un attachement local.
	 *
	 * @param array<string, mixed> $logo Bloc `logo` du fichier importé.
	 *
	 * @return int Identifiant du nouvel attachement, 0 en cas d'échec ou d'absence.
	 */
	private static function import_logo( array $logo ): int {
		if ( ! isset( $logo['data'], $logo['mime'] ) || ! is_string( $logo['data'] ) ) {
			return 0;
		}

		$bytes = base64_decode( $logo['data'], true );

		if ( false === $bytes || '' === $bytes ) {
			return 0;
		}

		$mime      = (string) $logo['mime'];
		$extension = self::extension_for_mime( $mime );

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			return 0;
		}

		$upload = wp_upload_bits( 'fpl-logo-import-' . time() . '.' . $extension, null, $bytes );

		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => __( 'Logo importé — Facture PDF Lumia', 'facture-pdf-lumia' ),
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			return 0;
		}

		if ( is_readable( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';

			if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
				$metadata = wp_generate_attachment_metadata( (int) $attachment_id, (string) $upload['file'] );

				wp_update_attachment_metadata( (int) $attachment_id, $metadata );
			}
		}

		return (int) $attachment_id;
	}

	/**
	 * Extension de fichier pour un type MIME image courant.
	 *
	 * @param string $mime Type MIME.
	 */
	private static function extension_for_mime( string $mime ): string {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);

		return $map[ $mime ] ?? 'png';
	}
}
