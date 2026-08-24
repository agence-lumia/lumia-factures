<?php
/**
 * Pied de page, répété en bas de chaque page.
 *
 * Le bloc est positionné en `fixed` par la feuille de style : Dompdf
 * interprète cette valeur comme « à cette position sur chaque page », ce qui
 * en fait le seul moyen fiable d'obtenir un pied de page récurrent — les
 * boîtes de marge `@page` de la spécification CSS Paged Media ne sont pas
 * implémentées par ce moteur.
 *
 * La pagination n'est pas rendue ici : dans un élément positionné, Dompdf
 * résout `counter(page)` mais renvoie 0 pour `counter(pages)`, le nombre
 * total de pages n'étant pas connu au moment où l'élément est mis en page.
 * Elle est dessinée après le rendu par `PdfEngine`, via l'API canvas.
 *
 * @package Lumia\FacturePDF
 *
 * @var array{text: string, lines: array<int, string>, pagination: bool} $footer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fpl_has_content = '' !== $footer['text'] || array() !== $footer['lines'] || array() !== ( $legal['footer'] ?? array() );

if ( ! $fpl_has_content ) {
	return;
}

?>
<div class="fpl-footer">
	<?php if ( '' !== $footer['text'] ) : ?>
		<div class="fpl-footer__text"><?php echo wp_kses_post( $footer['text'] ); ?></div>
	<?php endif; ?>

	<?php if ( array() !== $footer['lines'] ) : ?>
		<div>
			<?php foreach ( $footer['lines'] as $line ) : ?>
				<span class="fpl-footer__line"><?php echo esc_html( $line ); ?></span>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php
	$fpl_legal_placement = 'footer';
	echo \Lumia\FacturePDF\Rendering\TemplateLoader::render( 'partials/legal-mentions.php', get_defined_vars() );
	?>

</div>
