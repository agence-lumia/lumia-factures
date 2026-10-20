<?php
/**
 * Data cleanup on uninstall.
 *
 * Invoices must be kept for 10 years: by default, uninstalling the plugin
 * deletes NOTHING. Data is only removed when the site owner explicitly
 * defines `LUMIA_FACTURES_REMOVE_ALL_DATA` to true (wp-config.php).
 *
 * Each module declares the keys to delete through ::get_uninstall_keys().
 * The module list lives in Activator::MODULE_CLASSES.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'LUMIA_FACTURES_REMOVE_ALL_DATA' ) || true !== LUMIA_FACTURES_REMOVE_ALL_DATA ) {
	return;
}

// Load the autoloader to reach the module classes.
require_once plugin_dir_path( __FILE__ ) . 'includes/Core/Autoloader.php';
\Lumia\Factures\Core\Autoloader::register();

$module_classes = \Lumia\Factures\Core\Activator::MODULE_CLASSES;

// Delete the global option.
delete_option( \Lumia\Factures\Core\Settings::OPTION_KEY );
delete_site_option( \Lumia\Factures\Core\Settings::OPTION_KEY );

// Delete the options and meta that belong to each module.
foreach ( $module_classes as $id => $class ) {
	if ( ! class_exists( $class ) ) {
		continue;
	}

	// The module settings option, always owned by the module.
	delete_option( 'lumia_factures_module_' . $id );
	delete_site_option( 'lumia_factures_module_' . $id );

	$keys = $class::get_uninstall_keys();

	foreach ( $keys['options'] ?? [] as $option_key ) {
		delete_option( $option_key );
		delete_site_option( $option_key );
	}

	// Order meta, on legacy storage and on HPOS. The archive folder uploads/lumia-factures/ is never deleted.
	\Lumia\Factures\Core\Uninstaller::delete_order_meta( $keys['meta'] ?? [] );

	// Tables owned by a module, declared without a prefix. The name only comes
	// from the module code, never from user input: it is still restricted to
	// identifier characters before being interpolated.
	foreach ( $keys['tables'] ?? [] as $table ) {
		if ( ! preg_match( '/^[a-z0-9_]+$/', $table ) ) {
			continue;
		}
		$table = $GLOBALS['wpdb']->prefix . $table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated above.
		$GLOBALS['wpdb']->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	// Every event of the hook, whatever its arguments (see Deactivator).
	foreach ( $keys['cron'] ?? [] as $hook ) {
		wp_unschedule_hook( $hook );
	}

	// User metadata: delete_post_meta_by_key() does not touch it, it lives in
	// another table.
	foreach ( $keys['user_meta'] ?? [] as $meta_key ) {
		delete_metadata( 'user', 0, $meta_key, '', true );
	}

	// Delete the custom post types.
	foreach ( $keys['post_type'] ?? [] as $post_type ) {
		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'numberposts'    => -1,
				'posts_per_page' => -1,
			]
		);

		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID, true ); // True = hard delete.
		}
	}

	// Delete the custom taxonomies (every term).
	// The plugin is not booted here, so the taxonomy is not registered: register
	// it on the fly so that get_terms()/wp_delete_term() work.
	foreach ( $keys['taxonomy'] ?? [] as $tax_name ) {
		if ( '' === $tax_name ) {
			continue;
		}
		if ( ! taxonomy_exists( $tax_name ) ) {
			register_taxonomy( $tax_name, 'attachment', [ 'public' => false ] );
		}

		$terms = get_terms(
			[
				'taxonomy'   => $tax_name,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term_id ) {
				wp_delete_term( (int) $term_id, $tax_name );
			}
		}
	}
}
