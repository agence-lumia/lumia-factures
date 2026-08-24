<?php
/**
 * Construction du contexte de rendu.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Rendering;

use Lumia\FacturePDF\Documents\Document;
use Lumia\FacturePDF\Placeholders\PlaceholderResolver;
use Lumia\FacturePDF\Settings\SettingsRepository;
use WC_Order;
use WC_Order_Item_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transforme une commande WooCommerce en données prêtes à afficher.
 *
 * Toute la logique métier est concentrée ici : les templates ne font que
 * boucler et afficher. C'est ce qui permet à quelqu'un de personnaliser un
 * template sans comprendre le fonctionnement interne de WooCommerce, et de
 * garantir que deux templates différents affichent les mêmes montants.
 *
 * Chaque champ respecte son interrupteur : une valeur désactivée n'arrive pas
 * dans le contexte, plutôt que d'être masquée à l'affichage. Le template n'a
 * donc jamais à tester deux conditions pour un même champ.
 */
final class ContextBuilder {

	/**
	 * Document source.
	 */
	private Document $document;

	/**
	 * Commande associée.
	 */
	private WC_Order $order;

	/**
	 * Réglages.
	 */
	private SettingsRepository $settings;

	/**
	 * Les prix affichés incluent-ils la taxe ?
	 */
	private bool $incl_tax;

	/**
	 * Constructeur.
	 *
	 * @param Document $document Document à rendre.
	 */
	public function __construct( Document $document ) {
		$this->document = $document;
		$this->order    = $document->get_order();
		$this->settings = SettingsRepository::instance();
		$this->incl_tax = 'incl' === (string) $this->settings->get( 'content.price_display', 'incl' );
	}

	/**
	 * Contexte complet.
	 *
	 * @return array<string, mixed>
	 */
	public function build(): array {
		$context = array(
			'document' => $this->document,
			'order'    => $this->order,
			'settings' => $this->settings,
			'incl_tax' => $this->incl_tax,
			'header'   => $this->header(),
			'shop'     => $this->shop(),
			'customer' => $this->customer(),
			'shipping' => $this->shipping(),
			'meta'     => $this->meta_rows(),
			'message'  => $this->message(),
			'columns'  => $this->columns(),
			'items'    => $this->items(),
			'totals'   => $this->totals(),
			'notes'    => $this->notes(),
			'footer'   => $this->footer(),
			'legal'    => $this->legal_mentions(),
		);

		/**
		 * Filtre le contexte de rendu.
		 *
		 * Point d'extension principal pour injecter des données propres à un
		 * client sans modifier le template.
		 *
		 * @param array<string, mixed> $context  Contexte assemblé.
		 * @param Document             $document Document rendu.
		 * @param WC_Order             $order    Commande associée.
		 */
		return (array) apply_filters( 'fpl_document_context', $context, $this->document, $this->order );
	}

	/**
	 * En-tête : logo et titre du document.
	 *
	 * @return array<string, mixed>
	 */
	private function header(): array {
		return array(
			'logo'   => $this->logo(),
			'title'  => $this->document->get_title(),
			'number' => $this->document->get_number(),
		);
	}

	/**
	 * Logo encodé en base64.
	 *
	 * Passe par `ImageEncoder::for_attachment()`, qui tente d'abord le
	 * fichier local puis, s'il est introuvable (stockage déporté, CDN),
	 * récupère l'image par son URL. Voir la documentation de cette classe.
	 *
	 * @return array{src: string, width: int}|null
	 */
	private function logo(): ?array {
		if ( ! $this->settings->is_enabled( 'company.logo_enabled' ) ) {
			return null;
		}

		$attachment_id = (int) $this->settings->get( 'company.logo_id', 0 );

		if ( $attachment_id <= 0 ) {
			return null;
		}

		$source = ImageEncoder::for_attachment( $attachment_id, 0, 'logo' );

		if ( '' === $source ) {
			return null;
		}

		return array(
			'src'   => $source,
			'width' => (int) $this->settings->get( 'company.logo_width', 180 ),
		);
	}

