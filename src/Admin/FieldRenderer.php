<?php
/**
 * Rendu des champs de formulaire.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Traduit une définition de champ du schéma en HTML.
 *
 * Chaque type est géré au même endroit : ajouter un type de champ au plugin
 * revient à ajouter un `case` ici, et il devient immédiatement utilisable
 * dans tout le schéma.
 */
final class FieldRenderer {

	/**
	 * Rend un champ.
	 *
	 * @param string               $key   Clé du champ.
	 * @param array<string, mixed> $field Définition.
	 * @param mixed                $value Valeur courante.
	 */
	public static function render( string $key, array $field, $value ): void {
		$type = $field['type'] ?? 'text';

		switch ( $type ) {
			case 'checkbox':
				self::checkbox( $key, $field, $value );
				break;

			case 'textarea':
				self::textarea( $key, $field, $value );
				break;

			case 'richtext':
				self::richtext( $key, $field, $value );
				break;

			case 'code':
				self::textarea( $key, $field, $value, 'fpl-code' );
				break;

			case 'repeater':
				self::repeater( $key, $field, $value );
				break;

			case 'number':
				self::number( $key, $field, $value );
				break;

			case 'select':
				self::select( $key, $field, $value );
				break;

			case 'multiselect':
				self::multiselect( $key, $field, $value );
				break;

			case 'color':
				self::color( $key, $field, $value );
				break;

			case 'media':
				self::media( $key, $field, $value );
				break;

			case 'text':
			default:
				self::text( $key, $field, $value );
				break;
		}
	}

	/**
	 * Attribut `name` du champ.
	 *
	 * @param string $key Clé.
	 */
	private static function name( string $key ): string {
		return 'fpl[' . $key . ']';
	}

	/**
	 * Champ texte.
	 *
	 * @param string               $key   Clé.
	 * @param array<string, mixed> $field Définition.
	 * @param mixed                $value Valeur.
	 */
	private static function text( string $key, array $field, $value ): void {
		printf(
			'<input type="text" id="fpl-%1$s" name="%2$s" value="%3$s" class="regular-text" placeholder="%4$s" x-model="values[\'%1$s\']">',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			esc_attr( (string) $value ),
			esc_attr( (string) ( $field['placeholder'] ?? '' ) )
		);
	}

	/**
	 * Zone de texte.
	 *
	 * @param string               $key   Clé.
	 * @param array<string, mixed> $field Définition.
	 * @param mixed                $value Valeur.
	 * @param string               $class Classe additionnelle.
	 */
	private static function textarea( string $key, array $field, $value, string $class = '' ): void {
		printf(
			'<textarea id="fpl-%1$s" name="%2$s" rows="%3$d" class="large-text %4$s" placeholder="%5$s" x-model="values[\'%1$s\']">%6$s</textarea>',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			absint( $field['rows'] ?? 5 ),
			esc_attr( $class ),
			esc_attr( (string) ( $field['placeholder'] ?? '' ) ),
			esc_textarea( (string) $value )
		);
	}

	/**
	 * Case à cocher.
	 *
	 * Un champ caché précède la case afin qu'une case décochée envoie tout de
	 * même une valeur. Sans lui, le navigateur n'envoie rien et il devient
	 * impossible de distinguer « décoché » de « champ absent ».
	 *
	 * @param string               $key   Clé.
	 * @param array<string, mixed> $field Définition.
	 * @param mixed                $value Valeur.
	 */
	private static function checkbox( string $key, array $field, $value ): void {
		printf( '<input type="hidden" name="%s" value="0">', esc_attr( self::name( $key ) ) );

		printf(
			'<label class="fpl-switch"><input type="checkbox" id="fpl-%1$s" name="%2$s" value="1" %3$s x-model="values[\'%1$s\']"><span class="fpl-switch__track"></span><span class="fpl-switch__label">%4$s</span></label>',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			checked( (bool) $value, true, false ),
			esc_html( $field['inline_label'] ?? __( 'Activé', 'facture-pdf-lumia' ) )
		);
	}

