<?php
/**
 * Bricks module (always on).
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Bricks;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\AbstractModule;

/**
 * Bricks dynamic tags for the invoice. Locked (always on): it only hooks Bricks filters, which
 * never fire without Bricks, so there is nothing to turn on, and the legacy `{fpl_invoice_*}`
 * templates of a migrated site keep rendering.
 */
class Module extends AbstractModule {

	/**
	 * Registers the module hooks.
	 */
	public function init(): void {
		Tags::register();
	}
}
