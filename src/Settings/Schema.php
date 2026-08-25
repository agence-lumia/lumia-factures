<?php
/**
 * Définition déclarative des réglages.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Source de vérité unique des réglages du plugin.
 *
 * Chaque champ est décrit une seule fois ici. L'interface d'administration,
 * la validation, les valeurs par défaut, l'export JSON et la documentation en
 * découlent automatiquement. Ajouter un réglage = ajouter une entrée dans ce
 * tableau, sans écrire une ligne de HTML.
 *
 * Structure d'un champ :
 *
 *     'clé' => array(
 *         'type'        => 'text|textarea|richtext|number|checkbox|select|
 *                           multiselect|color|media|range|code|repeater',
 *         'label'       => 'Libellé affiché',
 *         'description' => 'Aide sous le champ',
 *         'default'     => mixed,
 *         'options'     => array,          // select, multiselect
 *         'min'/'max'/'step'               // number, range
 *         'unit'        => 'px',           // suffixe affiché
 *         'placeholder' => '',
 *         'depends'     => array( 'champ' => valeur ), // affichage conditionnel
 *         'sanitize'    => callable,       // surcharge du nettoyage par type
 *     )
 */
final class Schema {

	/**
	 * Cache du schéma résolu.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $schema = null;

	/**
	 * Schéma complet, groupé par onglet.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null !== self::$schema ) {
			return self::$schema;
		}

		$schema = array(
			'general'   => self::general(),
			'company'   => self::company(),
			'numbering' => self::numbering(),
			'content'   => self::content(),
			'design'    => self::design(),
			'preview'   => self::preview(),
			'tools'     => self::tools(),
			'documentation' => self::documentation(),
		);

		/**
		 * Filtre le schéma des réglages.
		 *
		 * Point d'extension principal pour ajouter des onglets, sections ou
		 * champs sans modifier le cœur du plugin.
		 *
		 * @param array<string, array<string, mixed>> $schema Schéma complet.
		 */
		self::$schema = (array) apply_filters( 'fpl_settings_schema', $schema );

