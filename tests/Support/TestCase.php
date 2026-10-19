<?php
/**
 * Base class of the plugin tests.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Support;

defined( 'ABSPATH' ) || exit;

use WP_UnitTestCase;

/**
 * Minimal abstract base: plugin-specific helpers are added by the lots that need them.
 */
abstract class TestCase extends WP_UnitTestCase {
}
