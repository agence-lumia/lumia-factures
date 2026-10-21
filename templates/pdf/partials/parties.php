<?php
/**
 * Billing, shipping and document details on one row.
 *
 * A hidden block gives its width to the others instead of leaving an empty column. On a credit
 * note the parties are those of the refunded order and the details start with the credit note
 * number and date and the refund date (keys `credit_note_number`, `credit_note_date`, `refund_date`).
 *
 * @package Lumia\Factures
 *
 * @var array{title: string, address: string, email: string, phone: string} $customer
 * @var array{show: bool, title: string, address: string, phone: string}    $shipping
 * @var array<int, array{key: string, label: string, value: string}>        $meta
 */

defined( 'ABSPATH' ) || exit;

$lumia_factures_has_shipping = ! empty( $shipping['show'] );
$lumia_factures_has_meta     = array() !== $meta;

if ( $lumia_factures_has_shipping && $lumia_factures_has_meta ) {
	$lumia_factures_widths = array( 30, 30, 40 );
} elseif ( $lumia_factures_has_shipping ) {
	$lumia_factures_widths = array( 50, 50, 0 );
} elseif ( $lumia_factures_has_meta ) {
	$lumia_factures_widths = array( 45, 0, 55 );
} else {
	$lumia_factures_widths = array( 100, 0, 0 );
}
?>
<table class="lf-parties">
	<tr>
		<td class="lf-party" style="width: <?php echo esc_attr( (string) $lumia_factures_widths[0] ); ?>%;">
			<h3><?php echo esc_html( $customer['title'] ); ?></h3>
			<?php if ( '' !== $customer['address'] ) : ?>
				<address><?php echo nl2br( esc_html( $customer['address'] ) ); ?></address>
			<?php endif; ?>
			<?php if ( '' !== $customer['email'] ) : ?>
				<div class="lf-party__contact"><?php echo esc_html( $customer['email'] ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $customer['phone'] ) : ?>
				<div class="lf-party__contact"><?php echo esc_html( $customer['phone'] ); ?></div>
			<?php endif; ?>
		</td>
		<?php if ( $lumia_factures_has_shipping ) : ?>
			<td class="lf-party" style="width: <?php echo esc_attr( (string) $lumia_factures_widths[1] ); ?>%;">
				<h3><?php echo esc_html( $shipping['title'] ); ?></h3>
				<address><?php echo nl2br( esc_html( $shipping['address'] ) ); ?></address>
				<?php if ( '' !== $shipping['phone'] ) : ?>
					<div class="lf-party__contact"><?php echo esc_html( $shipping['phone'] ); ?></div>
				<?php endif; ?>
			</td>
		<?php endif; ?>
		<?php if ( $lumia_factures_has_meta ) : ?>
			<td class="lf-party lf-party--meta" style="width: <?php echo esc_attr( (string) $lumia_factures_widths[2] ); ?>%;">
				<table class="lf-meta">
					<?php foreach ( $meta as $row ) : ?>
						<tr>
							<td class="lf-meta__label"><?php echo esc_html( $row['label'] ); ?></td>
							<td class="lf-meta__value"><?php echo esc_html( $row['value'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</td>
		<?php endif; ?>
	</tr>
</table>
