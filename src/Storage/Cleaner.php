<?php
/**
 * Purge des PDF expirés.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Storage;

use DirectoryIterator;
use Lumia\FacturePDF\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tâche planifiée supprimant les fichiers dépassant la durée de conservation.
 *
 * Le balayage porte sur le système de fichiers plutôt que sur les metas de
 * commande : c'est plus rapide (pas de requête sur les commandes) et surtout
 * plus sûr, puisqu'un fichier dont la meta a disparu — commande supprimée,
 * import raté — serait autrement conservé indéfiniment sans que rien ne le
 * référence.
 */
final class Cleaner {

	/**
	 * Nom de l'événement planifié.
	 */
	public const HOOK = 'fpl_cleanup_files';

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Exécute la purge.
	 *
	 * @return int Nombre de fichiers supprimés.
	 */
	public static function run(): int {
		$days = FileStore::retention_days();

		// Conservation illimitée : les fichiers restent, seul le réglage à 0
		// (cache désactivé) provoque un nettoyage complet.
		if ( $days < 0 ) {
			return 0;
		}

		$base = FileStore::base_path();

		if ( ! is_dir( $base ) ) {
			return 0;
		}

		$cutoff  = time() - ( max( 0, $days ) * DAY_IN_SECONDS );
		$deleted = 0;

		foreach ( self::pdf_files( $base ) as $path ) {
			$modified = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $modified || $modified > $cutoff ) {
				continue;
			}

			if ( ! FileStore::is_within_base( $path ) ) {
				continue;
			}

			wp_delete_file( $path );

			++$deleted;
		}

		self::remove_empty_directories( $base );

		if ( $deleted > 0 ) {
			Logger::info( sprintf( 'Purge des PDF : %d fichier(s) supprimé(s).', $deleted ) );
		}

		/**
		 * Déclenché après une purge.
		 *
		 * @param int $deleted Nombre de fichiers supprimés.
		 */
		do_action( 'fpl_files_purged', $deleted );

		return $deleted;
	}

	/**
	 * Liste les PDF présents sous la racine de stockage.
	 *
	 * @param string $base Racine.
	 *
	 * @return array<int, string>
	 */
	private static function pdf_files( string $base ): array {
		$files = array();

		// L'arborescence est connue et peu profonde (année/mois) : une
		// itération explicite évite de charger un itérateur récursif complet
		// et de suivre d'éventuels liens symboliques.
		foreach ( self::subdirectories( $base ) as $year ) {
			foreach ( self::subdirectories( $year ) as $month ) {
				foreach ( new DirectoryIterator( $month ) as $file ) {
					if ( $file->isDot() || ! $file->isFile() ) {
						continue;
					}

					if ( 'pdf' !== strtolower( $file->getExtension() ) ) {
						continue;
					}

					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}

	/**
	 * Sous-dossiers directs d'un dossier.
	 *
	 * @param string $path Dossier parent.
	 *
	 * @return array<int, string>
	 */
	private static function subdirectories( string $path ): array {
		if ( ! is_dir( $path ) ) {
			return array();
		}

		$directories = array();

		foreach ( new DirectoryIterator( $path ) as $item ) {
			if ( $item->isDot() || ! $item->isDir() || $item->isLink() ) {
				continue;
			}

			$directories[] = $item->getPathname();
		}

		return $directories;
	}

	/**
	 * Supprime les dossiers année/mois devenus vides.
	 *
	 * @param string $base Racine.
	 */
	private static function remove_empty_directories( string $base ): void {
		foreach ( self::subdirectories( $base ) as $year ) {
			foreach ( self::subdirectories( $year ) as $month ) {
				self::remove_if_empty( $month );
			}

			self::remove_if_empty( $year );
		}
	}

	/**
	 * Supprime un dossier s'il ne contient plus rien.
	 *
	 * @param string $path Dossier.
	 */
	private static function remove_if_empty( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		foreach ( new DirectoryIterator( $path ) as $item ) {
			if ( ! $item->isDot() ) {
				return;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
