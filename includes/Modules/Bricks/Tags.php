<?php
/**
 * Bricks dynamic tags of the order documents.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Bricks;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Front\Availability;
use Lumia\Factures\Modules\Invoices\Front\CustomerDownload;
use Lumia\Factures\Modules\Invoices\Front\DocumentList;
use Lumia\Factures\Modules\Invoices\Front\OrderContext;
use Lumia\Factures\Modules\Invoices\Front\Shortcode;
use WC_Order;

/**
 * Tags of the Bricks editor:
 *
 * - `{lumia_factures_invoice_url}`, `{lumia_factures_invoice_number}`, `{lumia_factures_invoice_status}`;
 * - `{lumia_factures_credit_note_url}`, `{lumia_factures_credit_note_number}`,
 *   `{lumia_factures_credit_note_status}`: the order's latest available credit note;
 * - `{lumia_factures_documents}`: the HTML of the documents block (a Rich text or Code element);
 *
 * plus the Facture PDF Lumia names (`{fpl_invoice_*}`) that existing templates use: rendered the same
 * way, not listed in the picker. There is no `fpl_` name for the credit notes or the documents.
 *
 * The hooks are added unconditionally: without Bricks the `bricks/*` filters never fire.
 */
final class Tags {

	/**
	 * Every tag name (without braces) with its resolver. The legacy names map to the same resolvers.
	 *
	 * @return array<string, callable(mixed): string>
	 */
	private static function resolvers(): array {
		$invoice     = static fn( string $field ): callable => static fn( $post ): string => self::invoice( $field, $post );
		$credit_note = static fn( string $field ): callable => static fn( $post ): string => self::credit_note( $field, $post );

		return [
			'lumia_factures_invoice_url'        => $invoice( 'url' ),
			'lumia_factures_invoice_number'     => $invoice( 'number' ),
			'lumia_factures_invoice_status'     => $invoice( 'status' ),
			'lumia_factures_credit_note_url'    => $credit_note( 'url' ),
			'lumia_factures_credit_note_number' => $credit_note( 'number' ),
			'lumia_factures_credit_note_status' => $credit_note( 'status' ),
			'lumia_factures_documents'          => static fn( $post ): string => self::documents( $post ),
			'fpl_invoice_url'                   => $invoice( 'url' ),
			'fpl_invoice_number'                => $invoice( 'number' ),
			'fpl_invoice_status'                => $invoice( 'status' ),
		];
	}

