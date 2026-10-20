<?php
/**
 * Admin interface of the plugin.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\AbstractModule;
use Lumia\Factures\Core\Modules;
use Lumia\Factures\Core\Plugin;
use Lumia\Factures\Core\Settings;

/**
 * Admin shell: one WooCommerce submenu opening a single screen, assets, toasts and modal.
 *
 * The screen is the invoice settings (client-side tabs, plugin settings included). When the
 * requirements fail no module runs, and the screen falls back to the plugin settings alone
 * (updates and environment), so an update can still be checked.
 */
class Admin {

	/**
	 * Admin page slug.
	 */
	public const SLUG = 'lumia-factures';

	/**
	 * Capability of the admin page.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Plugin instance (modules, settings, requirement errors).
	 */
	private Plugin $plugin;

	/**
	 * Modules manager (read by the templates).
	 */
	private Modules $modules;

	/**
	 * Settings manager (read by the templates).
	 */
	private Settings $settings;

	/**
	 * Hook suffix of the admin page, set once the menu is registered.
	 */
	private string $hook_suffix = '';

	/**
	 * Toast to display (message + type), built from `lumia_factures_notice`.
	 *
	 * @var array{message: string, type: string}|null
	 */
	private ?array $toast = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin   = $plugin;
		$this->modules  = $plugin->modules();
		$this->settings = $plugin->settings();

		new SaveHandler( $this->modules, $this->settings, self::SLUG, $plugin->updater() );

