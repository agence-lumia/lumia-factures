<?php
/**
 * The order's documents on the "View order" page and the thank-you page.
 *
 * Override it by copying it to yourtheme/lumia-factures/order-documents.php.
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
?>
<section class="woocommerce-order-documents lumia-factures-order-documents">
	<h2 class="woocommerce-order-documents__title"><?php esc_html_e( 'Documents', 'lumia-factures' ); ?></h2>
	<table class="woocommerce-table shop_table lumia-factures-order-documents__table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Document', 'lumia-factures' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Date', 'lumia-factures' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $documents as $lumia_factures_document ) : ?>
				<tr class="lumia-factures-order-documents__row lumia-factures-order-documents__row--<?php echo esc_attr( $lumia_factures_document['type'] ); ?>">
					<td>
						<a href="<?php echo esc_url( $lumia_factures_document['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $lumia_factures_document['label'] . ' ' . $lumia_factures_document['number'] ); ?></a>
					</td>
					<td><?php echo esc_html( $lumia_factures_document['date'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>