	/**
	 * Éditeur riche WordPress.
	 *
	 * Le champ est rendu par `wp_editor()` plutôt que par une zone de texte :
	 * ces contenus (remerciement, pied de page) finissent dans le PDF avec
	 * leur mise en forme, et demander à un utilisateur d'écrire du HTML à la
	 * main pour mettre un mot en gras n'a pas de sens.
	 *
	 * Deux précautions propres à cette page :
	 *
	 * - L'identifiant est normalisé en minuscules sans tiret. TinyMCE utilise
	 *   l'identifiant du textarea comme clé interne et se comporte mal avec
	 *   des caractères qu'il n'attend pas.
	 * - Aucun `x-model` n'est posé. Alpine et TinyMCE se disputeraient la
	 *   valeur du textarea, et c'est TinyMCE qui la synchronise à la
	 *   soumission. Le champ reste donc hors de l'état réactif, ce qui est
	 *   sans conséquence : aucun autre réglage ne dépend d'un contenu riche.
	 *
	 * @param string               $key   Clé.
	 * @param array<string, mixed> $field Définition.
	 * @param mixed                $value Valeur.
	 */
	private static function richtext( string $key, array $field, $value ): void {
		$editor_id = 'fpleditor' . preg_replace( '/[^a-z0-9]/', '', strtolower( $key ) );

		echo '<div class="fpl-richtext">';

		wp_editor(
			(string) $value,
			$editor_id,
			array(
				'textarea_name' => self::name( $key ),
				'textarea_rows' => absint( $field['rows'] ?? 6 ),
				'media_buttons' => false,
				'teeny'         => true,
				'quicktags'     => true,
				'tinymce'       => array(
					'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
					'toolbar2' => '',
				),
			)
		);

		echo '</div>';
	}

	/**
	 * Champ numérique.
	 *
	 * @param string               $key   Clé.
	 * @param array<string, mixed> $field Définition.
	 * @param mixed                $value Valeur.
	 */
	private static function number( string $key, array $field, $value ): void {
		printf(
			'<input type="number" id="fpl-%1$s" name="%2$s" value="%3$s" class="small-text" min="%4$s" max="%5$s" step="%6$s" x-model="values[\'%1$s\']">',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			esc_attr( (string) $value ),
			esc_attr( (string) ( $field['min'] ?? '' ) ),
			esc_attr( (string) ( $field['max'] ?? '' ) ),
			esc_attr( (string) ( $field['step'] ?? 1 ) )
		);

		if ( ! empty( $field['unit'] ) ) {
			printf( ' <span class="fpl-unit">%s</span>', esc_html( $field['unit'] ) );
		}
	}

