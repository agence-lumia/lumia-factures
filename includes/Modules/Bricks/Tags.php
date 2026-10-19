<?php
/**
 * Bricks dynamic tags of the invoice.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Bricks;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Front\CustomerDownload;
use Lumia\Factures\Modules\Invoices\Front\OrderContext;
use WC_Order;

/**
 * `{lumia_factures_invoice_url}`, `{lumia_factures_invoice_number}` and `{lumia_factures_invoice_status}`
 * for the Bricks editor, plus the Facture PDF Lumia names (`{fpl_invoice_*}`) that existing templates use:
 * rendered the same way, not listed in the picker.
 *
 * The hooks are added unconditionally: without Bricks the `bricks/*` filters never fire.
 */
final class Tags {

	/**
	 * Prefix of the tags listed in the picker.
	 */
	private const PREFIX = 'lumia_factures_invoice_';

	/**
	 * Prefix of the Facture PDF Lumia tags.
	 */
	private const LEGACY_PREFIX = 'fpl_invoice_';

	/**
	 * Tag suffixes with their label.
	 *
	 * A method rather than a constant: `__()` needs a literal string to be found by the translation tools.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return [
			'url'    => __( 'Invoice URL', 'lumia-factures' ),
			'number' => __( 'Invoice number', 'lumia-factures' ),
			'status' => __( 'Invoice status', 'lumia-factures' ),
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

		foreach ( self::labels() as $suffix => $label ) {
			$tags[] = [
				'name'  => '{' . self::PREFIX . $suffix . '}',
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

		$suffix = self::suffix( trim( $tag, '{}' ) );
		if ( null === $suffix ) {
			return $tag;
		}

		return self::resolve( $suffix, $post );
	}

	/**
	 * Resolves the tags found inside a larger content.
	 *
	 * @param mixed  $content Content that may hold one or several tags.
	 * @param mixed  $post    Current object given by Bricks.
	 * @param string $context Render context.
	 * @return mixed
	 */
	public static function render_content( $content, $post = null, $context = 'text' ) {
		unset( $context );

		if ( ! is_string( $content ) || ( ! str_contains( $content, self::PREFIX ) && ! str_contains( $content, self::LEGACY_PREFIX ) ) ) {
			return $content;
		}

		foreach ( array_keys( self::labels() ) as $suffix ) {
			foreach ( [ self::PREFIX, self::LEGACY_PREFIX ] as $prefix ) {
				$tag = '{' . $prefix . $suffix . '}';
				if ( str_contains( $content, $tag ) ) {
					$content = str_replace( $tag, self::resolve( $suffix, $post ), $content );
				}
			}
		}

		return $content;
	}

	/**
	 * Suffix (`url`, `number`, `status`) of one of our tags, null for any other tag.
	 *
	 * @param string $name Tag name, without braces.
	 */
	private static function suffix( string $name ): ?string {
		foreach ( [ self::PREFIX, self::LEGACY_PREFIX ] as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				$suffix = substr( $name, strlen( $prefix ) );

				return array_key_exists( $suffix, self::labels() ) ? $suffix : null;
			}
		}

		return null;
	}

	/**
	 * Value of a tag for the current object.
	 *
	 * The order comes from the page context, but a builder can hand over any post: the three tags give
	 * nothing to a visitor who could not download the invoice. The status then reads `none`, so a
	 * condition on it hides the element instead of leaving a dead link.
	 *
	 * @param string $suffix `url`, `number` or `status`.
	 * @param mixed  $post   Current object given by Bricks.
	 */
	private static function resolve( string $suffix, $post ): string {
		$order = OrderContext::resolve( $post );

		if ( ! $order instanceof WC_Order || ! CustomerDownload::can_access( $order, OrderContext::current_order_key() ) ) {
			return 'status' === $suffix ? 'none' : '';
		}

		$doc    = Register::find( Issuer::TYPE, $order->get_id() );
		$issued = null !== $doc && 'issued' === $doc->status;

		switch ( $suffix ) {
			case 'url':
				return $issued ? CustomerDownload::url( $order ) : '';

			case 'number':
				return $issued ? (string) $doc->number : '';

			default:
				if ( $issued ) {
					return 'available';
				}

				return Issuer::can_issue( $order ) ? 'pending' : 'none';
		}
	}
}
