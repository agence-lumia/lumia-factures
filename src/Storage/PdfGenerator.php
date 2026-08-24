<?php
/**
 * Obtention du PDF d'un document, avec cache.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Storage;

use Lumia\FacturePDF\Documents\Document;
use Lumia\FacturePDF\Rendering\PdfEngine;
use Lumia\FacturePDF\Rendering\Renderer;
use Lumia\FacturePDF\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Point d'entrée unique pour obtenir le PDF d'un document.
 *
 * Sert le cache quand il est valide, régénère sinon. Toute autre partie du
 * plugin (téléchargement, pièce jointe d'email, aperçu) passe par ici plutôt
 * que d'appeler le moteur de rendu directement : c'est ce qui garantit qu'un
 * numéro est attribué au bon moment et qu'un même document n'est pas rendu
 * deux fois pour rien.
 */
final class PdfGenerator {

	/**
	 * Renvoie le contenu binaire du PDF.
	 *
	 * @param Document $document Document concerné.
	 * @param bool     $force    Ignorer le cache et régénérer.
	 */
	public static function contents( Document $document, bool $force = false ): string {
		if ( ! $force && FileStore::has_cache( $document ) ) {
			$path = FileStore::path( $document );

			if ( null !== $path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$cached = file_get_contents( $path );

				if ( false !== $cached && '' !== $cached ) {
					return $cached;
				}
			}
		}

		return self::generate( $document );
	}

	/**
	 * Renvoie le chemin d'un PDF sur disque, en le générant si nécessaire.
	 *
	 * Utile pour les pièces jointes d'email, qui attendent un chemin. Si la
	 * mise en cache est désactivée, un fichier temporaire est créé : l'appelant
	 * reste responsable de son nettoyage via `cleanup_temp()`.
	 *
	 * @param Document $document Document concerné.
	 *
	 * @return string|null Chemin absolu, ou null en cas d'échec.
	 */
	public static function file( Document $document ): ?string {
		if ( FileStore::has_cache( $document ) ) {
			$path = FileStore::path( $document );

			if ( null !== $path ) {
				return $path;
			}
		}

		$contents = self::generate( $document );

		if ( '' === $contents ) {
			return null;
		}

		$path = FileStore::path( $document );

		if ( null !== $path ) {
			return $path;
		}

		return self::write_temp( $document, $contents );
	}

	/**
	 * Génère le PDF et le met en cache.
	 *
	 * @param Document $document Document concerné.
	 */
	private static function generate( Document $document ): string {
		// L'attribution du numéro doit précéder le rendu : le numéro figure
		// sur le document, et il doit être définitif avant d'être imprimé.
		if ( ! $document->exists() ) {
			$number = $document->assign_number();

			if ( '' === $number ) {
				Logger::warning(
					'Génération abandonnée : aucun numéro attribuable.',
					array( 'order' => $document->get_order()->get_id() )
				);

				return '';
			}
		}

		$html = Renderer::render( $document );

		if ( '' === $html ) {
			return '';
		}

		$contents = PdfEngine::render( $html );

		if ( '' === $contents ) {
			return '';
		}

		FileStore::store( $document, $contents );

		/**
		 * Déclenché après génération réussie d'un PDF.
		 *
		 * @param Document $document Document généré.
		 * @param int      $size     Taille du fichier en octets.
		 */
		do_action( 'fpl_invoice_generated', $document, strlen( $contents ) );

		return $contents;
	}

	/**
	 * Écrit un PDF dans un fichier temporaire.
	 *
	 * @param Document $document Document concerné.
	 * @param string   $contents Contenu binaire.
	 */
	private static function write_temp( Document $document, string $contents ): ?string {
		$directory = trailingslashit( get_temp_dir() ) . 'fpl-temp';

		if ( ! wp_mkdir_p( $directory ) ) {
			return null;
		}

		$path = $directory . '/' . sanitize_file_name( $document->get_filename() ) . '-' . wp_generate_password( 8, false ) . '.pdf';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $path, $contents, LOCK_EX ) ) {
			return null;
		}

		return $path;
	}

	/**
	 * Supprime un fichier temporaire produit par `file()`.
	 *
	 * Sans effet sur un fichier du cache : seuls les fichiers du dossier
	 * temporaire sont concernés.
	 *
	 * @param string $path Chemin renvoyé par `file()`.
	 */
	public static function cleanup_temp( string $path ): void {
		$temp_root = realpath( trailingslashit( get_temp_dir() ) . 'fpl-temp' );
		$real_path = realpath( $path );

		if ( false === $temp_root || false === $real_path ) {
			return;
		}

		if ( ! str_starts_with( $real_path, $temp_root . DIRECTORY_SEPARATOR ) ) {
			return;
		}

		wp_delete_file( $real_path );
	}
}
