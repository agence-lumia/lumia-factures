<?php
/**
 * Admin shell: menu, save handler, assets, rendering, coexistence with lumia-tools.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Admin;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the tests fire WordPress core hooks (admin_menu, admin_enqueue_scripts, admin_post_*).

use Lumia\Factures\Core\Plugin;
use Lumia\Factures\Core\Settings;
use Lumia\Factures\Tests\Support\SampleModule;
use Lumia\Factures\Tests\Support\TestCase;
use WPDieException;

/**
 * Admin page of the plugin, under the WooCommerce menu.
 */
class AdminTest extends TestCase {

	/**
	 * Menu globals: WordPress only builds them in wp-admin/menu.php, and
	 * WooCommerce's admin_menu callbacks iterate over them. The asset queues
	 * outlive a test: emptied so that each test sees only its own enqueues.
	 */
	public function set_up(): void {
		parent::set_up();
		$GLOBALS['menu']    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$GLOBALS['submenu'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		wp_styles()->queue  = array();
		wp_scripts()->queue = array();
	}

	/**
	 * Request globals touched by the tests.
	 */
	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		parent::tear_down();
	}

	/**
	 * Turns wp_safe_redirect() into an exception carrying the location (the handler exits right after).
	 */
	private function catch_redirects(): void {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the test, never printed.
			}
		);
	}

	/**
	 * Fires the save handler and returns the redirect location.
	 *
	 * @param array<string, mixed> $post POST payload.
	 */
	private function post_save( array $post ): string {
		$_POST    = $post;
		$_REQUEST = $post;
		$this->catch_redirects();
		try {
			do_action( 'admin_post_lumia_factures_save_settings' );
		} catch ( \RuntimeException $e ) {
			return substr( $e->getMessage(), strlen( 'redirect:' ) );
		}
		$this->fail( 'No redirect after saving.' );
	}

	/**
	 * Renders the admin page for a tab.
	 *
	 * @param string $tab Tab to render.
	 */
	private function render_tab( string $tab ): string {
		$_GET['page'] = 'lumia-factures';
		$_GET['tab']  = $tab;
		ob_start();
		try {
			Plugin::instance()->admin()->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	private function login_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_menu_registered_under_woocommerce_for_shop_manager(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		do_action( 'admin_menu' );
		global $submenu;
		$this->assertContains( 'lumia-factures', array_column( $submenu['woocommerce'] ?? array(), 2 ) );
	}

	public function test_no_top_level_menu(): void {
		$this->login_admin();
		do_action( 'admin_menu' );
		global $menu;
		$this->assertNotContains( 'lumia-factures', array_column( (array) $menu, 2 ) );
	}

	public function test_save_settings_requires_nonce(): void {
		$this->login_admin();
		$this->expectException( WPDieException::class );
		$_POST = array(
			'action'             => 'lumia_factures_save_settings',
			'lumia_factures_tab' => 'settings',
		);
		do_action( 'admin_post_lumia_factures_save_settings' );
	}

	public function test_save_settings_requires_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( WPDieException::class );
		$_POST = array(
			'lumia_factures_nonce'  => wp_create_nonce( 'lumia_factures_save_settings' ),
			'lumia_factures_tab'    => 'settings',
			'lumia_factures_global' => array( 'update_channel' => 'dev' ),
		);
		do_action( 'admin_post_lumia_factures_save_settings' );
	}

	public function test_save_settings_updates_option_and_redirects_with_notice(): void {
		$this->login_admin();
		$location = $this->post_save(
			array(
				'lumia_factures_nonce'  => wp_create_nonce( 'lumia_factures_save_settings' ),
				'lumia_factures_tab'    => 'settings',
				'lumia_factures_global' => array( 'update_channel' => 'dev' ),
			)
		);
		$this->assertSame( 'dev', Plugin::instance()->settings()->get( 'global.update_channel' ) );
		$this->assertStringContainsString( 'page=lumia-factures', $location );
		$this->assertStringContainsString( 'lumia_factures_notice=settings_saved', $location );
	}

	public function test_save_settings_rejects_unknown_channel(): void {
		$this->login_admin();
		$this->post_save(
			array(
				'lumia_factures_nonce'  => wp_create_nonce( 'lumia_factures_save_settings' ),
				'lumia_factures_tab'    => 'settings',
				'lumia_factures_global' => array( 'update_channel' => '<script>' ),
			)
		);
		$this->assertSame( 'stable', Plugin::instance()->settings()->get( 'global.update_channel' ) );
	}

	public function test_modules_tab_toggles_optional_modules_but_never_locked_ones(): void {
		$this->login_admin();
		$modules = Plugin::instance()->modules();
		$this->assertFalse( $modules->is_active( 'bricks' ) );

		$location = $this->post_save(
			array(
				'lumia_factures_nonce'   => wp_create_nonce( 'lumia_factures_save_settings' ),
				'lumia_factures_tab'     => 'modules',
				'lumia_factures_modules' => array( 'bricks' ),
			)
		);
		$this->assertTrue( $modules->is_active( 'bricks' ) );
		$this->assertTrue( $modules->is_active( 'invoices' ) );
		$this->assertStringContainsString( 'lumia_factures_notice=modules_updated', $location );

		// Nothing checked: the optional module goes off, the locked one stays on.
		$this->post_save(
			array(
				'lumia_factures_nonce' => wp_create_nonce( 'lumia_factures_save_settings' ),
				'lumia_factures_tab'   => 'modules',
			)
		);
		$this->assertFalse( $modules->is_active( 'bricks' ) );
		$this->assertTrue( $modules->is_active( 'invoices' ) );
	}

	public function test_module_tab_saves_through_the_module(): void {
		$this->login_admin();
		$this->post_save(
			array(
				'lumia_factures_nonce'           => wp_create_nonce( 'lumia_factures_save_settings' ),
				'lumia_factures_tab'             => 'module_invoices',
				'lumia_factures_module_settings' => array( 'sample' => '1' ),
			)
		);
		// The invoices module declares no defaults: an unknown key is dropped.
		$this->assertSame( array(), get_option( 'lumia_factures_module_invoices' ) );
	}

	public function test_base_save_settings_keeps_known_keys_and_coerces_types(): void {
		$module = new SampleModule( 'sample' );
		$module->save_settings(
			array(
				'enabled' => '0',
				'limit'   => '12abc',
				'label'   => '<b>Hi</b> ',
				'nested'  => array(
					'flag'  => '1',
					'other' => 'x',
				),
				'evil'    => '<script>',
			)
		);
		$this->assertSame(
			array(
				'enabled' => false,
				'limit'   => 12,
				'label'   => 'Hi',
				'nested'  => array( 'flag' => true ),
			),
			get_option( 'lumia_factures_module_sample' )
		);
		$this->assertFalse( $module->get_settings()['enabled'], 'A default-true boolean can be saved as false.' );
	}

	public function test_generic_module_form_emits_hidden_zero_before_checkbox(): void {
		$this->login_admin();
		$modules = Plugin::instance()->modules();
		$modules->register(
			'sample',
			array(
				'name'  => 'Sample',
				'class' => SampleModule::class,
			)
		);
		$modules->activate( 'sample' );
		$modules->init_active_modules();
		$html = $this->render_tab( 'module_sample' );
		$this->assertMatchesRegularExpression( '/type="hidden" name="lumia_factures_module_settings\[enabled\]" value="0">\s*<input type="checkbox"/', $html );
	}

	public function test_shop_manager_cannot_change_update_channel(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->post_save(
			array(
				'lumia_factures_nonce'  => wp_create_nonce( 'lumia_factures_save_settings' ),
				'lumia_factures_tab'    => 'settings',
				'lumia_factures_global' => array( 'update_channel' => 'dev' ),
			)
		);
		$this->assertSame( 'stable', Plugin::instance()->settings()->get( 'global.update_channel', 'stable' ) );
	}

	public function test_array_update_channel_does_not_warn(): void {
		$this->login_admin();
		$this->post_save(
			array(
				'lumia_factures_nonce'  => wp_create_nonce( 'lumia_factures_save_settings' ),
				'lumia_factures_tab'    => 'settings',
				'lumia_factures_global' => array( 'update_channel' => array( 'dev' ) ),
			)
		);
		$this->assertSame( 'stable', Plugin::instance()->settings()->get( 'global.update_channel', 'stable' ) );
	}

	public function test_channel_select_disabled_without_update_plugins(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertMatchesRegularExpression( '/<select id="lumia_factures_update_channel"[^>]*disabled/', $this->render_tab( 'settings' ) );
		$this->login_admin();
		$this->assertDoesNotMatchRegularExpression( '/<select id="lumia_factures_update_channel"[^>]*disabled/', $this->render_tab( 'settings' ) );
	}

	public function test_assets_enqueued_only_on_plugin_page(): void {
		$this->login_admin();
		do_action( 'admin_menu' );

		set_current_screen( 'edit.php' );
		do_action( 'admin_enqueue_scripts', 'edit.php' );
		$this->assertFalse( wp_style_is( 'lumia-factures-tokens', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'lumia-factures-admin', 'enqueued' ) );

		set_current_screen( Plugin::instance()->admin()->get_hook_suffix() );
		do_action( 'admin_enqueue_scripts', Plugin::instance()->admin()->get_hook_suffix() );
		foreach ( array( 'tokens', 'reset', 'layout', 'sidebar', 'components', 'buttons', 'notifications' ) as $sheet ) {
			$this->assertTrue( wp_style_is( 'lumia-factures-' . $sheet, 'enqueued' ), $sheet );
		}
		$this->assertTrue( wp_script_is( 'lumia-factures-admin', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'lumia-factures-notifications', 'enqueued' ) );

		$data = (string) wp_scripts()->get_data( 'lumia-factures-admin', 'data' );
		$this->assertStringContainsString( 'lumiaFacturesAdmin', $data );
		$this->assertStringContainsString( 'ajaxUrl', $data );
		$this->assertStringContainsString( 'nonce', $data );
		$this->assertStringContainsString( 'i18n', $data );
	}

	public function test_reset_css_is_scoped_to_the_plugin_wrapper(): void {
		$css = (string) file_get_contents( LUMIA_FACTURES_PLUGIN_DIR . 'assets/admin/css/reset.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		preg_match_all( '/([^{}]+)\{/', $css, $matches );
		$this->assertNotEmpty( $matches[1] );
		foreach ( $matches[1] as $group ) {
			foreach ( explode( ',', $group ) as $selector ) {
				$this->assertStringStartsWith( '.lumia-factures-admin', trim( $selector ) );
			}
		}
	}

	public function test_page_renders_sidebar_in_order_and_each_tab(): void {
		$this->login_admin();

		$html = $this->render_tab( 'dashboard' );
		$this->assertStringContainsString( 'lumia-admin-wrap lumia-factures-admin', $html );
		$overview = strpos( $html, 'tab=dashboard' );
		$modules  = strpos( $html, 'tab=modules' );
		$invoices = strpos( $html, 'tab=module_invoices' );
		$settings = strpos( $html, 'tab=settings' );
		$this->assertNotFalse( $overview );
		$this->assertTrue( $overview < $modules && $modules < $invoices && $invoices < $settings, 'Sidebar order: Overview, Modules, active modules, Settings.' );
		$this->assertStringNotContainsString( 'tab=module_bricks', $html, 'Inactive modules stay out of the sidebar.' );
		$this->assertStringContainsString( esc_html( LUMIA_FACTURES_VERSION ), $html );

		$html = $this->render_tab( 'modules' );
		$this->assertMatchesRegularExpression( '/value="invoices"[^>]*disabled/', $html, 'The locked module toggle is disabled.' );
		$this->assertStringContainsString( 'value="bricks"', $html );
		$this->assertStringContainsString( 'lumia_factures_save_settings', $html );

		$html = $this->render_tab( 'settings' );
		$this->assertStringContainsString( 'lumia_factures_global[update_channel]', $html );
		$this->assertStringContainsString( 'name="lumia_factures_nonce"', $html );

		$html = $this->render_tab( 'module_invoices' );
		$this->assertStringContainsString( 'lumia-page__title', $html );
	}

	public function test_render_page_requires_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( WPDieException::class );
		$this->render_tab( 'dashboard' );
	}

	public function test_toast_reads_prefixed_query_args(): void {
		$this->login_admin();
		do_action( 'admin_menu' );
		set_current_screen( Plugin::instance()->admin()->get_hook_suffix() );

		$_GET['lumia_notice'] = 'settings_saved';
		$this->render_tab( 'settings' );
		$this->assertStringNotContainsString( 'Settings saved successfully.', $this->footer_html() );

		unset( $_GET['lumia_notice'] );
		$_GET['lumia_factures_notice']      = 'settings_saved';
		$_GET['lumia_factures_notice_type'] = 'warning';
		$this->render_tab( 'settings' );
		$footer = $this->footer_html();
		$this->assertStringContainsString( 'Settings saved successfully.', $footer );
		$this->assertStringContainsString( '"type":"warning"', $footer );
	}

	public function test_module_notice_codes_are_filterable(): void {
		$this->login_admin();
		do_action( 'admin_menu' );
		set_current_screen( Plugin::instance()->admin()->get_hook_suffix() );
		add_filter(
			'lumia_factures_admin_notices',
			static function ( array $messages ): array {
				$messages['invoice_issued'] = 'Invoice issued.';
				return $messages;
			}
		);

		$_GET['lumia_factures_notice'] = 'invoice_issued';
		$this->render_tab( 'settings' );
		$this->assertStringContainsString( 'Invoice issued.', $this->footer_html() );
	}

	private function footer_html(): string {
		ob_start();
		Plugin::instance()->admin()->render_footer_ui();
		return (string) ob_get_clean();
	}

	public function test_footer_prints_toast_and_modal_on_plugin_screen_only(): void {
		$this->login_admin();
		do_action( 'admin_menu' );
		$_GET['lumia_factures_notice'] = 'settings_saved';
		$this->render_tab( 'settings' );

		set_current_screen( 'edit.php' );
		ob_start();
		Plugin::instance()->admin()->render_footer_ui();
		$this->assertSame( '', (string) ob_get_clean() );

		set_current_screen( Plugin::instance()->admin()->get_hook_suffix() );
		ob_start();
		Plugin::instance()->admin()->render_footer_ui();
		$footer = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="lumia-factures-toast-container"', $footer );
		$this->assertStringContainsString( 'id="lumia-factures-modal-overlay"', $footer );
		$this->assertStringContainsString( 'lumiaFacturesToast', $footer );
		$this->assertStringContainsString( 'Settings saved successfully.', $footer );
	}

	/**
	 * Fresh singleton whose requirements fail: the module registry is empty,
	 * the page must still render (no fatal) and show why.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_page_renders_when_requirements_fail(): void {
		// The bootstrap instance of this child process must not answer the form.
		remove_all_actions( 'admin_post_lumia_factures_save_settings' );
		$instance = new \ReflectionProperty( Plugin::class, 'instance' );
		$instance->setValue( null, null );

		add_filter( 'lumia_factures_requirements_wc_active', '__return_false' );
		$plugin = Plugin::instance();
		$plugin->boot();
		$this->assertSame( array(), $plugin->modules()->get_all() );

		$this->login_admin();

		$html = $this->render_tab( 'dashboard' );
		$this->assertStringContainsString( 'requires WooCommerce', $html );
		$this->assertStringNotContainsString( 'tab=module_', $html );

		foreach ( array( 'modules', 'settings', 'module_invoices', 'unknown' ) as $tab ) {
			$this->assertStringContainsString( 'lumia-factures-admin', $this->render_tab( $tab ), $tab );
		}

		// Saving the modules tab with an empty registry is a harmless no-op.
		$location = $this->post_save(
			array(
				'lumia_factures_nonce'   => wp_create_nonce( 'lumia_factures_save_settings' ),
				'lumia_factures_tab'     => 'modules',
				'lumia_factures_modules' => array( 'bricks' ),
			)
		);
		$this->assertStringContainsString( 'lumia_factures_notice=modules_updated', $location );
		$this->assertFalse( (bool) $plugin->settings()->get( 'modules.bricks', false ) );
	}

	public function test_coexists_with_lumia_tools(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/lumia-tools-stub.php';

		$this->assertTrue( class_exists( 'Lumia\Tools\Core\Plugin' ) );
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertTrue( defined( 'LUMIA_VERSION' ) );
		$this->assertNotSame( 'Lumia\Tools\Core\Plugin', Plugin::class );

		$this->login_admin();
		do_action( 'admin_menu' );
		global $menu, $submenu;
		$this->assertContains( 'lumia-tools', array_column( (array) $menu, 2 ), 'lumia-tools keeps its own menu.' );
		$this->assertContains( 'lumia-factures', array_column( $submenu['woocommerce'] ?? array(), 2 ) );

		// Our handles, captured alone: none may be one of lumia-tools'.
		$hook   = Plugin::instance()->admin()->get_hook_suffix();
		$before = array_merge( wp_styles()->queue, wp_scripts()->queue );
		set_current_screen( $hook );
		Plugin::instance()->admin()->enqueue_assets( $hook );
		$ours = array_diff( array_merge( wp_styles()->queue, wp_scripts()->queue ), $before );
		$this->assertNotEmpty( $ours );
		foreach ( $ours as $handle ) {
			$this->assertStringStartsWith( 'lumia-factures-', $handle );
		}
		$this->assertSame( array(), array_values( array_intersect( \Lumia\Tools\Core\Plugin::HANDLES, $ours ) ) );

		// Then both plugins enqueue on the same screen: everything stays queued.
		do_action( 'admin_enqueue_scripts', $hook );
		foreach ( array_merge( $ours, array( 'lumia-admin' ) ) as $handle ) {
			$this->assertTrue( wp_style_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'enqueued' ), $handle );
		}
		$this->assertStringContainsString( 'lumiaFacturesAdmin', (string) wp_scripts()->get_data( 'lumia-factures-admin', 'data' ) );

		// Distinct options.
		$this->assertNotSame( \Lumia\Tools\Core\Plugin::OPTION, Settings::OPTION_KEY );
		update_option( \Lumia\Tools\Core\Plugin::OPTION, array( 'global' => array( 'update_channel' => 'dev' ) ) );
		Plugin::instance()->settings()->set( 'global.update_channel', 'stable' );
		$this->assertSame( 'dev', get_option( \Lumia\Tools\Core\Plugin::OPTION )['global']['update_channel'] );

		// Distinct form actions and admin-post hooks.
		$this->assertNotFalse( has_action( 'admin_post_lumia_factures_save_settings' ) );
		$this->assertNotFalse( has_action( 'admin_post_lumia_save_settings' ) );

		// Our scripts only define lumiaFactures* globals: lumia-tools' lumiaAdmin,
		// lumiaModal, lumiaShowToast... are never overwritten.
		foreach ( glob( LUMIA_FACTURES_PLUGIN_DIR . 'assets/admin/js/*.js' ) as $file ) {
			preg_match_all( '/window\.(\w+)\s*=(?!=)/', (string) file_get_contents( $file ), $globals ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			foreach ( $globals[1] as $global ) {
				$this->assertStringStartsWith( 'lumiaFactures', $global, basename( $file ) );
			}
		}
	}
}
