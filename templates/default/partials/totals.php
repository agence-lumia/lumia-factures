<?php
/**
 * Bloc des totaux, dans un encart aligné à droite, suivi des mentions
 * légales positionnées « sous les totaux ».
 *
 * @package Lumia\FacturePDF
 *
 * @var array<int, array{key: string, label: string, value: string, grand: bool}> $totals
 * @var array<string, array<int, array{title: string, body: string}>> $legal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( array() !== $totals ) :
	?>
	<table class="fpl-totals-wrap">
		<tr>
			<td></td>
			<td class="fpl-totals-cell">
				<table class="fpl-totals">
					<?php foreach ( $totals as $row ) : ?>
						<tr class="<?php echo $row['grand'] ? 'fpl-grand-total' : 'fpl-total-row'; ?>">
							<td class="fpl-totals__label"><?php echo esc_html( $row['label'] ); ?></td>
							<td class="fpl-totals__value"><?php echo esc_html( $row['value'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</td>
		</tr>
	</table>
	<?php
endif;

$fpl_legal_placement = 'totals';
echo \Lumia\FacturePDF\Rendering\TemplateLoader::render( 'partials/legal-mentions.php', get_defined_vars() );
