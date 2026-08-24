<?php
/**
 * Tableau des produits, colonnes dynamiques.
 *
 * Les colonnes affichées et leur largeur viennent de `$columns`, construit
 * par `ContextBuilder::columns()` à partir des réglages. Ce partial ne prend
 * aucune décision de contenu : il boucle et affiche, ce qui garantit qu'un
 * template personnalisé qui copie ce fichier restera synchronisé avec les
 * réglages sans logique dupliquée.
 *
 * @package Lumia\FacturePDF
 *
 * @var array<int, array{key: string, label: string, width: int, align: string}> $columns
 * @var array<int, array<string, mixed>> $items
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( array() === $columns || array() === $items ) {
	return;
}

?>
<table class="fpl-items">
	<colgroup>
		<?php foreach ( $columns as $column ) : ?>
			<col style="width: <?php echo esc_attr( (string) $column['width'] ); ?>%;">
		<?php endforeach; ?>
	</colgroup>
	<thead>
		<tr>
			<?php foreach ( $columns as $column ) : ?>
				<th class="fpl-col-<?php echo esc_attr( $column['align'] ); ?>"><?php echo esc_html( $column['label'] ); ?></th>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $items as $row => $item ) : ?>
			<tr<?php echo ( 1 === $row % 2 ) ? ' class="fpl-row-alt"' : ''; ?>>
				<?php foreach ( $columns as $column ) : ?>
					<td class="fpl-col-<?php echo esc_attr( $column['align'] ); ?>">
						<?php
						switch ( $column['key'] ) :
							case 'image':
								if ( '' !== $item['image'] ) :
									?>
									<span class="fpl-item__image"><img src="<?php echo esc_attr( $item['image'] ); ?>" alt=""></span>
									<?php
								endif;
								break;

							case 'name':
								?>
								<span class="fpl-item__name"><?php echo esc_html( $item['name'] ); ?></span>
								<?php if ( $item['discounted'] ) : ?>
									<span class="fpl-item__discount-badge"><?php esc_html_e( 'Remise', 'facture-pdf-lumia' ); ?></span>
								<?php endif; ?>
								<?php if ( array() !== $item['meta'] ) : ?>
									<div class="fpl-item__meta">
										<?php foreach ( $item['meta'] as $index => $meta ) : ?>
											<?php echo $index > 0 ? ' · ' : ''; ?><?php echo esc_html( $meta['label'] ); ?>: <?php echo esc_html( $meta['value'] ); ?>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<?php
								break;

							case 'description':
								echo esc_html( $item['description'] );
								break;

							case 'sku':
								if ( '' !== $item['sku'] ) :
									?>
									<span class="fpl-item__sku"><?php echo esc_html( $item['sku'] ); ?></span>
									<?php
								endif;
								break;

							case 'quantity':
								echo esc_html( (string) $item['quantity'] );
								break;

							case 'unit_price':
								echo esc_html( $item['unit_price'] );
								break;

							case 'tax_rate':
								echo esc_html( $item['tax_rate'] );
								break;

							case 'total':
								echo esc_html( $item['total'] );
								break;

							default:
								/**
								 * Permet d'afficher une colonne personnalisée.
								 *
								 * @param string                $key    Identifiant de colonne inconnu du cœur.
								 * @param array<string, mixed>  $item   Données de la ligne.
								 * @param array<string, mixed>  $column Définition de la colonne.
								 */
								do_action( 'fpl_render_custom_column', $column['key'], $item, $column );
						endswitch;
						?>
					</td>
				<?php endforeach; ?>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
