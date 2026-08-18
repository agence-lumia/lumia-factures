<?php
/**
 * Messages d'administration différés.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File de messages survivant à une redirection.
 *
 * Le nettoyage des réglages s'exécute avant la redirection de sauvegarde :
 * une erreur détectée à ce moment-là serait perdue si on l'affichait
 * directement. Les messages sont donc stockés par utilisateur, puis vidés au
 * premier affichage.
 */
final class Notices {

	/**
	 * Durée de vie d'un message en file.
	 */
	private const TTL = 60;

	/**
	 * Ajoute un message d'erreur.
	 *
	 * @param string $message Message à afficher.
	 */
	public static function add_error( string $message ): void {
		self::add( 'error', $message );
	}

	/**
	 * Ajoute un message de succès.
	 *
	 * @param string $message Message à afficher.
	 */
	public static function add_success( string $message ): void {
		self::add( 'success', $message );
	}

	/**
	 * Ajoute un avertissement.
	 *
	 * @param string $message Message à afficher.
	 */
	public static function add_warning( string $message ): void {
		self::add( 'warning', $message );
	}

	/**
	 * Empile un message.
	 *
	 * @param string $type    Type WordPress (error, success, warning, info).
	 * @param string $message Message.
	 */
	private static function add( string $type, string $message ): void {
		$queue = self::queue();

		$queue[] = array(
			'type'    => $type,
			'message' => $message,
		);

		set_transient( self::key(), $queue, self::TTL );
	}

	/**
	 * Récupère et vide la file.
	 *
	 * @return array<int, array{type: string, message: string}>
	 */
	public static function flush(): array {
		$queue = self::queue();

		delete_transient( self::key() );

		return $queue;
	}

	/**
	 * Affiche puis vide la file.
	 */
	public static function render(): void {
		foreach ( self::flush() as $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * File courante.
	 *
	 * @return array<int, array{type: string, message: string}>
	 */
	private static function queue(): array {
		$queue = get_transient( self::key() );

		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Clé de transient, propre à l'utilisateur courant.
	 *
	 * Deux administrateurs travaillant en même temps ne doivent pas hériter
	 * des messages l'un de l'autre.
	 */
	private static function key(): string {
		return 'fpl_notices_' . get_current_user_id();
	}
}
