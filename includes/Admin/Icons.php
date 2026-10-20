<?php
/**
 * Inline SVG icons of the admin interface.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Lucide v1.34.0 icons (official paths, copied as is), limited to the ones the
 * plugin screens use.
 */
class Icons {

	/**
	 * Fallback icon for unknown names.
	 */
	public const FALLBACK = 'package';

	/**
	 * Returns the inline SVG of an icon.
	 *
	 * @param string $icon        Lucide icon name.
	 * @param string $size        Size modifier (sm, md, lg, xl).
	 * @param string $extra_class Additional CSS classes.
	 */
	public static function render( string $icon, string $size = 'md', string $extra_class = '' ): string {
		$paths = self::paths();
		$path  = $paths[ $icon ] ?? $paths[ self::FALLBACK ];
		$class = trim( 'lumia-icon lumia-icon--' . $size . ' ' . $extra_class );

		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/**
	 * Help marker: Lucide `info` icon carrying a clarification in a tooltip.
	 *
	 * The SVG is emitted here rather than by render(): `.lumia-icon` forces 20px
	 * with !important (to stand up to wp-admin), which would overflow the 16px
	 * button.
	 *
	 * @param string $text      Tooltip text.
	 * @param string $placement Tooltip placement (top, bottom, left, right).
	 */
	public static function help_tip( string $text, string $placement = 'top' ): string {
		return '<button type="button" class="lumia-tip-info" tabindex="0"'
			. ' data-lumia-tip="' . esc_attr( $text ) . '"'
			. ( 'top' === $placement ? '' : ' data-lumia-tip-placement="' . esc_attr( $placement ) . '"' )
			. ' aria-label="' . esc_attr( $text ) . '">'
			. '<svg class="lumia-tip-info__i" width="14" height="14" viewBox="0 0 24 24" fill="none"'
			. ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
			. ' aria-hidden="true" focusable="false">' . self::paths()['info'] . '</svg>'
			. '</button>';
	}

	/**
	 * Lucide name => inner SVG content.
	 *
	 * @return array<string, string>
	 */
	public static function paths(): array {
		return [
			'package'      => '<path d="m7.5 4.27 9 5.15"></path><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path>',
			'settings'     => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/>',
			'check-circle' => '<circle cx="12" cy="12" r="10"></circle><path d="m9 12 2 2 4-4"></path>',
			'circle-alert' => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
			'info'         => '<circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path>',
			'x'            => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
			'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
			'file-text'    => '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
			'blocks'       => '<path d="M10 22V7a1 1 0 0 0-1-1H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5a1 1 0 0 0-1-1H2"/><rect x="14" y="2" width="8" height="8" rx="1"/>',
			'minus'        => '<path d="M5 12h14"/>',
			'plus'         => '<path d="M5 12h14"/><path d="M12 5v14"/>',
			'refresh-cw'   => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
			'check'        => '<path d="M20 6 9 17l-5-5"/>',
		];
	}
}
