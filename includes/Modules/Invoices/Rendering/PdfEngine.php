<?php
/**
 * Dompdf wrapper.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Rendering;

defined( 'ABSPATH' ) || exit;

use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;

/**
 * Renders invoice HTML to PDF bytes. Never throws: a failure is logged and gives ''.
 */
final class PdfEngine {

	/**
	 * Renders the HTML.
	 *
	 * @param string               $html       Complete HTML document.
	 * @param array<string, mixed> $design     Design settings.
	 * @param bool                 $pagination Draw "Page n / N" at the foot of every page.
	 * @return string `%PDF` bytes, '' on failure.
	 */
	public static function render( string $html, array $design, bool $pagination = true ): string {
		if ( ! class_exists( Dompdf::class ) ) {
			self::log( 'Dompdf is missing: the vendor directory is absent or damaged.' );
			return '';
		}

		if ( '' === trim( $html ) ) {
			return '';
		}

		wp_raise_memory_limit( 'image' );

		try {
			$dompdf = new Dompdf( self::options() );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();

			if ( $pagination ) {
				self::draw_pagination( $dompdf, $design );
			}

			$output = (string) $dompdf->output();
		} catch ( Throwable $e ) {
			self::log( 'PDF rendering failed: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			return '';
		}

		if ( ! str_starts_with( $output, '%PDF' ) ) {
			self::log( 'PDF rendering produced no PDF.' );
			return '';
		}

		return $output;
	}

	/**
	 * Dompdf options.
	 */
	public static function options(): Options {
		$cache = trailingslashit( get_temp_dir() ) . 'lumia-factures-fonts';
		wp_mkdir_p( $cache );

		$options = new Options();
		// Every image is a data URI: nothing may be fetched, which also closes the SSRF door.
		$options->setIsRemoteEnabled( false );
		$options->setIsHtml5ParserEnabled( true );
		$options->setDefaultFont( 'DejaVu Sans' );
		$options->setFontDir( $cache );
		$options->setFontCache( $cache );
		$options->setTempDir( get_temp_dir() );
		$options->setChroot( [ LUMIA_FACTURES_PLUGIN_DIR ] );

		/**
		 * Filters the Dompdf options.
		 *
		 * @param Options $options Options.
		 */
		$filtered = apply_filters( 'lumia_factures_dompdf_options', $options );

		return $filtered instanceof Options ? $filtered : $options;
	}

	/**
	 * Draws "Page n / N" at the foot of every page.
	 *
	 * Done on the canvas after the layout: inside an element, Dompdf cannot know the page count yet.
	 *
	 * @param Dompdf               $dompdf Rendered document.
	 * @param array<string, mixed> $design Design settings.
	 */
	private static function draw_pagination( Dompdf $dompdf, array $design ): void {
		$canvas  = $dompdf->getCanvas();
		$metrics = $dompdf->getFontMetrics();
		$size    = max( 6.0, ( isset( $design['font_size'] ) && is_numeric( $design['font_size'] ) ? (float) $design['font_size'] : 9.5 ) - 2.5 );
		$font    = $metrics->getFont( 'DejaVu Sans', 'normal' );

		if ( null === $font ) {
			return;
		}

		$text = sprintf(
			/* translators: 1: current page number, 2: page count */
			__( 'Page %1$s / %2$s', 'lumia-factures' ),
			'{PAGE_NUM}',
			'{PAGE_COUNT}'
		);

		// page_text() has no alignment: centre on a sample, the markers being replaced by shorter numbers.
		$sample = str_replace( [ '{PAGE_NUM}', '{PAGE_COUNT}' ], [ '00', '00' ], $text );
		$width  = $metrics->getTextWidth( $sample, $font, $size );
		$left   = max( 0.0, ( $canvas->get_width() - $width ) / 2 );

		$canvas->page_text( $left, $canvas->get_height() - 14.0, $text, $font, $size, [ 0.45, 0.45, 0.45 ] );
	}

	/**
	 * Logs a line.
	 *
	 * @param string $message Message.
	 */
	private static function log( string $message ): void {
		error_log( '[lumia-factures] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
	}
}