	/**
	 * Liste déroulante.
	 *
	 * @param string               $key   Clé.
	 * @param array<string, mixed> $field Définition.
	 * @param mixed                $value Valeur.
	 */
	private static function select( string $key, array $field, $value ): void {
		printf(
			'<select id="fpl-%1$s" name="%2$s" x-model="values[\'%1$s\']">',
			esc_attr( $key ),
			esc_attr( self::name( $key ) )
		);

		foreach ( self::options( $field ) as $option_value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $option_value ),
				selected( (string) $value, (string) $option_value, false ),
				esc_html( (string) $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Sélection multiple, rendue en liste de cases à cocher.
	 *
	 * Plus lisible qu'un `<select multiple>`, et bien plus utilisable quand la
	 * liste dépasse cinq entrées — ce qui est le cas des statuts de commande.
	 *
	 * @param string               $key   Clé.
	 * @param array<string, mixed> $field Définition.
	 * @param mixed                $value Valeur.
	 */
	private static function multiselect( string $key, array $field, $value ): void {
		$selected = is_array( $value ) ? array_map( 'strval', $value ) : array();

		printf( '<input type="hidden" name="%s[]" value="">', esc_attr( self::name( $key ) ) );

		echo '<div class="fpl-checklist">';

		foreach ( self::options( $field ) as $option_value => $label ) {
			printf(
				'<label class="fpl-checklist__item"><input type="checkbox" name="%s[]" value="%s"%s> <span>%s</span></label>',
				esc_attr( self::name( $key ) ),
				esc_attr( (string) $option_value ),
				checked( in_array( (string) $option_value, $selected, true ), true, false ),
				esc_html( (string) $label )
			);
		}

		echo '</div>';
	}

	/**
	 * Sélecteur de couleur.
	 *
	 * @param string               $key   Clé.
	 * @param array<string, mixed> $field Définition.
	 * @param mixed                $value Valeur.
	 */
	private static function color( string $key, array $field, $value ): void {
		printf(
			'<input type="text" id="fpl-%1$s" name="%2$s" value="%3$s" class="fpl-color-picker" data-default-color="%4$s">',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			esc_attr( (string) $value ),
			esc_attr( (string) ( $field['default'] ?? '' ) )
		);
	}

	/**
	 * Sélecteur de média.
	 *
	 * @param string               $key   Clé.
	 * @param array<string, mixed> $field Définition.
	 * @param mixed                $value Valeur.
	 */
	private static function media( string $key, array $field, $value ): void {
		$attachment_id = absint( $value );
		$preview       = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';

		printf(
			'<div class="fpl-media" data-field="%s">',
			esc_attr( $key )
		);

		printf(
			'<input type="hidden" id="fpl-%s" name="%s" value="%d" class="fpl-media__input">',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			$attachment_id
		);

		printf(
			'<div class="fpl-media__preview"%s><img src="%s" alt=""></div>',
			$preview ? '' : ' style="display:none"',
			esc_url( (string) $preview )
		);

		printf(
			'<p class="fpl-media__actions"><button type="button" class="button fpl-media__select">%s</button> <button type="button" class="button-link fpl-media__remove"%s>%s</button></p>',
			esc_html__( 'Choisir une image', 'facture-pdf-lumia' ),
			$attachment_id ? '' : ' style="display:none"',
			esc_html__( 'Retirer', 'facture-pdf-lumia' )
		);

		echo '</div>';
	}

	/**
	 * Résout les options d'un champ, éventuellement fournies par un callable.
	 *
	 * @param array<string, mixed> $field Définition.
	 *
	 * @return array<string|int, string>
	 */
	private static function options( array $field ): array {
		$options = $field['options'] ?? array();

		if ( is_callable( $options ) ) {
			$options = call_user_func( $options );
		}

		return is_array( $options ) ? $options : array();
	}

	/**
	 * Champ répéteur : une liste de lignes ajoutables/supprimables en direct.
	 *
	 * S'appuie sur `x-for` d'Alpine plutôt que sur du JavaScript de clonage
	 * de DOM manuel. C'est praticable précisément parce que l'état du
	 * formulaire (`values`) est désormais rendu côté serveur avec les
	 * valeurs réelles dès le premier chargement — voir SettingsPage — donc
	 * `values['<clé>']` est déjà un tableau exploitable au moment où Alpine
	 * démarre, sans course avec un script qui le poserait après coup.
	 *
	 * Chaque case à cocher de ligne est précédée d'un champ caché à `0`,
	 * exactement comme pour un champ de premier niveau : une case décochée
	 * n'est jamais envoyée par le navigateur, seul ce repli garantit que la
	 * ligne repasse bien à faux plutôt que de conserver sa dernière valeur
	 * connue côté serveur.
	 *
	 * @param string               $key   Clé du champ.
	 * @param array<string, mixed> $field Définition, avec `row_fields`.
	 * @param mixed                $value Valeur courante (non utilisée : l'état vient de `values` côté Alpine).
	 */
	private static function repeater( string $key, array $field, $value ): void {
		$row_fields = (array) ( $field['row_fields'] ?? array() );
		$name_base  = "'fpl[" . $key . "][' + index + ']";

		printf( '<div class="fpl-repeater" data-repeater="%s">', esc_attr( $key ) );

		printf(
			'<template x-for="(row, index) in (values[\'%1$s\'] || [])" :key="index">',
			esc_js( $key )
		);

		echo '<div class="fpl-repeater__row">';

		foreach ( $row_fields as $sub_key => $sub_field ) {
			self::repeater_row_field( $name_base, $sub_key, $sub_field );
		}

		printf(
			'<button type="button" class="button-link fpl-repeater__remove" @click="values[\'%1$s\'].splice(index, 1)">%2$s</button>',
			esc_js( $key ),
			esc_html__( 'Supprimer cette ligne', 'facture-pdf-lumia' )
		);

		echo '</div>'; // .fpl-repeater__row
		echo '</template>';

		printf(
			'<button type="button" class="button fpl-repeater__add" @click="values[\'%1$s\'].push(%2$s)">%3$s</button>',
			esc_js( $key ),
			esc_attr( (string) wp_json_encode( self::empty_row( $row_fields ) ) ),
			esc_html__( '+ Ajouter une ligne', 'facture-pdf-lumia' )
		);

		echo '</div>'; // .fpl-repeater
	}

	/**
	 * Rend un sous-champ d'une ligne de répéteur.
	 *
	 * Les attributs `name` et `x-model` sont construits dynamiquement (via
	 * `:name`, qu'Alpine résout à l'affichage) puisque l'index de la ligne
	 * n'est connu qu'au moment du rendu par `x-for`, pas à la génération PHP
	 * du gabarit commun à toutes les lignes.
	 *
	 * @param string               $name_base Expression Alpine du nom de champ, jusqu'à la sous-clé.
	 * @param string               $sub_key   Sous-clé (ex. `title`, `enabled`).
	 * @param array<string, mixed> $sub_field Définition du sous-champ.
	 */
	private static function repeater_row_field( string $name_base, string $sub_key, array $sub_field ): void {
		$type      = $sub_field['type'] ?? 'text';
		$name_expr = $name_base . " + '[" . $sub_key . "]'";

		switch ( $type ) {
			case 'checkbox':
				printf( '<input type="hidden" :name="%s" value="0">', esc_attr( $name_expr ) );
				printf(
					'<label class="fpl-switch fpl-repeater__enabled"><input type="checkbox" :name="%s" x-model="row.%s"><span class="fpl-switch__track"></span></label>',
					esc_attr( $name_expr ),
					esc_attr( $sub_key )
				);
				break;

			case 'select':
				printf( '<select :name="%s" x-model="row.%s" class="fpl-repeater__select">', esc_attr( $name_expr ), esc_attr( $sub_key ) );

				foreach ( (array) ( $sub_field['options'] ?? array() ) as $option_value => $option_label ) {
					printf(
						'<option value="%s">%s</option>',
						esc_attr( (string) $option_value ),
						esc_html( (string) $option_label )
					);
				}

				echo '</select>';
				break;

			case 'textarea':
				printf(
					'<textarea :name="%s" x-model="row.%s" rows="%d" class="large-text fpl-repeater__body" placeholder="%s"></textarea>',
					esc_attr( $name_expr ),
					esc_attr( $sub_key ),
					absint( $sub_field['rows'] ?? 3 ),
					esc_attr( (string) ( $sub_field['placeholder'] ?? '' ) )
				);
				break;

			case 'text':
			default:
				printf(
					'<input type="text" :name="%s" x-model="row.%s" class="fpl-repeater__title" placeholder="%s">',
					esc_attr( $name_expr ),
					esc_attr( $sub_key ),
					esc_attr( (string) ( $sub_field['placeholder'] ?? '' ) )
				);
				break;
		}
	}

	/**
	 * Ligne vide, pour le bouton d'ajout.
	 *
	 * @param array<string, array<string, mixed>> $row_fields Sous-schéma du répéteur.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_row( array $row_fields ): array {
		$row = array();

		foreach ( $row_fields as $sub_key => $sub_field ) {
			$row[ $sub_key ] = $sub_field['default'] ?? ( 'checkbox' === ( $sub_field['type'] ?? '' ) ? false : '' );
		}

		return $row;
	}
}
