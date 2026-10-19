<?php
/**
 * Products table. Columns and widths come from the settings through the context.
 *
 * The name cell prints the name, then one line per attribute. A coupon code is never printed on
 * a line: the discount is one row of the totals.
 *
 * @package Lumia\Factures
 *
 * @var array<int, array{key: string, label: string, width: float, align: string}> $columns
 * @var array<int, array<string, mixed>> $items
 */

defined( 'ABSPATH' ) || exit;

if ( array() === $columns || array() === $items ) {
	return;
}
?>
<table class="lf-items">
	<colgroup>
		<?php foreach ( $columns as $column ) : ?>
			<col style="width: <?php echo esc_attr( (string) $column['width'] ); ?>%;">
		<?php endforeach; ?>
	</colgroup>
	<thead>
		<tr>
			<?php foreach ( $columns as $column ) : ?>
				<th class="lf-col-<?php echo esc_attr( $column['align'] ); ?>"><?php echo esc_html( $column['label'] ); ?></th>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $items as $row => $item ) : ?>
			<tr<?php echo ( 1 === $row % 2 ) ? ' class="lf-row-alt"' : ''; ?>>
				<?php foreach ( $columns as $column ) : ?>
					<td class="lf-col-<?php echo esc_attr( $column['align'] ); ?>">
						<?php if ( 'image' === $column['key'] && '' !== $item['image'] ) : ?>
							<span class="lf-item__image"><img src="<?php echo esc_attr( $item['image'] ); ?>" alt=""></span>
						<?php elseif ( 'name' === $column['key'] ) : ?>
							<span class="lf-item__name"><?php echo esc_html( $item['name'] ); ?></span>
							<?php foreach ( $item['attributes'] as $attribute ) : ?>
								<div class="lf-item__attribute"><?php echo esc_html( $attribute['label'] . ' : ' . $attribute['value'] ); ?></div>
							<?php endforeach; ?>
						<?php elseif ( 'description' === $column['key'] ) : ?>
							<span class="lf-item__description"><?php echo esc_html( $item['description'] ); ?></span>
						<?php elseif ( 'sku' === $column['key'] ) : ?>
							<span class="lf-item__sku"><?php echo esc_html( $item['sku'] ); ?></span>
						<?php elseif ( in_array( $column['key'], array( 'quantity', 'unit_price', 'tax_rate', 'total' ), true ) ) : ?>
							<?php echo esc_html( $item[ $column['key'] ] ); ?>
						<?php endif; ?>
					</td>
				<?php endforeach; ?>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
