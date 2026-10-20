<?php
/**
 * Module registry: locked core module and optional modules.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Core;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\AbstractModule;
use Lumia\Factures\Core\Activator;
use Lumia\Factures\Core\Modules;
use Lumia\Factures\Core\Plugin;
use Lumia\Factures\Tests\Support\SampleModule;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * Activation rules of the initial modules.
 */
class ModulesTest extends TestCase {

	public function test_initial_modules_are_registered(): void {
		$modules = Plugin::instance()->modules();
		$this->assertNotNull( $modules->get( 'invoices' ) );
		$this->assertNotNull( $modules->get( 'bricks' ) );
		$this->assertTrue( $modules->is_locked( 'invoices' ) );
		$this->assertTrue( $modules->is_locked( 'bricks' ), 'Bricks is always on: its hooks only fire under Bricks.' );
	}

	public function test_invoices_module_cannot_be_deactivated(): void {
		$this->assertFalse( Plugin::instance()->modules()->deactivate( 'invoices' ) );
		$this->assertTrue( Plugin::instance()->modules()->is_active( 'invoices' ) );
	}

	public function test_bricks_module_is_active_and_cannot_be_deactivated(): void {
		$modules = Plugin::instance()->modules();
		$this->assertTrue( $modules->is_active( 'bricks' ) );
		$this->assertArrayHasKey( 'bricks', $modules->get_active_instances() );
		$this->assertFalse( $modules->deactivate( 'bricks' ) );
		$this->assertTrue( $modules->is_active( 'bricks' ) );
	}

	public function test_optional_module_toggles(): void {
		$modules = Plugin::instance()->modules();
		$modules->register(
			'sample',
			array(
				'name'  => 'Sample',
				'class' => SampleModule::class,
			)
		);
		$this->assertFalse( $modules->is_active( 'sample' ) );

		$this->assertTrue( $modules->activate( 'sample' ) );
		$this->assertTrue( $modules->is_active( 'sample' ) );

		$this->assertTrue( $modules->deactivate( 'sample' ) );
		$this->assertFalse( $modules->is_active( 'sample' ) );
	}

	/**
	 * Module whose default is an empty map.
	 */
	private function map_module(): AbstractModule {
		return new class( 'map_fixture' ) extends AbstractModule {
			public function init(): void {}

			public static function get_defaults(): array {
				return array( 'map' => array() );
			}

			public static function get_uninstall_keys(): array {
				return array();
			}
		};
	}

	public function test_save_settings_keeps_keyed_map_under_empty_default(): void {
		$module = $this->map_module();
		$module->save_settings( array( 'map' => array( 'a' => 'x' ) ) );
		$this->assertSame( array( 'a' => 'x' ), $module->get_settings()['map'] );

		// A list stays a list.
		$module->save_settings( array( 'map' => array( 'p', 'q' ) ) );
		$this->assertSame( array( 'p', 'q' ), $module->get_settings()['map'] );

		// Scalars of a map are sanitized, nested arrays dropped.
		$module->save_settings(
			array(
				'map' => array(
					'a' => '<b>x</b>',
					'n' => array( 1 ),
				),
			)
		);
		$this->assertSame( array( 'a' => 'x' ), $module->get_settings()['map'] );
	}

	public function test_save_settings_dispatches_sanitizer_late(): void {
		$module = new class( 'late_fixture' ) extends AbstractModule {
			public function init(): void {}

			public static function get_defaults(): array {
				return array( 'label' => '' );
			}

			public static function get_uninstall_keys(): array {
				return array();
			}

			protected static function sanitize_against_defaults( array $defaults, array $input ): array {
				return array( 'label' => 'overridden' );
			}
		};
		$module->save_settings( array( 'label' => 'raw' ) );
		$this->assertSame( 'overridden', $module->get_settings()['label'] );
	}

	public function test_activator_reads_locked_modules(): void {
		$definitions = Modules::default_definitions();
		$this->assertTrue( $definitions['invoices']['locked'] );
		$this->assertTrue( $definitions['bricks']['locked'] );

		delete_option( 'lumia_factures_settings' );
		Activator::activate();
		foreach ( array_keys( Activator::MODULE_CLASSES ) as $id ) {
			$this->assertSame( ! empty( $definitions[ $id ]['locked'] ), Plugin::instance()->settings()->get( 'modules.' . $id ), $id );
		}
	}
}
