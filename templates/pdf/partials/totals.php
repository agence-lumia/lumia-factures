<?php
/**
 * Totals block, right aligned, then the legal text.
 *
 * Informational rows (excl. VAT total, VAT detail in incl. VAT mode, coupon codes) are muted: they
 * are not part of the sum. On a credit note the rows add up to the refunded amount and the grand
 * total reads "Total refunded".
 *
 * @package Lumia\Factures
 *
 * @var array<int, array{key: string, label: string, value: string, grand: bool, informational: bool}> $totals
 * @var string $legal_text
 */

defined( 'ABSPATH' ) || exit;

if ( array() !== $totals ) :
	?>
	<table class="lf-totals-wrap">
		<tr>
			<td class="lf-totals-wrap__space"></td>
			<td class="lf-totals-wrap__cell">
				<table class="lf-totals">
					<?php foreach ( $totals as $row ) : ?>
						<?php
						$lumia_factures_row_class = $row['grand'] ? 'lf-total-row lf-total-row--grand' : 'lf-total-row';
						if ( $row['informational'] ) {
							$lumia_factures_row_class .= ' lf-total-row--muted';
						}
						?>
						<tr class="<?php echo esc_attr( $lumia_factures_row_class ); ?>">
							<td class="lf-totals__label"><?php echo esc_html( $row['label'] ); ?></td>
							<td class="lf-totals__value"><?php echo esc_html( $row['value'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</td>
		</tr>
	</table>
<?php endif; ?>
<?php if ( '' !== $legal_text ) : ?>
	<div class="lf-legal-text"><?php echo nl2br( esc_html( $legal_text ) ); ?></div>
<?php endif; ?>
