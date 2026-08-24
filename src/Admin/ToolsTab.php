<?php
/**
 * Onglet Outils.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Admin;

use Lumia\FacturePDF\Settings\Migrator;
use Lumia\FacturePDF\Settings\PortManager;
use Lumia\FacturePDF\Settings\SettingsRepository;
use Lumia\FacturePDF\Support\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Diagnostic d'environnement et maintenance.
 *
 * Le diagnostic sert d'abord au support : avant de chercher un bug dans le
 * plugin, il permet de vérifier en dix secondes que le problème ne vient pas
 * d'une limite mémoire trop basse ou d'un dossier non inscriptible.
 */
final class ToolsTab {

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_fpl_reset_settings', array( $this, 'handle_reset' ) );
		add_action( 'admin_post_fpl_export_settings', array( $this, 'handle_export' ) );
		add_action( 'admin_post_fpl_import_settings', array( $this, 'handle_import' ) );
	}

	/**
	 * Rend l'onglet.
	 */
	public static function render(): void {
		self::render_diagnostics();
		self::render_export();
		self::render_import();
		self::render_updater();
		self::render_maintenance();
	}

	/**
	 * Tableau de diagnostic.
	 */
	private static function render_diagnostics(): void {
		printf( '<h2 class="fpl-panel__title">%s</h2>', esc_html__( 'Diagnostic', 'facture-pdf-lumia' ) );

		echo '<table class="widefat striped fpl-diagnostics"><tbody>';

		foreach ( self::checks() as $check ) {
			printf(
				'<tr><td class="fpl-diagnostics__label">%s</td><td class="fpl-diagnostics__value">%s</td><td class="fpl-diagnostics__status"><span class="fpl-badge fpl-badge--%s">%s</span></td></tr>',
				esc_html( $check['label'] ),
				esc_html( $check['value'] ),
				esc_attr( $check['status'] ),
				esc_html( $check['status_label'] )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Contrôles d'environnement.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function checks(): array {
		$memory_limit  = self::memory_limit_bytes();
		$upload_path   = Installer::upload_path();
		$writable      = is_dir( $upload_path ) && wp_is_writable( $upload_path );
		$memory_ok     = $memory_limit >= 256 * MB_IN_BYTES;
		$wc_version    = defined( 'WC_VERSION' ) ? WC_VERSION : __( 'absent', 'facture-pdf-lumia' );

		$ok      = __( 'OK', 'facture-pdf-lumia' );
		$warning = __( 'À surveiller', 'facture-pdf-lumia' );
		$error   = __( 'Problème', 'facture-pdf-lumia' );

		$checks = array(
			array(
				'label'        => __( 'Version du plugin', 'facture-pdf-lumia' ),
				'value'        => FPL_VERSION,
				'status'       => 'ok',
				'status_label' => $ok,
			),
			array(
				'label'        => __( 'Version de PHP', 'facture-pdf-lumia' ),
				'value'        => PHP_VERSION,
				'status'       => version_compare( PHP_VERSION, '8.2', '>=' ) ? 'ok' : 'warning',
				'status_label' => version_compare( PHP_VERSION, '8.2', '>=' ) ? $ok : $warning,
			),
			array(
				'label'        => __( 'Version de WooCommerce', 'facture-pdf-lumia' ),
				'value'        => (string) $wc_version,
				'status'       => defined( 'WC_VERSION' ) ? 'ok' : 'error',
				'status_label' => defined( 'WC_VERSION' ) ? $ok : $error,
			),
			array(
				'label'        => __( 'Limite mémoire PHP', 'facture-pdf-lumia' ),
				'value'        => size_format( $memory_limit ),
				'status'       => $memory_ok ? 'ok' : 'warning',
				'status_label' => $memory_ok ? $ok : $warning,
			),
			array(
				'label'        => __( 'Dossier de stockage', 'facture-pdf-lumia' ),
				'value'        => $upload_path,
				'status'       => $writable ? 'ok' : 'error',
				'status_label' => $writable ? __( 'Inscriptible', 'facture-pdf-lumia' ) : __( 'Non inscriptible', 'facture-pdf-lumia' ),
			),
			array(
				'label'        => __( 'Stockage HPOS', 'facture-pdf-lumia' ),
				'value'        => self::hpos_status(),
				'status'       => 'ok',
				'status_label' => $ok,
			),
			array(
				'label'        => __( 'Version du schéma de réglages', 'facture-pdf-lumia' ),
				'value'        => (string) get_option( Migrator::OPTION, 0 ),
				'status'       => 'ok',
				'status_label' => $ok,
			),
		);

		foreach ( array( 'gd', 'mbstring', 'dom' ) as $extension ) {
			$loaded = extension_loaded( $extension );

			$checks[] = array(
				'label'        => sprintf(
					/* translators: %s: nom de l'extension */
					__( 'Extension %s', 'facture-pdf-lumia' ),
					$extension
				),
				'value'        => $loaded ? __( 'chargée', 'facture-pdf-lumia' ) : __( 'absente', 'facture-pdf-lumia' ),
				'status'       => $loaded ? 'ok' : 'error',
				'status_label' => $loaded ? $ok : $error,
			);
		}

		return $checks;
	}

	/**
	 * Section Export.
	 */
	private static function render_export(): void {
		printf(
			'<h2 class="fpl-panel__title fpl-panel__title--spaced">%s</h2>',
			esc_html__( 'Export de la configuration', 'facture-pdf-lumia' )
		);

		printf(
			'<p class="fpl-panel__desc">%s</p>',
			esc_html__( 'Télécharge un fichier JSON reprenant les réglages actuels — pratique pour configurer une charte une fois puis la déployer sur d\'autres sites clients.', 'facture-pdf-lumia' )
		);

		printf(
			'<form method="post" action="%s" class="fpl-export-form">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( 'fpl_export_settings', 'fpl_export_nonce' );

		echo '<input type="hidden" name="action" value="fpl_export_settings">';

		printf(
			'<label class="fpl-checklist__item"><input type="checkbox" name="include_site_specific" value="1"> %s</label>',
			esc_html__( 'Inclure les données propres à ce site (logo, SIRET, adresse, numérotation en cours)', 'facture-pdf-lumia' )
		);

		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Télécharger l\'export', 'facture-pdf-lumia' )
		);

		echo '</form>';
	}

	/**
	 * Traite le téléchargement de l'export.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'facture-pdf-lumia' ) );
		}

		check_admin_referer( 'fpl_export_settings', 'fpl_export_nonce' );

		$include_site_specific = ! empty( $_POST['include_site_specific'] );
		$json                   = PortManager::export_json( $include_site_specific );

		nocache_headers();

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . PortManager::export_filename() . '"' );
		header( 'Content-Length: ' . strlen( $json ) );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, pas du HTML.
		echo $json;

		exit;
	}

	/**
	 * Section Import.
	 */
	private static function render_import(): void {
		printf(
			'<h2 class="fpl-panel__title fpl-panel__title--spaced">%s</h2>',
			esc_html__( 'Import d\'une configuration', 'facture-pdf-lumia' )
		);

		printf(
			'<p class="fpl-panel__desc">%s</p>',
			esc_html__( 'Sélectionne un fichier exporté depuis ce plugin. Les réglages sont appliqués immédiatement à l\'envoi — pense à exporter la configuration actuelle avant, si tu veux pouvoir y revenir.', 'facture-pdf-lumia' )
		);

		printf(
			'<form method="post" action="%s" enctype="multipart/form-data">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( 'fpl_import_settings', 'fpl_import_nonce' );

		echo '<input type="hidden" name="action" value="fpl_import_settings">';

		echo '<p><input type="file" name="fpl_import_file" accept="application/json,.json" required></p>';

		printf(
			'<label class="fpl-checklist__item"><input type="checkbox" name="include_site_specific" value="1"> %s</label>',
			esc_html__( 'Appliquer aussi les données propres au site présentes dans le fichier (logo, SIRET, adresse…)', 'facture-pdf-lumia' )
		);

		printf(
			'<p><button type="submit" class="button button-secondary">%s</button></p>',
			esc_html__( 'Importer', 'facture-pdf-lumia' )
		);

		echo '</form>';
	}

	/**
	 * Traite l'import d'un fichier.
	 */
	public function handle_import(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'facture-pdf-lumia' ) );
		}

		check_admin_referer( 'fpl_import_settings', 'fpl_import_nonce' );

		$file = $_FILES['fpl_import_file'] ?? null;

		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) ) {
			Notices::add_error( __( 'Aucun fichier valide n\'a été reçu.', 'facture-pdf-lumia' ) );

			self::redirect_to_tools();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$json = file_get_contents( $file['tmp_name'] );

		if ( false === $json ) {
			Notices::add_error( __( 'Impossible de lire le fichier envoyé.', 'facture-pdf-lumia' ) );

			self::redirect_to_tools();
		}

		$parsed = PortManager::parse( (string) $json );

		if ( ! $parsed['valid'] ) {
			Notices::add_error( $parsed['error'] );

			self::redirect_to_tools();
		}

		$include_site_specific = ! empty( $_POST['include_site_specific'] );
		$applied                = PortManager::apply( $parsed['payload'], $include_site_specific );

		if ( array() === $applied ) {
			Notices::add_error( __( 'Le fichier ne contenait aucun réglage applicable.', 'facture-pdf-lumia' ) );
		} else {
			Notices::add_success(
				sprintf(
					/* translators: %s: liste des onglets importés */
					__( 'Configuration importée (%s).', 'facture-pdf-lumia' ),
					implode( ', ', $applied )
				)
			);
		}

		self::redirect_to_tools();
	}

	/**
	 * Redirige vers l'onglet Outils.
	 */
	private static function redirect_to_tools(): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => SettingsPage::SLUG,
					'tab'  => 'tools',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Section Mise à jour.
	 */
	private static function render_updater(): void {
		printf(
			'<h2 class="fpl-panel__title fpl-panel__title--spaced">%s</h2>',
			esc_html__( 'Mises à jour', 'facture-pdf-lumia' )
		);

		if ( ! defined( 'FPL_UPDATE_TOKEN' ) ) {
			printf(
				'<p class="fpl-panel__desc">%s</p>',
				esc_html__( 'Aucun dépôt de mise à jour configuré. Définissez FPL_UPDATE_REPO et FPL_UPDATE_TOKEN dans wp-config.php pour activer les mises à jour automatiques depuis le dépôt privé.', 'facture-pdf-lumia' )
			);

			return;
		}

		$repo = defined( 'FPL_UPDATE_REPO' ) ? FPL_UPDATE_REPO : '';

		printf(
			'<p class="fpl-panel__desc">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: dépôt configuré */
					__( 'Dépôt configuré : %s. Les mises à jour apparaissent normalement dans Extensions → Extensions installées, comme pour un plugin du répertoire officiel.', 'facture-pdf-lumia' ),
					'' !== $repo ? $repo : __( '(non renseigné)', 'facture-pdf-lumia' )
				)
			)
		);
	}

	/**
	 * Bloc de maintenance.
	 */
	private static function render_maintenance(): void {
		printf(
			'<h2 class="fpl-panel__title fpl-panel__title--spaced">%s</h2>',
			esc_html__( 'Maintenance', 'facture-pdf-lumia' )
		);

		printf(
			'<p class="fpl-panel__desc">%s</p>',
			esc_html__( 'Remet tous les réglages à leur valeur par défaut. N\'affecte ni les factures déjà émises ni leurs numéros.', 'facture-pdf-lumia' )
		);

		printf(
			'<form method="post" action="%s" onsubmit="return confirm(\'%s\');">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_js( __( 'Tous les réglages seront remis à leur valeur par défaut. Les factures déjà émises et leurs numéros ne sont pas affectés. Confirmer ?', 'facture-pdf-lumia' ) )
		);

		wp_nonce_field( 'fpl_reset_settings', 'fpl_reset_nonce' );

		echo '<input type="hidden" name="action" value="fpl_reset_settings">';

		printf(
			'<button type="submit" class="button button-secondary">%s</button>',
			esc_html__( 'Réinitialiser les réglages', 'facture-pdf-lumia' )
		);

		echo '</form>';
	}

	/**
	 * Traite la réinitialisation.
	 */
	public function handle_reset(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'facture-pdf-lumia' ) );
		}

		check_admin_referer( 'fpl_reset_settings', 'fpl_reset_nonce' );

		SettingsRepository::instance()->reset();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => SettingsPage::SLUG,
					'tab'        => 'tools',
					'fpl-notice' => 'reset',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * État du stockage des commandes.
	 */
	private static function hpos_status(): string {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return __( 'indéterminé', 'facture-pdf-lumia' );
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? __( 'tables dédiées (HPOS)', 'facture-pdf-lumia' )
			: __( 'articles WordPress (legacy)', 'facture-pdf-lumia' );
	}

	/**
	 * Limite mémoire en octets.
	 */
	private static function memory_limit_bytes(): int {
		$limit = ini_get( 'memory_limit' );

		if ( false === $limit || '' === $limit || '-1' === $limit ) {
			return PHP_INT_MAX;
		}

		return (int) wp_convert_hr_to_bytes( $limit );
	}
}
