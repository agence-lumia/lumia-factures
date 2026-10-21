<?php
/**
 * Links to the order's documents in a customer e-mail (HTML).
 *
 * Override it by copying it to yourtheme/lumia-factures/emails/order-documents.php.
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
<h2><?php esc_html_e( 'Your documents', 'lumia-factures' ); ?></h2>
<ul class="lumia-factures-documents" style="margin: 0 0 16px; padding-left: 20px;">
	<?php foreach ( $documents as $lumia_factures_document ) : ?>
		<li>
			<a href="<?php echo esc_url( $lumia_factures_document['url'] ); ?>"><?php echo esc_html( $lumia_factures_document['label'] . ' ' . $lumia_factures_document['number'] ); ?></a>
			<?php if ( '' !== $lumia_factures_document['date'] ) : ?>
				&ndash; <?php echo esc_html( $lumia_factures_document['date'] ); ?>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