	/**
	 * Tags listed in the picker, with their label (the legacy names stay out of it).
	 *
	 * A method rather than a constant: `__()` needs a literal string to be found by the translation tools.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return [
			'lumia_factures_invoice_url'        => __( 'Invoice URL', 'lumia-factures' ),
			'lumia_factures_invoice_number'     => __( 'Invoice number', 'lumia-factures' ),
			'lumia_factures_invoice_status'     => __( 'Invoice status', 'lumia-factures' ),
			'lumia_factures_credit_note_url'    => __( 'Latest credit note URL', 'lumia-factures' ),
			'lumia_factures_credit_note_number' => __( 'Latest credit note number', 'lumia-factures' ),
			'lumia_factures_credit_note_status' => __( 'Latest credit note status', 'lumia-factures' ),
			'lumia_factures_documents'          => __( 'Order documents (HTML list)', 'lumia-factures' ),
		];
	}

	/**
	 * Adds the hooks.
	 */
	public static function register(): void {
		add_filter( 'bricks/dynamic_tags_list', [ self::class, 'list_tags' ] );
		add_filter( 'bricks/dynamic_data/render_tag', [ self::class, 'render_tag' ], 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', [ self::class, 'render_content' ], 20, 3 );
		add_filter( 'bricks/frontend/render_data', [ self::class, 'render_content' ], 20, 2 );
	}

	/**
	 * Lists the tags in the Bricks picker (the legacy names stay out of it).
	 *
	 * @param mixed $tags Tags already registered.
	 * @return mixed
	 */
	public static function list_tags( $tags ) {
		if ( ! is_array( $tags ) ) {
			return $tags;
		}

		foreach ( self::labels() as $name => $label ) {
			$tags[] = [
				'name'  => '{' . $name . '}',
				'label' => $label,
				'group' => 'Lümia Factures',
			];
		}

		return $tags;
	}

	/**
	 * Resolves a tag set alone on an element (a button, for example).
	 *
	 * @param mixed  $tag     Tag as written in Bricks, with or without braces.
	 * @param mixed  $post    Current object given by Bricks.
	 * @param string $context Render context.
	 * @return mixed
	 */
	public static function render_tag( $tag, $post = null, $context = 'text' ) {
		unset( $context );

		// Bricks can pass structured values through this filter when it resolves some controls;
		// they are not ours and casting them to a string would raise "Array to string conversion".
		if ( ! is_string( $tag ) ) {
			return $tag;
		}

		$resolvers = self::resolvers();
		$name      = trim( $tag, '{}' );
		if ( ! isset( $resolvers[ $name ] ) ) {
			return $tag;
		}

		return $resolvers[ $name ]( $post );
	}

	/**
	 * Resolves the tags found inside a larger content.
	 *
	 * The short circuit tests the real tag names: a prefix test would miss a tag whose name does not
	 * start with it (`{lumia_factures_documents}` against `lumia_factures_invoice_`).
	 *
	 * @param mixed  $content Content that may hold one or several tags.
	 * @param mixed  $post    Current object given by Bricks.
	 * @param string $context Render context.
	 * @return mixed
	 */
	public static function render_content( $content, $post = null, $context = 'text' ) {
		unset( $context );

		if ( ! is_string( $content ) || ! str_contains( $content, '{' ) ) {
			return $content;
		}

		foreach ( self::resolvers() as $name => $resolve ) {
			$tag = '{' . $name . '}';
			if ( str_contains( $content, $tag ) ) {
				$value = $resolve( $post );
				// Usually inside an href: a URL is escaped (a filter may change it). The documents
				// list is HTML escaped by its template.
				$content = str_replace( $tag, str_ends_with( $name, '_url' ) ? esc_url( $value ) : $value, $content );
			}
		}

		return $content;
	}

	/**
	 * Value of an invoice tag for the current object.
	 *
	 * The order comes from the page context, but a builder can hand over any post: the three tags give
	 * nothing to a visitor who could not download the invoice. The status then reads `none`, so a
	 * condition on it hides the element instead of leaving a dead link.
	 *
	 * The status is `available` only when the download link works: an issued invoice whose PDF was
	 * never built and can be built by the endpoint, or whose archive passes verification
	 * (Availability::is_available()). Anything else is `none`, the URL being empty in the same cases.
	 * Nothing here may write: Issuer::can_issue() notes and saves the order, a front render must not.
	 *
	 * @param string $field `url`, `number` or `status`.
	 * @param mixed  $post  Current object given by Bricks.
	 */
	private static function invoice( string $field, $post ): string {
		$order = OrderContext::resolve( $post );

		if ( ! $order instanceof WC_Order || ! CustomerDownload::can_access( $order, OrderContext::current_order_key() ) ) {
			return 'status' === $field ? 'none' : '';
		}

		$doc       = Register::find( Issuer::TYPE, $order->get_id() );
		$issued    = null !== $doc && 'issued' === $doc->status;
		$available = $issued && Availability::is_available( $doc );

		switch ( $field ) {
			case 'url':
				return $available ? CustomerDownload::url( $order ) : '';

			case 'number':
				return $issued ? (string) $doc->number : '';

			default:
				return $available ? 'available' : 'none';
		}
	}

	/**
	 * Value of a credit note tag: the order's latest available credit note (DocumentList order).
	 *
	 * Same rules as the invoice tags, with the credit note access check: nothing without access, the
	 * status `none` when there is no available credit note. Reads only.
	 *
	 * @param string $field `url`, `number` or `status`.
	 * @param mixed  $post  Current object given by Bricks.
	 */
	private static function credit_note( string $field, $post ): string {
		$order = OrderContext::resolve( $post );

		$latest = null;
		if ( $order instanceof WC_Order && CustomerDownload::can_access( $order, OrderContext::current_order_key(), CreditNoteIssuer::TYPE ) ) {
			foreach ( DocumentList::for_order( $order, false ) as $doc ) {
				if ( CreditNoteIssuer::TYPE === $doc['type'] ) {
					$latest = $doc;
				}
			}
		}

		switch ( $field ) {
			case 'url':
				return null !== $latest ? $latest['url'] : '';

			case 'number':
				return null !== $latest ? $latest['number'] : '';

			default:
				return null !== $latest ? 'available' : 'none';
		}
	}

	/**
	 * HTML of the order's documents block for the current visitor, '' without order or access.
	 *
	 * @param mixed $post Current object given by Bricks.
	 */
	private static function documents( $post ): string {
		$order = OrderContext::resolve( $post );

		return $order instanceof WC_Order ? Shortcode::documents( $order ) : '';
	}
}
