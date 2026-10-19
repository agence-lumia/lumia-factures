<?php
/**
 * Invoices module (core).
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\AbstractModule;

/**
 * Core module: numbering, documents, rendering, storage and emails (lot 2).
 * Always active, it cannot be deactivated.
 */
class Module extends AbstractModule {

	/**
	 * Registers the module hooks.
	 */
	public function init(): void {}
}
