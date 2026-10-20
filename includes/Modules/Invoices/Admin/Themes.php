<?php
/**
 * Ready-made color themes of the Appearance tab.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Settings\Schema;

/**
 * A theme is a full set of the design colors. Picking one only fills the color fields of the
 * form (assets/admin/js/invoices.js): nothing is stored but the colors, which stay editable.
 *
 * Every theme keeps the header text readable: `color_thead_text` is printed on both
 * `color_primary` (title band, grand total) and `color_thead_bg` (table header).
 */
final class Themes {

	/**
	 * Color keys of a theme, in the Schema order.
	 */
	public const KEYS = [ 'color_primary', 'color_text', 'color_muted', 'color_thead_bg', 'color_thead_text', 'color_row_alt', 'color_block_bg', 'color_border', 'color_page' ];

	/**
	 * Themes, keyed by id: a label and the colors keyed like the design settings.
	 *
	 * @return array<string, array{label: string, colors: array<string, string>}>
	 */
	public static function all(): array {
		$defaults = Schema::defaults()['design'];
		$classic  = [];
		foreach ( self::KEYS as $key ) {
			$classic[ $key ] = (string) $defaults[ $key ];
		}

		return [
			'classic'    => [
				'label'  => __( 'Black', 'lumia-factures' ),
				'colors' => $classic,
			],
			'navy'       => [
				'label'  => __( 'Navy', 'lumia-factures' ),
				'colors' => self::colors( '#1e3a8a', '#0f172a', '#64748b', '#1e3a8a', '#ffffff', '#f8fafc', '#eff4fb', '#d6deea', '#ffffff' ),
			],
			'forest'     => [
				'label'  => __( 'Forest', 'lumia-factures' ),
				'colors' => self::colors( '#14532d', '#111827', '#6b7280', '#14532d', '#ffffff', '#f7faf8', '#eef6f0', '#d5e3d9', '#ffffff' ),
			],
			'terracotta' => [
				'label'  => __( 'Terracotta', 'lumia-factures' ),
				'colors' => self::colors( '#9a3412', '#1c1917', '#78716c', '#9a3412', '#ffffff', '#fcfaf8', '#f8f1ec', '#e7ddd5', '#ffffff' ),
			],
			'plum'       => [
				'label'  => __( 'Plum', 'lumia-factures' ),
				'colors' => self::colors( '#581c87', '#18181b', '#71717a', '#581c87', '#ffffff', '#faf8fc', '#f5f0fa', '#e4dcec', '#ffffff' ),
			],
		];
	}

	/**
	 * Colors keyed like the settings, from positional values in the KEYS order.
	 *
	 * @param string ...$values One hex color per key of KEYS.
	 * @return array<string, string>
	 */
	private static function colors( string ...$values ): array {
		return array_combine( self::KEYS, $values );
	}
}
