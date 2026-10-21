<?php
/**
 * Header: logo and company on one row, then the title band and, on a credit note, the invoice
 * it credits (a mandatory mention, `reference` is never empty on a credit note).
 *
 * Tables, not flexbox: Dompdf supports neither flexbox nor grid.
 *
 * @package Lumia\Factures
 *
 * @var array{logo: array{src: string, width: int}|null, title: string, number: string, reference: string} $header
 * @var array<int, array{key: string, label: string, value: string}> $shop
 */

defined( 'ABSPATH' ) || exit;
?>
<table class="lf-header">
	<tr>
		<td class="lf-header__logo">
			<?php if ( null !== $header['logo'] ) : ?>
				<img src="<?php echo esc_attr( $header['logo']['src'] ); ?>" width="<?php echo esc_attr( (string) $header['logo']['width'] ); ?>" alt="">
			<?php endif; ?>
		</td>
		<td class="lf-header__company">
			<?php foreach ( $shop as $index => $row ) : ?>
				<?php if ( 0 === $index ) : ?>
					<span class="lf-header__name"><?php echo esc_html( $row['value'] ); ?></span>
				<?php elseif ( 'address' === $row['key'] ) : ?>
					<?php echo nl2br( esc_html( $row['value'] ) ); ?><br>
				<?php elseif ( '' !== $row['label'] ) : ?>
					<?php echo esc_html( $row['label'] . ' ' . $row['value'] ); ?><br>
				<?php else : ?>
					<?php echo esc_html( $row['value'] ); ?><br>
				<?php endif; ?>
			<?php endforeach; ?>
		</td>
	</tr>
</table>
<table class="lf-titlebar">
	<tr>
		<td class="lf-titlebar__label"><h1><?php echo esc_html( $header['title'] ); ?></h1></td>
	</tr>
</table>
<?php if ( ! empty( $header['reference'] ) ) : ?>
	<p class="lf-reference"><?php echo esc_html( (string) $header['reference'] ); ?></p>
<?php endif; ?>
