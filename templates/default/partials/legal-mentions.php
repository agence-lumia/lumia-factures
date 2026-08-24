<?php
/**
 * Mentions légales, filtrées par emplacement.
 *
 * Réutilisé aux trois points d'insertion possibles (sous les totaux, bloc
 * dédié, pied de page) avec un `$fpl_legal_placement` différent à chaque
 * fois : un seul gabarit pour les trois cas, plutôt que trois qui
 * finiraient par diverger.
 *
 * @package Lumia\FacturePDF
 *
 * @var array{totals: array<int, array{title: string, body: string}>, block: array<int, array{title: string, body: string}>, footer: array<int, array{title: string, body: string}>} $legal
 * @var string $fpl_legal_placement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fpl_legal_rows = $legal[ $fpl_legal_placement ] ?? array();

if ( array() === $fpl_legal_rows ) {
	return;
}

?>
<div class="fpl-legal fpl-legal--<?php echo esc_attr( $fpl_legal_placement ); ?>">
	<?php foreach ( $fpl_legal_rows as $fpl_legal_row ) : ?>
		<p class="fpl-legal__item">
			<?php if ( '' !== $fpl_legal_row['title'] ) : ?>
				<strong class="fpl-legal__title"><?php echo esc_html( $fpl_legal_row['title'] ); ?>. </strong>
			<?php endif; ?>
			<?php if ( '' !== $fpl_legal_row['body'] ) : ?>
				<span class="fpl-legal__body"><?php echo esc_html( $fpl_legal_row['body'] ); ?></span>
			<?php endif; ?>
		</p>
	<?php endforeach; ?>
</div>
