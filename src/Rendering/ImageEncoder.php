<?php
/**
 * Encodage des images pour le PDF.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Rendering;

use Lumia\FacturePDF\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prépare les images destinées au document.
 *
 * Trois pièges à éviter. D'abord le poids : une commande de trente articles
 * avec des visuels d'origine à 3000 px produit un PDF de plusieurs dizaines
 * de mégaoctets et peut dépasser la limite mémoire. Les images sont donc
 * redimensionnées à leur taille d'affichage réelle avant encodage.
 *
 * Ensuite le format : Dompdf ne rend pas le SVG de manière fiable, et une
 * image absente ou corrompue interrompt le rendu. Toute erreur est donc
 * absorbée — une facture sans vignette reste une facture valide, une facture
 * qui ne se génère pas ne l'est pas.
 *
 * Enfin l'emplacement réel du fichier. `get_attached_file()` renvoie le
 * chemin que WordPress *croit* correct, pas une garantie que le fichier s'y
 * trouve : sur un site utilisant un stockage déporté (S3, CDN, synchronisation
 * partielle entre environnements), le fichier local n'existe souvent plus une
 * fois l'image téléversée vers le stockage distant. Sans repli, la vignette
 * disparaît silencieusement du document. Le repli récupère l'image par son
 * URL — laquelle provient de nos propres données d'attachement, jamais d'une
 * entrée utilisateur, donc sans le risque qu'ouvrirait la récupération d'une
 * URL arbitraire.
 */
final class ImageEncoder {

	/**
	 * Cache de la requête courante, indexé par attachement et largeur.
	 *
	 * Un même produit peut apparaître sur plusieurs lignes d'une commande.
	 *
	 * @var array<string, string>
	 */
	private static array $cache = array();

