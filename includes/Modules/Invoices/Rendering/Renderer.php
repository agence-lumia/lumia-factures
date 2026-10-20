<?php
/**
 * Invoice renderer.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Rendering;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;
use Lumia\Factures\Modules\Invoices\Amounts\Statement;
use WC_Order;

/**
 * Order + settings -> HTML -> PDF, for an invoice or a credit note.
 *
 * A credit note is rendered with the refunded order and the statement of its refund
 * (`CreditNoteStatementBuilder`), see `Context::build()`.
 */
final class Renderer {

	/**
	 * Document HTML (a complete document, styles inline), in the site language.
	 *
	 * A document is archived for good: it is rendered in the site locale, never in the language
	 * of the admin who happens to issue, rebuild or preview it.
	 *
	 * @param WC_Order             $order     Order; for a credit note, the refunded order.
	 * @param DocumentData         $doc       Document.
	 * @param array<string, mixed> $settings  Invoice settings (`Module::settings()`).
	 * @param Statement|null       $statement Amounts, null for the order's own (invoice). Required for a credit note.
	 * @return string HTML, '' when the template is missing.
	 * @throws InvalidArgumentException For a credit note without its statement or its invoice reference.
	 */
	public static function html( WC_Order $order, DocumentData $doc, array $settings, ?Statement $statement = null ): string {
		return self::in_site_locale( static fn(): string => self::build_html( $order, $doc, $settings, $statement ) );
	}

	/**
	 * Runs a callback in the site locale, then restores the request's locale.
	 *
	 * `switch_to_locale()` reloads every text domain already loaded (the plugin's included) and
	 * the date locale; it returns false when the request already runs in the site locale.
	 *
	 * @template T
	 * @param callable(): T $run Callback.
	 * @return T
	 */
	public static function in_site_locale( callable $run ) {
		$switched = switch_to_locale( get_locale() );

		try {
			return $run();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Builds the document HTML in the current locale.
	 *
	 * @param WC_Order             $order     Order.
	 * @param DocumentData         $doc       Document.
	 * @param array<string, mixed> $settings  Invoice settings.
	 * @param Statement|null       $statement Amounts, null for the order's own.
	 */
	private static function build_html( WC_Order $order, DocumentData $doc, array $settings, ?Statement $statement ): string {
		$design = isset( $settings['design'] ) && is_array( $settings['design'] ) ? $settings['design'] : [];
		$vars   = Context::build( $order, $doc, $settings, $statement );

		$vars['doc']   = $doc;
		$vars['order'] = $order;
		$vars['css']   = Styles::css( $design );

		ob_start();
		$found = self::include_template( 'invoice.php', $vars );
		$html  = (string) ob_get_clean();

		if ( ! $found ) {
			return '';
		}

		/**
		 * Filters the final HTML of an invoice or a credit note, before it is turned into a PDF.
		 *
		 * @param string       $html  Complete HTML document.
		 * @param WC_Order     $order Order (the refunded order for a credit note).
		 * @param DocumentData $doc   Document.
		 * @param string       $type  Document type, `invoice` or `credit_note` (`$doc->type`).
		 */
		$filtered = apply_filters( 'lumia_factures_invoice_html', $html, $order, $doc, $doc->type );

		return is_string( $filtered ) ? $filtered : $html;
	}

	/**
	 * Document PDF bytes.
	 *
	 * @param WC_Order             $order     Order; for a credit note, the refunded order.
	 * @param DocumentData         $doc       Document.
	 * @param array<string, mixed> $settings  Invoice settings (`Module::settings()`).
	 * @param Statement|null       $statement Amounts, null for the order's own (invoice). Required for a credit note.
	 * @return string `%PDF` bytes, '' on failure.
	 * @throws InvalidArgumentException For a credit note without its statement or its invoice reference.
	 */
	public static function pdf( WC_Order $order, DocumentData $doc, array $settings, ?Statement $statement = null ): string {
		// The page footer label is translated by the engine: the whole render runs in the site locale.
		return self::in_site_locale(
			static function () use ( $order, $doc, $settings, $statement ): string {
				$html = self::build_html( $order, $doc, $settings, $statement );
				if ( '' === $html ) {
					return '';
				}

				$design  = isset( $settings['design'] ) && is_array( $settings['design'] ) ? $settings['design'] : [];
				$content = isset( $settings['content'] ) && is_array( $settings['content'] ) ? $settings['content'] : [];

				return PdfEngine::render( $html, $design, ! empty( $content['footer_pagination_enabled'] ) );
			}
		);
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