		// Priority 60: where the legacy "Facture PDF" entry sat in the WooCommerce menu.
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 60 );
		add_filter( 'plugin_action_links_' . plugin_basename( LUMIA_FACTURES_PLUGIN_FILE ), [ $this, 'add_plugin_action_links' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_footer', [ $this, 'render_footer_ui' ] );
		// Maximum priority: the plugin page has its own layout, the WP footer stays empty.
		add_filter( 'admin_footer_text', [ $this, 'filter_footer_text' ], PHP_INT_MAX );
		add_filter( 'update_footer', [ $this, 'filter_footer_text' ], PHP_INT_MAX );
	}

	/* ================================================================
	 * MENU
	 * ================================================================ */

	/**
	 * Adds the "Invoices" page under the WooCommerce menu.
	 */
	public function add_menu_page(): void {
		/**
		 * Parent menu of the plugin page.
		 *
		 * @param string $parent Parent menu slug (default: the WooCommerce menu).
		 */
		$parent = (string) apply_filters( 'lumia_factures_menu_parent', 'woocommerce' );

		$hook = add_submenu_page(
			$parent,
			__( 'Invoices', 'lumia-factures' ),
			__( 'Invoices', 'lumia-factures' ),
			'manage_woocommerce',
			self::SLUG,
			[ $this, 'render_page' ]
		);

		if ( is_string( $hook ) ) {
			$this->hook_suffix = $hook;
		}
	}

	/**
	 * Hook suffix (and screen id) of the admin page; empty before `admin_menu`.
	 */
	public function get_hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Adds a "Settings" link before "Deactivate" in the plugins list.
	 *
	 * @param array<int|string, string> $links Action links of the plugin row.
	 * @return array<int|string, string>
	 */
	public function add_plugin_action_links( $links ): array {
		$links = (array) $links;
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return $links;
		}

		return array_merge(
			[ 'settings' => '<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Settings', 'lumia-factures' ) . '</a>' ],
			$links
		);
	}

	/* ================================================================
	 * ASSETS
	 * ================================================================ */

	/**
	 * Loads the CSS/JS assets, on the plugin page only.
	 *
	 * @param string $hook Hook suffix of the current admin page.
	 */
	public function enqueue_assets( $hook ): void {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}

		$css     = LUMIA_FACTURES_ASSETS_URL . 'admin/css/';
		$js      = LUMIA_FACTURES_ASSETS_URL . 'admin/js/';
		$version = LUMIA_FACTURES_VERSION;

		// tokens.css first: it only holds custom properties, the other sheets depend on it.
		wp_enqueue_style( 'lumia-factures-tokens', $css . 'tokens.css', [], $version );
		wp_enqueue_style( 'lumia-factures-reset', $css . 'reset.css', [ 'lumia-factures-tokens' ], $version );
		wp_enqueue_style( 'lumia-factures-layout', $css . 'layout.css', [ 'lumia-factures-reset' ], $version );
		wp_enqueue_style( 'lumia-factures-components', $css . 'components.css', [ 'lumia-factures-tokens' ], $version );
		wp_enqueue_style( 'lumia-factures-buttons', $css . 'buttons.css', [ 'lumia-factures-components' ], $version );
		wp_enqueue_style( 'lumia-factures-notifications', $css . 'notifications.css', [ 'lumia-factures-tokens' ], $version );

		wp_enqueue_script( 'lumia-factures-admin', $js . 'admin.js', [], $version, true );
		wp_enqueue_script( 'lumia-factures-notifications', $js . 'notifications.js', [ 'lumia-factures-admin' ], $version, true );

		wp_localize_script(
			'lumia-factures-admin',
			'lumiaFacturesAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lumia_factures_admin_nonce' ),
				'i18n'    => [
					'saveSuccess'   => __( 'Settings saved successfully.', 'lumia-factures' ),
					'saveError'     => __( 'An error occurred.', 'lumia-factures' ),
					'confirmAction' => __( 'Are you sure?', 'lumia-factures' ),
					'confirm'       => __( 'Confirm', 'lumia-factures' ),
					'cancel'        => __( 'Cancel', 'lumia-factures' ),
					'close'         => __( 'Close', 'lumia-factures' ),
					'error'         => __( 'Error', 'lumia-factures' ),
					'unsavedTitle'  => __( 'Unsaved changes', 'lumia-factures' ),
					'unsavedText'   => __( 'You have unsaved changes. Leave without saving?', 'lumia-factures' ),
					'unsavedLeave'  => __( 'Leave without saving', 'lumia-factures' ),
					'unsavedStay'   => __( 'Stay on this page', 'lumia-factures' ),
				],
			]
		);

		$this->enqueue_module_assets();
	}

	/**
	 * Loads the assets of the active modules: the single screen shows all of them.
	 */
	private function enqueue_module_assets(): void {
		foreach ( $this->modules->get_active_instances() as $module_id => $instance ) {
			foreach ( $instance->get_admin_css() as $index => $style_url ) {
				if ( '' !== $style_url ) {
					wp_enqueue_style( 'lumia-factures-module-' . $module_id . '-' . $index, $style_url, [ 'lumia-factures-components', 'lumia-factures-buttons' ], LUMIA_FACTURES_VERSION );
				}
			}

			foreach ( $instance->get_admin_js() as $index => $script_url ) {
				if ( '' === $script_url ) {
					continue;
				}

				$handle = 'lumia-factures-module-' . $module_id . '-' . $index;
				$deps   = array_values( array_filter( array_merge( [ 'lumia-factures-admin' ], $instance->get_admin_js_deps() ) ) );
				wp_enqueue_script( $handle, $script_url, $deps, LUMIA_FACTURES_VERSION, true );

				// Module data merged into lumiaFacturesAdmin (i18n merged, other keys set).
				$js_data = $instance->get_admin_js_data();
				if ( empty( $js_data ) ) {
					continue;
				}

				$inline = 'window.lumiaFacturesAdmin=window.lumiaFacturesAdmin||{};';
				foreach ( $js_data as $key => $value ) {
					$inline .= 'i18n' === $key
						? 'window.lumiaFacturesAdmin.i18n=Object.assign(window.lumiaFacturesAdmin.i18n||{},' . wp_json_encode( $value ) . ');'
						: 'window.lumiaFacturesAdmin[' . wp_json_encode( $key ) . ']=' . wp_json_encode( $value ) . ';';
				}
				wp_add_inline_script( $handle, $inline, 'before' );
			}
		}
	}

	/* ================================================================
	 * RENDERING
	 * ================================================================ */

	/**
	 * Renders the admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have the required permissions.', 'lumia-factures' ), '', [ 'response' => 403 ] );
		}

		$this->prepare_toast();

		include LUMIA_FACTURES_TEMPLATES_DIR . 'admin/layout.php';
	}

	/**
	 * Toast message (via `lumia_factures_notice` in the query string, set by SaveHandler).
	 */
	private function prepare_toast(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['lumia_factures_notice'] ) ? sanitize_key( wp_unslash( $_GET['lumia_factures_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$type = isset( $_GET['lumia_factures_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['lumia_factures_notice_type'] ) ) : 'success';

		$messages = [
			'settings_saved'  => __( 'Settings saved successfully.', 'lumia-factures' ),
			'updates_checked' => __( 'Update check completed.', 'lumia-factures' ),
		];

		/**
		 * Lets a module add toast codes, passed back as `lumia_factures_notice`.
		 *
		 * @param array<string, string> $messages Toast code => message.
		 */
		$messages = apply_filters( 'lumia_factures_admin_notices', $messages );
		$messages = is_array( $messages ) ? $messages : [];

		$this->toast = null;

		if ( isset( $messages[ $notice ] ) && is_string( $messages[ $notice ] ) ) {
			$this->toast = [
				'message' => $messages[ $notice ],
				'type'    => in_array( $type, [ 'success', 'error', 'warning', 'info' ], true ) ? $type : 'success',
			];
		}
	}

	/**
	 * Footer of the plugin page: confirmation modal, toast container and toast data.
	 *
	 * Distinct ids from lumia-tools (`lumia-factures-*`): lumia-tools prints its
	 * own toast container and notification drawer on every admin page.
	 */
	public function render_footer_ui(): void {
		if ( ! $this->is_plugin_screen() ) {
			return;
		}

		self::render_modal_overlay();
		?>
		<div id="lumia-factures-toast-container" class="lumia-toast-container" role="region" aria-live="polite" aria-label="<?php esc_attr_e( 'Notifications', 'lumia-factures' ); ?>"></div>
		<script>window.lumiaFacturesToast = <?php echo wp_json_encode( $this->toast ); ?>;</script>
		<?php
	}

	/**
	 * The confirmation modal singleton (`window.lumiaFacturesModal`, `[data-modal-confirm]`), printed
	 * once per page: by the plugin screen footer, or by a module that loads `admin.js` on another
	 * screen (the order screen's "Cancel invoice").
	 */
	public static function render_modal_overlay(): void {
		?>
		<div id="lumia-factures-modal-overlay" class="lumia-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
			<div class="lumia-modal">
				<div class="lumia-modal__header">
					<h2 class="lumia-modal__title"></h2>
				</div>
				<div class="lumia-modal__body">
					<p class="lumia-modal__message"></p>
				</div>
				<div class="lumia-modal__footer">
					<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal__cancel"><?php esc_html_e( 'Cancel', 'lumia-factures' ); ?></button>
					<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary lumia-modal__confirm"><?php esc_html_e( 'Confirm', 'lumia-factures' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Empties the WordPress footer texts on the plugin page.
	 *
	 * @param string|null $text Footer text.
	 */
	public function filter_footer_text( $text ): string {
		return $this->is_plugin_screen() ? '' : (string) $text;
	}

	/* ================================================================
	 * TEMPLATE HELPERS
	 * ================================================================ */

	/**
	 * Inline SVG of a Lucide icon.
	 *
	 * @param string $icon        Icon name.
	 * @param string $size        Size modifier.
	 * @param string $extra_class Additional classes.
	 */
	public function render_icon( string $icon, string $size = 'md', string $extra_class = '' ): string {
		return Icons::render( $icon, $size, $extra_class );
	}

	/**
	 * Help marker with a tooltip.
	 *
	 * @param string $text      Tooltip text.
	 * @param string $placement Tooltip placement.
	 */
	public function render_help_tip( string $text, string $placement = 'top' ): string {
		return Icons::help_tip( $text, $placement );
	}

	/**
	 * Admin page slug.
	 */
	public function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * URL of the admin page.
	 */
	public function page_url(): string {
		return add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * Whether the screen shows the invoice settings: the Invoices module runs (requirements met).
	 */
	public function shows_invoices(): bool {
		return isset( $this->modules->get_active_instances()['invoices'] );
	}

	/**
	 * Requirement errors of the last boot (reason => message).
	 *
	 * @return array<string, string>
	 */
	public function requirement_errors(): array {
		return $this->plugin->requirement_errors();
	}

	/**
	 * Capability declared by a module, `manage_woocommerce` by default.
	 *
	 * @param Modules $modules   Modules manager.
	 * @param string  $module_id Module identifier.
	 */
	public static function module_capability( Modules $modules, string $module_id ): string {
		$class = $modules->get( $module_id )['class'] ?? '';

		if ( ! is_string( $class ) || '' === $class || ! class_exists( $class ) || ! is_subclass_of( $class, AbstractModule::class ) ) {
			return self::CAPABILITY;
		}

		return (string) $class::get_required_capability();
	}

	/**
	 * Whether the current screen is the plugin page.
	 */
	private function is_plugin_screen(): bool {
		if ( '' === $this->hook_suffix || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return null !== $screen && $screen->id === $this->hook_suffix;
	}
}