		return self::$schema;
	}

	/**
	 * Onglet Général.
	 *
	 * @return array<string, mixed>
	 */
	private static function general(): array {
		return array(
			'label'    => __( 'Général', 'facture-pdf-lumia' ),
			'priority' => 10,
			'sections' => array(
				'generation' => array(
					'label'       => __( 'Génération', 'facture-pdf-lumia' ),
					'description' => __( 'Conditions dans lesquelles une facture peut être émise.', 'facture-pdf-lumia' ),
					'fields'      => array(
						'trigger_statuses' => array(
							'type'        => 'multiselect',
							'label'       => __( 'Statuts autorisés', 'facture-pdf-lumia' ),
							'description' => __( 'Une facture ne peut être générée que si la commande est dans l\'un de ces statuts. Le numéro est attribué définitivement à la première génération.', 'facture-pdf-lumia' ),
							'default'     => array( 'completed' ),
							'options'     => array( self::class, 'order_status_options' ),
						),
						'retention_days'   => array(
							'type'        => 'number',
							'label'       => __( 'Durée de conservation du fichier', 'facture-pdf-lumia' ),
							'description' => __( 'Nombre de jours pendant lesquels le PDF reste en cache sur le serveur. Passé ce délai il est supprimé, puis régénéré à la demande. Mettre 0 pour ne jamais mettre en cache.', 'facture-pdf-lumia' ),
							'default'     => 7,
							'min'         => 0,
							'max'         => 3650,
							'step'        => 1,
							'unit'        => __( 'jours', 'facture-pdf-lumia' ),
						),
					),
				),
				'advanced'   => array(
					'label'  => __( 'Avancé', 'facture-pdf-lumia' ),
					'fields' => array(
						'debug_mode' => array(
							'type'        => 'checkbox',
							'label'       => __( 'Mode débogage', 'facture-pdf-lumia' ),
							'description' => __( 'Journalise le détail des générations dans WooCommerce → État → Journaux. Les erreurs sont enregistrées même sans cette option.', 'facture-pdf-lumia' ),
							'default'     => false,
						),
					),
				),
				'emails'     => array(
					'label'       => __( 'Emails', 'facture-pdf-lumia' ),
					'description' => __( 'La facture n\'est jointe que si la commande est déjà dans un des statuts autorisés ci-dessus au moment de l\'envoi. Un email envoyé plus tôt part sans pièce jointe plutôt que d\'être bloqué : un email qui ne part pas est un problème plus grave qu\'une facture manquante.', 'facture-pdf-lumia' ),
					'fields'      => array(
						'email_attachments' => array(
							'type'    => 'multiselect',
							'label'   => __( 'Joindre la facture à ces emails', 'facture-pdf-lumia' ),
							'default' => array( 'customer_completed_order', 'customer_invoice' ),
							'options' => array( self::class, 'woocommerce_email_options' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Onglet Entreprise.
	 *
	 * Chaque champ possède son propre interrupteur `*_enabled` : c'est la
	 * granularité demandée, un champ masqué ne dépend pas de son bloc.
	 *
	 * @return array<string, mixed>
	 */
	private static function company(): array {
		$identity = array(
			'display_name' => array( __( 'Nom commercial', 'facture-pdf-lumia' ), true ),
			'legal_name'   => array( __( 'Raison sociale', 'facture-pdf-lumia' ), true ),
			'legal_form'   => array( __( 'Forme juridique', 'facture-pdf-lumia' ), false ),
			'manager'      => array( __( 'Nom du dirigeant', 'facture-pdf-lumia' ), true ),
		);

		$contact = array(
			'phone'   => array( __( 'Téléphone fixe', 'facture-pdf-lumia' ), true ),
			'mobile'  => array( __( 'Téléphone mobile', 'facture-pdf-lumia' ), true ),
			'email'   => array( __( 'Adresse email', 'facture-pdf-lumia' ), false ),
			'website' => array( __( 'Site web', 'facture-pdf-lumia' ), false ),
		);

		$legal = array(
			'siret'         => array( __( 'SIRET', 'facture-pdf-lumia' ), true ),
			'rcs'           => array( __( 'RCS', 'facture-pdf-lumia' ), false ),
			'vat_number'    => array( __( 'TVA intracommunautaire', 'facture-pdf-lumia' ), false ),
			'share_capital' => array( __( 'Capital social', 'facture-pdf-lumia' ), false ),
			'ape_code'      => array( __( 'Code APE / NAF', 'facture-pdf-lumia' ), false ),
			'iban'          => array( __( 'IBAN', 'facture-pdf-lumia' ), false ),
			'bic'           => array( __( 'BIC', 'facture-pdf-lumia' ), false ),
		);

		return array(
			'label'    => __( 'Entreprise', 'facture-pdf-lumia' ),
			'priority' => 20,
			'sections' => array(
				'logo'     => array(
					'label'  => __( 'Logo', 'facture-pdf-lumia' ),
					'fields' => array(
						'logo_enabled' => array(
							'type'    => 'checkbox',
							'label'   => __( 'Afficher le logo', 'facture-pdf-lumia' ),
							'default' => true,
						),
						'logo_id'      => array(
							'type'        => 'media',
							'label'       => __( 'Fichier', 'facture-pdf-lumia' ),
							'description' => __( 'Indépendant du logo du site. Privilégier un PNG ou un JPG : le SVG n\'est pas rendu correctement dans un PDF.', 'facture-pdf-lumia' ),
							'default'     => 0,
							'depends'     => array( 'logo_enabled' => true ),
						),
						'logo_width'   => array(
							'type'    => 'number',
							'label'   => __( 'Largeur', 'facture-pdf-lumia' ),
							'default' => 180,
							'min'     => 40,
							'max'     => 500,
							'step'    => 5,
							'unit'    => 'px',
							'depends' => array( 'logo_enabled' => true ),
						),
					),
				),
				'identity' => array(
					'label'       => __( 'Identité', 'facture-pdf-lumia' ),
					'description' => __( 'Chaque champ s\'affiche uniquement s\'il est coché et renseigné.', 'facture-pdf-lumia' ),
					'fields'      => self::toggleable_text_fields( $identity ),
				),
				'address'  => array(
					'label'  => __( 'Adresse', 'facture-pdf-lumia' ),
					'fields' => array(
						'address_enabled' => array(
							'type'    => 'checkbox',
							'label'   => __( 'Afficher l\'adresse', 'facture-pdf-lumia' ),
							'default' => true,
						),
						'address'         => array(
							'type'        => 'textarea',
							'label'       => __( 'Adresse complète', 'facture-pdf-lumia' ),
							'description' => __( 'Les retours à la ligne sont conservés tels quels dans le PDF.', 'facture-pdf-lumia' ),
							'default'     => '',
							'rows'        => 4,
							'depends'     => array( 'address_enabled' => true ),
						),
					),
				),
				'contact'  => array(
					'label'  => __( 'Contact', 'facture-pdf-lumia' ),
					'fields' => self::toggleable_text_fields( $contact ),
				),
				'legal'    => array(
					'label'  => __( 'Informations légales', 'facture-pdf-lumia' ),
					'fields' => self::toggleable_text_fields( $legal ),
				),
			),
		);
	}

	/**
	 * Onglet Numérotation.
	 *
	 * @return array<string, mixed>
	 */
	private static function numbering(): array {
		return array(
			'label'    => __( 'Numérotation', 'facture-pdf-lumia' ),
			'priority' => 30,
			'sections' => array(
				'format'   => array(
					'label'       => __( 'Format du numéro', 'facture-pdf-lumia' ),
					'description' => __( 'Le numéro est attribué définitivement à la première génération de la facture. Modifier le format n\'affecte que les factures émises ensuite.', 'facture-pdf-lumia' ),
					'after'       => array( \Lumia\FacturePDF\Admin\NumberingTab::class, 'render_tags' ),
					'fields'      => array(
						'format'       => array(
							'type'        => 'text',
							'label'       => __( 'Format', 'facture-pdf-lumia' ),
							'description' => __( 'Doit contenir le tag {number}. Voir la liste des tags disponibles ci-dessous.', 'facture-pdf-lumia' ),
							'default'     => 'FA-{year}-{number:4}',
							'placeholder' => 'FA-{year}-{number:4}',
							'sanitize'    => array( \Lumia\FacturePDF\Admin\NumberingTab::class, 'sanitize_format' ),
						),
						'start_number' => array(
							'type'        => 'number',
							'label'       => __( 'Premier numéro', 'facture-pdf-lumia' ),
							'description' => __( 'Valeur du compteur pour la toute première facture. À ajuster en reprise d\'existant, pour poursuivre une numérotation démarrée ailleurs.', 'facture-pdf-lumia' ),
							'default'     => 1,
							'min'         => 1,
							'max'         => 999999999,
							'step'        => 1,
						),
					),
				),
				'reset'    => array(
					'label'  => __( 'Remise à zéro', 'facture-pdf-lumia' ),
					'fields' => array(
						'reset_enabled' => array(
							'type'        => 'checkbox',
							'label'       => __( 'Réinitialiser périodiquement', 'facture-pdf-lumia' ),
							'description' => __( 'Le compteur repart du premier numéro à chaque nouvelle période. Penser à inclure {year} — et {month} en périodicité mensuelle — dans le format, sans quoi les numéros se répéteraient d\'une période à l\'autre.', 'facture-pdf-lumia' ),
							'default'     => true,
						),
						'reset_period'  => array(
							'type'    => 'select',
							'label'   => __( 'Périodicité', 'facture-pdf-lumia' ),
							'default' => 'yearly',
							'options' => array(
								'yearly'  => __( 'Annuelle', 'facture-pdf-lumia' ),
								'monthly' => __( 'Mensuelle', 'facture-pdf-lumia' ),
							),
							'depends' => array( 'reset_enabled' => true ),
						),
					),
				),
				'counters' => array(
					'label'       => __( 'Compteurs en cours', 'facture-pdf-lumia' ),
					'description' => __( 'État des séquences déjà utilisées. Un compteur ne peut être modifié qu\'en connaissance de cause : reprendre en dessous d\'une valeur déjà émise produirait des doublons.', 'facture-pdf-lumia' ),
					'after'       => array( \Lumia\FacturePDF\Admin\NumberingTab::class, 'render_counters' ),
					'fields'      => array(),
				),
			),
		);
	}

	/**
	 * Onglet Contenu.
	 *
	 * Amorcé au strict nécessaire pour que le template par défaut ait un
	 * texte de remerciement et un mode de prix configurables. Le détail
	 * complet — un interrupteur par champ du tableau produits, des totaux,
	 * du bloc client — arrive au lot 5 : ce sont les mêmes clés de contexte
	 * que consomme déjà le template, seule l'interface d'administration
	 * reste à construire.
	 *
	 * @return array<string, mixed>
	 */
	private static function content(): array {
		return array(
			'label'    => __( 'Contenu', 'facture-pdf-lumia' ),
			'priority' => 40,
			'sections' => array(
				'document' => array(
					'label'  => __( 'Document', 'facture-pdf-lumia' ),
					'fields' => array(
						'invoice_title' => array(
							'type'    => 'text',
							'label'   => __( 'Libellé du document', 'facture-pdf-lumia' ),
							'default' => __( 'Facture', 'facture-pdf-lumia' ),
						),
						'price_display' => array(
							'type'        => 'select',
							'label'       => __( 'Affichage des prix', 'facture-pdf-lumia' ),
							'description' => __( 'TTC affiche un montant unique par ligne. HT ajoute la colonne TVA et le détail des taux dans les totaux.', 'facture-pdf-lumia' ),
							'default'     => 'incl',
							'options'     => array(
								'incl' => __( 'Toutes taxes comprises', 'facture-pdf-lumia' ),
								'excl' => __( 'Hors taxes', 'facture-pdf-lumia' ),
							),
						),
					),
				),
				'billing'  => array(
					'label'  => __( 'Bloc client', 'facture-pdf-lumia' ),
					'fields' => array(
						'billing_title'           => array(
							'type'    => 'text',
							'label'   => __( 'Titre du bloc', 'facture-pdf-lumia' ),
							'default' => __( 'Facturer à :', 'facture-pdf-lumia' ),
						),
						'billing_address_enabled' => array(
							'type'    => 'checkbox',
							'label'   => __( 'Afficher l\'adresse de facturation', 'facture-pdf-lumia' ),
							'default' => true,
						),
						'billing_email_enabled'   => array(
							'type'    => 'checkbox',
							'label'   => __( 'Afficher l\'email', 'facture-pdf-lumia' ),
							'default' => true,
						),
						'billing_phone_enabled'   => array(
							'type'    => 'checkbox',
							'label'   => __( 'Afficher le téléphone', 'facture-pdf-lumia' ),
							'default' => true,
						),
					),
				),
				'shipping' => array(
					'label'  => __( 'Bloc livraison', 'facture-pdf-lumia' ),
					'fields' => array(
						'shipping_enabled'       => array(
							'type'    => 'checkbox',
							'label'   => __( 'Afficher le bloc livraison', 'facture-pdf-lumia' ),
							'default' => true,
						),
						'shipping_title'         => array(
							'type'    => 'text',
							'label'   => __( 'Titre du bloc', 'facture-pdf-lumia' ),
							'default' => __( 'Livrer à :', 'facture-pdf-lumia' ),
							'depends' => array( 'shipping_enabled' => true ),
						),
						'shipping_hide_if_same'  => array(
							'type'        => 'checkbox',
							'label'       => __( 'Masquer si identique à l\'adresse de facturation', 'facture-pdf-lumia' ),
							'default'     => true,
							'depends'     => array( 'shipping_enabled' => true ),
						),
						'shipping_hide_virtual'  => array(
							'type'        => 'checkbox',
							'label'       => __( 'Masquer sur les commandes sans livraison physique', 'facture-pdf-lumia' ),
							'default'     => true,
							'depends'     => array( 'shipping_enabled' => true ),
						),
						'shipping_phone_enabled' => array(
							'type'    => 'checkbox',
							'label'   => __( 'Afficher le téléphone de livraison', 'facture-pdf-lumia' ),
							'default' => false,
							'depends' => array( 'shipping_enabled' => true ),
						),
					),
				),
				'meta'     => array(
					'label'  => __( 'Métadonnées', 'facture-pdf-lumia' ),
					'fields' => array(
						'meta_invoice_number_enabled' => array(
							'type'    => 'checkbox',
							'label'   => __( 'Numéro de facture', 'facture-pdf-lumia' ),
							'default' => true,
							'inline'  => true,
						),
						'meta_invoice_date_enabled'   => array(
							'type'    => 'checkbox',
							'label'   => __( 'Date de facture', 'facture-pdf-lumia' ),
							'default' => true,
							'inline'  => true,
						),
						'meta_order_number_enabled'   => array(
							'type'    => 'checkbox',
							'label'   => __( 'Numéro de commande', 'facture-pdf-lumia' ),
							'default' => true,
							'inline'  => true,
						),
						'meta_order_date_enabled'     => array(
							'type'    => 'checkbox',
							'label'   => __( 'Date de commande', 'facture-pdf-lumia' ),
							'default' => true,
							'inline'  => true,
						),
						'meta_payment_method_enabled' => array(
							'type'    => 'checkbox',
							'label'   => __( 'Méthode de paiement', 'facture-pdf-lumia' ),
							'default' => true,
							'inline'  => true,
						),
					),
				),
				'message'  => array(
					'label'  => __( 'Message de remerciement', 'facture-pdf-lumia' ),
					'fields' => array(
						'message_enabled' => array(
							'type'    => 'checkbox',
							'label'   => __( 'Afficher le message', 'facture-pdf-lumia' ),
							'default' => true,
						),
						'message_title'   => array(
							'type'    => 'text',
							'label'   => __( 'Titre', 'facture-pdf-lumia' ),
							'default' => __( 'Merci pour votre commande', 'facture-pdf-lumia' ),
							'depends' => array( 'message_enabled' => true ),
						),
						'message_body'    => array(
							'type'        => 'richtext',
							'label'       => __( 'Message', 'facture-pdf-lumia' ),
							'description' => __( 'Les placeholders ({customer_first_name}, {shop_name}…) seront ajoutés au lot 8.', 'facture-pdf-lumia' ),
							'default'     => __( 'Nous vous remercions pour votre confiance.', 'facture-pdf-lumia' ),
							'depends'     => array( 'message_enabled' => true ),
						),
					),
				),
				'columns'  => array(
					'label'       => __( 'Colonnes du tableau produits', 'facture-pdf-lumia' ),
					'description' => __( 'Largeurs indicatives en pourcentage. Une somme différente de 100 % est simplement redistribuée par le navigateur PDF.', 'facture-pdf-lumia' ),
					'fields'      => self::column_fields(),
				),
				'items'    => array(
					'label'  => __( 'Détail des lignes', 'facture-pdf-lumia' ),
					'fields' => array(
						'item_meta_enabled' => array(
							'type'        => 'checkbox',
							'label'       => __( 'Afficher les métadonnées de variation', 'facture-pdf-lumia' ),
							'description' => __( 'Taille, couleur, ou tout attribut choisi par le client à l\'achat.', 'facture-pdf-lumia' ),
							'default'     => true,
						),
					),
				),
				'totals'   => array(
					'label'  => __( 'Bloc totaux', 'facture-pdf-lumia' ),
					'fields' => array(
						'total_subtotal_enabled' => array(
							'type'    => 'checkbox',
							'label'   => __( 'Sous-total', 'facture-pdf-lumia' ),
							'default' => true,
							'inline'  => true,
						),
						'total_discount_enabled' => array(
							'type'    => 'checkbox',
							'label'   => __( 'Remise', 'facture-pdf-lumia' ),
							'default' => true,
							'inline'  => true,
						),
						'total_shipping_enabled' => array(
							'type'    => 'checkbox',
							'label'   => __( 'Livraison', 'facture-pdf-lumia' ),
							'default' => true,
							'inline'  => true,
						),
						'total_shipping_always'  => array(
							'type'        => 'checkbox',
							'label'       => __( 'Afficher la ligne livraison même à 0 €', 'facture-pdf-lumia' ),
							'default'     => false,
						),
						'total_fees_enabled'     => array(
							'type'    => 'checkbox',
							'label'   => __( 'Frais', 'facture-pdf-lumia' ),
							'default' => true,
							'inline'  => true,
						),
						'total_tax_enabled'      => array(
							'type'        => 'checkbox',
							'label'       => __( 'TVA (détaillée par taux)', 'facture-pdf-lumia' ),
							'default'     => true,
							'inline'      => true,
						),
						'total_grand_enabled'    => array(
							'type'    => 'checkbox',
							'label'   => __( 'Total', 'facture-pdf-lumia' ),
							'default' => true,
							'inline'  => true,
						),
						'total_refunded_enabled' => array(
							'type'        => 'checkbox',
							'label'       => __( 'Remboursement et net payé', 'facture-pdf-lumia' ),
							'description' => __( 'N\'apparaît que si la commande comporte un remboursement.', 'facture-pdf-lumia' ),
							'default'     => true,
							'inline'      => true,
						),
					),
				),
				'notes'    => array(
					'label'  => __( 'Notes', 'facture-pdf-lumia' ),
					'fields' => array(
						'customer_note_enabled' => array(
							'type'    => 'checkbox',
							'label'   => __( 'Afficher la note client', 'facture-pdf-lumia' ),
							'default' => true,
						),
						'customer_note_label'   => array(
							'type'    => 'text',
							'label'   => __( 'Libellé', 'facture-pdf-lumia' ),
							'default' => __( 'Note du client', 'facture-pdf-lumia' ),
							'depends' => array( 'customer_note_enabled' => true ),
						),
					),
				),
				'footer'   => array(
					'label'       => __( 'Pied de page', 'facture-pdf-lumia' ),
					'description' => __( 'Ces interrupteurs choisissent quelles coordonnées apparaissent en pied de page. Les valeurs elles-mêmes (téléphone, SIRET, IBAN…) se saisissent dans l\'onglet Entreprise.', 'facture-pdf-lumia' ),
					'fields'      => array_merge(
						array(
							'footer_text'                => array(
								'type'        => 'richtext',
								'label'       => __( 'Texte libre', 'facture-pdf-lumia' ),
								'default'     => '',
							),
							'footer_pagination_enabled'  => array(
								'type'    => 'checkbox',
								'label'   => __( 'Numérotation des pages', 'facture-pdf-lumia' ),
								'default' => true,
							),
						),
						self::footer_toggle_fields()
					),
				),
				'legal'    => array(
					'label'       => __( 'Mentions légales', 'facture-pdf-lumia' ),
					'description' => __( 'Une bibliothèque de mentions courantes est pré-remplie ci-dessous, toutes désactivées par défaut : chacune doit correspondre à la situation réelle de l\'entreprise avant d\'être activée. Les placeholders ({shop_name}, {customer_first_name}…) sont utilisables dans le titre et le texte. L\'emplacement « Pied de page » est répété sur chaque page en hauteur fixe : à réserver aux mentions courtes, une phrase au plus — les textes plus longs trouvent mieux leur place « sous les totaux » ou dans le bloc dédié. Le bouton en bas de liste permet d\'ajouter autant de mentions personnalisées que nécessaire.', 'facture-pdf-lumia' ),
					'fields'      => array(
						'legal_mentions' => array(
							'type'       => 'repeater',
							'label'      => __( 'Mentions', 'facture-pdf-lumia' ),
							'default'    => self::default_legal_mentions(),
							'row_fields' => array(
								'enabled'   => array(
									'type'    => 'checkbox',
									'label'   => __( 'Activée', 'facture-pdf-lumia' ),
									'default' => false,
								),
								'title'     => array(
									'type'        => 'text',
									'label'       => __( 'Titre', 'facture-pdf-lumia' ),
									'default'     => '',
									'placeholder' => __( '(optionnel)', 'facture-pdf-lumia' ),
								),
								'body'      => array(
									'type'    => 'textarea',
									'label'   => __( 'Texte', 'facture-pdf-lumia' ),
									'default' => '',
									'rows'    => 3,
								),
								'placement' => array(
									'type'    => 'select',
									'label'   => __( 'Emplacement', 'facture-pdf-lumia' ),
									'default' => 'footer',
									'options' => array(
										'totals' => __( 'Sous les totaux', 'facture-pdf-lumia' ),
										'block'  => __( 'Bloc dédié en fin de document', 'facture-pdf-lumia' ),
										'footer' => __( 'Pied de page', 'facture-pdf-lumia' ),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Bibliothèque de mentions légales pré-remplies.
	 *
	 * Toutes désactivées par défaut : contrairement aux autres réglages, une
	 * mention légale engage l'entreprise et dépend étroitement de sa
	 * situation réelle (régime de TVA, statut juridique, activité). En
	 * activer une qui ne s'applique pas — la franchise en base de TVA sur une
	 * société normalement assujettie, par exemple — serait une erreur
	 * autrement plus sérieuse qu'un simple oubli d'affichage.
	 *
	 * @return array<int, array{enabled: bool, title: string, body: string, placement: string}>
	 */
	private static function default_legal_mentions(): array {
		return array(
			array(
				'enabled'   => false,
				'title'     => __( 'Franchise en base de TVA', 'facture-pdf-lumia' ),
				'body'      => __( 'TVA non applicable, article 293 B du Code général des impôts.', 'facture-pdf-lumia' ),
				'placement' => 'footer',
			),
			array(
				'enabled'   => false,
				'title'     => __( 'Pénalités de retard', 'facture-pdf-lumia' ),
				'body'      => __( 'En cas de retard de paiement, une pénalité égale à trois fois le taux d\'intérêt légal sera exigible, conformément à l\'article L441-10 du Code de commerce.', 'facture-pdf-lumia' ),
				'placement' => 'totals',
			),
			array(
				'enabled'   => false,
				'title'     => __( 'Indemnité forfaitaire de recouvrement', 'facture-pdf-lumia' ),
				'body'      => __( 'Une indemnité forfaitaire de 40 € pour frais de recouvrement est due en cas de retard de paiement (article L441-10 du Code de commerce).', 'facture-pdf-lumia' ),
				'placement' => 'totals',
			),
			array(
				'enabled'   => false,
				'title'     => __( 'Escompte pour paiement anticipé', 'facture-pdf-lumia' ),
				'body'      => __( 'Pas d\'escompte pour paiement anticipé.', 'facture-pdf-lumia' ),
				'placement' => 'totals',
			),
			array(
				'enabled'   => false,
				'title'     => __( 'Clause de réserve de propriété', 'facture-pdf-lumia' ),
				'body'      => __( 'Les marchandises vendues restent la propriété de {shop_name} jusqu\'au paiement intégral du prix.', 'facture-pdf-lumia' ),
				'placement' => 'block',
			),
			array(
				'enabled'   => false,
				'title'     => __( 'Délai de paiement contractuel', 'facture-pdf-lumia' ),
				'body'      => __( 'Sauf accord contraire, le règlement est dû à réception de la facture.', 'facture-pdf-lumia' ),
				'placement' => 'totals',
			),
			array(
				'enabled'   => false,
				'title'     => __( 'Mention auto-entrepreneur', 'facture-pdf-lumia' ),
				'body'      => __( 'Dispensé d\'immatriculation au registre du commerce et des sociétés (RCS) et au répertoire des métiers (RM).', 'facture-pdf-lumia' ),
				'placement' => 'footer',
			),
			array(
				'enabled'   => false,
				'title'     => __( 'Droit de rétractation', 'facture-pdf-lumia' ),
				'body'      => __( 'Conformément aux articles L221-18 et suivants du Code de la consommation, vous disposez d\'un délai de 14 jours pour exercer votre droit de rétractation.', 'facture-pdf-lumia' ),
				'placement' => 'block',
			),
			array(
				'enabled'   => false,
				'title'     => __( 'Médiateur de la consommation', 'facture-pdf-lumia' ),
				'body'      => __( 'En cas de litige non résolu directement avec {shop_name}, vous pouvez recourir gratuitement au service de médiation de la consommation dont les coordonnées sont disponibles sur demande.', 'facture-pdf-lumia' ),
				'placement' => 'block',
			),
			array(
				'enabled'   => false,
				'title'     => __( 'Assurance professionnelle', 'facture-pdf-lumia' ),
				'body'      => __( 'Assurance responsabilité civile professionnelle souscrite garantissant les activités mentionnées sur ce document.', 'facture-pdf-lumia' ),
				'placement' => 'footer',
			),
		);
	}

	/**
	 * Colonnes du tableau produits.
	 *
	 * Génère un triplet activation/libellé/largeur pour chaque colonne. La
	 * disposition (alignement) reste fixée par colonne : la faire varier
	 * n'apporterait rien et compliquerait le rendu Dompdf, qui n'aime pas les
	 * tableaux aux colonnes trop hétérogènes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function column_fields(): array {
		$columns = array(
			'image'       => array( __( 'Image', 'facture-pdf-lumia' ), true, 8 ),
			'name'        => array( __( 'Produit', 'facture-pdf-lumia' ), true, 22 ),
			'description' => array( __( 'Description', 'facture-pdf-lumia' ), true, 30 ),
			'sku'         => array( __( 'Référence', 'facture-pdf-lumia' ), false, 10 ),
			'quantity'    => array( __( 'Qté', 'facture-pdf-lumia' ), true, 6 ),
			'unit_price'  => array( __( 'Prix unitaire', 'facture-pdf-lumia' ), true, 12 ),
			'tax_rate'    => array( __( 'TVA', 'facture-pdf-lumia' ), true, 8 ),
			'total'       => array( __( 'Total', 'facture-pdf-lumia' ), true, 14 ),
		);

		$fields = array();

		foreach ( $columns as $key => $definition ) {
			list( $label, $default_enabled, $default_width ) = $definition;

			$fields[ 'col_' . $key . '_enabled' ] = array(
				'type'    => 'checkbox',
				'label'   => sprintf(
					/* translators: %s: nom de la colonne */
					__( 'Colonne : %s', 'facture-pdf-lumia' ),
					$label
				),
				'default' => $default_enabled,
				'inline'  => true,
			);

			$fields[ 'col_' . $key . '_label' ] = array(
				'type'    => 'text',
				'label'   => __( 'Libellé', 'facture-pdf-lumia' ),
				'default' => $label,
				'depends' => array( 'col_' . $key . '_enabled' => true ),
			);

			$fields[ 'col_' . $key . '_width' ] = array(
				'type'    => 'number',
				'label'   => __( 'Largeur', 'facture-pdf-lumia' ),
				'default' => $default_width,
				'min'     => 2,
				'max'     => 60,
				'step'    => 1,
				'unit'    => '%',
				'depends' => array( 'col_' . $key . '_enabled' => true ),
			);
		}

		return $fields;
	}

	/**
	 * Interrupteurs des mentions de contact en pied de page.
	 *
	 * Ces champs contrôlent uniquement l'affichage sur le document ; la
	 * valeur elle-même est celle déjà saisie dans l'onglet Entreprise. Un
	 * numéro SIRET peut ainsi être renseigné (pour l'en-tête) sans être
	 * répété dans le pied de page, ou inversement.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function footer_toggle_fields(): array {
		$items = array(
			'phone'         => __( 'Téléphone', 'facture-pdf-lumia' ),
			'mobile'        => __( 'Mobile', 'facture-pdf-lumia' ),
			'email'         => __( 'Email', 'facture-pdf-lumia' ),
			'website'       => __( 'Site web', 'facture-pdf-lumia' ),
			'siret'         => __( 'SIRET', 'facture-pdf-lumia' ),
			'rcs'           => __( 'RCS', 'facture-pdf-lumia' ),
			'vat_number'    => __( 'TVA intracommunautaire', 'facture-pdf-lumia' ),
			'share_capital' => __( 'Capital social', 'facture-pdf-lumia' ),
			'ape_code'      => __( 'Code APE / NAF', 'facture-pdf-lumia' ),
			'iban'          => __( 'IBAN', 'facture-pdf-lumia' ),
			'bic'           => __( 'BIC', 'facture-pdf-lumia' ),
		);

		$fields = array();

		foreach ( $items as $key => $label ) {
			$fields[ 'footer_' . $key . '_enabled' ] = array(
				'type'    => 'checkbox',
				'label'   => sprintf(
					/* translators: %s: nom du champ */
					__( 'Afficher : %s', 'facture-pdf-lumia' ),
					$label
				),
				'default' => in_array( $key, array( 'phone', 'mobile', 'siret' ), true ),
				'inline'  => true,
			);
		}

		return $fields;
	}

	/**
	 * Onglet Design.
	 *
	 * Chaque champ correspond à une variable consommée par `StyleCompiler`
	 * puis substituée dans `style.css` via `var(--fpl-...)`. Ajouter un
	 * réglage ici et l'exploiter dans la feuille du template suffit à le
	 * rendre personnalisable, sans toucher au moteur de rendu.
	 *
	 * @return array<string, mixed>
	 */
	private static function design(): array {
		return array(
			'label'    => __( 'Design', 'facture-pdf-lumia' ),
			'priority' => 70,
			'sections' => array(
				'template'   => array(
					'label'  => __( 'Modèle', 'facture-pdf-lumia' ),
					'fields' => array(
						'template' => array(
							'type'        => 'select',
							'label'       => __( 'Template actif', 'facture-pdf-lumia' ),
							'description' => __( 'Un template déposé dans le thème (dossier facture-pdf-lumia/) apparaît automatiquement ici.', 'facture-pdf-lumia' ),
							'default'     => 'default',
							'options'     => array( \Lumia\FacturePDF\Rendering\TemplateLoader::class, 'available' ),
						),
					),
					'after'  => array( \Lumia\FacturePDF\Admin\DesignTab::class, 'render_variations' ),
				),
				'colors'     => array(
					'label'  => __( 'Couleurs', 'facture-pdf-lumia' ),
					'fields' => array(
						'color_primary'    => array(
							'type'    => 'color',
							'label'   => __( 'Couleur primaire', 'facture-pdf-lumia' ),
							'default' => '#111111',
						),
						'color_secondary'  => array(
							'type'    => 'color',
							'label'   => __( 'Couleur secondaire', 'facture-pdf-lumia' ),
							'default' => '#6b6b6b',
						),
						'color_text'       => array(
							'type'    => 'color',
							'label'   => __( 'Texte principal', 'facture-pdf-lumia' ),
							'default' => '#1a1a1a',
						),
						'color_muted'      => array(
							'type'    => 'color',
							'label'   => __( 'Texte secondaire', 'facture-pdf-lumia' ),
							'default' => '#6b6b6b',
						),
						'color_thead_bg'   => array(
							'type'    => 'color',
							'label'   => __( 'Fond d\'en-tête de tableau', 'facture-pdf-lumia' ),
							'default' => '#111111',
						),
						'color_thead_text' => array(
							'type'    => 'color',
							'label'   => __( 'Texte d\'en-tête de tableau', 'facture-pdf-lumia' ),
							'default' => '#ffffff',
						),
						'color_row_alt'    => array(
							'type'    => 'color',
							'label'   => __( 'Fond de ligne alternée', 'facture-pdf-lumia' ),
							'default' => '#fafafa',
						),
						'color_block_bg'   => array(
							'type'    => 'color',
							'label'   => __( 'Fond des encarts', 'facture-pdf-lumia' ),
							'default' => '#f7f7f7',
						),
						'color_border'     => array(
							'type'    => 'color',
							'label'   => __( 'Bordures et filets', 'facture-pdf-lumia' ),
							'default' => '#dddddd',
						),
						'color_page'       => array(
							'type'    => 'color',
							'label'   => __( 'Fond de page', 'facture-pdf-lumia' ),
							'default' => '#ffffff',
						),
					),
				),
				'typography' => array(
					'label'       => __( 'Typographie', 'facture-pdf-lumia' ),
					'description' => __( 'Huit polices embarquées avec accents français complets, en Regular, Italique, Gras et Gras Italique.', 'facture-pdf-lumia' ),
					'fields'      => array(
						'font_heading'  => array(
							'type'    => 'select',
							'label'   => __( 'Police des titres', 'facture-pdf-lumia' ),
							'default' => 'Inter',
							'options' => array( \Lumia\FacturePDF\Rendering\FontLibrary::class, 'options' ),
						),
						'font_body'     => array(
							'type'    => 'select',
							'label'   => __( 'Police du corps', 'facture-pdf-lumia' ),
							'default' => 'Inter',
							'options' => array( \Lumia\FacturePDF\Rendering\FontLibrary::class, 'options' ),
						),
						'font_size'     => array(
							'type'    => 'number',
							'label'   => __( 'Taille de base', 'facture-pdf-lumia' ),
							'default' => 9.5,
							'min'     => 7,
							'max'     => 14,
							'step'    => 0.5,
							'unit'    => 'pt',
						),
						'heading_scale' => array(
							'type'    => 'number',
							'label'   => __( 'Échelle des titres', 'facture-pdf-lumia' ),
							'default' => 1.6,
							'min'     => 1.1,
							'max'     => 2.4,
							'step'    => 0.1,
						),
						'line_height'   => array(
							'type'    => 'number',
							'label'   => __( 'Hauteur de ligne', 'facture-pdf-lumia' ),
							'default' => 1.45,
							'min'     => 1.1,
							'max'     => 2,
							'step'    => 0.05,
						),
						'heading_bold'  => array(
							'type'    => 'checkbox',
							'label'   => __( 'Titres en gras', 'facture-pdf-lumia' ),
							'default' => true,
						),
					),
				),
				'shape'      => array(
					'label'  => __( 'Formes', 'facture-pdf-lumia' ),
					'fields' => array(
						'radius'       => array(
							'type'        => 'number',
							'label'       => __( 'Rayon d\'arrondi', 'facture-pdf-lumia' ),
							'description' => __( 'Dompdf n\'arrondit pas les cellules de tableau : l\'effet s\'applique aux encarts (en-tête, totaux), pas aux lignes du tableau produits.', 'facture-pdf-lumia' ),
							'default'     => 4,
							'min'         => 0,
							'max'         => 20,
							'step'        => 1,
							'unit'        => 'px',
						),
						'border_width' => array(
							'type'    => 'number',
							'label'   => __( 'Épaisseur des filets', 'facture-pdf-lumia' ),
							'default' => 0.6,
							'min'     => 0,
							'max'     => 3,
							'step'    => 0.1,
							'unit'    => 'pt',
						),
					),
				),
				'layout'     => array(
					'label'  => __( 'Mise en page', 'facture-pdf-lumia' ),
					'fields' => array(
						'density'    => array(
							'type'    => 'select',
							'label'   => __( 'Densité', 'facture-pdf-lumia' ),
							'default' => 'normal',
							'options' => array(
								'compact' => __( 'Compact', 'facture-pdf-lumia' ),
								'normal'  => __( 'Normal', 'facture-pdf-lumia' ),
								'airy'    => __( 'Aéré', 'facture-pdf-lumia' ),
							),
						),
						'thumb_width' => array(
							'type'    => 'number',
							'label'   => __( 'Largeur des vignettes produit', 'facture-pdf-lumia' ),
							'default' => 46,
							'min'     => 0,
							'max'     => 100,
							'step'    => 2,
							'unit'    => 'px',
						),
						'margin_top'    => array(
							'type'    => 'number',
							'label'   => __( 'Marge haute', 'facture-pdf-lumia' ),
							'default' => 18,
							'min'     => 5,
							'max'     => 40,
							'step'    => 1,
							'unit'    => 'mm',
						),
						'margin_bottom' => array(
							'type'        => 'number',
							'label'       => __( 'Marge basse', 'facture-pdf-lumia' ),
							'description' => __( 'Doit rester suffisante pour accueillir le pied de page, qui est répété en bas de chaque page. Augmenter cette valeur si le pied de page chevauche le contenu.', 'facture-pdf-lumia' ),
							'default'     => 26,
							'min'         => 12,
							'max'         => 50,
							'step'        => 1,
							'unit'        => 'mm',
						),
						'margin_sides'  => array(
							'type'    => 'number',
							'label'   => __( 'Marges latérales', 'facture-pdf-lumia' ),
							'default' => 16,
							'min'     => 5,
							'max'     => 40,
							'step'    => 1,
							'unit'    => 'mm',
						),
					),
				),
				'advanced'   => array(
					'label'  => __( 'Avancé', 'facture-pdf-lumia' ),
					'fields' => array(
						'custom_css' => array(
							'type'        => 'code',
							'label'       => __( 'CSS additionnel', 'facture-pdf-lumia' ),
							'description' => __( 'Injecté en dernier : il l\'emporte toujours sur le template, sans besoin de !important.', 'facture-pdf-lumia' ),
							'default'     => '',
							'rows'        => 10,
						),
					),
				),
			),
		);
	}

	/**
	 * Onglet Aperçu.
	 *
	 * Entièrement rendu par son contrôleur : il ne contient aucun réglage,
	 * seulement des outils de vérification.
	 *
	 * @return array<string, mixed>
	 */
	private static function preview(): array {
		return array(
			'label'    => __( 'Aperçu', 'facture-pdf-lumia' ),
			'priority' => 80,
			'render'   => array( \Lumia\FacturePDF\Admin\PreviewController::class, 'render' ),
			'sections' => array(),
		);
	}

	/**
	 * Onglet Outils.
	 *
	 * @return array<string, mixed>
	 */
	private static function tools(): array {
		return array(
			'label'    => __( 'Outils', 'facture-pdf-lumia' ),
			'priority' => 90,
			'render'   => array( \Lumia\FacturePDF\Admin\ToolsTab::class, 'render' ),
			'sections' => array(),
		);
	}

	/**
	 * Onglet Documentation.
	 *
	 * Guide pratique destiné aux utilisateurs de la boutique. Placé après
	 * l'onglet Outils afin de rester le dernier onglet de navigation.
	 *
	 * @return array<string, mixed>
	 */
	private static function documentation(): array {
		return array(
			'label'    => __( 'Documentation', 'facture-pdf-lumia' ),
			'priority' => 100,
			'render'   => array( \Lumia\FacturePDF\Admin\DocumentationTab::class, 'render' ),
			'sections' => array(),
		);
	}

	/**
	 * Fabrique une paire « interrupteur + champ texte » pour chaque entrée.
	 *
	 * @param array<string, array{0: string, 1: bool}> $fields Clé => [libellé, activé par défaut].
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function toggleable_text_fields( array $fields ): array {
		$output = array();

		foreach ( $fields as $key => $definition ) {
			list( $label, $enabled_by_default ) = $definition;

			$output[ $key . '_enabled' ] = array(
				'type'    => 'checkbox',
				'label'   => sprintf(
					/* translators: %s: nom du champ */
					__( 'Afficher : %s', 'facture-pdf-lumia' ),
					$label
				),
				'default' => $enabled_by_default,
				'inline'  => true,
			);

			$output[ $key ] = array(
				'type'    => 'text',
				'label'   => $label,
				'default' => '',
				'depends' => array( $key . '_enabled' => true ),
			);
		}

		return $output;
	}

	/**
	 * Emails WooCommerce disponibles, pour la liste des pièces jointes.
	 *
	 * Interrogée à l'affichage plutôt que mise en dur : un email ajouté par
	 * une extension tierce (abonnements, réservations…) apparaît
	 * automatiquement, sans mise à jour de ce plugin.
	 *
	 * @return array<string, string>
	 */
	public static function woocommerce_email_options(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return array();
		}

		$options = array();

		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( ! is_object( $email ) || ! isset( $email->id ) ) {
				continue;
			}

			$options[ $email->id ] = method_exists( $email, 'get_title' ) ? $email->get_title() : $email->id;
		}

		return $options;
	}

	/**
	 * Statuts de commande disponibles, pour les listes déroulantes.
	 *
	 * Résolue à l'affichage plutôt qu'au chargement du schéma : les statuts
	 * personnalisés ajoutés par des extensions ne sont pas encore enregistrés
	 * au moment où le schéma est construit.
	 *
	 * @return array<string, string>
	 */
	public static function order_status_options(): array {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}

		$options = array();

		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$options[ str_replace( 'wc-', '', $slug ) ] = $label;
		}

		return $options;
	}

	/**
	 * Aplatit le schéma en un tableau `onglet.section.champ => définition`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function flatten(): array {
		$flat = array();

		foreach ( self::all() as $tab_key => $tab ) {
			if ( empty( $tab['sections'] ) || ! is_array( $tab['sections'] ) ) {
				continue;
			}

			foreach ( $tab['sections'] as $section_key => $section ) {
				if ( empty( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
					continue;
				}

				foreach ( $section['fields'] as $field_key => $field ) {
					$flat[ $tab_key . '.' . $section_key . '.' . $field_key ] = $field;
				}
			}
		}

		return $flat;
	}

	/**
	 * Valeurs par défaut, structurées comme l'option stockée.
	 *
	 * Les champs sont regroupés par onglet, sans niveau de section : les
	 * sections n'existent que pour l'affichage. Une clé de champ doit donc
	 * être unique au sein d'un onglet.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		$defaults = array();

		foreach ( self::all() as $tab_key => $tab ) {
			if ( empty( $tab['sections'] ) || ! is_array( $tab['sections'] ) ) {
				continue;
			}

			$defaults[ $tab_key ] = array();

			foreach ( $tab['sections'] as $section ) {
				if ( empty( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
					continue;
				}

				foreach ( $section['fields'] as $field_key => $field ) {
					$defaults[ $tab_key ][ $field_key ] = $field['default'] ?? null;
				}
			}
		}

		return $defaults;
	}

	/**
	 * Retrouve la définition d'un champ.
	 *
	 * @param string $tab   Clé d'onglet.
	 * @param string $field Clé de champ.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function field( string $tab, string $field ): ?array {
		$schema = self::all();

		if ( empty( $schema[ $tab ]['sections'] ) ) {
			return null;
		}

		foreach ( $schema[ $tab ]['sections'] as $section ) {
			if ( isset( $section['fields'][ $field ] ) ) {
				return $section['fields'][ $field ];
			}
		}

		return null;
	}

	/**
	 * Vide le cache du schéma.
	 */
	public static function reset(): void {
		self::$schema = null;
	}
}
