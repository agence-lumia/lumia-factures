<?php
/**
 * Stockage des fichiers PDF générés.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Storage;

use Lumia\FacturePDF\Documents\Document;
use Lumia\FacturePDF\Settings\SettingsRepository;
use Lumia\FacturePDF\Support\Installer;
use Lumia\FacturePDF\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Écriture, lecture et suppression des PDF en cache.
 *
 * Le PDF est généré à la demande puis conservé quelques jours, le temps
 * d'absorber les consultations répétées (client qui rouvre son email,
 * gestionnaire qui vérifie une commande). Passé ce délai il est supprimé et
 * régénéré au besoin : le document faisant foi, ce sont le numéro et la date
 * figés en meta de commande, pas le fichier.
 */
final class FileStore {

	/**
	 * Suffixe des metas portant les informations de fichier.
	 */
	private const META_FILE      = 'file';
	private const META_GENERATED = 'generated_at';

	/**
	 * Chemin absolu du PDF en cache, s'il existe encore.
	 *
	 * @param Document $document Document concerné.
	 */
	public static function path( Document $document ): ?string {
		$relative = (string) $document->get_order()->get_meta( $document->meta_key( self::META_FILE ), true );

		if ( '' === $relative ) {
			return null;
		}

		$path = self::base_path() . '/' . ltrim( $relative, '/' );

		// Un fichier référencé mais absent (purge manuelle, restauration
		// partielle) est traité comme inexistant plutôt que comme une erreur.
		if ( ! is_readable( $path ) ) {
			return null;
		}

		return $path;
	}

	/**
	 * Le document dispose-t-il d'un PDF en cache valide ?
	 *
	 * @param Document $document Document concerné.
	 */
	public static function has_cache( Document $document ): bool {
		if ( ! self::caching_enabled() ) {
			return false;
		}

		$path = self::path( $document );

		if ( null === $path ) {
			return false;
		}

		return ! self::is_expired( $document );
	}

	/**
	 * Écrit un PDF en cache et enregistre sa référence sur la commande.
	 *
	 * @param Document $document Document concerné.
	 * @param string   $contents Contenu binaire du PDF.
	 *
	 * @return string|null Chemin absolu écrit, null en cas d'échec ou si le
	 *                     cache est désactivé.
	 */
	public static function store( Document $document, string $contents ): ?string {
		if ( '' === $contents || ! self::caching_enabled() ) {
			return null;
		}

		$directory = self::base_path() . '/' . gmdate( 'Y/m' );

		if ( ! wp_mkdir_p( $directory ) ) {
			Logger::error( 'Dossier de stockage non inscriptible : ' . $directory );

			return null;
		}

		self::protect_directory( self::base_path() );

		$filename = self::build_filename( $document );
		$path     = $directory . '/' . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $path, $contents, LOCK_EX );

		if ( false === $written ) {
			Logger::error( 'Échec de l\'écriture du PDF : ' . $path );

			return null;
		}

		// L'ancien fichier n'est supprimé qu'une fois le nouveau écrit, pour
		// ne jamais laisser la commande sans PDF consultable entre les deux.
		self::delete_file( $document );

		$order = $document->get_order();
		$order->update_meta_data( $document->meta_key( self::META_FILE ), gmdate( 'Y/m' ) . '/' . $filename );
		$order->update_meta_data( $document->meta_key( self::META_GENERATED ), time() );
		$order->save();

		Logger::info(
			'PDF mis en cache.',
			array(
				'order'    => $order->get_id(),
				'document' => $document->get_type(),
			)
		);

