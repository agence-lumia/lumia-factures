<?php
/**
 * Résolution des placeholders dans les textes libres.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Placeholders;

use Lumia\FacturePDF\Documents\Document;
use Lumia\FacturePDF\Settings\SettingsRepository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remplace les tags `{xxx}` dans un texte par leur valeur réelle.
 *
 * Utilisé partout où l'utilisateur saisit du texte libre : message de
 * remerciement, pied de page, mentions légales. Un même texte peut ainsi
 * s'adapter à chaque commande sans réécriture — « Merci {customer_first_name}
 * pour votre confiance » plutôt qu'un message générique.
 */
final class PlaceholderResolver {

	/**
	 * Résout tous les placeholders d'un texte.
	 *
	 * @param string        $text     Texte contenant d'éventuels `{tag}`.
	 * @param WC_Order      $order    Commande courante.
	 * @param Document|null $document Document courant, s'il existe.
	 */
	public static function resolve( string $text, WC_Order $order, ?Document $document = null ): string {
		if ( ! str_contains( $text, '{' ) ) {
			return $text;
		}

		$values = self::values( $order, $document );

		return (string) preg_replace_callback(
			'/\{([a-z0-9_]+)\}/i',
			static function ( array $matches ) use ( $values ): string {
				$key = $matches[1];

				return array_key_exists( $key, $values ) ? $values[ $key ] : $matches[0];
			},
			$text
		);
	}

	/**
	 * Table des valeurs disponibles pour la commande courante.
	 *
	 * @param WC_Order      $order    Commande courante.
	 * @param Document|null $document Document courant, s'il existe.
	 *
	 * @return array<string, string>
	 */
	private static function values( WC_Order $order, ?Document $document ): array {
		$settings = SettingsRepository::instance();

		$values = array(
			// Commande.
			'order_number'       => (string) $order->get_order_number(),
			'order_date'         => self::formatted_order_date( $order ),
			'order_total'        => self::formatted_total( $order ),
			'payment_method'     => (string) $order->get_payment_method_title(),
			'shipping_method'    => self::shipping_method( $order ),
			'order_items_count'  => (string) self::items_count( $order ),

			// Facture.
			'invoice_number'     => null !== $document ? $document->get_number() : '',
			'invoice_date'       => null !== $document ? $document->get_formatted_date() : '',
			'due_date'           => '',

			// Client.
			'customer_first_name' => (string) $order->get_billing_first_name(),
			'customer_last_name'  => (string) $order->get_billing_last_name(),
			'customer_full_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'customer_company'    => (string) $order->get_billing_company(),
			'customer_email'      => (string) $order->get_billing_email(),
			'customer_phone'      => (string) $order->get_billing_phone(),

			// Boutique — lus dans l'onglet Entreprise, respectant chaque
			// interrupteur : un champ désactivé n'a pas plus de raison
			// d'apparaître dans un texte libre qu'ailleurs sur le document.
			'shop_name'          => $settings->get_if_enabled( 'company', 'display_name' ),
			'shop_legal_name'    => $settings->get_if_enabled( 'company', 'legal_name' ),
			'shop_manager'       => $settings->get_if_enabled( 'company', 'manager' ),
			'shop_address'       => $settings->get_if_enabled( 'company', 'address' ),
			'shop_phone'         => $settings->get_if_enabled( 'company', 'phone' ),
			'shop_mobile'        => $settings->get_if_enabled( 'company', 'mobile' ),
			'shop_email'         => $settings->get_if_enabled( 'company', 'email' ),
			'shop_website'       => $settings->get_if_enabled( 'company', 'website' ),
			'shop_siret'         => $settings->get_if_enabled( 'company', 'siret' ),
			'shop_vat'           => $settings->get_if_enabled( 'company', 'vat_number' ),

			// Divers.
			'current_date'       => wp_date( (string) get_option( 'date_format', 'd/m/Y' ) ),
			'current_year'       => wp_date( 'Y' ),
		);

		/**
		 * Filtre la table des placeholders disponibles.
		 *
		 * Point d'extension pour ajouter un tag personnalisé, exploitable
		 * partout où `PlaceholderResolver::resolve()` est appelée.
		 *
		 * @param array<string, string> $values   Valeurs résolues.
		 * @param WC_Order              $order    Commande courante.
		 * @param Document|null         $document Document courant.
		 */
		return (array) apply_filters( 'fpl_placeholders', $values, $order, $document );
	}

	/**
	 * Quantité totale d'articles de la commande.
	 *
	 * Passe par `get_quantity()` explicitement plutôt que par un utilitaire
	 * de type "pluck" : ces derniers attendent une propriété publique ou une
	 * clé de tableau, alors qu'un article de commande n'expose sa quantité
	 * que via une méthode.
	 *
	 * @param WC_Order $order Commande courante.
	 */
	private static function items_count( WC_Order $order ): int {
		$count = 0;

		foreach ( $order->get_items() as $item ) {
			if ( is_callable( array( $item, 'get_quantity' ) ) ) {
				$count += (int) $item->get_quantity();
			}
		}

		return $count;
	}

	/**
	 * Date de commande formatée selon les réglages du site.
	 *
	 * @param WC_Order $order Commande courante.
	 */
	private static function formatted_order_date( WC_Order $order ): string {
		$date = $order->get_date_created();

		if ( ! $date ) {
			return '';
		}

		return wp_date( (string) get_option( 'date_format', 'd/m/Y' ), $date->getTimestamp() );
	}

	/**
	 * Total de la commande formaté dans sa devise.
	 *
	 * @param WC_Order $order Commande courante.
	 */
	private static function formatted_total( WC_Order $order ): string {
		if ( ! function_exists( 'wc_price' ) ) {
			return (string) $order->get_total();
		}

		return html_entity_decode(
			wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ),
			ENT_QUOTES,
			'UTF-8'
		);
	}

	/**
	 * Libellé de la méthode de livraison.
	 *
	 * @param WC_Order $order Commande courante.
	 */
	private static function shipping_method( WC_Order $order ): string {
		if ( ! is_callable( array( $order, 'get_shipping_methods' ) ) ) {
			return '';
		}

		foreach ( $order->get_shipping_methods() as $method ) {
			if ( is_callable( array( $method, 'get_method_title' ) ) ) {
				return (string) $method->get_method_title();
			}
		}

		return '';
	}
}
