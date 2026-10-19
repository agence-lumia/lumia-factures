<?php
/**
 * Module registry: locked core module and optional modules.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Core;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\Plugin;
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
		$this->assertFalse( $modules->is_locked( 'bricks' ) );
	}

	public function test_invoices_module_cannot_be_deactivated(): void {
		$this->assertFalse( Plugin::instance()->modules()->deactivate( 'invoices' ) );
		$this->assertTrue( Plugin::instance()->modules()->is_active( 'invoices' ) );
	}

	public function test_optional_module_toggles(): void {
		$modules = Plugin::instance()->modules();
		$this->assertFalse( $modules->is_active( 'bricks' ) );

		$this->assertTrue( $modules->activate( 'bricks' ) );
		$this->assertTrue( $modules->is_active( 'bricks' ) );

		$this->assertTrue( $modules->deactivate( 'bricks' ) );
		$this->assertFalse( $modules->is_active( 'bricks' ) );
	}
}
