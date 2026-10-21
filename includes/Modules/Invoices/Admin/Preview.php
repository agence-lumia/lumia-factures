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
use Throwable;
use WC_Order;
use WP_Error;

/**
 * Renders the unsaved form settings against a real order, or the demo order when the shop has none.
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
	 * optional `order_id`. Output: `pdf` (base64), `order` (`#number`, '' for the demo order) and `demo`.
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

		$order = self::source( $order_id );
		if ( is_wp_error( $order ) ) {
			wp_send_json_error( [ 'message' => $order->get_error_message() ] );
		}

		$pdf = self::render_order( $order, $posted, 'pdf' );
		if ( is_wp_error( $pdf ) ) {
			wp_send_json_error( [ 'message' => $pdf->get_error_message() ] );
		}

		$demo = $order instanceof DemoOrder;

		wp_send_json_success(
			[
				'pdf'   => base64_encode( $pdf ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary payload inside JSON.
				'order' => $demo ? '' : '#' . $order->get_order_number(),
				'demo'  => $demo,
			]
		);
	}

	/**
	 * Renders the preview.
	 *
	 * @param array<string, mixed> $posted_settings Unslashed form values, same shape as the stored settings. Sanitized here, never saved.
	 * @param int                  $order_id        Order to render, 0 for the latest one (the demo order when there is none).
	 * @param string               $format          `pdf` (bytes) or `html`.
	 * @return string|WP_Error
	 */
	public static function render( array $posted_settings, int $order_id = 0, string $format = 'pdf' ) {
		$order = self::source( $order_id );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return self::render_order( $order, $posted_settings, $format );
	}

	/**
	 * The order to render: the given one, else the latest real order (a checkout draft is not one),
	 * else the demo order. An unknown id stays an error; a refund is not a `WC_Order`, so it is refused.
	 *
	 * @param int $order_id Order id, 0 for the latest.
	 */
	public static function source( int $order_id ): WC_Order|WP_Error {
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );

			return $order instanceof WC_Order
				? $order
				: new WP_Error( 'lumia_factures_preview_no_order', __( 'This order does not exist.', 'lumia-factures' ) );
		}

		$orders = wc_get_orders(
			[
				'limit'   => 1,
				'orderby' => 'date',
				'order'   => 'DESC',
				'status'  => array_values( array_diff( array_keys( wc_get_order_statuses() ), [ 'wc-checkout-draft' ] ) ),
			]
		);
		$order  = is_array( $orders ) ? reset( $orders ) : false;

		return $order instanceof WC_Order ? $order : DemoOrder::build();
	}

	/**
	 * Renders an order with the posted settings.
	 *
	 * The demo order is rendered under a guard: a hook that cannot handle an order without id
	 * becomes a preview error, never a fatal error of the settings screen.
	 *
	 * @param WC_Order             $order           Order.
	 * @param array<string, mixed> $posted_settings Unslashed form values.
	 * @param string               $format          `pdf` (bytes) or `html`.
	 * @return string|WP_Error
	 */
	private static function render_order( WC_Order $order, array $posted_settings, string $format ) {
		if ( $order instanceof DemoOrder ) {
			$level = ob_get_level();
			try {
				$output = self::build( $order, $posted_settings, $format );
			} catch ( Throwable $e ) {
				while ( ob_get_level() > $level ) {
					ob_end_clean();
				}
				error_log( '[lumia-factures] The demo order preview failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
				$output = '';
			}
		} else {
			$output = self::build( $order, $posted_settings, $format );
		}

		if ( '' === $output && ( 'pdf' === $format || $order instanceof DemoOrder ) ) {
			return new WP_Error( 'lumia_factures_preview_failed', __( 'The preview could not be generated.', 'lumia-factures' ) );
		}

		return $output;
	}

	/**
	 * Sanitizes the posted settings and renders the document with the next sample number.
	 *
	 * @param WC_Order             $order           Order.
	 * @param array<string, mixed> $posted_settings Unslashed form values.
	 * @param string               $format          `pdf` (bytes) or `html`.
	 */
	private static function build( WC_Order $order, array $posted_settings, string $format ): string {
		$result   = Schema::sanitize( $posted_settings, Module::settings() );
		$settings = $result['settings'];

		$doc = new DocumentData( '', self::sample_number( $settings ), time(), true );

		return 'html' === $format ? Renderer::html( $order, $doc, $settings ) : Renderer::pdf( $order, $doc, $settings );
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
