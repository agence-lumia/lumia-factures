<?php
/**
 * Customer download of an issued invoice.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use WC_Order;

/**
 * Public download endpoint: `?lumia_factures_invoice=<order id>[&key=<order key>][&inline=0]`, and the
 * Facture PDF Lumia links already sent to customers (`?fpl_download=1&order=<id>[&key=...][&inline=1]`).
 *
 * The archive folder is never reachable from the web: the PDF is streamed by PHP after the access check.
 * Every refusal gives the same 404, so nothing reveals whether an order or an invoice exists.
 */
final class CustomerDownload {

	/**
	 * Query var of the download URL.
	 */
	public const QUERY_VAR = 'lumia_factures_invoice';

	/**
	 * Query var of the Facture PDF Lumia links.
	 */
	public const LEGACY_VAR = 'fpl_download';

	/**
	 * Builds per window and per visitor.
	 */
	public const RATE_LIMIT = 10;

	/**
	 * Rate limit window in seconds.
	 */
	public const RATE_WINDOW = 60;

	/**
	 * Registers the request handler.
	 */
	public static function register(): void {
		// `init` rather than `template_redirect`: the download must not depend on the resolution of a
		// WordPress query, and works on a URL that matches no content.
		add_action( 'init', [ self::class, 'handle' ], 20 );
	}

	/**
	 * Download URL of an order's invoice.
	 *
	 * The order key is added unless the visitor is allowed in without it (a manager or the order's
	 * customer): a guest, or a logged-in user who is not the owner, needs it.
	 *
	 * @param WC_Order $order  Order.
	 * @param bool     $inline False to ask for an attachment instead of a display in the browser.
	 */
	public static function url( WC_Order $order, bool $inline = true ): string {
		$args = [ self::QUERY_VAR => $order->get_id() ];

		if ( ! self::is_manager_or_owner( $order ) ) {
			$args['key'] = $order->get_order_key();
		}

		if ( ! $inline ) {
			$args['inline'] = 0;
		}

		/**
		 * Filters the customer download URL of an invoice.
		 *
		 * @param string   $url   URL.
		 * @param WC_Order $order Order.
		 */
		return (string) apply_filters( 'lumia_factures_customer_download_url', add_query_arg( $args, home_url( '/' ) ), $order );
	}

	/**
	 * Whether the visitor may download the order's invoice.
	 *
	 * @param WC_Order    $order Order.
	 * @param string|null $key   Order key presented by the visitor.
	 */
	public static function can_access( WC_Order $order, ?string $key ): bool {
		$can = self::is_manager_or_owner( $order );

		if ( ! $can && null !== $key && '' !== $key ) {
			$can = hash_equals( (string) $order->get_order_key(), $key );
		}

		/**
		 * Filters whether the visitor may download the order's invoice.
		 *
		 * @param bool        $can   Result of the built-in check.
		 * @param WC_Order    $order Order.
		 * @param string|null $key   Order key presented by the visitor.
		 */
		return (bool) apply_filters( 'lumia_factures_can_access_invoice', $can, $order, $key );
	}

	/**
	 * Resolves a download request to the file to stream. Ends the request with a 404 otherwise.
	 *
	 * An invoice imported without a PDF (the legacy file was missing) is built here on first need,
	 * under a rate limit. A recorded file that is missing or damaged is never rebuilt on a public
	 * request: the shop manager does it explicitly from the order.
	 *
	 * @param array<string, mixed> $request Raw query (`$_GET`).
	 * @return array{path: string, name: string, inline: bool}
	 */
	public static function locate( array $request ): array {
		$request = wp_unslash( $request );

		if ( isset( $request[ self::QUERY_VAR ] ) ) {
			$order_id = is_scalar( $request[ self::QUERY_VAR ] ) ? absint( $request[ self::QUERY_VAR ] ) : 0;
			$inline   = ! isset( $request['inline'] ) || '0' !== (string) $request['inline'];
			$document = Issuer::TYPE;
		} else {
			$order_id = isset( $request['order'] ) && is_scalar( $request['order'] ) ? absint( $request['order'] ) : 0;
			// The legacy link is an attachment unless it asked to be displayed.
			$inline   = isset( $request['inline'] ) && '1' === (string) $request['inline'];
			$document = isset( $request['document'] ) && is_scalar( $request['document'] ) ? sanitize_key( (string) $request['document'] ) : Issuer::TYPE;
		}

		$key   = isset( $request['key'] ) && is_scalar( $request['key'] ) ? sanitize_text_field( (string) $request['key'] ) : '';
		$order = $order_id > 0 && Issuer::TYPE === $document ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order || ! self::can_access( $order, $key ) ) {
			self::not_found();
		}

		$doc = Register::find( Issuer::TYPE, $order_id );
		if ( null === $doc || 'issued' !== $doc->status ) {
			self::not_found();
		}

		if ( ! Archive::verify( $doc ) ) {
			// Recorded but missing or damaged: never rebuilt for a visitor.
			if ( null !== $doc->file || ! self::allow_build() ) {
				self::not_found();
			}

			Issuer::restore( $doc, $order );
			$doc = Register::find( Issuer::TYPE, $order_id );
			// A file that still fails verification is never served.
			if ( null === $doc || ! Archive::verify( $doc ) ) {
				self::not_found();
			}
		}

		$path = Archive::path( $doc );
		if ( null === $path ) {
			self::not_found();
		}

		return [
			'path'   => $path,
			'name'   => sanitize_file_name( (string) $doc->number ) . '.pdf',
			'inline' => $inline,
		];
	}

	/**
	 * Request handler: streams the PDF and exits when the request is a download.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Access rests on the order key or the session, not on a nonce: the link is sent by email.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) && ! isset( $_GET[ self::LEGACY_VAR ] ) ) {
			return;
		}

		$file = self::locate( $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$size = filesize( $file['path'] );

		// Anything buffered so far (a notice, a stray space from another plugin) would corrupt the PDF.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( $file['inline'] ? 'inline' : 'attachment' ) . '; filename="' . $file['name'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store' );
		if ( false !== $size ) {
			header( 'Content-Length: ' . $size );
		}

		readfile( $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a validated archive file.
		exit;
	}

	/**
	 * Whether the visitor is a shop manager or the customer of the order.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function is_manager_or_owner( WC_Order $order ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$user_id = get_current_user_id();

		return $user_id > 0 && $user_id === $order->get_customer_id();
	}

	/**
	 * Consumes one build of the visitor's quota.
	 *
	 * Counted only for the requests that must build a PDF: serving an archived file is cheap and
	 * never limited. The window starts at the first build and is not extended by later ones.
	 *
	 * @return bool False when the visitor is over the limit.
	 */
	private static function allow_build(): bool {
		$user_id = get_current_user_id();
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$name    = 'lumia_factures_rate_' . md5( $user_id > 0 ? (string) $user_id : $address );
		$state   = get_transient( $name );
		$now     = time();

		if ( ! is_array( $state ) || ! isset( $state['count'], $state['until'] ) || $state['until'] <= $now ) {
			$state = [
				'count' => 0,
				'until' => $now + self::RATE_WINDOW,
			];
		}

		if ( $state['count'] >= self::RATE_LIMIT ) {
			return false;
		}

		++$state['count'];
		set_transient( $name, $state, (int) max( 1, (int) $state['until'] - $now ) );

		return true;
	}

	/**
	 * Ends the request with a 404. One message for every refusal.
	 */
	private static function not_found(): never {
		wp_die( esc_html__( 'Invoice not found.', 'lumia-factures' ), '', [ 'response' => 404 ] );
	}
}
