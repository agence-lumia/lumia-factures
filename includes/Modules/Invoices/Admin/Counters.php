<?php
/**
 * Raising an invoice counter from the settings screen.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Numbering\Sequences;

/**
 * Admin-post handler of the "Raise" form of the Numbering tab. A counter can only go up:
 * the number of an issued invoice is never reissued (`Sequences::raise()` enforces it).
 */
final class Counters {

	/**
	 * Admin-post action and nonce action.
	 */
	public const ACTION = 'lumia_factures_invoices_raise_counter';

	/**
	 * Registers the handler and the toast messages.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_filter( 'lumia_factures_admin_notices', [ self::class, 'add_messages' ] );
	}

	/**
	 * Adds the toast codes to the plugin page ones.
	 *
	 * @param mixed $messages Code => message.
	 * @return array<string, string>
	 */
	public static function add_messages( $messages ): array {
		$messages = is_array( $messages ) ? $messages : [];

		$messages['counter_raised']     = __( 'The counter has been raised.', 'lumia-factures' );
		$messages['counter_not_raised'] = __( 'The counter was not raised: the new value must be higher than the current one.', 'lumia-factures' );

		return $messages;
	}

	/**
	 * Raises an existing counter.
	 *
	 * @param string $key   Counter key, must already exist (no counter is created from the client).
	 * @param int    $value New last used number.
	 * @return string Notice code: `counter_raised` or `counter_not_raised`.
	 */
	public static function raise_for( string $key, int $value ): string {
		$known = array_column( Sequences::all(), 'key' );

		if ( '' === $key || ! in_array( $key, $known, true ) ) {
			return 'counter_not_raised';
		}

		return Sequences::raise( $key, $value ) ? 'counter_raised' : 'counter_not_raised';
	}

	/**
	 * Admin-post handler: checks capability and nonce, raises, redirects to the Numbering tab.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just above.
		$key = isset( $_POST['sequence_key'] ) ? sanitize_key( wp_unslash( $_POST['sequence_key'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just above.
		$value = isset( $_POST['value'] ) ? absint( wp_unslash( $_POST['value'] ) ) : 0;

		$code = self::raise_for( $key, $value );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                       => 'lumia-factures',
					'lumia_factures_notice'      => $code,
					'lumia_factures_notice_type' => 'counter_raised' === $code ? 'success' : 'error',
				],
				admin_url( 'admin.php' )
			) . '#numbering'
		);
		exit;
	}
}
