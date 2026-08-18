<?php
/**
 * Tâches d'installation, de mise à jour et de désactivation.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crée et maintient les ressources persistantes du plugin.
 */
final class Installer {

	/**
	 * Version du schéma de base de données.
	 *
	 * À incrémenter dès qu'une table change de structure.
	 */
	public const DB_VERSION = 1;

	/**
	 * Option stockant la version de base installée.
	 */
	public const DB_VERSION_OPTION = 'fpl_db_version';

	/**
	 * Option stockant la version du plugin installée.
	 */
	public const VERSION_OPTION = 'fpl_version';

	/**
	 * Nom du dossier de stockage dans `uploads`.
	 */
	public const UPLOAD_DIR = 'facture-pdf-lumia';

	/**
	 * Activation du plugin.
	 */
	public static function activate(): void {
		self::install_tables();
		self::prepare_upload_directory();
		self::schedule_events();

		update_option( self::VERSION_OPTION, FPL_VERSION, false );

		// Le drapeau déclenche l'écran de bienvenue au prochain chargement admin.
		if ( ! get_option( 'fpl_settings' ) ) {
			set_transient( 'fpl_activation_redirect', 1, 60 );
		}
	}

	/**
	 * Désactivation du plugin.
	 *
	 * On retire les tâches planifiées, mais on ne touche à aucune donnée :
	 * une désactivation n'est pas une désinstallation.
	 */
	public static function deactivate(): void {
		self::unschedule_events();
	}

	/**
	 * Vérifie à chaque chargement si une migration est nécessaire.
	 *
	 * Appelée depuis Plugin::boot(). Couvre le cas d'une mise à jour par FTP
	 * ou par l'updater Git, qui ne déclenchent pas le hook d'activation.
	 */
	public static function maybe_upgrade(): void {
		$installed_db = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $installed_db < self::DB_VERSION ) {
			self::install_tables();
			self::prepare_upload_directory();
		}

		$installed_version = (string) get_option( self::VERSION_OPTION, '' );

		if ( FPL_VERSION !== $installed_version ) {
			self::schedule_events();

			update_option( self::VERSION_OPTION, FPL_VERSION, false );

			/**
			 * Déclenché après une montée de version du plugin.
			 *
			 * @param string $to   Nouvelle version.
			 * @param string $from Version précédente (chaîne vide en première installation).
			 */
			do_action( 'fpl_upgraded', FPL_VERSION, $installed_version );
		}
	}

	/**
	 * Crée ou met à jour les tables.
	 *
	 * La table des séquences existe pour une seule raison : permettre une
	 * incrémentation atomique du compteur de factures. Un compteur stocké en
	 * option est sujet à une condition de concurrence — deux commandes
	 * terminées dans la même seconde peuvent recevoir le même numéro, sans
	 * aucun message d'erreur. Le lot 2 s'appuiera sur cette table avec un
	 * UPDATE atomique.
	 */
	private static function install_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'fpl_sequences';
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			sequence_key varchar(191) NOT NULL,
			current_value bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (sequence_key)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Prépare le dossier de stockage des PDF.
	 *
	 * Triple protection : `.htaccess` pour Apache, `index.php` contre le
	 * listing, et — surtout — des noms de fichiers contenant un hash
	 * aléatoire, seule protection réellement fiable sous Nginx où le
	 * `.htaccess` est ignoré.
	 */
	public static function prepare_upload_directory(): bool {
		$path = self::upload_path();

		if ( ! wp_mkdir_p( $path ) ) {
			Logger::error( 'Impossible de créer le dossier de stockage : ' . $path );

			return false;
		}

		$files = array(
			'.htaccess'   => "# Facture PDF Lumia\nOrder Deny,Allow\nDeny from all\n",
			'index.php'   => "<?php\n// Silence is golden.\n",
			'index.html'  => '',
		);

		foreach ( $files as $name => $content ) {
			$file = trailingslashit( $path ) . $name;

			if ( file_exists( $file ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file, $content );
		}

		return true;
	}

	/**
	 * Chemin absolu du dossier de stockage.
	 */
	public static function upload_path(): string {
		$uploads = wp_upload_dir( null, false );

		/**
		 * Filtre le chemin de stockage des PDF.
		 *
		 * @param string $path Chemin absolu.
		 */
		return (string) apply_filters(
			'fpl_upload_path',
			trailingslashit( $uploads['basedir'] ) . self::UPLOAD_DIR
		);
	}

	/**
	 * Planifie les tâches récurrentes.
	 */
	private static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'fpl_cleanup_files' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fpl_cleanup_files' );
		}
	}

	/**
	 * Retire les tâches récurrentes.
	 */
	private static function unschedule_events(): void {
		$timestamp = wp_next_scheduled( 'fpl_cleanup_files' );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'fpl_cleanup_files' );

			$timestamp = wp_next_scheduled( 'fpl_cleanup_files' );
		}
	}
}
