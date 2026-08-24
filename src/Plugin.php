<?php
/**
 * Orchestration du plugin.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF;

use Lumia\FacturePDF\Admin\Assets;
use Lumia\FacturePDF\Admin\DesignTab;
use Lumia\FacturePDF\Admin\OrderMetabox;
use Lumia\FacturePDF\Admin\PreviewController;
use Lumia\FacturePDF\Admin\NumberingTab;
use Lumia\FacturePDF\Admin\SettingsPage;
use Lumia\FacturePDF\Admin\ToolsTab;
use Lumia\FacturePDF\Emails\Attacher;
use Lumia\FacturePDF\Frontend\DownloadHandler;
use Lumia\FacturePDF\Frontend\DynamicTag;
use Lumia\FacturePDF\Frontend\Shortcode;
use Lumia\FacturePDF\Settings\Migrator;
use Lumia\FacturePDF\Storage\Cleaner;
use Lumia\FacturePDF\Support\Installer;
use Lumia\FacturePDF\Updater\GitUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Point d'entrée unique.
 *
 * Se contente d'instancier et de brancher les services. Aucune logique
 * métier ici : cette classe doit rester lisible d'un seul coup d'œil, même
 * quand le plugin en comptera trente.
 */
final class Plugin {

	/**
	 * Instance unique.
	 */
	private static ?self $instance = null;

	/**
	 * Le plugin a-t-il déjà démarré ?
	 */
	private bool $booted = false;

	/**
	 * Services instanciés, indexés par identifiant.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Constructeur privé.
	 */
	private function __construct() {}

	/**
	 * Instance unique.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Démarre le plugin.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->load_textdomain();

		require_once FPL_PLUGIN_DIR . 'src/functions.php';

		Installer::maybe_upgrade();
		Migrator::run();

		$this->register_services();

		add_filter( 'plugin_action_links_' . FPL_PLUGIN_BASENAME, array( $this, 'action_links' ) );

		/**
		 * Déclenché une fois le plugin entièrement initialisé.
		 *
		 * Point d'accroche recommandé pour toute extension : à ce stade, les
		 * réglages, le registre de documents et les services admin sont
		 * disponibles.
		 *
		 * @param Plugin $plugin Instance du plugin.
		 */
		do_action( 'fpl_loaded', $this );
	}

	/**
	 * Instancie et enregistre les services.
	 */
	private function register_services(): void {
		$services = array(
			// Le téléchargement, la purge, les pièces jointes d'email, le
			// shortcode et le dynamic tag Bricks tournent dans tous les
			// contextes : front pour la plupart, cron pour la purge, envoi
			// d'email côté serveur pour les pièces jointes.
			'download'    => new DownloadHandler(),
			'cleaner'     => new Cleaner(),
			'attacher'    => new Attacher(),
			'shortcode'   => new Shortcode(),
			'dynamic_tag' => new DynamicTag(),
			'updater'     => new GitUpdater(),
		);

		if ( is_admin() ) {
			$services['settings_page'] = new SettingsPage();
			$services['numbering_tab'] = new NumberingTab();
			$services['design_tab']    = new DesignTab();
			$services['preview']       = new PreviewController();
			$services['tools_tab']     = new ToolsTab();
			$services['assets']        = new Assets();
			$services['order_metabox'] = new OrderMetabox();
		}

		/**
		 * Filtre la liste des services avant enregistrement.
		 *
		 * @param array<string, object> $services Services.
		 * @param Plugin                $plugin   Instance du plugin.
		 */
		$services = (array) apply_filters( 'fpl_services', $services, $this );

		foreach ( $services as $id => $service ) {
			if ( is_object( $service ) && method_exists( $service, 'register' ) ) {
				$service->register();
			}

			$this->services[ $id ] = $service;
		}
	}

	/**
	 * Récupère un service enregistré.
	 *
	 * @param string $id Identifiant.
	 */
	public function service( string $id ): ?object {
		return $this->services[ $id ] ?? null;
	}

	/**
	 * Charge les traductions.
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain(
			'facture-pdf-lumia',
			false,
			dirname( FPL_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Ajoute un lien « Réglages » dans la liste des extensions.
	 *
	 * @param array<int, string> $links Liens existants.
	 *
	 * @return array<int, string>
	 */
	public function action_links( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				add_query_arg(
					array( 'page' => SettingsPage::SLUG ),
					admin_url( 'admin.php' )
				)
			),
			esc_html__( 'Réglages', 'facture-pdf-lumia' )
		);

		array_unshift( $links, $settings );

		return $links;
	}
}
