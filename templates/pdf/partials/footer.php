<?php
/**
 * Footer, repeated at the bottom of every page.
 *
 * Positioned `fixed` by the stylesheet: Dompdf repeats such a block on every page, and it does not
 * implement the `@page` margin boxes. The pagination is not printed here (inside a positioned
 * block Dompdf cannot resolve the page count): PdfEngine draws it on the canvas.
 *
 * @package Lumia\Factures
 *
 * @var array{text: string, lines: array<int, string>} $footer
 */

defined( 'ABSPATH' ) || exit;

if ( '' === $footer['text'] && array() === $footer['lines'] ) {
	return;
}
?>
<div class="lf-footer">
	<?php if ( '' !== $footer['text'] ) : ?>
		<div class="lf-footer__text"><?php echo nl2br( esc_html( $footer['text'] ) ); ?></div>
	<?php endif; ?>
	<?php if ( array() !== $footer['lines'] ) : ?>
		<div>
			<?php foreach ( $footer['lines'] as $line ) : ?>
				<span class="lf-footer__line"><?php echo esc_html( $line ); ?></span>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
