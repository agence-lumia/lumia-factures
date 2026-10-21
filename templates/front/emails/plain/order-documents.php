<?php
/**
 * Links to the order's documents in a customer e-mail (plain text).
 *
 * One line per document: "<label> <number>: <url>". Override it by copying it to
 * yourtheme/lumia-factures/emails/plain/order-documents.php.
 *
 * @package Lumia\Factures
 *
 * @var WC_Order                                                                         $order     Order.
 * @var list<array{type: string, label: string, number: string, date: string, url: string}> $documents Available documents.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $documents ) || ! is_array( $documents ) ) {
	return;
}

echo "\n" . esc_html( wc_strtoupper( __( 'Your documents', 'lumia-factures' ) ) ) . "\n\n";

foreach ( $documents as $lumia_factures_document ) {
	// Plain text: the URL is printed raw so that it stays clickable (no HTML entity).
	echo esc_html( wp_strip_all_tags( $lumia_factures_document['label'] . ' ' . $lumia_factures_document['number'] ) ) . ': ' . esc_url_raw( $lumia_factures_document['url'] ) . "\n";
}

echo "\n";
