<?php
/**
 * En-tête : logo et société sur une ligne, puis bandeau de titre.
 *
 * Disposition en tableau plutôt qu'en Flexbox : Dompdf ne supporte ni
 * Flexbox ni Grid, un tableau à deux cellules reste le moyen le plus fiable
 * d'aligner deux blocs sur une même ligne dans ce moteur.
 *
 * @package Lumia\FacturePDF
 *
 * @var array{logo: array{src: string, width: int}|null, title: string, number: string} $header
 * @var array<int, array{key: string, label: string, value: string}> $shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<table class="fpl-header">
	<tr>
		<td class="fpl-header__logo">
			<?php if ( null !== $header['logo'] ) : ?>
				<img src="<?php echo esc_attr( $header['logo']['src'] ); ?>" alt="">
			<?php endif; ?>
		</td>
		<td class="fpl-header__company">
			<?php foreach ( $shop as $index => $row ) : ?>
				<?php if ( 0 === $index ) : ?>
					<span class="fpl-header__name"><?php echo esc_html( $row['value'] ); ?></span>
				<?php elseif ( 'address' === $row['key'] ) : ?>
					<?php echo nl2br( esc_html( $row['value'] ) ); ?><br>
				<?php elseif ( '' !== $row['label'] ) : ?>
					<?php echo esc_html( $row['label'] ); ?> <?php echo esc_html( $row['value'] ); ?><br>
				<?php else : ?>
					<?php echo esc_html( $row['value'] ); ?><br>
				<?php endif; ?>
			<?php endforeach; ?>
		</td>
	</tr>
</table>

<table class="fpl-titlebar">
	<tr>
		<td class="fpl-titlebar__label">
			<h1><?php echo esc_html( $header['title'] ); ?></h1>
		</td>
		<td class="fpl-titlebar__number">
			<?php if ( '' !== $header['number'] ) : ?>
				<?php echo esc_html( $header['number'] ); ?>
			<?php endif; ?>
		</td>
	</tr>
</table>
