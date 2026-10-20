<?php
/**
 * Module fixture with typed defaults.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Support;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\AbstractModule;

/**
 * Minimal module exercising the base save_settings() and the generic form.
 */
class SampleModule extends AbstractModule {

	public function init(): void {}

	/**
	 * Typed defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'enabled' => true,
			'limit'   => 5,
			'label'   => '',
			'nested'  => array( 'flag' => false ),
		);
	}

	/**
	 * Nothing to uninstall.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_uninstall_keys(): array {
		return array();
	}
}