	/**
	 * Informations de la boutique, champs désactivés exclus.
	 *
	 * @return array<int, array{key: string, label: string, value: string}>
	 */
	private function shop(): array {
		$fields = array(
			'display_name' => '',
			'legal_name'   => __( 'Société', 'facture-pdf-lumia' ),
			'legal_form'   => '',
			'manager'      => '',
			'address'      => '',
			'phone'        => __( 'Tél', 'facture-pdf-lumia' ),
			'mobile'       => __( 'Mob', 'facture-pdf-lumia' ),
			'email'        => '',
			'website'      => '',
		);

		$rows = array();

		foreach ( $fields as $key => $label ) {
			$value = $this->settings->get_if_enabled( 'company', $key );

			if ( '' === $value ) {
				continue;
			}

			$rows[] = array(
				'key'   => $key,
				'label' => $label,
				'value' => $value,
			);
		}

		return $rows;
	}

	/**
	 * Adresse de facturation et coordonnées client.
	 *
	 * @return array<string, mixed>
	 */
	private function customer(): array {
		$data = array(
			'title'   => (string) $this->settings->get( 'content.billing_title', __( 'Facturer à :', 'facture-pdf-lumia' ) ),
			'address' => '',
			'email'   => '',
			'phone'   => '',
		);

		if ( $this->settings->is_enabled( 'content.billing_address_enabled', true ) ) {
			$data['address'] = $this->format_address( (string) $this->order->get_formatted_billing_address() );
		}

		if ( $this->settings->is_enabled( 'content.billing_email_enabled', true ) ) {
			$data['email'] = $this->order->get_billing_email();
		}

		if ( $this->settings->is_enabled( 'content.billing_phone_enabled', true ) ) {
			$data['phone'] = $this->order->get_billing_phone();
		}

		return $data;
	}

	/**
	 * Adresse de livraison.
	 *
	 * @return array<string, mixed>
	 */
	private function shipping(): array {
		$data = array(
			'show'    => false,
			'title'   => (string) $this->settings->get( 'content.shipping_title', __( 'Livrer à :', 'facture-pdf-lumia' ) ),
			'address' => '',
			'phone'   => '',
		);

		if ( ! $this->settings->is_enabled( 'content.shipping_enabled', true ) ) {
			return $data;
		}

		$address = $this->order->get_formatted_shipping_address();

		if ( '' === trim( (string) $address ) ) {
			return $data;
		}

		// Une commande entièrement dématérialisée n'a pas de livraison à
		// afficher, même si WooCommerce a recopié l'adresse de facturation.
		if ( $this->settings->is_enabled( 'content.shipping_hide_virtual', true ) && ! $this->order->needs_shipping_address() ) {
			return $data;
		}

		// Répéter à l'identique l'adresse de facturation n'apporte rien et
		// occupe une colonne entière.
		if ( $this->settings->is_enabled( 'content.shipping_hide_if_same', true ) ) {
			$billing = $this->order->get_formatted_billing_address();

			if ( $this->normalize( (string) $address ) === $this->normalize( (string) $billing ) ) {
				return $data;
			}
		}

		$data['show']    = true;
		$data['address'] = $this->format_address( (string) $address );

		if ( $this->settings->is_enabled( 'content.shipping_phone_enabled', false ) ) {
			$data['phone'] = $this->order->get_shipping_phone();
		}

		return $data;
	}

	/**
	 * Métadonnées du document : numéros et dates.
	 *
	 * @return array<int, array{key: string, label: string, value: string}>
	 */
	private function meta_rows(): array {
		$rows = array();

		$add = function ( string $key, string $setting, string $label, string $value ) use ( &$rows ): void {
			if ( ! $this->settings->is_enabled( 'content.' . $setting, true ) || '' === $value ) {
				return;
			}

			$rows[] = array(
				'key'   => $key,
				'label' => (string) $this->settings->get( 'content.' . $key . '_label', $label ),
				'value' => $value,
			);
		};

		$add(
			'invoice_number',
			'meta_invoice_number_enabled',
			__( 'N° de facture :', 'facture-pdf-lumia' ),
			$this->document->get_number()
		);

		$add(
			'invoice_date',
			'meta_invoice_date_enabled',
			__( 'Date de facture :', 'facture-pdf-lumia' ),
			$this->document->get_formatted_date()
		);

		// Une commande de démonstration n'est jamais enregistrée : son
		// identifiant reste à 0, et afficher « N° de commande : 0 » sur
		// l'aperçu ressemblerait à une erreur plutôt qu'à un exemple.
		if ( $this->order->get_id() > 0 ) {
			$add(
				'order_number',
				'meta_order_number_enabled',
				__( 'N° de commande :', 'facture-pdf-lumia' ),
				(string) $this->order->get_order_number()
			);
		}

		$order_date = $this->order->get_date_created();

		$add(
			'order_date',
			'meta_order_date_enabled',
			__( 'Date de commande :', 'facture-pdf-lumia' ),
			$order_date ? wp_date( (string) get_option( 'date_format', 'j F Y' ), $order_date->getTimestamp() ) : ''
		);

		$add(
			'payment_method',
			'meta_payment_method_enabled',
			__( 'Méthode de paiement :', 'facture-pdf-lumia' ),
			(string) $this->order->get_payment_method_title()
		);

		return $rows;
	}

