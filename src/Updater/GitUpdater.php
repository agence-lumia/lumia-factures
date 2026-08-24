<?php
/**
 * Mise à jour depuis un dépôt Git privé.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Updater;

use Lumia\FacturePDF\Support\Logger;
use stdClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Branche le plugin sur le mécanisme de mise à jour natif de WordPress, en
 * s'appuyant sur les releases d'un dépôt GitHub privé.
 *
 * Deux constantes à définir dans `wp-config.php`, jamais en base de données :
 *
 *     define( 'FPL_UPDATE_REPO', 'lumia-studio/facture-pdf-lumia' );
 *     define( 'FPL_UPDATE_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxx' );
 *
 * Sans ces deux constantes, la classe ne fait rien — un site qui n'a pas
 * configuré l'updater continue de fonctionner normalement, simplement sans
 * mises à jour automatiques.
 *
 * Le jeton n'est jamais stocké en base ni visible dans l'administration : le
 * poser en constante est la seule façon de le garder hors de portée d'un
 * export de base de données ou d'un accès en lecture seule à l'admin.
 */
final class GitUpdater {

	/**
	 * Durée de mise en cache de la vérification, en secondes.
	 *
	 * L'API GitHub limite le nombre de requêtes par heure ; interroger à
	 * chaque chargement d'écran d'administration épuiserait ce quota sur un
	 * site à plusieurs administrateurs actifs.
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Transient de cache.
	 */
	private const CACHE_KEY = 'fpl_update_check';

	/**
	 * Accroche les hooks, si l'updater est configuré.
	 */
	public function register(): void {
		if ( ! self::configured() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download_package' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'authorize_package_request' ), 10, 2 );
	}

	/**
	 * L'updater dispose-t-il de tout ce qu'il faut pour fonctionner ?
	 */
	public static function configured(): bool {
		return defined( 'FPL_UPDATE_REPO' ) && defined( 'FPL_UPDATE_TOKEN' )
			&& '' !== (string) constant( 'FPL_UPDATE_REPO' )
			&& '' !== (string) constant( 'FPL_UPDATE_TOKEN' );
	}

	/**
	 * Ajoute la mise à jour disponible au transient WordPress standard.
	 *
	 * @param mixed $transient Transient WordPress, ou vide au tout premier appel.
	 *
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->latest_release();

		if ( null === $release ) {
			return $transient;
		}

		if ( version_compare( $release['version'], FPL_VERSION, '<=' ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$item = new stdClass();

		$item->slug        = 'facture-pdf-lumia';
		$item->plugin      = FPL_PLUGIN_BASENAME;
		$item->new_version = $release['version'];
		$item->url         = self::repo_url();
		$item->package      = $release['package'];
		$item->tested       = get_bloginfo( 'version' );

		$transient->response[ FPL_PLUGIN_BASENAME ] = $item;

		return $transient;
	}

	/**
	 * Fournit le contenu de la popup « Voir les détails de la version ».
	 *
	 * @param mixed  $result Résultat déjà résolu par un autre filtre.
	 * @param string $action Action demandée par WordPress.
	 * @param mixed  $args   Arguments de la requête.
	 *
	 * @return mixed
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || 'facture-pdf-lumia' !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();

		if ( null === $release ) {
			return $result;
		}

		$info = new stdClass();

		$info->name          = 'Facture PDF Lumia';
		$info->slug          = 'facture-pdf-lumia';
		$info->version       = $release['version'];
		$info->author        = 'Lumia';
		$info->homepage      = self::repo_url();
		$info->sections      = array(
			'changelog' => wp_kses_post( $release['changelog'] ),
		);
		$info->download_link = $release['package'];

		return $info;
	}

	/**
	 * Télécharge le paquet en s'authentifiant auprès du dépôt.
	 *
	 * L'archive d'une release privée exige un en-tête d'autorisation que le
	 * téléchargeur générique de WordPress ne fournit pas. On l'intercepte
	 * donc ici pour effectuer la requête nous-mêmes.
	 *
	 * @param bool|mixed $reply   Résultat déjà produit par un autre filtre (false = poursuivre normalement).
	 * @param string     $package URL du paquet à télécharger.
	 * @param mixed      $upgrader Instance de l'upgrader WordPress en cours.
	 * @param mixed      $args    Arguments additionnels.
	 *
	 * @return bool|string|\WP_Error
	 */
	public function download_package( $reply, $package, $upgrader, $args = array() ) {
		if ( false !== $reply || ! str_contains( $package, self::api_host() ) ) {
			return $reply;
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout' => 60,
				'headers' => self::auth_headers(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Logger::error( 'Échec du téléchargement de la mise à jour.', array( 'package' => $package ) );

			return new \WP_Error( 'fpl_download_failed', __( 'Le téléchargement de la mise à jour a échoué.', 'facture-pdf-lumia' ) );
		}

		$tmp_file = wp_tempnam( $package );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp_file, wp_remote_retrieve_body( $response ) ) ) {
			return new \WP_Error( 'fpl_download_write_failed', __( 'Impossible d\'écrire le fichier téléchargé.', 'facture-pdf-lumia' ) );
		}

		return $tmp_file;
	}

