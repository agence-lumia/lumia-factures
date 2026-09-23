<?php
/**
 * Commande de démonstration pour l'aperçu.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Admin;

use WC_Order;
use WC_Order_Item_Coupon;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Construit une commande fictive complète, sans rien enregistrer.
 *
 * L'objet est monté en mémoire et jamais sauvegardé : aucune ligne n'apparaît
 * dans la liste des commandes, aucun stock n'est décrémenté, aucun email
 * n'est déclenché.
 *
 * Le jeu de données est volontairement le pire cas raisonnable — deux taux de
 * TVA, une remise, des frais, une adresse de livraison distincte, une note
 * client, une variation de produit — parce qu'un aperçu sur une commande
 * simple ne révèle jamais les problèmes de mise en page.
 */
final class DemoOrder {

	/**
	 * Construit la commande de démonstration.
	 */
	public static function build(): WC_Order {
		$order = new WC_Order();

		$order->set_currency( get_woocommerce_currency() );
		$order->set_status( 'completed' );
		$order->set_payment_method_title( __( 'Carte bancaire', 'facture-pdf-lumia' ) );
		$order->set_customer_note( __( 'Merci de livrer en point relais si je suis absente.', 'facture-pdf-lumia' ) );

		self::set_addresses( $order );
		self::add_items( $order );

		return $order;
	}

	/**
	 * Renseigne les adresses de facturation et de livraison.
	 *
	 * Les deux diffèrent volontairement : c'est la seule façon de vérifier le
	 * comportement du bloc livraison et de sa règle « masquer si identique ».
	 *
	 * @param WC_Order $order Commande en construction.
	 */
	private static function set_addresses( WC_Order $order ): void {
		$order->set_billing_first_name( 'Camille' );
		$order->set_billing_last_name( 'Dupont' );
		$order->set_billing_company( 'Atelier Dupont' );
		$order->set_billing_address_1( '18 rue des Lilas' );
		$order->set_billing_address_2( 'Bâtiment B, 3e étage' );
		$order->set_billing_postcode( '44000' );
		$order->set_billing_city( 'Nantes' );
		$order->set_billing_country( 'FR' );
		$order->set_billing_email( 'camille.dupont@example.com' );
		$order->set_billing_phone( '+33 6 12 34 56 78' );

		$order->set_shipping_first_name( 'Camille' );
		$order->set_shipping_last_name( 'Dupont' );
		$order->set_shipping_address_1( '5 place du Commerce' );
		$order->set_shipping_postcode( '44100' );
		$order->set_shipping_city( 'Nantes' );
		$order->set_shipping_country( 'FR' );
	}

	/**
	 * Ajoute articles, livraison et frais.
	 *
	 * @param WC_Order $order Commande en construction.
	 */
	private static function add_items( WC_Order $order ): void {
		$lines = array(
			array(
				'name'     => __( 'Bougie parfumée Ambre & Bois de Santal', 'facture-pdf-lumia' ),
				'qty'      => 2,
				'subtotal' => 39.80,
				'total'    => 35.80,
				'tax'      => 7.16,
				'tax_sub'  => 7.96,
				'meta'     => array( __( 'Contenance', 'facture-pdf-lumia' ) => '220 g' ),
			),
			array(
				'name'     => __( 'Diffuseur de parfum d\'ambiance', 'facture-pdf-lumia' ),
				'qty'      => 1,
				'subtotal' => 28.00,
				'total'    => 28.00,
				'tax'      => 5.60,
				'tax_sub'  => 5.60,
				'meta'     => array(),
			),
			array(
				'name'     => __( 'Coffret dégustation de miels', 'facture-pdf-lumia' ),
				'qty'      => 1,
				'subtotal' => 24.00,
				'total'    => 24.00,
				'tax'      => 1.32,
				'tax_sub'  => 1.32,
				'meta'     => array(),
			),
		);

		foreach ( $lines as $line ) {
			$item = new WC_Order_Item_Product();

			$item->set_name( $line['name'] );
			$item->set_quantity( $line['qty'] );
			$item->set_subtotal( (string) $line['subtotal'] );
			$item->set_total( (string) $line['total'] );

			// `set_taxes()` plutôt que `set_total_tax()` : sur les lignes de
			// livraison et de frais, WooCommerce déclare `set_total_tax()` en
			// portée protégée pour forcer le passage par le tableau de taxes.
			// On uniformise sur les trois types de lignes.
			$item->set_taxes(
				array(
					'total'    => array( 0 => (string) $line['tax'] ),
					'subtotal' => array( 0 => (string) $line['tax_sub'] ),
				)
			);

			foreach ( $line['meta'] as $meta_key => $meta_value ) {
				$item->add_meta_data( (string) $meta_key, $meta_value );
			}

			$order->add_item( $item );
		}

		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( __( 'Livraison standard', 'facture-pdf-lumia' ) );
		$shipping->set_total( '5.90' );
		$shipping->set_taxes( array( 'total' => array( 0 => '1.18' ) ) );
		$order->add_item( $shipping );

		$fee = new WC_Order_Item_Fee();
		$fee->set_name( __( 'Emballage cadeau', 'facture-pdf-lumia' ) );
		$fee->set_total( '3.00' );
		$fee->set_taxes( array( 'total' => array( 0 => '0.60' ) ) );
		$order->add_item( $fee );

		$coupon = new WC_Order_Item_Coupon();
		$coupon->set_code( 'BIENVENUE10' );
		$coupon->set_discount( '4.00' );
		$coupon->set_discount_tax( '0.80' );
		$order->add_item( $coupon );

		$order->set_discount_total( '4.00' );
		$order->set_discount_tax( '0.80' );
		$order->set_shipping_total( '5.90' );
		$order->set_shipping_tax( '1.18' );
		$order->set_cart_tax( '14.08' );
		$order->set_total( '112.56' );
	}

	/**
	 * Ventilation de TVA à afficher pour la démonstration.
	 *
	 * Une commande non enregistrée n'a pas de lignes de taxe rattachées à des
	 * taux réels : `get_tax_totals()` renverrait un tableau vide et le bloc
	 * des totaux perdrait ses lignes de TVA, donnant un aperçu trompeur. La
	 * ventilation est donc injectée par filtre le temps de l'aperçu.
	 *
	 * @return array<string, object>
	 */
	public static function tax_totals(): array {
		$normal = new \stdClass();

		$normal->rate     = 20.0;
		$normal->label    = __( 'TVA 20 %', 'facture-pdf-lumia' );
		$normal->amount   = 14.54;
		$normal->rate_id  = 0;
		$normal->is_compound = false;
		$normal->formatted_amount = '';

		$reduced = new \stdClass();

		$reduced->rate     = 5.5;
		$reduced->label    = __( 'TVA 5,5 %', 'facture-pdf-lumia' );
		$reduced->amount   = 1.32;
		$reduced->rate_id  = 0;
		$reduced->is_compound = false;
		$reduced->formatted_amount = '';

		return array(
			'demo-20'  => $normal,
			'demo-5-5' => $reduced,
		);
	}

	/**
	 * Taux appliqué à chaque ligne de la démonstration.
	 *
	 * Le troisième article est au taux réduit, les autres au taux normal.
	 *
	 * @param int $index Rang de la ligne, à partir de 0.
	 */
	public static function line_rate( int $index ): float {
		return 2 === $index ? 5.5 : 20.0;
	}
}
