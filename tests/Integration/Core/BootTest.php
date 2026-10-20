<?php
/**
 * Plugin bootstrap and requirements.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Core;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\Activator;
use Lumia\Factures\Core\Plugin;
use Lumia\Factures\Core\Requirements;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * Boot sequence, activation defaults and blocking requirements.
 */
class BootTest extends TestCase {

	public function test_plugin_boots_with_woocommerce(): void {
		$this->assertTrue( defined( 'LUMIA_FACTURES_VERSION' ) );
		$this->assertTrue( Plugin::instance()->modules()->is_active( 'invoices' ) );
		$this->assertArrayHasKey( 'invoices', Plugin::instance()->modules()->get_active_instances() );
	}

	public function test_textdomain_loaded_on_init(): void {
		$this->assertGreaterThan( 0, has_action( 'init', array( Plugin::instance(), 'load_textdomain' ) ) );
	}

	public function test_activation_creates_default_options_without_overwriting(): void {
		update_option( 'lumia_factures_settings', array( 'global' => array( 'update_channel' => 'dev' ) ) );
		Activator::activate();
		$this->assertSame( 'dev', Plugin::instance()->settings()->get( 'global.update_channel' ) );
	}

	public function test_activation_creates_default_options(): void {
		delete_option( 'lumia_factures_settings' );
		Activator::activate();
		$this->assertSame( 'stable', Plugin::instance()->settings()->get( 'global.update_channel' ) );
		$this->assertTrue( Plugin::instance()->settings()->get( 'modules.bricks' ), 'Bricks is locked: on from the start.' );
	}

	public function test_requirements_pass_in_test_environment(): void {
		$this->assertSame( array(), Requirements::check() );
	}

	public function test_does_not_boot_without_woocommerce(): void {
		add_filter( 'lumia_factures_requirements_wc_active', '__return_false' );
		$this->assertNotEmpty( Requirements::check() );
		$this->assertArrayHasKey( 'woocommerce', Requirements::check() );
	}

	/**
	 * Fresh singleton in a child process: the bootstrap one already started its
	 * modules, and nothing set here (instance, errors) may reach the other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_failed_requirements_show_notice_instead_of_modules(): void {
		$instance = new \ReflectionProperty( Plugin::class, 'instance' );
		$instance->setValue( null, null );

		add_filter( 'lumia_factures_requirements_wc_active', '__return_false' );
		$plugin = Plugin::instance();
		$this->assertSame( array(), $plugin->modules()->get_all(), 'Fresh instance expected.' );

		$plugin->boot();
		$this->assertArrayHasKey( 'woocommerce', $plugin->requirement_errors() );
		$this->assertSame( array(), $plugin->modules()->get_all() );
		$this->assertSame( array(), $plugin->modules()->get_active_instances() );
		$this->assertGreaterThan( 0, has_action( 'all_admin_notices', array( $plugin, 'render_requirements_notice' ) ) );
		$this->assertFalse( has_action( 'admin_notices', array( $plugin, 'render_requirements_notice' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		$plugin->render_requirements_notice();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'WooCommerce', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_refuses_to_run_alongside_legacy_plugin(): void {
		define( 'FPL_VERSION', '1.1.1' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the legacy plugin.
		$this->assertContains( 'legacy_active', array_keys( Requirements::check() ) );
	}

	public function test_requirements_report_missing_dompdf(): void {
		add_filter( 'lumia_factures_requirements_dompdf_class', fn() => 'Missing\Dompdf' );
		$this->assertArrayHasKey( 'dompdf', Requirements::check() );
	}

	public function test_requirements_report_missing_extension(): void {
		add_filter( 'lumia_factures_required_extensions', fn() => array( 'lumia_missing_ext' ) );
		$errors = Requirements::check();
		$this->assertArrayHasKey( 'extensions', $errors );
		$this->assertStringContainsString( 'lumia_missing_ext', $errors['extensions'] );
	}
}
