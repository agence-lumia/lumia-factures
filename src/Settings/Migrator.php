<?php
/**
 * Migrations du schéma de réglages.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Settings;

use Lumia\FacturePDF\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applique les transformations de structure entre versions de schéma.
 *
 * Le schéma de réglages évoluera : clés renommées, valeurs redécoupées,
 * réglages fusionnés. Sans mécanisme de migration, chaque évolution casse
 * silencieusement la configuration des sites déjà déployés — et comme le
 * plugin est destiné à tourner sur plusieurs sites clients, la situation se
 * présentera à coup sûr.
 *
 * Pour ajouter une migration : incrémenter SCHEMA_VERSION, puis ajouter une
 * méthode `to_N()` dans le tableau `migrations()`.
 */
final class Migrator {

	/**
	 * Version courante du schéma de réglages.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Option stockant la version appliquée.
	 */
	public const OPTION = 'fpl_schema_version';

	/**
	 * Exécute les migrations en attente.
	 */
	public static function run(): void {
		$installed = (int) get_option( self::OPTION, 0 );

		if ( $installed >= self::SCHEMA_VERSION ) {
			return;
		}

		// Première installation : rien à migrer, on pose simplement le jalon.
		if ( 0 === $installed && ! get_option( SettingsRepository::OPTION ) ) {
			update_option( self::OPTION, self::SCHEMA_VERSION, false );

			return;
		}

		$settings = get_option( SettingsRepository::OPTION, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		foreach ( self::migrations() as $version => $callback ) {
			if ( $version <= $installed ) {
				continue;
			}

			$settings = (array) call_user_func( $callback, $settings );

			Logger::info( sprintf( 'Migration du schéma de réglages vers la version %d.', $version ) );
		}

		update_option( SettingsRepository::OPTION, $settings, true );
		update_option( self::OPTION, self::SCHEMA_VERSION, false );

		SettingsRepository::instance()->flush();
	}

	/**
	 * Table des migrations, indexée par version cible.
	 *
	 * @return array<int, callable>
	 */
	private static function migrations(): array {
		return array(
			// 2 => array( self::class, 'to_2' ),
		);
	}

	/*
	 * Exemple de migration, à conserver comme modèle :
	 *
	 * private static function to_2( array $settings ): array {
	 *     if ( isset( $settings['general']['ancien_nom'] ) ) {
	 *         $settings['general']['nouveau_nom'] = $settings['general']['ancien_nom'];
	 *         unset( $settings['general']['ancien_nom'] );
	 *     }
	 *
	 *     return $settings;
	 * }
	 */
}
