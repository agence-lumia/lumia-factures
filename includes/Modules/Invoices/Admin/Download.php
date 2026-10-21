<?php
/**
 * Admin download of an archived invoice or credit note.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
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
	 * Admin-post action: an order's invoice.
	 */
	public const ACTION = 'lumia_factures_invoices_download';

	/**
	 * Admin-post action: a refund's credit note.
	 */
	public const CREDIT_NOTE_ACTION = 'lumia_factures_invoices_download_credit_note';

	/**
	 * Registers the admin-post handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_post_' . self::CREDIT_NOTE_ACTION, [ self::class, 'handle_credit_note' ] );
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
	 * Signed download URL of a credit note.
	 *
	 * @param int $order_id  Refunded order id.
	 * @param int $refund_id Refund id (the credit note's `order_id` in the register).
	 */
	public static function credit_note_url( int $order_id, int $refund_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'    => self::CREDIT_NOTE_ACTION,
					'order_id'  => $order_id,
					'refund_id' => $refund_id,
				],
				admin_url( 'admin-post.php' )
			),
			self::CREDIT_NOTE_ACTION . '_' . $refund_id
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
		self::check_capability();

		$doc = $order_id > 0 ? Register::find( Issuer::TYPE, $order_id ) : null;
		if ( null === $doc || 'issued' !== $doc->status ) {
			self::not_found();
		}

		if ( ! Archive::verify( $doc ) ) {
			if ( null !== $doc->file ) {
				self::damaged();
			}

			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				Issuer::restore( $doc, $order );
			}
			$doc = Register::find( Issuer::TYPE, $order_id );
		}

		return self::verified_path( $doc );
	}

	/**
	 * Absolute path of a refund's archived credit note, read from the register: it still works once
	 * the refund is deleted. The credit note must belong to the order (its `parent_id`).
	 *
	 * An archive never built is built first, while the refund still exists; a recorded archive that
	 * is missing or damaged is never rebuilt here (see file_for()).
	 *
	 * @param int $order_id  Refunded order id.
	 * @param int $refund_id Refund id.
	 * @return string Path; ends the request with a 403 or a 404 otherwise.
	 */
	public static function credit_note_file_for( int $order_id, int $refund_id ): string {
		self::check_capability();

		$doc = self::credit_note( $order_id, $refund_id );
		if ( null === $doc ) {
			self::not_found();
		}

		if ( ! Archive::verify( $doc ) ) {
			if ( null !== $doc->file ) {
				self::damaged();
			}

			CreditNoteIssuer::restore( $doc );
			$doc = self::credit_note( $order_id, $refund_id );
		}

		return self::verified_path( $doc );
	}

	/**
	 * Admin-post handler: checks the nonce, then streams the invoice and exits.
	 */
	public static function handle(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

		check_admin_referer( self::ACTION . '_' . $order_id );

		$path = self::file_for( $order_id );
		$doc  = Register::find( Issuer::TYPE, $order_id );

		self::stream( $path, (string) ( $doc->number ?? 'invoice' ) );
	}

	/**
	 * Admin-post handler: checks the nonce, then streams the credit note and exits.
	 */
	public static function handle_credit_note(): void {
		$order_id  = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$refund_id = isset( $_GET['refund_id'] ) ? absint( wp_unslash( $_GET['refund_id'] ) ) : 0;

		check_admin_referer( self::CREDIT_NOTE_ACTION . '_' . $refund_id );

		$path = self::credit_note_file_for( $order_id, $refund_id );
		$doc  = self::credit_note( $order_id, $refund_id );

		self::stream( $path, (string) ( $doc->number ?? 'credit-note' ) );
	}

	/**
	 * The issued credit note of the refund, when it credits this order.
	 *
	 * @param int $order_id  Refunded order id.
	 * @param int $refund_id Refund id.
	 */
	private static function credit_note( int $order_id, int $refund_id ): ?IssuedDocument {
		$doc = $order_id > 0 && $refund_id > 0 ? Register::find( CreditNoteIssuer::TYPE, $refund_id ) : null;

		return null !== $doc && 'issued' === $doc->status && $doc->parent_id === $order_id ? $doc : null;
	}

	/**
	 * Path of a document whose archive passes verification; a 404 otherwise.
	 *
	 * @param IssuedDocument|null $doc Document.
	 */
	private static function verified_path( ?IssuedDocument $doc ): string {
		// A file that still fails verification is never served.
		if ( null === $doc || ! Archive::verify( $doc ) ) {
			self::not_found();
		}

		$path = Archive::path( $doc );
		if ( null === $path ) {
			self::not_found();
		}

		return $path;
	}

	/**
	 * Streams a PDF and exits.
	 *
	 * @param string $path   Verified archive path.
	 * @param string $number Document number, the file name.
	 */
	private static function stream( string $path, string $number ): never {
		$name = sanitize_file_name( $number ) . '.pdf';
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
	 * Ends the request with a 403 unless the user manages WooCommerce.
	 */
	private static function check_capability(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to download invoices.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * Ends the request with a 404 pointing to the rebuild button.
	 */
	private static function damaged(): never {
		wp_die(
			esc_html__( 'The archived PDF of this invoice is missing or damaged. Use "Rebuild missing PDF" in the invoice box of the order.', 'lumia-factures' ),
			'',
			[ 'response' => 404 ]
		);
	}

	/**
	 * Ends the request with a 404.
	 */
	private static function not_found(): never {
		wp_die( esc_html__( 'This invoice does not exist.', 'lumia-factures' ), '', [ 'response' => 404 ] );
	}
}
