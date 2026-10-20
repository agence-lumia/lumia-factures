<?php
/**
 * Plugin Name: Lümia Factures
 * Plugin URI:  https://github.com/agence-lumia/lumia-factures
 * Update URI:  https://github.com/agence-lumia/lumia-factures
 * Description: PDF invoices for WooCommerce orders.
 * Version:     2.0.0-dev.3
 * Author:      Agence Lümia
 * Author URI:  https://agence-lumia.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: lumia-factures
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 11.2
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

// Base constants.
define( 'LUMIA_FACTURES_VERSION', '2.0.0-dev.3' );
define( 'LUMIA_FACTURES_PLUGIN_FILE', __FILE__ );
define( 'LUMIA_FACTURES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUMIA_FACTURES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LUMIA_FACTURES_INCLUDES_DIR', LUMIA_FACTURES_PLUGIN_DIR . 'includes/' );
define( 'LUMIA_FACTURES_TEMPLATES_DIR', LUMIA_FACTURES_PLUGIN_DIR . 'templates/' );
define( 'LUMIA_FACTURES_ASSETS_URL', LUMIA_FACTURES_PLUGIN_URL . 'assets/' );

// Runtime dependencies (Dompdf). A missing vendor/ is reported by Requirements, never a fatal error.
if ( is_readable( LUMIA_FACTURES_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once LUMIA_FACTURES_PLUGIN_DIR . 'vendor/autoload.php';
}

// Autoloader.
require_once LUMIA_FACTURES_INCLUDES_DIR . 'Core/Autoloader.php';
Lumia\Factures\Core\Autoloader::register();

// WooCommerce features compatibility: HPOS and the cart/checkout blocks.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LUMIA_FACTURES_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', LUMIA_FACTURES_PLUGIN_FILE, true );
		}
	}
);

// Bootstrap.
add_action(
	'plugins_loaded',
	static function (): void {
		Lumia\Factures\Core\Plugin::instance();
	},
	10
);

// Activation hook.
register_activation_hook( __FILE__, [ 'Lumia\Factures\Core\Activator', 'activate' ] );

// Deactivation hook.
register_deactivation_hook( __FILE__, [ 'Lumia\Factures\Core\Deactivator', 'deactivate' ] );
