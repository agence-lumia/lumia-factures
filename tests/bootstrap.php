<?php
/**
 * PHPUnit bootstrap: WordPress test suite shipped with wp-env, WooCommerce, then the plugin.
 *
 * @package Lumia\Factures
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- test bootstrap scope.

$lumia_factures_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $lumia_factures_tests_dir ) {
	$lumia_factures_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$lumia_factures_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
if ( is_dir( $lumia_factures_polyfills ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $lumia_factures_polyfills ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constant read by the WordPress test suite.
}

require_once $lumia_factures_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		// wp-env installs the downloaded zip as "woocommerce.latest-stable", a regular install as "woocommerce".
		$lumia_factures_wc = glob( WP_PLUGIN_DIR . '/woocommerce*/woocommerce.php' );
		if ( ! empty( $lumia_factures_wc ) ) {
			require_once $lumia_factures_wc[0];
		}
		require_once dirname( __DIR__ ) . '/lumia-factures.php';
	}
);

// Create the WooCommerce tables once the theme setup runs.
tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
		}
		// Real tables must exist before the first test: inside a test, CREATE TABLE becomes CREATE TEMPORARY TABLE.
		\Lumia\Factures\Modules\Invoices\Database\Installer::install();

		// Order storage of the run: HPOS by default, the legacy posts storage with `npm run test:legacy`.
		// Data sync stays off in both modes so each run exercises exactly one storage.
		$lumia_factures_legacy = '1' === getenv( 'LUMIA_FACTURES_TEST_LEGACY_ORDERS' );
		update_option( 'woocommerce_custom_orders_table_enabled', $lumia_factures_legacy ? 'no' : 'yes' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
	}
);

require $lumia_factures_tests_dir . '/includes/bootstrap.php';
