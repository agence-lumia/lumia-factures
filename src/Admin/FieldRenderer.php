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

			case 'code':
				self::textarea( $key, $field, $value, 'fpl-code' );
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
}
