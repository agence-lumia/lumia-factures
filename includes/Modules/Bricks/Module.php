<?php
/**
 * Bricks module (optional).
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Bricks;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\AbstractModule;

/**
 * Optional module: Bricks dynamic tags for the invoice. Inactive by default, turned on by the
 * legacy import when a Bricks template uses a `{fpl_invoice_*}` tag.
 */
class Module extends AbstractModule {

	/**
	 * Registers the module hooks.
	 */
	public function init(): void {
		Tags::register();
	}
}
