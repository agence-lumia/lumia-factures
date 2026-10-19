<?php
/**
 * Constants PHPStan finds neither in the WordPress stubs nor in the analysed code.
 * Tooling file only: never loaded by the plugin.
 */

define( 'WPINC', 'wp-includes' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant, declared for PHPStan only.
define( 'LUMIA_FACTURES_PLUGIN_DIR', dirname( __DIR__ ) . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Plugin constant, its value comes from a function call PHPStan does not evaluate.
