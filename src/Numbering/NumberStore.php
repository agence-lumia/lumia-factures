<?php
/**
 * Attribution et persistance des numéros de document.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Numbering;

use Lumia\FacturePDF\Documents\Document;
use Lumia\FacturePDF\Settings\SettingsRepository;
use Lumia\FacturePDF\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attribue un numéro à un document et l'écrit sur la commande.
 *
 * Un numéro attribué est définitif. Aucune méthode de cette classe ne permet
 * de le modifier : c'est une contrainte légale, pas une préférence
 * d'implémentation. La seule opération de correction disponible passe par
 * l'onglet Outils, sous confirmation explicite.
 */
final class NumberStore {

	/**
	 * Suffixe de meta : numéro formaté.
	 */
	public const META_NUMBER = 'number';

	/**
	 * Suffixe de meta : valeur brute du compteur.
	 */
	public const META_SEQUENCE = 'sequence';

	/**
	 * Suffixe de meta : date d'émission.
	 */
	public const META_DATE = 'date';

	/**
	 * Suffixe de meta : clé de séquence utilisée.
	 */
	public const META_SEQUENCE_KEY = 'sequence_key';

	/**
	 * Attribue un numéro au document s'il n'en a pas déjà un.
	 *
	 * @param Document $document Document à numéroter.
	 *
	 * @return string Numéro attribué ou déjà existant, chaîne vide en cas d'échec.
	 */
	public static function assign( Document $document ): string {
		$existing = $document->get_number();

		if ( '' !== $existing ) {
			return $existing;
		}

		$order    = $document->get_order();
		$settings = SettingsRepository::instance();

		/**
		 * Permet d'empêcher l'attribution d'un numéro.
		 *
		 * Déclenché avant toute écriture. Retourner false annule l'opération
		 * sans consommer de valeur dans la séquence.
		 *
		 * @param bool     $allowed  Autorisation.
		 * @param Document $document Document concerné.
		 */
		if ( ! apply_filters( 'fpl_should_assign_number', true, $document ) ) {
			return '';
		}

		$lock = self::acquire_lock( $order->get_id(), $document->get_type() );

		if ( ! $lock ) {
			// Une autre requête numérote la même commande au même instant.
			// On attend brièvement puis on relit : le numéro sera là.
			usleep( 250000 );

			return $document->get_number();
		}

		try {
			// Relecture après verrou : la requête concurrente a pu terminer
			// entre notre premier test et l'obtention du verrou.
			$order = wc_get_order( $order->get_id() ) ?: $order;

			$already = (string) $order->get_meta( $document->meta_key( self::META_NUMBER ), true );

			if ( '' !== $already ) {
				return $already;
			}

			$format       = (string) $settings->get( 'numbering.format', 'FA-{year}-{number:4}' );
			$start        = (int) $settings->get( 'numbering.start_number', 1 );
			$sequence_key = self::sequence_key( $document );
			$sequence     = Sequence::next( $sequence_key, $start );

			if ( $sequence <= 0 ) {
				Logger::error(
					'Attribution de numéro abandonnée : séquence indisponible.',
					array(
						'order_id' => $order->get_id(),
						'key'      => $sequence_key,
					)
				);

				return '';
			}

			$issued_at = self::issue_timestamp( $document );
			$number    = NumberGenerator::build( $format, $sequence, $order, $issued_at );

			$order->update_meta_data( $document->meta_key( self::META_NUMBER ), $number );
			$order->update_meta_data( $document->meta_key( self::META_SEQUENCE ), $sequence );
			$order->update_meta_data( $document->meta_key( self::META_DATE ), $issued_at );
			$order->update_meta_data( $document->meta_key( self::META_SEQUENCE_KEY ), $sequence_key );
			$order->save();

			$order->add_order_note(
				sprintf(
					/* translators: %s: numéro de facture */
					__( 'Facture émise sous le numéro %s.', 'facture-pdf-lumia' ),
					$number
				)
			);

			Logger::info(
				'Numéro attribué.',
				array(
					'order_id' => $order->get_id(),
					'number'   => $number,
					'sequence' => $sequence,
					'key'      => $sequence_key,
				)
			);

			/**
			 * Déclenché après attribution définitive d'un numéro.
			 *
			 * @param string   $number   Numéro attribué.
			 * @param int      $sequence Valeur de séquence.
			 * @param Document $document Document concerné.
			 */
			do_action( 'fpl_invoice_number_assigned', $number, $sequence, $document );

			return $number;
		} finally {
			self::release_lock( $order->get_id(), $document->get_type() );
		}
	}

