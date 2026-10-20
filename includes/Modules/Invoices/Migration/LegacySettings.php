<?php
/**
 * Maps the legacy Facture PDF Lumia settings to the invoice settings.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Settings\Schema;

/**
 * Pure mapping of the legacy `fpl_settings` option (1.x) to the lot 2 schema.
 *
 * The groups and keys are the same (lot 2 kept them); the output only holds the keys the
 * legacy option had, so it must still go through Schema::sanitize() before it is stored.
 */
final class LegacySettings {

	/**
	 * French defaults of the legacy labels and titles, keyed by `content` key.
	 *
	 * Source: `git show v1.1.1:src/Settings/Schema.php` (content tab and column_fields()) and
	 * `v1.1.1:src/Rendering/ContextBuilder.php` (fallbacks). A stored value equal to its legacy
	 * default was never customised: it maps to '' so the translated default of lot 2 applies.
	 * `message_title` and `message_body` are not here: lot 2 prints them as free texts with no
	 * translated fallback, so emptying them would remove the message from the invoice.
	 */
	private const LABEL_DEFAULTS = [
		'invoice_title'         => 'Facture',
		'billing_title'         => "Facturer \u{00e0} :",
		'shipping_title'        => "Livrer \u{00e0} :",
		'customer_note_label'   => 'Note du client',
		'col_image_label'       => 'Image',
		'col_name_label'        => 'Produit',
		'col_description_label' => 'Description',
		'col_sku_label'         => "R\u{00e9}f\u{00e9}rence",
		'col_quantity_label'    => "Qt\u{00e9}",
		'col_unit_price_label'  => 'Prix unitaire',
		'col_tax_rate_label'    => 'TVA',
		'col_total_label'       => 'Total',
	];

	/**
	 * Legacy keys lot 2 removed, with their legacy default (same source). `content.legal_mentions`
	 * is handled apart: its default is a library of disabled entries.
	 */
	private const REMOVED_DEFAULTS = [
		'general' => [
			'retention_days' => 7,
			'debug_mode'     => false,
		],
		'content' => [
			'total_refunded_enabled' => true,
		],
		'design'  => [
			'template'        => 'default',
			'custom_css'      => '',
			// Removed after 2.0.0: the PDF template never printed it.
			'color_secondary' => '#6b6b6b',
		],
	];

	/**
	 * Legacy font names that have an equivalent in lot 2 (lowercased name => lot 2 option).
	 */
	private const FONTS = [
		'inter' => 'inter',
	];

	/**
	 * Maps the legacy settings.
	 *
	 * @param array<string, mixed> $legacy Legacy `fpl_settings` value.
	 * @return array{settings: array<string, array<string, mixed>>, notes: string[]} Partial settings and
	 *                                                                             what could not be imported.
	 */
	public static function map( array $legacy ): array {
		$settings = [];
		$notes    = [];

		foreach ( Schema::fields() as $group => $fields ) {
			if ( ! isset( $legacy[ $group ] ) || ! is_array( $legacy[ $group ] ) ) {
				continue;
			}

			foreach ( $legacy[ $group ] as $key => $value ) {
				$key = (string) $key;

				if ( ! isset( $fields[ $key ] ) ) {
					$note = self::dropped( $group, $key, $value );
					if ( null !== $note ) {
						$notes[] = $note;
					}
					continue;
				}

				$settings[ $group ][ $key ] = self::value( $group, $key, $fields[ $key ], $value, $notes );
			}
		}

		return [
			'settings' => $settings,
			'notes'    => $notes,
		];
	}

	/**
	 * Converts one legacy value to the lot 2 shape.
	 *
	 * @param string               $group Group.
	 * @param string               $key   Key.
	 * @param array<string, mixed> $field Lot 2 field definition.
	 * @param mixed                $value Legacy value.
	 * @param string[]             $notes Notes, appended to.
	 * @return mixed
	 */
	private static function value( string $group, string $key, array $field, $value, array &$notes ) {
		if ( 'content' === $group && isset( self::LABEL_DEFAULTS[ $key ] ) && is_scalar( $value ) ) {
			$text = trim( (string) $value );
			return self::LABEL_DEFAULTS[ $key ] === $text ? '' : $value;
		}

		switch ( $field['type'] ) {
			case 'statuses':
			case 'emails':
				if ( ! is_array( $value ) ) {
					return [];
				}
				return array_values( array_filter( $value, static fn( $entry ): bool => is_string( $entry ) && '' !== $entry ) );

			case 'color':
				return is_string( $value ) ? strtolower( $value ) : $value;

			case 'textarea':
				return is_string( $value ) ? str_replace( [ "\r\n", "\r" ], "\n", $value ) : $value;
		}

		if ( 'design' === $group && in_array( $key, [ 'font_heading', 'font_body' ], true ) ) {
			$font = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
			if ( isset( self::FONTS[ $font ] ) ) {
				return self::FONTS[ $font ];
			}
			if ( in_array( $font, (array) ( $field['options'] ?? [] ), true ) ) {
				return $font;
			}

			$notes[] = sprintf(
				/* translators: 1: setting name, e.g. design.font_body, 2: legacy font name */
				__( '%1$s "%2$s" is not available: replaced with Inter.', 'lumia-factures' ),
				$group . '.' . $key,
				is_scalar( $value ) ? (string) $value : ''
			);
			return 'inter';
		}

		return $value;
	}

	/**
	 * The note for a legacy key lot 2 does not have, or null when it held its default.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param mixed  $value Legacy value.
	 */
	private static function dropped( string $group, string $key, $value ): ?string {
		$name = $group . '.' . $key;

		if ( 'content' === $group && 'legal_mentions' === $key ) {
			$enabled = 0;
			foreach ( is_array( $value ) ? $value : [] as $mention ) {
				if ( is_array( $mention ) && ! empty( $mention['enabled'] ) ) {
					++$enabled;
				}
			}

			if ( 0 === $enabled ) {
				return null;
			}

			return sprintf(
				/* translators: 1: setting name, 2: number of legal mentions */
				_n( '%1$s had %2$d entry: re-enter it in "Legal text".', '%1$s had %2$d entries: re-enter them in "Legal text".', $enabled, 'lumia-factures' ),
				$name,
				$enabled
			);
		}

		$removed = self::REMOVED_DEFAULTS[ $group ] ?? [];
		if ( array_key_exists( $key, $removed ) && self::same( $removed[ $key ], $value ) ) {
			return null;
		}

		/* translators: %s: setting name, e.g. design.custom_css */
		return sprintf( __( '%s was set and is no longer supported: it was not imported.', 'lumia-factures' ), $name );
	}

	/**
	 * Loose equality of a legacy value with its default (`7` and `"7"`, `false` and `"0"`).
	 *
	 * @param mixed $default_value Default.
	 * @param mixed $value         Stored value.
	 */
	private static function same( $default_value, $value ): bool {
		if ( is_bool( $default_value ) ) {
			return in_array( $value, [ true, 1, '1', 'true', 'on', 'yes' ], true ) === $default_value;
		}

		// Case-insensitive: the legacy colors were stored as typed ("#6B6B6B" is the default "#6b6b6b").
		return is_scalar( $value ) && strtolower( (string) $default_value ) === strtolower( (string) $value );
	}
}
