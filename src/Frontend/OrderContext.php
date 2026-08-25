<?php
/**
 * Résolution de la commande WooCommerce depuis le contexte front-end.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Frontend;

use WC_Order;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrouve de façon fiable la commande affichée par WooCommerce.
 *
 * Une page « Mon compte » reste techniquement le post courant dans Bricks,
 * même lorsque l'URL affiche l'endpoint « Voir la commande ». Il ne faut donc
 * jamais supposer que l'identifiant transmis par le builder est celui d'une
 * commande : on le teste, puis on se replie sur les query vars WooCommerce et,
 * en dernier recours, sur l'URL de l'endpoint configuré.
 */
final class OrderContext {

	/**
	 * Résout une commande depuis l'objet fourni par le renderer puis le contexte HTTP.
	 *
	 * @param WP_Post|WC_Order|int|mixed $subject Objet ou identifiant fourni par Bricks/WordPress.
	 */
	public static function resolve( $subject = null ): ?WC_Order {
		if ( $subject instanceof WC_Order ) {
			return $subject;
		}

		// Sur un endpoint WooCommerce, l'identifiant porté par l'URL est la
		// source la plus fiable. Bricks transmet souvent le WP_Post de la page
		// « Mon compte » ; sous HPOS son ID pourrait même coïncider avec celui
		// d'une autre commande, d'où la priorité donnée au contexte endpoint.
		$context_id = self::current_order_id();

		if ( $context_id > 0 ) {
			$context_order = wc_get_order( $context_id );

			if ( $context_order instanceof WC_Order ) {
				return $context_order;
			}
		}

		$candidate_id = self::extract_id( $subject );

		if ( $candidate_id <= 0 ) {
			return null;
		}

		$candidate = wc_get_order( $candidate_id );

		return $candidate instanceof WC_Order ? $candidate : null;
	}

	/**
	 * Identifiant de la commande portée par l'URL WooCommerce courante.
	 */
	public static function current_order_id(): int {
		$keys = array( 'view-order', 'order-received' );

		foreach ( $keys as $key ) {
			if ( function_exists( 'get_query_var' ) ) {
				$value = absint( get_query_var( $key, 0 ) );

				if ( $value > 0 ) {
					return $value;
				}
			}
		}

		global $wp;

		if ( is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ) {
			foreach ( $keys as $key ) {
				if ( ! empty( $wp->query_vars[ $key ] ) ) {
					return absint( $wp->query_vars[ $key ] );
				}
			}
		}

		return self::order_id_from_request_uri();
	}

	/**
	 * Clé de commande présente sur les pages invitées (notamment « commande reçue »).
	 */
	public static function current_order_key(): ?string {
		$key = '';

		if ( function_exists( 'get_query_var' ) ) {
			$key = (string) get_query_var( 'key', '' );
		}

		if ( '' === $key && isset( $_GET['key'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- clé WooCommerce utilisée uniquement pour le contrôle d'accès en lecture.
			$key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		}

		$key = trim( $key );

		return '' !== $key ? $key : null;
	}

	/**
	 * Extrait un identifiant potentiel d'un objet transmis par le renderer.
	 *
	 * @param WP_Post|int|mixed $subject Valeur fournie par WordPress/Bricks.
	 */
	private static function extract_id( $subject ): int {
		if ( $subject instanceof WP_Post ) {
			return (int) $subject->ID;
		}

		if ( is_numeric( $subject ) ) {
			return (int) $subject;
		}

		if ( is_object( $subject ) && isset( $subject->ID ) && is_numeric( $subject->ID ) ) {
			return (int) $subject->ID;
		}

		return 0;
	}

	/**
	 * Dernier repli : lit l'identifiant depuis le chemin de l'endpoint.
	 *
	 * Cela couvre notamment certains rendus Bricks où les query vars ne sont
	 * pas encore réinjectées dans le contexte du composant. Les slugs ne sont
	 * pas codés en dur : on respecte les endpoints personnalisés WooCommerce
	 * comme « voir-commande ».
	 */
	private static function order_id_from_request_uri(): int {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return 0;
		}

		$request_uri = wp_unslash( (string) $_SERVER['REQUEST_URI'] );
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( '' === $path ) {
			return 0;
		}

		$endpoints = array_filter(
			array(
				(string) get_option( 'woocommerce_myaccount_view_order_endpoint', 'view-order' ),
				(string) get_option( 'woocommerce_checkout_order_received_endpoint', 'order-received' ),
			)
		);

		foreach ( array_unique( $endpoints ) as $endpoint ) {
			$endpoint = trim( $endpoint, '/' );

			if ( '' === $endpoint ) {
				continue;
			}

			$pattern = '~/(?:' . preg_quote( $endpoint, '~' ) . ')/(\\d+)(?:/|$)~';

			if ( preg_match( $pattern, $path, $matches ) ) {
				return absint( $matches[1] );
			}
		}

		return 0;
	}
}