	/**
	 * Clé de séquence applicable, selon la périodicité de remise à zéro.
	 *
	 * @param Document $document Document concerné.
	 */
	public static function sequence_key( Document $document ): string {
		$settings = SettingsRepository::instance();
		$type     = $document->get_type();
		$key      = $type;

		if ( $settings->is_enabled( 'numbering.reset_enabled' ) ) {
			$period = (string) $settings->get( 'numbering.reset_period', 'yearly' );
			$time   = self::issue_timestamp( $document );

			if ( 'monthly' === $period ) {
				$key .= '_' . wp_date( 'Y_m', $time );
			} else {
				$key .= '_' . wp_date( 'Y', $time );
			}
		}

		/**
		 * Filtre la clé de séquence.
		 *
		 * @param string   $key      Clé calculée.
		 * @param Document $document Document concerné.
		 */
		return (string) apply_filters( 'fpl_sequence_key', $key, $document );
	}

	/**
	 * Horodatage d'émission.
	 *
	 * Utilise la date d'émission déjà enregistrée si elle existe, pour qu'une
	 * relecture de la clé de séquence donne toujours le même résultat.
	 *
	 * @param Document $document Document concerné.
	 */
	private static function issue_timestamp( Document $document ): int {
		$existing = $document->get_date();

		if ( null !== $existing ) {
			return $existing;
		}

		return function_exists( 'current_datetime' )
			? current_datetime()->getTimestamp()
			: time();
	}

	/**
	 * Pose un verrou applicatif sur le couple commande + type de document.
	 *
	 * Le verrou SQL de la séquence protège le compteur, mais pas la commande :
	 * sans ce second garde-fou, deux requêtes simultanées sur la même commande
	 * consommeraient deux valeurs de séquence, dont une serait perdue. Un trou
	 * dans la numérotation est un défaut sérieux pour une facturation.
	 *
	 * @param int    $order_id Identifiant de commande.
	 * @param string $type     Type de document.
	 */
	private static function acquire_lock( int $order_id, string $type ): bool {
		$key = self::lock_key( $order_id, $type );

		// `add_option` avec autoload désactivé est atomique : l'insertion
		// échoue si la clé existe déjà, sans écraser la valeur en place.
		$acquired = add_option( $key, time(), '', false );

		if ( $acquired ) {
			return true;
		}

		// Verrou périmé (processus interrompu) : on le reprend au-delà de 30 s.
		$since = (int) get_option( $key, 0 );

		if ( $since > 0 && ( time() - $since ) > 30 ) {
			update_option( $key, time(), false );

			return true;
		}

		return false;
	}

	/**
	 * Lève le verrou.
	 *
	 * @param int    $order_id Identifiant de commande.
	 * @param string $type     Type de document.
	 */
	private static function release_lock( int $order_id, string $type ): void {
		delete_option( self::lock_key( $order_id, $type ) );
	}

	/**
	 * Nom de l'option de verrou.
	 *
	 * @param int    $order_id Identifiant de commande.
	 * @param string $type     Type de document.
	 */
	private static function lock_key( int $order_id, string $type ): string {
		return 'fpl_lock_' . $type . '_' . $order_id;
	}
}