		return $path;
	}

	/**
	 * Supprime le PDF en cache d'un document.
	 *
	 * Le numéro et la date d'émission ne sont pas touchés : seul le fichier
	 * est transitoire.
	 *
	 * @param Document $document Document concerné.
	 */
	public static function forget( Document $document ): void {
		self::delete_file( $document );

		$order = $document->get_order();
		$order->delete_meta_data( $document->meta_key( self::META_FILE ) );
		$order->delete_meta_data( $document->meta_key( self::META_GENERATED ) );
		$order->save();
	}

	/**
	 * Horodatage de la dernière génération, s'il existe.
	 *
	 * @param Document $document Document concerné.
	 */
	public static function generated_at( Document $document ): ?int {
		$timestamp = $document->get_order()->get_meta( $document->meta_key( self::META_GENERATED ), true );

		return $timestamp ? (int) $timestamp : null;
	}

	/**
	 * Date d'expiration du cache, ou null s'il n'expire pas.
	 *
	 * @param Document $document Document concerné.
	 */
	public static function expires_at( Document $document ): ?int {
		$generated = self::generated_at( $document );
		$days      = self::retention_days();

		if ( null === $generated || $days <= 0 ) {
			return null;
		}

		return $generated + ( $days * DAY_IN_SECONDS );
	}

	/**
	 * Le cache du document a-t-il dépassé la durée de conservation ?
	 *
	 * @param Document $document Document concerné.
	 */
	public static function is_expired( Document $document ): bool {
		$expires = self::expires_at( $document );

		if ( null === $expires ) {
			return false;
		}

		return time() > $expires;
	}

	/**
	 * Nombre de jours de conservation configuré.
	 */
	public static function retention_days(): int {
		$days = (int) SettingsRepository::instance()->get( 'general.retention_days', 7 );

		/**
		 * Filtre la durée de conservation des PDF, en jours.
		 *
		 * @param int $days Durée configurée. 0 désactive la mise en cache.
		 */
		return (int) apply_filters( 'fpl_retention_days', $days );
	}

	/**
	 * La mise en cache est-elle active ?
	 */
	public static function caching_enabled(): bool {
		return self::retention_days() > 0;
	}

	/**
	 * Racine du stockage.
	 */
	public static function base_path(): string {
		return untrailingslashit( Installer::upload_path() );
	}

	/**
	 * Construit un nom de fichier non devinable.
	 *
	 * Le nom lisible (`facture-FA-2026-0042`) est suivi d'un jeton aléatoire.
	 * C'est la protection réellement efficace : le `.htaccess` du dossier
	 * n'est lu que par Apache, et reste sans effet sous Nginx ou LiteSpeed,
	 * où l'URL directe du fichier serait sinon servie sans contrôle.
	 *
	 * @param Document $document Document concerné.
	 */
	private static function build_filename( Document $document ): string {
		$token = bin2hex( random_bytes( 16 ) );

		return sanitize_file_name( $document->get_filename() . '-' . $token ) . '.pdf';
	}

	/**
	 * Supprime physiquement le fichier référencé, sans toucher aux metas.
	 *
	 * @param Document $document Document concerné.
	 */
	private static function delete_file( Document $document ): void {
		$path = self::path( $document );

		if ( null === $path ) {
			return;
		}

		// Garde-fou : ne jamais supprimer hors du dossier de stockage, même
		// si une meta a été altérée.
		if ( ! self::is_within_base( $path ) ) {
			Logger::warning( 'Tentative de suppression hors du dossier de stockage : ' . $path );

			return;
		}

		wp_delete_file( $path );
	}

	/**
	 * Le chemin est-il bien contenu dans le dossier de stockage ?
	 *
	 * @param string $path Chemin absolu.
	 */
	public static function is_within_base( string $path ): bool {
		$real_path = realpath( $path );
		$real_base = realpath( self::base_path() );

		if ( false === $real_path || false === $real_base ) {
			return false;
		}

		return str_starts_with( $real_path, $real_base . DIRECTORY_SEPARATOR );
	}

	/**
	 * (Re)pose les fichiers de protection du dossier.
	 *
	 * @param string $path Dossier à protéger.
	 */
	private static function protect_directory( string $path ): void {
		$htaccess = $path . '/.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "# Facture PDF Lumia\nOrder Deny,Allow\nDeny from all\n" );
		}

		$index = $path . '/index.php';

		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}
}
