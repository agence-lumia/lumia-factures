<?php
/**
 * Journalisation.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enveloppe autour de WC_Logger.
 *
 * Les erreurs sont toujours enregistrées. Les niveaux inférieurs ne le sont
 * que si le mode débogage est activé dans les réglages, pour ne pas remplir
 * le disque d'un site en production.
 */
final class Logger {

	/**
	 * Source affichée dans WooCommerce → État → Journaux.
	 */
	public const SOURCE = 'facture-pdf-lumia';

	/**
	 * Instance WooCommerce, résolue paresseusement.
	 *
	 * @var \WC_Logger_Interface|null
	 */
	private static $logger = null;

	/**
	 * Le mode débogage est-il actif ?
	 *
	 * Mis en cache pour la durée de la requête : cette méthode est appelée
	 * potentiellement des dizaines de fois par génération.
	 *
	 * @var bool|null
	 */
	private static ?bool $debug = null;

	/**
	 * Erreur : toujours journalisée.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Contexte additionnel.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context, true );
	}

	/**
	 * Avertissement : toujours journalisé.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Contexte additionnel.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context, true );
	}

	/**
	 * Information : journalisée si le débogage est actif.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Contexte additionnel.
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Débogage : journalisé si le débogage est actif.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Contexte additionnel.
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Écriture effective.
	 *
	 * @param string               $level   Niveau WC.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Contexte additionnel.
	 * @param bool                 $force   Ignorer le réglage de débogage.
	 */
	private static function log( string $level, string $message, array $context, bool $force = false ): void {
		if ( ! $force && ! self::debug_enabled() ) {
			return;
		}

		if ( array() !== $context ) {
			$message .= ' ' . wp_json_encode( $context );
		}

		$logger = self::logger();

		if ( null === $logger ) {
			return;
		}

		$logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Résout l'instance WC_Logger.
	 *
	 * @return \WC_Logger_Interface|null
	 */
	private static function logger() {
		if ( null !== self::$logger ) {
			return self::$logger;
		}

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return null;
		}

		self::$logger = wc_get_logger();

		return self::$logger;
	}

	/**
	 * Le débogage est-il activé dans les réglages ?
	 */
	private static function debug_enabled(): bool {
		if ( null !== self::$debug ) {
			return self::$debug;
		}

		// Lecture directe de l'option plutôt que via le dépôt de réglages :
		// le logger doit rester utilisable avant l'initialisation complète.
		$settings = get_option( 'fpl_settings', array() );

		self::$debug = is_array( $settings )
			&& ! empty( $settings['general']['debug_mode'] );

		return self::$debug;
	}

	/**
	 * Réinitialise le cache interne (utile en tests).
	 */
	public static function reset(): void {
		self::$debug  = null;
		self::$logger = null;
	}
}
