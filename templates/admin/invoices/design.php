<?php
/**
 * Invoices settings, Design tab.
 *
 * Included by includes/Modules/Invoices/settings-template.php ($fields is a Fields instance).
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

$design_colors = [
	'color_primary'    => __( 'Primary', 'lumia-factures' ),
	'color_secondary'  => __( 'Secondary', 'lumia-factures' ),
	'color_text'       => __( 'Text', 'lumia-factures' ),
	'color_muted'      => __( 'Muted text', 'lumia-factures' ),
	'color_thead_bg'   => __( 'Table header background', 'lumia-factures' ),
	'color_thead_text' => __( 'Table header text', 'lumia-factures' ),
	'color_row_alt'    => __( 'Alternate row', 'lumia-factures' ),
	'color_block_bg'   => __( 'Block background', 'lumia-factures' ),
	'color_border'     => __( 'Borders', 'lumia-factures' ),
	'color_page'       => __( 'Page background', 'lumia-factures' ),
];

$design_fonts = [
	'inter'       => 'Inter',
	'dejavu-sans' => 'DejaVu Sans',
];
?>
<div class="lumia-factures-inv-design">
	<div class="lumia-factures-inv-design__form">
		<div class="lumia-section">
			<div class="lumia-section__header">
				<h2 class="lumia-section__title"><?php echo esc_html__( 'Colors', 'lumia-factures' ); ?></h2>
			</div>
			<div class="lumia-section__content lumia-factures-inv-grid">
				<?php foreach ( $design_colors as $design_key => $design_label ) : ?>
					<?php $fields->color( 'design', $design_key, $design_label ); ?>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="lumia-section">
			<div class="lumia-section__header">
				<h2 class="lumia-section__title"><?php echo esc_html__( 'Typography', 'lumia-factures' ); ?></h2>
			</div>
			<div class="lumia-section__content lumia-factures-inv-grid">
				<?php
				$fields->select( 'design', 'font_heading', __( 'Heading font', 'lumia-factures' ), $design_fonts );
				$fields->select( 'design', 'font_body', __( 'Body font', 'lumia-factures' ), $design_fonts );
				$fields->number( 'design', 'font_size', __( 'Body font size (pt)', 'lumia-factures' ) );
				$fields->number( 'design', 'heading_scale', __( 'Heading size (times the body size)', 'lumia-factures' ) );
				$fields->number( 'design', 'line_height', __( 'Line height', 'lumia-factures' ) );
				?>
			</div>
			<div class="lumia-section__content">
				<?php $fields->toggle( 'design', 'heading_bold', __( 'Bold headings', 'lumia-factures' ) ); ?>
			</div>
		</div>

		<div class="lumia-section">
			<div class="lumia-section__header">
				<h2 class="lumia-section__title"><?php echo esc_html__( 'Layout', 'lumia-factures' ); ?></h2>
			</div>
			<div class="lumia-section__content lumia-factures-inv-grid">
				<?php
				$fields->select(
					'design',
					'density',
					__( 'Density', 'lumia-factures' ),
					[
						'compact' => __( 'Compact', 'lumia-factures' ),
						'normal'  => __( 'Normal', 'lumia-factures' ),
						'airy'    => __( 'Airy', 'lumia-factures' ),
					]
				);
				$fields->number( 'design', 'radius', __( 'Corner radius (px)', 'lumia-factures' ) );
				$fields->number( 'design', 'border_width', __( 'Border width (px)', 'lumia-factures' ) );
				$fields->number( 'design', 'thumb_width', __( 'Product image width (px)', 'lumia-factures' ), __( '0 hides the image.', 'lumia-factures' ) );
				$fields->number( 'design', 'margin_top', __( 'Top margin (mm)', 'lumia-factures' ) );
				$fields->number( 'design', 'margin_bottom', __( 'Bottom margin (mm)', 'lumia-factures' ) );
				$fields->number( 'design', 'margin_sides', __( 'Side margins (mm)', 'lumia-factures' ) );
				?>
			</div>
		</div>
	</div>

	<?php // PREVIEW PANE (Task 10): the live PDF preview is mounted in this container. ?>
	<aside class="lumia-factures-inv-design__preview" id="lumia-factures-invoices-preview" data-lumia-factures-preview aria-label="<?php esc_attr_e( 'Invoice preview', 'lumia-factures' ); ?>"></aside>
</div>
