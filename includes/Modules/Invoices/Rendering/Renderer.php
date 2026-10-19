<?php
/**
 * Invoice renderer.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Rendering;

defined( 'ABSPATH' ) || exit;

use WC_Order;

/**
 * Order + settings -> HTML -> PDF.
 */
final class Renderer {

	/**
	 * Invoice HTML (a complete document, styles inline).
	 *
	 * @param WC_Order             $order    Order.
	 * @param DocumentData         $doc      Document.
	 * @param array<string, mixed> $settings Invoice settings (`Module::settings()`).
	 * @return string HTML, '' when the template is missing.
	 */
	public static function html( WC_Order $order, DocumentData $doc, array $settings ): string {
		$design = isset( $settings['design'] ) && is_array( $settings['design'] ) ? $settings['design'] : [];
		$vars   = Context::build( $order, $doc, $settings );

		$vars['doc']   = $doc;
		$vars['order'] = $order;
		$vars['css']   = Styles::css( $design );

		ob_start();
		$found = self::include_template( 'invoice.php', $vars );
		$html  = (string) ob_get_clean();

		return $found ? $html : '';
	}

	/**
	 * Invoice PDF bytes.
	 *
	 * @param WC_Order             $order    Order.
	 * @param DocumentData         $doc      Document.
	 * @param array<string, mixed> $settings Invoice settings (`Module::settings()`).
	 * @return string `%PDF` bytes, '' on failure.
	 */
	public static function pdf( WC_Order $order, DocumentData $doc, array $settings ): string {
		$html = self::html( $order, $doc, $settings );
		if ( '' === $html ) {
			return '';
		}

		$design  = isset( $settings['design'] ) && is_array( $settings['design'] ) ? $settings['design'] : [];
		$content = isset( $settings['content'] ) && is_array( $settings['content'] ) ? $settings['content'] : [];

		return PdfEngine::render( $html, $design, ! empty( $content['footer_pagination_enabled'] ) );
	}

	/**
	 * Prints a template of `templates/pdf/` with variables in scope. Used by `invoice.php` for its partials.
	 *
	 * @param string               $name Template path relative to `templates/pdf/` (`partials/header.php`).
	 * @param array<string, mixed> $vars Variables.
	 */
	public static function partial( string $name, array $vars ): void {
		self::include_template( $name, $vars );
	}

	/**
	 * Includes a template.
	 *
	 * @param string               $name Template path relative to `templates/pdf/`.
	 * @param array<string, mixed> $vars Variables.
	 * @return bool False when no readable file was found.
	 */
	private static function include_template( string $name, array $vars ): bool {
		$default = LUMIA_FACTURES_TEMPLATES_DIR . 'pdf/' . $name;

		/**
		 * Filters the file used for a PDF template (invoice.php, partials/totals.php...).
		 *
		 * The theme is never searched: an override is an explicit decision of whoever hooks here.
		 *
		 * @param string $path Absolute path of the template file.
		 * @param string $name Template name, relative to `templates/pdf/`.
		 */
		$path = apply_filters( 'lumia_factures_template_path', $default, $name );
		$path = is_string( $path ) && is_readable( $path ) ? $path : $default;

		if ( ! is_readable( $path ) ) {
			error_log( '[lumia-factures] PDF template not found: ' . $name ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
			return false;
		}

		( static function ( string $lumia_factures_file, array $lumia_factures_vars ): void {
			extract( $lumia_factures_vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables.
			include $lumia_factures_file;
		} )( $path, $vars );

		return true;
	}
}