	/**
	 * Encode l'image d'un attachement WordPress en data-URI.
	 *
	 * Point d'entrée à utiliser depuis le reste du plugin : centralise la
	 * tentative locale, le repli distant et la journalisation, plutôt que de
	 * laisser chaque appelant réimplémenter cette logique.
	 *
	 * @param int    $attachment_id Identifiant de l'attachement.
	 * @param int    $max_width     Largeur maximale en pixels, 0 pour ne pas redimensionner.
	 * @param string $context       Libellé court pour les journaux (ex. « logo », « produit #12 »).
	 *
	 * @return string Data-URI, ou chaîne vide en cas d'échec.
	 */
	public static function for_attachment( int $attachment_id, int $max_width = 0, string $context = '' ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$cache_key = 'a' . $attachment_id . '|' . $max_width;

		if ( isset( self::$cache[ $cache_key ] ) ) {
			return self::$cache[ $cache_key ];
		}

		self::$cache[ $cache_key ] = '';

		$label = '' !== $context ? $context : ( '#' . $attachment_id );
		$path  = get_attached_file( $attachment_id );

		if ( $path && is_readable( $path ) ) {
			$result = self::encode( $path, $max_width );

			if ( '' !== $result ) {
				self::$cache[ $cache_key ] = $result;

				return $result;
			}

			Logger::warning(
				sprintf( 'Image locale illisible ou de format non pris en charge pour %s, tentative à distance.', $label ),
				array( 'attachment_id' => $attachment_id, 'path' => (string) $path )
			);
		} else {
			Logger::info(
				sprintf( 'Fichier local introuvable pour %s (stockage probablement déporté), tentative à distance.', $label ),
				array( 'attachment_id' => $attachment_id, 'path' => (string) $path )
			);
		}

		$result = self::encode_remote( $attachment_id, $max_width );

		if ( '' === $result ) {
			Logger::warning(
				sprintf( 'Image introuvable pour %s : ni fichier local, ni URL distante accessible. La ligne s\'affichera sans vignette.', $label ),
				array( 'attachment_id' => $attachment_id )
			);
		}

		self::$cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Encode une image à partir d'un chemin de fichier local.
	 *
	 * @param string $path      Chemin absolu du fichier.
	 * @param int    $max_width Largeur maximale en pixels, 0 pour ne pas redimensionner.
	 *
	 * @return string Data-URI, ou chaîne vide en cas d'échec.
	 */
	public static function encode( string $path, int $max_width = 0 ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$mime = self::detect_mime( $path );

		if ( '' === $mime ) {
			return '';
		}

		$source = $path;
		$temp   = '';

		if ( $max_width > 0 ) {
			$resized = self::resize( $path, $max_width );

			if ( '' !== $resized ) {
				$source = $resized;
				$temp   = $resized;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$binary = file_get_contents( $source );

		if ( '' !== $temp ) {
			wp_delete_file( $temp );
		}

		if ( false === $binary || '' === $binary ) {
			return '';
		}

		/*
		 * Le type réel peut différer après préparation. `resize()` enregistre
		 * volontairement son fichier temporaire en PNG, quelle que soit
		 * l'extension de l'image d'origine. Utiliser ici le MIME d'origine
		 * produisait donc par exemple `data:image/jpeg` contenant en réalité
		 * des octets PNG : les navigateurs sont parfois tolérants, Dompdf ne
		 * l'est pas et omettait alors silencieusement les vignettes produit.
		 *
		 * On détecte donc le type du fichier effectivement lu. Cela corrige
		 * aussi le cas WebP lorsque l'éditeur WordPress l'a converti en PNG.
		 */
		$final_mime = self::detect_mime( $source );

		if ( '' === $final_mime ) {
			return '';
		}

		return 'data:' . $final_mime . ';base64,' . base64_encode( $binary );
	}

	/**
	 * Récupère l'image par son URL quand le fichier local est introuvable.
	 *
	 * Passe par `wp_remote_get()` — le client HTTP de WordPress, avec ses
	 * propres protections (délai d'expiration, en-têtes cohérents) — plutôt
	 * que par un appel réseau direct. L'URL vient de `wp_get_attachment_url()`
	 * sur un identifiant d'attachement de notre propre boutique, jamais d'une
	 * saisie utilisateur : le risque qu'un `fpl_dompdf_options` ou un
	 * `isRemoteEnabled` mal réglé ouvrirait est ici structurellement absent.
	 *
	 * @param int $attachment_id Identifiant de l'attachement.
	 * @param int $max_width     Largeur maximale en pixels.
	 *
	 * @return string Data-URI, ou chaîne vide en cas d'échec.
	 */
	private static function encode_remote( int $attachment_id, int $max_width ): string {
		$url = wp_get_attachment_url( $attachment_id );

		if ( ! $url ) {
			return '';
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 5,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return '';
		}

		// Écrit dans un fichier temporaire pour réutiliser le même pipeline
		// de détection de type et de redimensionnement que le chemin local :
		// deux implémentations parallèles finissent toujours par diverger.
		$extension = self::guess_extension( (string) wp_remote_retrieve_header( $response, 'content-type' ), $url );
		$temp_path = trailingslashit( get_temp_dir() ) . 'fpl-remote-' . wp_generate_password( 12, false ) . $extension;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $temp_path, $body ) ) {
			return '';
		}

		$result = self::encode( $temp_path, $max_width );

		wp_delete_file( $temp_path );

		return $result;
	}

	/**
	 * Déduit une extension de fichier pour le type MIME reçu.
	 *
	 * Le fichier temporaire doit porter une extension reconnue : la détection
	 * de type en aval s'appuie sur `wp_check_filetype()`, qui juge par
	 * l'extension et non par le contenu.
	 *
	 * @param string $content_type En-tête Content-Type de la réponse.
	 * @param string $url          URL d'origine, repli si l'en-tête est absent.
	 */
	private static function guess_extension( string $content_type, string $url ): string {
		$map = array(
			'image/jpeg' => '.jpg',
			'image/png'  => '.png',
			'image/gif'  => '.gif',
			'image/webp' => '.webp',
		);

		$content_type = strtolower( trim( explode( ';', $content_type )[0] ?? '' ) );

		if ( isset( $map[ $content_type ] ) ) {
			return $map[ $content_type ];
		}

		$path_extension = strtolower( (string) pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) );

		return in_array( $path_extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ? '.' . $path_extension : '.jpg';
	}

	/**
	 * Détermine le type MIME d'un fichier, et écarte les formats non pris en
	 * charge.
	 *
	 * @param string $path Chemin du fichier.
	 */
	private static function detect_mime( string $path ): string {
		$type = wp_check_filetype( $path );
		$mime = (string) ( $type['type'] ?? '' );

		if ( 'image/svg+xml' === $mime ) {
			// Dompdf embarque php-svg-lib, mais son rendu diverge des
			// navigateurs sur tout ce qui dépasse les formes simples. Mieux
			// vaut ne rien afficher qu'un logo déformé.
			Logger::warning( 'Image SVG ignorée : format non fiable en PDF.', array( 'path' => $path ) );

			return '';
		}

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			return '';
		}

		return $mime;
	}

	/**
	 * Redimensionne une image dans un fichier temporaire.
	 *
	 * @param string $path      Chemin source.
	 * @param int    $max_width Largeur cible.
	 *
	 * @return string Chemin du fichier temporaire, ou chaîne vide.
	 */
	private static function resize( string $path, int $max_width ): string {
		$editor = wp_get_image_editor( $path );

		if ( is_wp_error( $editor ) ) {
			return '';
		}

		$size = $editor->get_size();

		if ( isset( $size['width'] ) && (int) $size['width'] <= $max_width ) {
			return '';
		}

		$resized = $editor->resize( $max_width, null, false );

		if ( is_wp_error( $resized ) ) {
			return '';
		}

		$saved = $editor->save( trailingslashit( get_temp_dir() ) . 'fpl-' . wp_generate_password( 12, false ) . '.png' );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return '';
		}

		return (string) $saved['path'];
	}

	/**
	 * Vide le cache mémoire.
	 */
	public static function flush(): void {
		self::$cache = array();
	}
}
