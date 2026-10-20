<?php
/**
 * Form handler of the admin page.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\Modules;
use Lumia\Factures\Core\Settings;
use Lumia\Factures\Core\Updater;

/**
 * Handles the `lumia_factures_save_settings` admin-post action.
 *
 * One action for both forms of the screen:
 *  - the global settings (`lumia_factures_global[...]`) are saved whenever they are posted: they
 *    sit in the Settings tab of the invoices form, or alone in the fallback form;
 *  - `lumia_factures_tab` = `module_{id}` also saves an active module, through its save_settings().
 */
class SaveHandler {

	public const ACTION = 'lumia_factures_save_settings';

	public const NONCE_FIELD = 'lumia_factures_nonce';

	public const CHECK_UPDATES_ACTION = 'lumia_factures_check_updates';

	public const CHECK_UPDATES_NONCE_FIELD = 'lumia_factures_check_nonce';

	/**
	 * Update channels accepted for `global.update_channel`.
	 */
	private const CHANNELS = [ 'stable', 'dev' ];

	/**
	 * Modules manager.
	 */
	private Modules $modules;

	/**
	 * Settings manager.
	 */
	private Settings $settings;

	/**
	 * Admin page slug.
	 */
	private string $slug;

	/**
	 * GitHub updater.
	 */
	private Updater $updater;

	/**
	 * Constructor.
	 *
	 * @param Modules  $modules  Modules manager.
	 * @param Settings $settings Settings manager.
	 * @param string   $slug     Admin page slug.
	 * @param Updater  $updater  GitHub updater.
	 */
	public function __construct( Modules $modules, Settings $settings, string $slug, Updater $updater ) {
		$this->modules  = $modules;
		$this->settings = $settings;
		$this->slug     = $slug;
		$this->updater  = $updater;

		add_action( 'admin_post_' . self::CHECK_UPDATES_ACTION, [ $this, 'handle_check_updates' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * Saves the posted form, then redirects back to its tab with a notice.
	 */
	public function handle(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::ACTION ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}

		$tab = isset( $_POST['lumia_factures_tab'] ) ? sanitize_key( wp_unslash( $_POST['lumia_factures_tab'] ) ) : 'settings';

		// The module first: a module that refuses the user dies before anything is written.
		if ( 0 === strpos( $tab, 'module_' ) ) {
			$this->save_module( substr( $tab, strlen( 'module_' ) ) );
		}
		$this->save_global();

		$this->redirect( 'settings_saved' );
	}

	/**
	 * Back to the screen with a toast. The open tab is restored by the browser (admin.js).
	 *
	 * @param string $notice Toast code.
	 */
	private function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'                       => $this->slug,
					'lumia_factures_notice'      => $notice,
					'lumia_factures_notice_type' => 'success',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Global settings (whitelisted keys only). Nothing posted, nothing written.
	 */
	private function save_global(): void {
		$global = isset( $_POST['lumia_factures_global'] ) && is_array( $_POST['lumia_factures_global'] ) ? wp_unslash( $_POST['lumia_factures_global'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle(); each key is sanitized below.

		if ( array_key_exists( 'update_channel', $global ) && is_scalar( $global['update_channel'] ) && current_user_can( 'update_plugins' ) ) {
			$channel  = sanitize_key( (string) $global['update_channel'] );
			$channel  = in_array( $channel, self::CHANNELS, true ) ? $channel : 'stable';
			$previous = (string) $this->settings->get( 'global.update_channel', 'stable' );
			$this->settings->set( 'global.update_channel', $channel );

			// A pre-release found on the dev channel must not stay offered once back on stable.
			if ( $channel !== $previous ) {
				$this->updater->purge_caches();
			}
		}

		// Unchecked box = absent from POST. Written only when the user changed the
		// box since the page loaded, otherwise saving the channel alone would undo
		// a setting made meanwhile from the plugins list or WP-CLI. The source of
		// truth is the WordPress option, not the plugin settings.
		$auto_update         = ! empty( $global['auto_update'] );
		$auto_update_initial = ! empty( $global['auto_update_initial'] );

		if ( $auto_update !== $auto_update_initial && wp_is_auto_update_enabled_for_type( 'plugin' ) && current_user_can( 'update_plugins' ) ) {
			$this->set_auto_update( $auto_update );
		}
	}

	/**
	 * Enables or disables the plugin's automatic update.
	 *
	 * Writes to auto_update_plugins, the option WordPress reads for its
	 * background updates and edits from the plugins list.
	 *
	 * @param bool $enabled Desired state.
	 */
	private function set_auto_update( bool $enabled ): void {
		$plugin_file = plugin_basename( LUMIA_FACTURES_PLUGIN_FILE );
		$current     = (array) get_site_option( 'auto_update_plugins', [] );

		if ( in_array( $plugin_file, $current, true ) === $enabled ) {
			return;
		}

		$updated = $enabled ? array_merge( $current, [ $plugin_file ] ) : array_diff( $current, [ $plugin_file ] );

		update_site_option( 'auto_update_plugins', array_values( array_unique( $updated ) ) );
	}

	/**
	 * Manual update check: purges the caches and queries GitHub right away.
	 */
	public function handle_check_updates(): void {
		if ( ! isset( $_POST[ self::CHECK_UPDATES_NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::CHECK_UPDATES_NONCE_FIELD ] ) ), self::CHECK_UPDATES_ACTION ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}

		$this->updater->purge_caches();
		wp_update_plugins();

		$this->redirect( 'updates_checked' );
	}

	/**
	 * Settings of an active module, sanitized by the module itself.
	 *
	 * @param string $module_id Module identifier.
	 */
	private function save_module( string $module_id ): void {
		$instance = $this->modules->get_active_instances()[ $module_id ] ?? null;
		if ( ! $instance ) {
			return;
		}

		// Added to manage_woocommerce checked above, never an alternative to it.
		if ( ! current_user_can( Admin::module_capability( $this->modules, $module_id ) ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		if ( isset( $_POST['lumia_factures_module_settings'] ) && is_array( $_POST['lumia_factures_module_settings'] ) ) {
			$instance->save_settings( wp_unslash( $_POST['lumia_factures_module_settings'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- nonce and capability checked in handle(); each module sanitizes in save_settings() (AbstractModule contract).
		}
	}
}
