<?php
/**
 * Bloc client, bloc livraison et métadonnées sur une seule ligne.
 *
 * Les trois blocs partagent une rangée à largeur fixe. Quand la livraison est
 * masquée (adresse identique, commande dématérialisée), sa colonne est
 * réattribuée aux deux autres plutôt que laissée vide : une colonne vide au
 * milieu d'une facture ressemble à un bug d'affichage.
 *
 * @package Lumia\FacturePDF
 *
 * @var array{title: string, address: string, email: string, phone: string} $customer
 * @var array{show: bool, title: string, address: string, phone: string}    $shipping
 * @var array<int, array{key: string, label: string, value: string}>        $meta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fpl_has_shipping = ! empty( $shipping['show'] );
$fpl_has_meta     = array() !== $meta;

// Largeurs réparties selon les blocs réellement affichés.
if ( $fpl_has_shipping && $fpl_has_meta ) {
	$fpl_widths = array( 30, 30, 40 );
} elseif ( $fpl_has_meta ) {
	$fpl_widths = array( 45, 0, 55 );
} else {
	$fpl_widths = array( 50, 50, 0 );
}

?>
<table class="fpl-parties">
	<tr>
		<td class="fpl-party" style="width: <?php echo esc_attr( (string) $fpl_widths[0] ); ?>%;">
			<h3><?php echo esc_html( $customer['title'] ); ?></h3>
			<?php if ( '' !== $customer['address'] ) : ?>
				<address><?php echo wp_kses_post( $customer['address'] ); ?></address>
			<?php endif; ?>
			<?php if ( '' !== $customer['email'] ) : ?>
				<div class="fpl-party__contact"><?php echo esc_html( $customer['email'] ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $customer['phone'] ) : ?>
				<div class="fpl-party__contact"><?php echo esc_html( $customer['phone'] ); ?></div>
			<?php endif; ?>
		</td>

		<?php if ( $fpl_has_shipping ) : ?>
			<td class="fpl-party" style="width: <?php echo esc_attr( (string) $fpl_widths[1] ); ?>%;">
				<h3><?php echo esc_html( $shipping['title'] ); ?></h3>
				<address><?php echo wp_kses_post( $shipping['address'] ); ?></address>
				<?php if ( '' !== $shipping['phone'] ) : ?>
					<div class="fpl-party__contact"><?php echo esc_html( $shipping['phone'] ); ?></div>
				<?php endif; ?>
			</td>
		<?php endif; ?>

		<?php if ( $fpl_has_meta ) : ?>
			<td class="fpl-party fpl-party--meta" style="width: <?php echo esc_attr( (string) $fpl_widths[2] ); ?>%;">
				<table class="fpl-meta">
					<?php foreach ( $meta as $row ) : ?>
						<tr>
							<td class="fpl-meta__label"><?php echo esc_html( $row['label'] ); ?></td>
							<td class="fpl-meta__value"><?php echo esc_html( $row['value'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</td>
		<?php endif; ?>
	</tr>
</table>
