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
			'general'  => self::general(),
			'company'  => self::company(),
			'design'   => array(
				'label'    => __( 'Design', 'facture-pdf-lumia' ),
				'priority' => 70,
				'sections' => array(),
			),
			'tools'    => self::tools(),
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
