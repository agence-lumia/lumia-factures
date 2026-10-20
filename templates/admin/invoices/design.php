<?php
/**
 * Invoices settings, Design tab ("Appearance").
 *
 * Included by includes/Modules/Invoices/settings-template.php ($fields is a Fields instance, $this
 * the Admin instance). A theme button only fills the color fields (invoices.js).
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Admin\Themes;

$design_colors = [
	'color_thead_bg'   => __( 'Table header background', 'lumia-factures' ),
	'color_thead_text' => __( 'Header text', 'lumia-factures' ),
	'color_text'       => __( 'Text', 'lumia-factures' ),
	'color_muted'      => __( 'Muted text', 'lumia-factures' ),
	'color_block_bg'   => __( 'Block background', 'lumia-factures' ),
	'color_row_alt'    => __( 'Alternate row', 'lumia-factures' ),
	'color_border'     => __( 'Borders', 'lumia-factures' ),
	'color_page'       => __( 'Page background', 'lumia-factures' ),
];

$design_fonts = [
	'inter'       => 'Inter',
	'dejavu-sans' => 'DejaVu Sans',
];
?>
<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Theme', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'Start from a ready-made palette, then adjust any color.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<div class="lumia-factures-inv-themes" data-lumia-factures-themes>
			<?php foreach ( Themes::all() as $design_theme_id => $design_theme ) : ?>
				<?php $design_palette = $design_theme['colors']; ?>
				<button type="button" class="lumia-factures-inv-theme" data-lumia-factures-theme="<?php echo esc_attr( (string) wp_json_encode( $design_palette ) ); ?>" aria-pressed="false">
					<span class="lumia-factures-inv-theme__sample" aria-hidden="true" style="background: <?php echo esc_attr( $design_palette['color_page'] ); ?>; border-color: <?php echo esc_attr( $design_palette['color_border'] ); ?>;">
						<span class="lumia-factures-inv-theme__band" style="background: <?php echo esc_attr( $design_palette['color_primary'] ); ?>;"></span>
						<span class="lumia-factures-inv-theme__line" style="background: <?php echo esc_attr( $design_palette['color_muted'] ); ?>;"></span>
						<span class="lumia-factures-inv-theme__block" style="background: <?php echo esc_attr( $design_palette['color_block_bg'] ); ?>;"></span>
						<span class="lumia-factures-inv-theme__head" style="background: <?php echo esc_attr( $design_palette['color_thead_bg'] ); ?>;"></span>
					</span>
					<span class="lumia-factures-inv-theme__name">
						<?php echo esc_html( $design_theme['label'] ); ?>
						<?php echo $this->render_icon( 'check', 'sm', 'lumia-factures-inv-theme__check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
					</span>
				</button>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Colors', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'The primary color fills the title band and the grand total, and colors the headings.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<?php $fields->color( 'design', 'color_primary', __( 'Primary', 'lumia-factures' ) ); ?>

		<details class="lumia-factures-inv-advanced">
			<summary class="lumia-factures-inv-advanced__summary">
				<?php echo esc_html__( 'Advanced colors', 'lumia-factures' ); ?>
				<?php echo $this->render_icon( 'chevron-down', 'sm', 'lumia-factures-inv-advanced__chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
			</summary>
			<div class="lumia-factures-inv-colors">
				<?php foreach ( $design_colors as $design_key => $design_label ) : ?>
					<?php $fields->color( 'design', $design_key, $design_label ); ?>
				<?php endforeach; ?>
			</div>
		</details>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Typography', 'lumia-factures' ); ?></h2>
	</div>
	<div class="lumia-section__content">
		<div class="lumia-factures-inv-cols lumia-factures-inv-cols--2">
			<?php
			$fields->select( 'design', 'font_heading', __( 'Heading font', 'lumia-factures' ), $design_fonts );
			$fields->select( 'design', 'font_body', __( 'Body font', 'lumia-factures' ), $design_fonts );
			?>
		</div>
		<div class="lumia-factures-inv-cols lumia-factures-inv-cols--3">
			<?php
			$fields->number( 'design', 'font_size', __( 'Body size (pt)', 'lumia-factures' ) );
			$fields->number( 'design', 'heading_scale', __( 'Heading scale', 'lumia-factures' ), __( 'Times the body size.', 'lumia-factures' ) );
			$fields->number( 'design', 'line_height', __( 'Line height', 'lumia-factures' ) );
			?>
		</div>
		<?php $fields->toggle( 'design', 'heading_bold', __( 'Bold headings', 'lumia-factures' ) ); ?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Layout', 'lumia-factures' ); ?></h2>
	</div>
	<div class="lumia-section__content">
		<div class="lumia-factures-inv-cols lumia-factures-inv-cols--2">
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
			$fields->number( 'design', 'thumb_width', __( 'Product image width (px)', 'lumia-factures' ), __( '0 hides the image.', 'lumia-factures' ) );
			$fields->number( 'design', 'radius', __( 'Corner radius (px)', 'lumia-factures' ) );
			$fields->number( 'design', 'border_width', __( 'Border width (px)', 'lumia-factures' ) );
			?>
		</div>
		<p class="lumia-form__label lumia-factures-inv-subhead"><?php echo esc_html__( 'Page margins (mm)', 'lumia-factures' ); ?></p>
		<div class="lumia-factures-inv-cols lumia-factures-inv-cols--3">
			<?php
			$fields->number( 'design', 'margin_top', _x( 'Top', 'page margin', 'lumia-factures' ) );
			$fields->number( 'design', 'margin_bottom', _x( 'Bottom', 'page margin', 'lumia-factures' ) );
			$fields->number( 'design', 'margin_sides', _x( 'Sides', 'page margin', 'lumia-factures' ) );
			?>
		</div>
	</div>
</div>
