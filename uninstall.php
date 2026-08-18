<?php
/**
 * Désinstallation.
 *
 * Exécuté uniquement lors d'une suppression explicite depuis l'écran des
 * extensions, jamais à la désactivation.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Les données de facturation ne sont jamais supprimées automatiquement.
 *
 * Les numéros de facture et leurs dates d'émission sont conservés en metas de
 * commande. Une facture émise engage l'entreprise : supprimer sa trace parce
 * qu'un plugin a été désinstallé serait un comportement inacceptable, et
 * potentiellement un problème en cas de contrôle.
 *
 * Pour un nettoyage complet, définir la constante suivante dans wp-config.php
 * avant de supprimer le plugin :
 *
 *     define( 'FPL_REMOVE_ALL_DATA', true );
 */
if ( ! defined( 'FPL_REMOVE_ALL_DATA' ) || ! FPL_REMOVE_ALL_DATA ) {
	// On retire uniquement les tâches planifiées et le cache de fichiers, qui
	// n'ont aucune valeur légale.
	$timestamp = wp_next_scheduled( 'fpl_cleanup_files' );

	while ( false !== $timestamp ) {
		wp_unschedule_event( $timestamp, 'fpl_cleanup_files' );

		$timestamp = wp_next_scheduled( 'fpl_cleanup_files' );
	}

	return;
}

global $wpdb;

// Options.
$options = array(
	'fpl_settings',
	'fpl_version',
	'fpl_db_version',
	'fpl_schema_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Table des séquences.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fpl_sequences" );

// Metas de commande.
$meta_keys = array(
	'_fpl_invoice_number',
	'_fpl_invoice_sequence',
	'_fpl_invoice_date',
	'_fpl_invoice_file',
	'_fpl_invoice_generated_at',
);

foreach ( $meta_keys as $meta_key ) {
	// Stockage historique (articles WordPress).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) );

	// Stockage HPOS.
	$hpos_table = $wpdb->prefix . 'wc_orders_meta';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $hpos_table, array( 'meta_key' => $meta_key ) );
	}
}

// Fichiers PDF en cache.
$uploads = wp_upload_dir( null, false );
$path    = trailingslashit( $uploads['basedir'] ) . 'facture-pdf-lumia';

if ( is_dir( $path ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $item->getPathname() );
		} else {
			wp_delete_file( $item->getPathname() );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	rmdir( $path );
}

// Tâches planifiées.
$timestamp = wp_next_scheduled( 'fpl_cleanup_files' );

while ( false !== $timestamp ) {
	wp_unschedule_event( $timestamp, 'fpl_cleanup_files' );

	$timestamp = wp_next_scheduled( 'fpl_cleanup_files' );
}
