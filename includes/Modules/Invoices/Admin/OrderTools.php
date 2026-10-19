<?php
/**
 * Invoice tools on the WooCommerce order screens.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use WC_Order;
use WP_Post;

/**
 * Meta box, list column, and the issue / restore actions, on both the HPOS and the legacy order screens.
 *
 * The meta box sits inside the order edit form, and a form cannot nest: the actions are links
 * to `admin-post.php`, signed with a nonce.
 */
final class OrderTools {

	/**
	 * Admin-post action: issue the invoice.
	 */
	public const ACTION_ISSUE = 'lumia_factures_invoices_issue';

	/**
	 * Admin-post action: rebuild a missing or damaged PDF.
	 */
	public const ACTION_RESTORE = 'lumia_factures_invoices_restore';

	/**
	 * Registers the hooks (admin only).
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add_meta_box' ] );
		add_action( 'admin_post_' . self::ACTION_ISSUE, [ self::class, 'handle_issue' ] );
		add_action( 'admin_post_' . self::ACTION_RESTORE, [ self::class, 'handle_restore' ] );
		add_action( 'all_admin_notices', [ self::class, 'render_notice' ] );

		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ self::class, 'add_column' ] );
		add_filter( 'manage_edit-shop_order_columns', [ self::class, 'add_column' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ self::class, 'render_column' ], 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', [ self::class, 'render_column' ], 10, 2 );
	}

	/**
	 * Adds the meta box on both possible order screens.
	 */
	public static function add_meta_box(): void {
		foreach ( self::screens() as $screen ) {
			add_meta_box(
				'lumia-factures-invoice',
				__( 'Invoice', 'lumia-factures' ),
				[ self::class, 'render_meta_box' ],
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Meta box content.
	 *
	 * @param WP_Post|WC_Order|mixed $post_or_order Object given by WordPress, depending on the screen.
	 */
	public static function render_meta_box( $post_or_order ): void {
		$order = self::resolve_order( $post_or_order );
		if ( null === $order ) {
			return;
		}

		$order_id = $order->get_id();
		$doc      = Register::find( Issuer::TYPE, $order_id );

		if ( null !== $doc && 'issued' === $doc->status ) {
			printf(
				'<p><strong>%1$s</strong><br>%2$s</p>',
				esc_html( (string) $doc->number ),
				esc_html( (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $doc->issued_at ) )
			);

			printf(
				'<p><a href="%1$s" class="button" target="_blank" rel="noopener">%2$s</a>',
				esc_url( Download::url( $order_id ) ),
				esc_html__( 'Download PDF', 'lumia-factures' )
			);

			if ( ! Archive::verify( $doc ) ) {
				printf(
					' <a href="%1$s" class="button">%2$s</a>',
					esc_url( self::action_url( 'restore', $order_id ) ),
					esc_html__( 'Rebuild missing PDF', 'lumia-factures' )
				);
			}

			echo '</p>';
			return;
		}

		if ( Issuer::can_issue( $order ) ) {
			printf(
				'<p>%1$s</p><p><a href="%2$s" class="button button-primary">%3$s</a></p>',
				esc_html__( 'No invoice has been issued for this order yet.', 'lumia-factures' ),
				esc_url( self::action_url( 'issue', $order_id ) ),
				esc_html__( 'Issue invoice now', 'lumia-factures' )
			);
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The current order status does not allow an invoice yet.', 'lumia-factures' )
		);
	}

	/**
	 * Adds the invoice column after the status.
	 *
	 * @param mixed $columns Existing columns.
	 * @return array<string, string>
	 */
	public static function add_column( $columns ): array {
		$columns = is_array( $columns ) ? $columns : [];
		$label   = __( 'Invoice', 'lumia-factures' );
		$new     = [];

		foreach ( $columns as $key => $title ) {
			$new[ $key ] = $title;
			if ( 'order_status' === $key ) {
				$new['lumia_factures_invoice'] = $label;
			}
		}

		if ( ! isset( $new['lumia_factures_invoice'] ) ) {
			$new['lumia_factures_invoice'] = $label;
		}

		return $new;
	}

	/**
	 * Column content.
	 *
	 * @param mixed                $column     Column id.
	 * @param int|WP_Post|WC_Order $post_or_id Order id, post or order, depending on the screen.
	 */
	public static function render_column( $column, $post_or_id ): void {
		if ( 'lumia_factures_invoice' !== $column ) {
			return;
		}

		// The HPOS list passes the order itself: reading ->ID on it would raise a WooCommerce notice.
		$order = $post_or_id instanceof WC_Order ? $post_or_id : wc_get_order( $post_or_id );
		$doc   = $order instanceof WC_Order ? Register::find( Issuer::TYPE, $order->get_id() ) : null;

		if ( null === $doc || 'issued' !== $doc->status ) {
			echo '&#8212;';
			return;
		}

		printf(
			'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
			esc_url( Download::url( $doc->order_id ) ),
			esc_html( (string) $doc->number )
		);
	}

	/**
	 * Issues the invoice of an order.
	 *
	 * @param int $order_id Order id.
	 * @return string Notice code: `invoice_issued` or `invoice_not_issued`.
	 */
	public static function issue_for( int $order_id ): string {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return 'invoice_not_issued';
		}

		$doc = Issuer::issue( $order );

		return null !== $doc && 'issued' === $doc->status ? 'invoice_issued' : 'invoice_not_issued';
	}

	/**
	 * Rebuilds the missing or damaged PDF of an issued invoice.
	 *
	 * @param int $order_id Order id.
	 * @return string Notice code: `invoice_restored` or `invoice_restore_refused`.
	 */
	public static function restore_for( int $order_id ): string {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$doc   = $order instanceof WC_Order ? Register::find( Issuer::TYPE, $order_id ) : null;

		if ( null === $doc || 'issued' !== $doc->status ) {
			return 'invoice_restore_refused';
		}

		return Issuer::restore( $doc, $order ) ? 'invoice_restored' : 'invoice_restore_refused';
	}

	/**
	 * Admin-post handler: issue.
	 */
	public static function handle_issue(): void {
		$order_id = self::authorize( 'issue' );
		self::redirect( $order_id, self::issue_for( $order_id ) );
	}

	/**
	 * Admin-post handler: restore.
	 */
	public static function handle_restore(): void {
		$order_id = self::authorize( 'restore' );
		self::redirect( $order_id, self::restore_for( $order_id ) );
	}

	/**
	 * Core admin notice shown on the order edit screen after an action.
	 */
	public static function render_notice(): void {
		if ( ! isset( $_GET['lumia_factures_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no state change.
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! in_array( $screen->id, self::screens(), true ) ) {
			return;
		}

		$code     = sanitize_key( wp_unslash( $_GET['lumia_factures_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = self::messages();
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$type = isset( $_GET['lumia_factures_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['lumia_factures_notice_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = 'error' === $type ? 'error' : 'success';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $messages[ $code ] )
		);
	}

	/**
	 * Order screen ids: legacy post type and HPOS page.
	 *
	 * @return string[]
	 */
	private static function screens(): array {
		return array_values( array_unique( array_filter( [ 'shop_order', wc_get_page_screen_id( 'shop-order' ) ] ) ) );
	}

	/**
	 * Notice messages by code.
	 *
	 * @return array<string, string>
	 */
	private static function messages(): array {
		return [
			'invoice_issued'          => __( 'The invoice has been issued.', 'lumia-factures' ),
			'invoice_not_issued'      => __( 'The invoice could not be issued.', 'lumia-factures' ),
			'invoice_restored'        => __( 'The invoice PDF has been rebuilt.', 'lumia-factures' ),
			'invoice_restore_refused' => __( 'The invoice PDF could not be rebuilt.', 'lumia-factures' ),
		];
	}

	/**
	 * Verifies capability and nonce of an admin-post action.
	 *
	 * @param string $name `issue` or `restore`.
	 * @return int Order id.
	 */
	private static function authorize( string $name ): int {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

		check_admin_referer( 'lumia_factures_invoices_' . $name . '_' . $order_id );

		return $order_id;
	}

	/**
	 * Redirects to the order edit screen with the notice, then exits.
	 *
	 * @param int    $order_id Order id.
	 * @param string $code     Notice code.
	 */
	private static function redirect( int $order_id, string $code ): void {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$back  = wp_get_referer();
		if ( ! $back ) {
			$back = $order instanceof WC_Order ? $order->get_edit_order_url() : admin_url();
		}

		$error = in_array( $code, [ 'invoice_not_issued', 'invoice_restore_refused' ], true );
		$url   = add_query_arg(
			[
				'lumia_factures_notice'      => $code,
				'lumia_factures_notice_type' => $error ? 'error' : 'success',
			],
			remove_query_arg( [ 'lumia_factures_notice', 'lumia_factures_notice_type' ], $back )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Signed admin-post URL of an action.
	 *
	 * @param string $name     `issue` or `restore`.
	 * @param int    $order_id Order id.
	 */
	private static function action_url( string $name, int $order_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => 'lumia_factures_invoices_' . $name,
					'order_id' => $order_id,
				],
				admin_url( 'admin-post.php' )
			),
			'lumia_factures_invoices_' . $name . '_' . $order_id
		);
	}

	/**
	 * Normalizes the object given by WordPress to an order.
	 *
	 * @param WP_Post|WC_Order|mixed $post_or_order Object given by WordPress.
	 */
	private static function resolve_order( $post_or_order ): ?WC_Order {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}

		if ( $post_or_order instanceof WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order instanceof WC_Order ? $order : null;
		}

		return null;
	}
}
