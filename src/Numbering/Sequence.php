<?php
/**
 * Compteur séquentiel atomique.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Numbering;

use Lumia\FacturePDF\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Distribue des numéros séquentiels sans collision possible.
 *
 * Le problème à résoudre : deux commandes qui atteignent le statut déclencheur
 * dans la même seconde. Avec un compteur en option WordPress, la séquence
 * `get_option` → `+1` → `update_option` laisse une fenêtre pendant laquelle
 * les deux processus lisent la même valeur et écrivent le même numéro. Aucune
 * erreur n'est levée, rien n'apparaît dans les journaux, et le doublon ne se
 * découvre qu'au moment où quelqu'un compare les factures — potentiellement
 * lors d'un contrôle fiscal.
 *
 * La solution retenue repose sur `LAST_INSERT_ID()` de MySQL. Cette fonction
 * a une propriété précieuse : sa valeur est propre à la connexion courante.
 * On peut donc incrémenter et récupérer la valeur obtenue en une seule
 * instruction SQL, sans verrou explicite ni transaction :
 *
 *     UPDATE ... SET current_value = LAST_INSERT_ID( current_value + 1 ) WHERE ...
 *
 * L'incrémentation est atomique côté serveur MySQL, et `$wpdb->insert_id`
 * renvoie ensuite la valeur que *cette* connexion a produite, même si dix
 * autres processus ont incrémenté entre-temps.
 */
final class Sequence {

	/**
	 * Nombre de tentatives en cas de concurrence sur la création de la clé.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Nom complet de la table.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'fpl_sequences';
	}

	/**
	 * Réserve et retourne la valeur suivante de la séquence.
	 *
	 * @param string $key   Clé de séquence (ex. `invoice_2026`).
	 * @param int    $start Première valeur à distribuer si la clé est neuve.
	 *
	 * @return int Valeur réservée, ou 0 en cas d'échec irrécupérable.
	 */
	public static function next( string $key, int $start = 1 ): int {
		global $wpdb;

		$table = self::table();
		$start = max( 1, $start );
		$now   = current_time( 'mysql', true );

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			// Cas courant : la clé existe, on incrémente et on récupère la
			// valeur produite par notre propre connexion.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET current_value = LAST_INSERT_ID( current_value + 1 ), updated_at = %s WHERE sequence_key = %s",
					$now,
					$key
				)
			);

			if ( $updated ) {
				$value = (int) $wpdb->insert_id;

				if ( $value > 0 ) {
					return $value;
				}
			}

			// La clé n'existe pas encore : on la crée avec la valeur de départ.
			// `INSERT IGNORE` fait échouer silencieusement la tentative si un
			// autre processus a gagné la course — on repart alors sur l'UPDATE.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} ( sequence_key, current_value, updated_at ) VALUES ( %s, %d, %s )",
					$key,
					$start,
					$now
				)
			);

			if ( $inserted ) {
				return $start;
			}
		}

		Logger::error(
			'Impossible de réserver un numéro de séquence.',
			array(
				'key'      => $key,
				'attempts' => self::MAX_ATTEMPTS,
				'db_error' => $wpdb->last_error,
			)
		);

		return 0;
	}

	/**
	 * Lit la valeur courante sans la modifier.
	 *
	 * @param string $key Clé de séquence.
	 *
	 * @return int Valeur courante, 0 si la clé n'existe pas.
	 */
	public static function current( string $key ): int {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT current_value FROM {$table} WHERE sequence_key = %s",
				$key
			)
		);

		return null === $value ? 0 : (int) $value;
	}

	/**
	 * Force la valeur d'une séquence.
	 *
	 * Utilisé en reprise d'existant, quand la facturation a commencé ailleurs
	 * et qu'il faut reprendre la numérotation à un rang donné.
	 *
	 * @param string $key   Clé de séquence.
	 * @param int    $value Valeur à écrire (dernier numéro utilisé).
	 */
	public static function set( string $key, int $value ): bool {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->replace(
			$table,
			array(
				'sequence_key'  => $key,
				'current_value' => max( 0, $value ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s' )
		);

		if ( false === $result ) {
			Logger::error(
				'Écriture de séquence impossible.',
				array(
					'key'      => $key,
					'value'    => $value,
					'db_error' => $wpdb->last_error,
				)
			);

			return false;
		}

		return true;
	}

	/**
	 * Toutes les séquences enregistrées, pour l'affichage admin.
	 *
	 * @return array<int, array{key: string, value: int, updated_at: string}>
	 */
	public static function all(): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT sequence_key, current_value, updated_at FROM {$table} ORDER BY sequence_key ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn( array $row ): array => array(
				'key'        => (string) $row['sequence_key'],
				'value'      => (int) $row['current_value'],
				'updated_at' => (string) $row['updated_at'],
			),
			$rows
		);
	}
}