	/**
	 * Ajoute l'en-tête d'autorisation aux requêtes visant l'API du dépôt.
	 *
	 * Filet de sécurité pour les appels qui ne passent pas par
	 * `download_package()` (résolution de redirection par exemple) : mieux
	 * vaut une autorisation posée deux fois qu'une requête qui échoue
	 * silencieusement faute d'en-tête.
	 *
	 * @param array<string, mixed> $args Arguments de la requête HTTP.
	 * @param string               $url  URL cible.
	 *
	 * @return array<string, mixed>
	 */
	public function authorize_package_request( array $args, string $url ): array {
		if ( ! str_contains( $url, self::api_host() ) ) {
			return $args;
		}

		$args['headers'] = array_merge( (array) ( $args['headers'] ?? array() ), self::auth_headers() );

		return $args;
	}

	/**
	 * Récupère la dernière release, avec mise en cache.
	 *
	 * @return array{version: string, package: string, changelog: string}|null
	 */
	private function latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return array() === $cached ? null : $cached;
		}

		$release = $this->fetch_latest_release();

		// Le résultat négatif est mis en cache aussi (sous forme de tableau
		// vide) : un dépôt injoignable ne doit pas être réinterrogé à chaque
		// chargement d'écran, silencieusement, comme le veut le principe de
		// repli silencieux de cet updater.
		set_transient( self::CACHE_KEY, $release ?? array(), self::CACHE_TTL );

		return $release;
	}

	/**
	 * Interroge l'API GitHub pour la dernière release publiée.
	 *
	 * @return array{version: string, package: string, changelog: string}|null
	 */
	private function fetch_latest_release(): ?array {
		$repo = (string) constant( 'FPL_UPDATE_REPO' );

		$response = wp_remote_get(
			sprintf( 'https://%s/repos/%s/releases/latest', self::api_host(), $repo ),
			array(
				'timeout' => 15,
				'headers' => self::auth_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::info( 'Vérification de mise à jour impossible : dépôt injoignable.', array( 'error' => $response->get_error_message() ) );

			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Logger::info( 'Vérification de mise à jour : réponse inattendue du dépôt.', array( 'status' => wp_remote_retrieve_response_code( $response ) ) );

			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		$version = ltrim( (string) $data['tag_name'], 'vV' );
		$asset   = self::zip_asset_url( (array) ( $data['assets'] ?? array() ) );

		// Repli sur l'archive automatique du tag si aucun paquet ZIP n'a été
		// joint manuellement à la release : ça fonctionne toujours, avec une
		// arborescence de dossier légèrement différente (suffixée du hash).
		$package = $asset ?? (string) ( $data['zipball_url'] ?? '' );

		if ( '' === $package ) {
			return null;
		}

		return array(
			'version'   => $version,
			'package'   => $package,
			'changelog' => (string) ( $data['body'] ?? '' ),
		);
	}

	/**
	 * Cherche un fichier `.zip` joint manuellement à la release.
	 *
	 * Préférable à l'archive automatique de tag : elle peut être préparée à
	 * l'avance (avec `vendor/` déjà installé, par exemple), alors que
	 * l'archive automatique de GitHub est un instantané brut du code source.
	 *
	 * @param array<int, array<string, mixed>> $assets Pièces jointes de la release.
	 */
	private static function zip_asset_url( array $assets ): ?string {
		foreach ( $assets as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );

			if ( str_ends_with( strtolower( $name ), '.zip' ) ) {
				return (string) ( $asset['url'] ?? '' );
			}
		}

		return null;
	}

	/**
	 * En-têtes d'authentification pour l'API GitHub.
	 *
	 * @return array<string, string>
	 */
	private static function auth_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . (string) constant( 'FPL_UPDATE_TOKEN' ),
			'Accept'        => 'application/octet-stream, application/vnd.github+json',
		);
	}

	/**
	 * Hôte de l'API, pour cibler précisément les requêtes à authentifier.
	 */
	private static function api_host(): string {
		return 'api.github.com';
	}

	/**
	 * URL publique du dépôt, pour le lien « en savoir plus ».
	 */
	private static function repo_url(): string {
		return 'https://github.com/' . (string) constant( 'FPL_UPDATE_REPO' );
	}
}