	/**
	 * Message de remerciement.
	 *
	 * @return array{title: string, body: string}|null
	 */
	private function message(): ?array {
		if ( ! $this->settings->is_enabled( 'content.message_enabled', true ) ) {
			return null;
		}

		$title = trim( (string) $this->settings->get( 'content.message_title', '' ) );
		$body  = trim( (string) $this->settings->get( 'content.message_body', '' ) );

		if ( '' === $title && '' === $body ) {
			return null;
		}

		return array(
			'title' => PlaceholderResolver::resolve( $title, $this->order, $this->document ),
			'body'  => PlaceholderResolver::resolve( $body, $this->order, $this->document ),
		);
	}

	/**
	 * Colonnes actives du tableau produits.
	 *
	 * @return array<int, array{key: string, label: string, width: int, align: string}>
	 */
	private function columns(): array {
		$definitions = array(
			'image'       => array( '', 8, 'left' ),
			'name'        => array( __( 'Produit', 'facture-pdf-lumia' ), 22, 'left' ),
			'description' => array( __( 'Description', 'facture-pdf-lumia' ), 30, 'left' ),
			'sku'         => array( __( 'Référence', 'facture-pdf-lumia' ), 10, 'left' ),
			'quantity'    => array( __( 'Qté', 'facture-pdf-lumia' ), 6, 'center' ),
			'unit_price'  => array( __( 'Prix unitaire', 'facture-pdf-lumia' ), 12, 'right' ),
			'tax_rate'    => array( __( 'TVA', 'facture-pdf-lumia' ), 8, 'center' ),
			'total'       => array( __( 'Total', 'facture-pdf-lumia' ), 14, 'right' ),
		);

		$columns = array();

		foreach ( $definitions as $key => $definition ) {
			list( $label, $width, $align ) = $definition;

			if ( ! $this->settings->is_enabled( 'content.col_' . $key . '_enabled' ) ) {
				continue;
			}

			$columns[] = array(
				'key'   => $key,
				'label' => (string) $this->settings->get( 'content.col_' . $key . '_label', $label ),
				'width' => (int) $this->settings->get( 'content.col_' . $key . '_width', $width ),
				'align' => $align,
			);
		}

		/**
		 * Filtre les colonnes du tableau produits.
		 *
		 * @param array<int, array<string, mixed>> $columns  Colonnes actives.
		 * @param Document                         $document Document rendu.
		 */
		return (array) apply_filters( 'fpl_invoice_columns', $columns, $this->document );
	}

