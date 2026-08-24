<?php
/**
 * Contrôle d'accès aux documents.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Storage;

use Lumia\FacturePDF\Documents\Document;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Décide qui peut consulter un document, et à quelle fréquence.
 */
final class AccessGuard {

	/**
	 * Capacité donnant accès à toutes les factures.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Nombre maximal de générations par fenêtre.
	 */
	private const RATE_LIMIT = 10;

	/**
	 * Durée de la fenêtre de limitation, en secondes.
	 */
	private const RATE_WINDOW = 60;

	/**
	 * L'utilisateur courant peut-il accéder à ce document ?
	 *
	 * @param Document    $document Document demandé.
	 * @param string|null $key      Clé de commande fournie dans l'URL, pour un
	 *                              accès invité.
	 */
	public static function can_access( Document $document, ?string $key = null ): bool {
		$order   = $document->get_order();
		$allowed = false;

		// Gestionnaire de boutique : accès à tout.
		if ( current_user_can( self::CAPABILITY ) ) {
			$allowed = true;
		}

		// Client connecté, propriétaire de la commande. La comparaison exclut
		// l'identifiant 0 : une commande passée en invité n'appartient à
		// personne, et ne doit pas devenir accessible au premier visiteur
		// connecté venu.
		if ( ! $allowed ) {
			$customer_id = (int) $order->get_customer_id();
			$user_id     = get_current_user_id();

			if ( $customer_id > 0 && $user_id > 0 && $customer_id === $user_id ) {
				$allowed = true;
			}
		}

		// Accès invité par clé de commande, comme le fait WooCommerce pour la
		// page de confirmation. La comparaison est faite en temps constant.
		if ( ! $allowed && null !== $key && '' !== $key ) {
			$expected = (string) $order->get_order_key();

			if ( '' !== $expected && hash_equals( $expected, $key ) ) {
				$allowed = true;
			}
		}

		/**
		 * Filtre l'autorisation d'accès à un document.
		 *
		 * @param bool     $allowed  Autorisation calculée.
		 * @param Document $document Document demandé.
		 * @param WC_Order $order    Commande associée.
		 */
		return (bool) apply_filters( 'fpl_can_access_invoice', $allowed, $document, $order );
	}

	/**
	 * Le document est-il consultable, indépendamment de l'identité ?
	 *
	 * @param Document $document Document demandé.
	 */
	public static function is_ready( Document $document ): bool {
		return $document->is_available();
	}

	/**
	 * Vérifie et consomme le quota de génération.
	 *
	 * Protège contre une boucle de téléchargement, volontaire ou non : chaque
	 * génération non mise en cache mobilise Dompdf, qui est coûteux. La
	 * limite s'applique par utilisateur connecté, ou par adresse IP pour les
	 * invités.
	 */
	public static function check_rate_limit(): bool {
		$key   = 'fpl_rate_' . md5( self::requester_signature() );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );

		return true;
	}

	/**
	 * Signature de l'appelant, pour la limitation de débit.
	 */
	private static function requester_signature(): string {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			return 'user:' . $user_id;
		}

		$ip = '';

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}

		return 'ip:' . $ip;
	}
}
