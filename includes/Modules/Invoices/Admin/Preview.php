<?php
/**
 * Live PDF preview of the Design tab.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\NumberFormat;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Rendering\DocumentData;
use Lumia\Factures\Modules\Invoices\Rendering\Renderer;
use Lumia\Factures\Modules\Invoices\Settings\Schema;
use WC_Order;
use WP_Error;

/**
 * Renders the unsaved form settings against a real order.
 *
 * A preview has no side effect: it never goes through the issuer, the register or the archive, so
 * no number is reserved, no file or meta is written, no note is added, no option is saved. The
 * number printed is the next sample, read without consuming it.
 */
final class Preview {

	/**
	 * AJAX action suffix (`wp_ajax_lumia_factures_invoices_preview`).
	 */
	public const ACTION = 'lumia_factures_invoices_preview';

	/**
	 * Registers the AJAX endpoint.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * AJAX endpoint. Input: `nonce`, the module form fields as the browser posts them (a FormData of the form,
	 * i.e. the nested array `lumia_factures_module_settings[group][key]`, same shape as a save) and an
	 * optional `order_id`.
	 */
	public static function handle(): void {
		check_ajax_referer( 'lumia_factures_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do this.', 'lumia-factures' ) ], 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; the array is sanitized value by value by Schema::sanitize().
		$posted = isset( $_POST['lumia_factures_module_settings'] ) && is_array( $_POST['lumia_factures_module_settings'] ) ? wp_unslash( $_POST['lumia_factures_module_settings'] ) : [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;

		$pdf = self::render( $posted, $order_id, 'pdf' );
		if ( is_wp_error( $pdf ) ) {
			wp_send_json_error( [ 'message' => $pdf->get_error_message() ] );
		}

		$order = self::find_order( $order_id );

		wp_send_json_success(
			[
				'pdf'   => base64_encode( $pdf ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary payload inside JSON.
				'order' => $order instanceof WC_Order ? '#' . $order->get_order_number() : '',
			]
		);
	}

	/**
	 * Renders the preview.
	 *
	 * @param array<string, mixed> $posted_settings Unslashed form values, same shape as the stored settings. Sanitized here, never saved.
	 * @param int                  $order_id        Order to render, 0 for the latest one.
	 * @param string               $format          `pdf` (bytes) or `html`.
	 * @return string|WP_Error
	 */
	public static function render( array $posted_settings, int $order_id = 0, string $format = 'pdf' ) {
		$order = self::find_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			$message = $order_id > 0
				? __( 'This order does not exist.', 'lumia-factures' )
				: __( 'Create a first order to preview the invoice.', 'lumia-factures' );

			return new WP_Error( 'lumia_factures_preview_no_order', $message );
		}

		$result   = Schema::sanitize( $posted_settings, Module::settings() );
		$settings = $result['settings'];

		$doc = new DocumentData( '', self::sample_number( $settings ), time(), true );

		if ( 'html' === $format ) {
			return Renderer::html( $order, $doc, $settings );
		}

		$pdf = Renderer::pdf( $order, $doc, $settings );
		if ( '' === $pdf ) {
			return new WP_Error( 'lumia_factures_preview_failed', __( 'The preview could not be generated.', 'lumia-factures' ) );
		}

		return $pdf;
	}

	/**
	 * The given order, or the latest one. A refund is not a `WC_Order`, so it is refused.
	 *
	 * @param int $order_id Order id, 0 for the latest.
	 */
	private static function find_order( int $order_id ): ?WC_Order {
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );

			return $order instanceof WC_Order ? $order : null;
		}

		$orders = wc_get_orders(
			[
				'limit'   => 1,
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);
		$order  = is_array( $orders ) ? reset( $orders ) : false;

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * The number the next invoice would get, read without consuming the counter.
	 *
	 * @param array<string, array<string, mixed>> $settings Sanitized settings.
	 */
	private static function sample_number( array $settings ): string {
		$numbering = $settings['numbering'];
		$last      = Sequences::current( Sequences::key( Issuer::TYPE, time(), $numbering ) );
		$next      = $last > 0 ? $last + 1 : max( 1, (int) $numbering['start_number'] );

		return NumberFormat::preview( (string) $numbering['format'], $next );
	}
}
