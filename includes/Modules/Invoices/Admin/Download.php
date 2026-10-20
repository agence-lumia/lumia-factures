<?php
/**
 * Admin download of an archived invoice.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use WC_Order;

/**
 * Streams the archived PDF to a shop manager. The archive folder is never reachable from the web:
 * every download goes through `admin-post.php`, with a nonce and the `manage_woocommerce` capability.
 */
final class Download {

	/**
	 * Admin-post action.
	 */
	public const ACTION = 'lumia_factures_invoices_download';

	/**
	 * Registers the admin-post handler.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Signed download URL of an order's invoice.
	 *
	 * @param int $order_id Order id.
	 */
	public static function url( int $order_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => self::ACTION,
					'order_id' => $order_id,
				],
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $order_id
		);
	}

	/**
	 * Absolute path of the order's archived invoice.
	 *
	 * An archive never built (rendering or storage failed at issue time) is built first. A recorded
	 * archive that is missing or damaged is never rebuilt here: the 404 points the admin to the
	 * explicit "Rebuild missing PDF" button of the order's invoice box.
	 *
	 * @param int $order_id Order id.
	 * @return string Path; ends the request with a 403 or a 404 otherwise.
	 */
	public static function file_for( int $order_id ): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to download invoices.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}

		$doc = $order_id > 0 ? Register::find( Issuer::TYPE, $order_id ) : null;
		if ( null === $doc || 'issued' !== $doc->status ) {
			self::not_found();
		}

		if ( ! Archive::verify( $doc ) ) {
			if ( null !== $doc->file ) {
				wp_die(
					esc_html__( 'The archived PDF of this invoice is missing or damaged. Use "Rebuild missing PDF" in the invoice box of the order.', 'lumia-factures' ),
					'',
					[ 'response' => 404 ]
				);
			}

			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				Issuer::restore( $doc, $order );
			}
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

		return $path;
	}

	/**
	 * Admin-post handler: checks the nonce, then streams the PDF and exits.
	 */
	public static function handle(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

		check_admin_referer( self::ACTION . '_' . $order_id );

		$path = self::file_for( $order_id );
		$doc  = Register::find( Issuer::TYPE, $order_id );
		$name = sanitize_file_name( (string) ( $doc->number ?? 'invoice' ) ) . '.pdf';
		$size = filesize( $path );

		// Anything buffered so far (a notice, a stray space from another plugin) would corrupt the PDF.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store' );
		if ( false !== $size ) {
			header( 'Content-Length: ' . $size );
		}

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a validated archive file.
		exit;
	}

	/**
	 * Ends the request with a 404.
	 */
	private static function not_found(): never {
		wp_die( esc_html__( 'This invoice does not exist.', 'lumia-factures' ), '', [ 'response' => 404 ] );
	}
}