	/**
	 * Lignes de produits.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function items(): array {
		$items = array();

		foreach ( $this->order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product  = $item->get_product();
			$quantity = (int) $item->get_quantity();

			$subtotal = (float) $item->get_subtotal();
			$total    = (float) $item->get_total();
			$tax      = (float) $item->get_total_tax();

			$line_total = $this->incl_tax ? $total + $tax : $total;
			$unit_price = $quantity > 0 ? $line_total / $quantity : $line_total;

			$data = array(
				'id'          => $item_id,
				'image'       => $this->item_image( $product ),
				'name'        => $item->get_name(),
				'description' => $this->item_description( $product ),
				'sku'         => $product ? (string) $product->get_sku() : '',
				'quantity'    => $quantity,
				'unit_price'  => $this->price( $unit_price ),
				'tax_rate'    => $this->item_tax_rate( $item ),
				'tax_amount'  => $this->price( $tax ),
				'total'       => $this->price( $line_total ),
				'meta'        => $this->item_meta( $item ),
				'discounted'  => $subtotal > $total,
				'raw'         => $item,
			);

			/**
			 * Filtre les données d'une ligne de produit.
			 *
			 * @param array<string, mixed> $data Données de la ligne.
			 * @param object               $item Article de commande.
			 * @param WC_Order             $order Commande.
			 */
			$items[] = (array) apply_filters( 'fpl_invoice_item_data', $data, $item, $this->order );
		}

		/**
		 * Filtre l'ensemble des lignes de produits.
		 *
		 * @param array<int, array<string, mixed>> $items Lignes.
		 * @param WC_Order                         $order Commande.
		 */
		return (array) apply_filters( 'fpl_invoice_items', $items, $this->order );
	}

	/**
	 * Vignette d'un produit.
	 *
	 * @param object|false $product Produit WooCommerce.
	 */
	private function item_image( $product ): string {
		if ( ! $this->settings->is_enabled( 'content.col_image_enabled' ) || ! $product ) {
			return '';
		}

		$attachment_id = (int) $product->get_image_id();

		if ( $attachment_id <= 0 && method_exists( $product, 'get_parent_id' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );

			$attachment_id = $parent ? (int) $parent->get_image_id() : 0;
		}

		if ( $attachment_id <= 0 ) {
			return '';
		}

		$width = (int) $this->settings->get( 'design.thumb_width', 46 ) * 3;

		return ImageEncoder::for_attachment(
			$attachment_id,
			$width,
			method_exists( $product, 'get_id' ) ? ( 'produit #' . $product->get_id() ) : ''
		);
	}

	/**
	 * Description courte d'un produit.
	 *
	 * @param object|false $product Produit WooCommerce.
	 */
	private function item_description( $product ): string {
		if ( ! $product ) {
			return '';
		}

		$description = (string) $product->get_short_description();

		if ( '' === trim( $description ) && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );

			$description = $parent ? (string) $parent->get_short_description() : '';
		}

		return trim( wp_strip_all_tags( $description ) );
	}

	/**
	 * Taux de TVA appliqué à une ligne.
	 *
	 * Le taux est lu dans les taxes enregistrées sur la ligne de commande, via
	 * l'identifiant de taux que WooCommerce y conserve. C'est la seule source
	 * exacte : déduire le taux d'une division `taxe / total` donne un résultat
	 * faux dès qu'un arrondi intervient (on obtient 19,9 % ou 20,1 % au lieu
	 * de 20 %), et complètement faux si les prix sont saisis taxe incluse.
	 *
	 * Le calcul par division ne sert que de repli, pour une commande importée
	 * d'un autre système sans identifiant de taux exploitable.
	 *
	 * @param object $item Article de commande.
	 */
	private function item_tax_rate( $item ): string {
		$rate = $this->lookup_tax_rate( $item );

		if ( null === $rate ) {
			$rate = $this->derive_tax_rate( $item );
		}

		if ( null === $rate ) {
			return '';
		}

		return $this->format_rate( $rate );
	}

	/**
	 * Recherche le taux réel via l'identifiant de taux WooCommerce.
	 *
	 * @param object $item Article de commande.
	 */
	private function lookup_tax_rate( $item ): ?float {
		if ( ! class_exists( 'WC_Tax' ) || ! is_callable( array( $item, 'get_taxes' ) ) ) {
			return null;
		}

		$taxes = $item->get_taxes();

		if ( empty( $taxes['total'] ) || ! is_array( $taxes['total'] ) ) {
			return null;
		}

		foreach ( $taxes['total'] as $rate_id => $amount ) {
			// Une ligne peut porter plusieurs taux dont certains à zéro : on
			// retient le premier réellement appliqué.
			if ( '' === $amount || abs( (float) $amount ) < 0.0001 ) {
				continue;
			}

			if ( ! is_callable( array( 'WC_Tax', 'get_rate_percent_value' ) ) ) {
				return null;
			}

			$percent = \WC_Tax::get_rate_percent_value( $rate_id );

			if ( is_numeric( $percent ) ) {
				return (float) $percent;
			}
		}

		return null;
	}

	/**
	 * Repli : déduit le taux des montants de la ligne.
	 *
	 * @param object $item Article de commande.
	 */
	private function derive_tax_rate( $item ): ?float {
		$total = (float) $item->get_total();
		$tax   = (float) $item->get_total_tax();

		if ( $total <= 0.0 || $tax <= 0.0 ) {
			return null;
		}

		$rate = ( $tax / $total ) * 100;

		// Aligne sur le taux normalisé le plus proche quand l'écart relève
		// manifestement de l'arrondi, plutôt que d'imprimer « 19,97 % ».
		foreach ( array( 20.0, 10.0, 5.5, 2.1, 8.5, 13.0, 6.0, 21.0, 19.0 ) as $known ) {
			if ( abs( $rate - $known ) < 0.35 ) {
				return $known;
			}
		}

		return round( $rate, 1 );
	}

	/**
	 * Formate un taux pour l'affichage.
	 *
	 * @param float $rate Taux en pourcentage.
	 */
	private function format_rate( float $rate ): string {
		$formatted = 0.0 === fmod( $rate, 1.0 )
			? (string) (int) $rate
			: rtrim( rtrim( number_format( $rate, 2, ',', '' ), '0' ), ',' );

		return $formatted . ' %';
	}

	/**
	 * Métadonnées de variation, en texte simple.
	 *
	 * @param object $item Article de commande.
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private function item_meta( $item ): array {
		if ( ! $this->settings->is_enabled( 'content.item_meta_enabled', true ) ) {
			return array();
		}

		$meta = array();

		foreach ( $item->get_formatted_meta_data() as $entry ) {
			$meta[] = array(
				'label' => wp_strip_all_tags( (string) $entry->display_key ),
				'value' => trim( wp_strip_all_tags( (string) $entry->display_value ) ),
			);
		}

		return $meta;
	}

	/**
	 * Bloc des totaux.
	 *
	 * @return array<int, array{key: string, label: string, value: string, grand: bool}>
	 */
	private function totals(): array {
		$rows = array();

		$add = function ( string $key, string $setting, string $label, string $value, bool $grand = false ) use ( &$rows ): void {
			if ( ! $this->settings->is_enabled( 'content.' . $setting, true ) || '' === $value ) {
				return;
			}

			$rows[] = array(
				'key'   => $key,
				'label' => (string) $this->settings->get( 'content.total_' . $key . '_label', $label ),
				'value' => $value,
				'grand' => $grand,
			);
		};

		// Sous-total.
		$subtotal = (float) $this->order->get_subtotal();

		if ( $this->incl_tax ) {
			$subtotal += (float) $this->order->get_total_tax() - (float) $this->order->get_shipping_tax();
		}

		$add( 'subtotal', 'total_subtotal_enabled', __( 'Sous-total', 'facture-pdf-lumia' ), $this->price( $subtotal ) );

		// Remise.
		$discount = (float) $this->order->get_discount_total();

		if ( $this->incl_tax ) {
			$discount += (float) $this->order->get_discount_tax();
		}

		if ( $discount > 0.0 ) {
			$add( 'discount', 'total_discount_enabled', __( 'Remise', 'facture-pdf-lumia' ), '-' . $this->price( $discount ) );
		}

		// Livraison.
		$shipping = (float) $this->order->get_shipping_total();

		if ( $this->incl_tax ) {
			$shipping += (float) $this->order->get_shipping_tax();
		}

		if ( $shipping > 0.0 || $this->settings->is_enabled( 'content.total_shipping_always', false ) ) {
			$add( 'shipping', 'total_shipping_enabled', __( 'Livraison', 'facture-pdf-lumia' ), $this->price( $shipping ) );
		}

		// Frais.
		$fees = 0.0;

		foreach ( $this->order->get_fees() as $fee ) {
			$fees += (float) $fee->get_total();

			if ( $this->incl_tax ) {
				$fees += (float) $fee->get_total_tax();
			}
		}

		if ( abs( $fees ) > 0.001 ) {
			$add( 'fees', 'total_fees_enabled', __( 'Frais', 'facture-pdf-lumia' ), $this->price( $fees ) );
		}

		// TVA, détaillée par taux.
		if ( $this->settings->is_enabled( 'content.total_tax_enabled', true ) ) {
			foreach ( $this->tax_breakdown() as $line ) {
				$rows[] = array(
					'key'   => 'tax',
					'label' => $line['label'],
					'value' => $line['value'],
					'grand' => false,
				);
			}
		}

		// Total.
		$add( 'total', 'total_grand_enabled', __( 'Total', 'facture-pdf-lumia' ), $this->price( (float) $this->order->get_total() ), true );

		// Remboursements.
		$refunded = (float) $this->order->get_total_refunded();

		if ( $refunded > 0.0 && $this->settings->is_enabled( 'content.total_refunded_enabled', true ) ) {
			$add( 'refunded', 'total_refunded_enabled', __( 'Remboursé', 'facture-pdf-lumia' ), '-' . $this->price( $refunded ) );
			$add( 'net', 'total_refunded_enabled', __( 'Net payé', 'facture-pdf-lumia' ), $this->price( (float) $this->order->get_total() - $refunded ), true );
		}

		/**
		 * Filtre les lignes de totaux.
		 *
		 * @param array<int, array<string, mixed>> $rows  Lignes.
		 * @param WC_Order                         $order Commande.
		 */
		return (array) apply_filters( 'fpl_invoice_totals', $rows, $this->order );
	}

	/**
	 * Ventilation de la TVA par taux.
	 *
	 * Une commande peut mélanger plusieurs taux — 5,5 % sur l'alimentaire,
	 * 20 % sur le reste. Les agréger en une seule ligne rendrait la facture
	 * incontrôlable pour un comptable.
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private function tax_breakdown(): array {
		$lines = array();

		$tax_totals = $this->order->get_tax_totals();

		// Une commande non enregistrée (aperçu de démonstration) n'a pas de
		// lignes de taxe rattachées à des taux réels : sans ce repli, le bloc
		// des totaux perdrait sa ventilation et l'aperçu serait trompeur.
		if ( array() === $tax_totals && 0 === $this->order->get_id() ) {
			/**
			 * Ventilation de TVA de substitution pour un aperçu.
			 *
			 * @param array<string, object> $tax_totals Ventilation injectée.
			 */
			$tax_totals = (array) apply_filters( 'fpl_demo_tax_totals', array() );
		}

		foreach ( $tax_totals as $tax ) {
			$rate  = isset( $tax->rate ) ? (float) $tax->rate : null;
			$label = (string) $tax->label;

			// Le libellé est reconstruit à partir du taux plutôt que repris
			// tel quel : celui de WooCommerce dépend du nom donné au taux en
			// base, souvent hétérogène d'un site à l'autre. Le formatage passe
			// par `format_rate()`, le même que la colonne TVA du tableau, pour
			// éviter qu'un taux s'affiche « 5,5 % » en colonne et « 5.5% » en
			// totaux sur le même document.
			if ( null !== $rate ) {
				$label = sprintf(
					/* translators: %s: taux de TVA formaté, unité comprise */
					__( 'TVA %s', 'facture-pdf-lumia' ),
					$this->format_rate( $rate )
				);
			}

			$lines[] = array(
				'label' => $label,
				'value' => $this->price( (float) $tax->amount ),
			);
		}

		return $lines;
	}

	/**
	 * Notes client et internes.
	 *
	 * @return array<int, array{title: string, body: string}>
	 */
	private function notes(): array {
		$notes = array();

		if ( $this->settings->is_enabled( 'content.customer_note_enabled', true ) ) {
			$note = trim( (string) $this->order->get_customer_note() );

			if ( '' !== $note ) {
				$notes[] = array(
					'title' => (string) $this->settings->get( 'content.customer_note_label', __( 'Note du client', 'facture-pdf-lumia' ) ),
					'body'  => $note,
				);
			}
		}

		return $notes;
	}

	/**
	 * Pied de page : texte libre et mentions de contact.
	 *
	 * @return array<string, mixed>
	 */
	private function footer(): array {
		$lines = array();

		$legal = array(
			'phone'         => __( 'Tel', 'facture-pdf-lumia' ),
			'mobile'        => __( 'Mob', 'facture-pdf-lumia' ),
			'email'         => '',
			'website'       => '',
			'siret'         => __( 'SIRET', 'facture-pdf-lumia' ),
			'rcs'           => __( 'RCS', 'facture-pdf-lumia' ),
			'vat_number'    => __( 'TVA', 'facture-pdf-lumia' ),
			'share_capital' => __( 'Capital', 'facture-pdf-lumia' ),
			'ape_code'      => __( 'APE', 'facture-pdf-lumia' ),
			'iban'          => __( 'IBAN', 'facture-pdf-lumia' ),
			'bic'           => __( 'BIC', 'facture-pdf-lumia' ),
		);

		foreach ( $legal as $key => $label ) {
			if ( ! $this->settings->is_enabled( 'content.footer_' . $key . '_enabled' ) ) {
				continue;
			}

			$value = $this->settings->get_if_enabled( 'company', $key );

			if ( '' === $value ) {
				continue;
			}

			$lines[] = '' !== $label ? $label . ' : ' . $value : $value;
		}

		return array(
			'text'       => PlaceholderResolver::resolve(
				trim( (string) $this->settings->get( 'content.footer_text', '' ) ),
				$this->order,
				$this->document
			),
			'lines'      => $lines,
			'pagination' => $this->settings->is_enabled( 'content.footer_pagination_enabled', true ),
		);
	}

	/**
	 * Mentions légales activées, groupées par emplacement.
	 *
	 * @return array{totals: array<int, array{title: string, body: string}>, block: array<int, array{title: string, body: string}>, footer: array<int, array{title: string, body: string}>}
	 */
	private function legal_mentions(): array {
		$grouped = array(
			'totals' => array(),
			'block'  => array(),
			'footer' => array(),
		);

		$rows = (array) $this->settings->get( 'content.legal_mentions', array() );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['enabled'] ) ) {
				continue;
			}

			$title = trim( (string) ( $row['title'] ?? '' ) );
			$body  = trim( (string) ( $row['body'] ?? '' ) );

			if ( '' === $title && '' === $body ) {
				continue;
			}

			$placement = (string) ( $row['placement'] ?? 'footer' );

			if ( ! isset( $grouped[ $placement ] ) ) {
				$placement = 'footer';
			}

			$grouped[ $placement ][] = array(
				'title' => PlaceholderResolver::resolve( $title, $this->order, $this->document ),
				'body'  => PlaceholderResolver::resolve( $body, $this->order, $this->document ),
			);
		}

		return $grouped;
	}

	/**
	 * Formate un montant dans la devise de la commande.
	 *
	 * La devise est lue sur la commande, pas dans les réglages de la boutique :
	 * sur un site multidevise, une facture doit refléter la devise dans
	 * laquelle le client a effectivement payé.
	 *
	 * `wc_price()` renvoie du HTML contenant des entités littérales
	 * (`&nbsp;`, `&euro;`). `wp_strip_all_tags()` retire les balises mais ne
	 * décode pas les entités : sans ce décodage, un template qui échappe la
	 * valeur pour l'affichage (comme il le doit) transforme le `&` en
	 * `&amp;` et fait apparaître le texte littéral « &nbsp; » sur le
	 * document. Le décodage ramène la valeur à du texte brut, sûr à
	 * rééchapper normalement.
	 *
	 * @param float $amount Montant.
	 */
	private function price( float $amount ): string {
		$html = wp_strip_all_tags(
			wc_price(
				$amount,
				array( 'currency' => $this->order->get_currency() )
			)
		);

		return html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Normalise une adresse pour comparaison.
	 *
	 * @param string $address Adresse formatée.
	 */
	/**
	 * Normalise une adresse formatée pour l'affichage.
	 *
	 * `get_formatted_billing_address()` renvoie normalement une chaîne avec
	 * des `<br/>`, mais les séparateurs varient selon le format d'adresse du
	 * pays et selon les extensions qui filtrent cette sortie — certaines
	 * renvoient des sauts de ligne bruts. Sans normalisation, l'adresse
	 * s'imprime alors sur une seule ligne, ce qui est immédiatement visible
	 * sur le document.
	 *
	 * @param string $address Adresse telle que fournie par WooCommerce.
	 */
	private function format_address( string $address ): string {
		if ( '' === trim( $address ) ) {
			return '';
		}

		// Uniformise les variantes de balise de saut de ligne.
		$address = (string) preg_replace( '#<br\s*/?>#i', "\n", $address );
		$address = str_replace( array( "\r\n", "\r" ), "\n", $address );

		$lines = array_filter(
			array_map( 'trim', explode( "\n", wp_strip_all_tags( $address ) ) ),
			static fn( string $line ): bool => '' !== $line
		);

		return implode( '<br>', array_map( 'esc_html', $lines ) );
	}

	private function normalize( string $address ): string {
		$plain = wp_strip_all_tags( str_replace( array( '<br/>', '<br>', '<br />' ), ' ', $address ) );

		return strtolower( (string) preg_replace( '/\s+/', ' ', trim( $plain ) ) );
	}
}
