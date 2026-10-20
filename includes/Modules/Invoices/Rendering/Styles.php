<?php
/**
 * Stylesheet of the invoice.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Rendering;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Settings\Schema;

/**
 * Compiles the design settings into the CSS handed to Dompdf.
 *
 * `templates/pdf/style.css` is written with `var(--lf-*)` custom properties, which Dompdf does not
 * understand: they are substituted here, so the sheet stays readable and Dompdf receives literals.
 */
final class Styles {

	/**
	 * Spacing multiplier per density.
	 */
	private const DENSITIES = [
		'compact' => 0.72,
		'normal'  => 1.0,
		'airy'    => 1.32,
	];

	/**
	 * Inter variants shipped in `assets/fonts/Inter/`.
	 */
	private const INTER_VARIANTS = [ 'Regular', 'Italic', 'Bold', 'BoldItalic' ];

	/**
	 * Compiled CSS.
	 *
	 * @param array<string, mixed> $design Design settings.
	 */
	public static function css( array $design ): string {
		$design    = array_merge( Schema::defaults()['design'], $design );
		$variables = self::variables( $design );

		$path  = LUMIA_FACTURES_TEMPLATES_DIR . 'pdf/style.css';
		$sheet = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.

		// The documentation block of default values is useless to Dompdf.
		$sheet = (string) preg_replace( '/:root\s*\{[^}]*\}/s', '', $sheet );
		$sheet = (string) preg_replace_callback(
			'/var\(\s*--lf-([a-z0-9-]+)\s*\)/i',
			static fn( array $matches ): string => $variables[ $matches[1] ] ?? 'inherit',
			$sheet
		);

		$fonts = [ (string) $design['font_body'], (string) $design['font_heading'] ];

		return trim( ( in_array( 'inter', $fonts, true ) ? self::inter_font_faces() : '' ) . $sheet );
	}

	/**
	 * CSS variables, without the `--lf-` prefix.
	 *
	 * @param array<string, mixed> $design Complete design settings.
	 * @return array<string, string>
	 */
	private static function variables( array $design ): array {
		$density = self::DENSITIES[ (string) $design['density'] ] ?? 1.0;
		$size    = (float) $design['font_size'];
		$radius  = (int) $design['radius'];
		$border  = (float) $design['border_width'];
		$bottom  = (float) $design['margin_bottom'];

		$variables = [
			'font-body'           => self::font_stack( (string) $design['font_body'] ),
			'font-heading'        => self::font_stack( (string) $design['font_heading'] ),
			'font-size'           => self::pt( $size ),
			'font-size-sm'        => self::pt( $size * 0.88 ),
			'font-size-xs'        => self::pt( $size * 0.78 ),
			'font-size-lg'        => self::pt( $size * 1.15 ),
			'font-size-title'     => self::pt( $size * (float) $design['heading_scale'] ),
			'font-size-h3'        => self::pt( $size * 1.05 ),
			'line-height'         => (string) round( (float) $design['line_height'], 2 ),
			'heading-weight'      => ! empty( $design['heading_bold'] ) ? 'bold' : 'normal',
			'radius'              => $radius . 'px',
			'radius-sm'           => max( 0, (int) round( $radius * 0.6 ) ) . 'px',
			'border-width'        => self::pt( $border ),
			'border-width-strong' => self::pt( $border * 1.6 ),
			'space-xs'            => self::pt( 2 * $density ),
			'space-sm'            => self::pt( 4 * $density ),
			'space-md'            => self::pt( 8 * $density ),
			'space-lg'            => self::pt( 14 * $density ),
			'cell-padding-y'      => self::pt( 6 * $density ),
			'cell-padding-x'      => self::pt( 7 * $density ),
			'thumb-width'         => (int) $design['thumb_width'] . 'px',
			'margin-top'          => round( (float) $design['margin_top'], 1 ) . 'mm',
			'margin-bottom'       => round( $bottom, 1 ) . 'mm',
			'margin-sides'        => round( (float) $design['margin_sides'], 1 ) . 'mm',
			// Dompdf evaluates no calc(): the fixed footer sits in the bottom margin, 6 mm above the sheet edge.
			'footer-bottom'       => round( 6 - $bottom, 1 ) . 'mm',
		];

		foreach ( [ 'primary', 'text', 'muted', 'thead_bg', 'thead_text', 'row_alt', 'block_bg', 'border', 'page' ] as $color ) {
			// Re-validated here: the value ends up inside a stylesheet.
			$value = sanitize_hex_color( (string) $design[ 'color_' . $color ] );
			if ( empty( $value ) ) {
				$value = (string) Schema::defaults()['design'][ 'color_' . $color ];
			}
			$variables[ 'color-' . str_replace( '_', '-', $color ) ] = $value;
		}

		return $variables;
	}

	/**
	 * CSS font stack of a font setting.
	 *
	 * @param string $font `inter` or `dejavu-sans`.
	 */
	private static function font_stack( string $font ): string {
		return 'dejavu-sans' === $font ? "'DejaVu Sans', sans-serif" : "'Inter', 'DejaVu Sans', sans-serif";
	}

	/**
	 * `@font-face` rules of the bundled Inter files.
	 */
	private static function inter_font_faces(): string {
		$css = '';

		foreach ( self::INTER_VARIANTS as $variant ) {
			$file = LUMIA_FACTURES_PLUGIN_DIR . 'assets/fonts/Inter/Inter-' . $variant . '.ttf';
			if ( ! is_readable( $file ) ) {
				continue;
			}

			$css .= sprintf(
				"@font-face { font-family: 'Inter'; font-style: %s; font-weight: %s; src: url('file://%s') format('truetype'); }\n",
				str_contains( $variant, 'Italic' ) ? 'italic' : 'normal',
				str_contains( $variant, 'Bold' ) ? 'bold' : 'normal',
				str_replace( '\\', '/', $file )
			);
		}

		return $css;
	}

	/**
	 * A length in points.
	 *
	 * @param float $value Value.
	 */
	private static function pt( float $value ): string {
		return round( $value, 2 ) . 'pt';
	}
}
