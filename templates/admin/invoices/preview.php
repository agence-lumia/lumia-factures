<?php
/**
 * Live preview pane of the invoice settings.
 *
 * Included by includes/Modules/Invoices/settings-template.php: $this is the Admin instance. Shown
 * next to the tabs listed in `data-lumia-factures-preview-tabs` only; the PDF pages are drawn by
 * assets/admin/js/invoices.js (pdf.js). The order field has no name: it is never saved and never
 * marks the form as unsaved.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;
?>
<aside class="lumia-factures-inv-preview" id="lumia-factures-invoices-preview" data-lumia-factures-preview data-lumia-factures-preview-tabs="company content design" aria-label="<?php esc_attr_e( 'Invoice preview', 'lumia-factures' ); ?>" hidden>
	<div class="lumia-factures-inv-preview__bar">
		<span class="lumia-factures-inv-preview__title"><?php echo esc_html__( 'Preview', 'lumia-factures' ); ?></span>

		<label class="lumia-factures-inv-preview__order">
			<span class="screen-reader-text"><?php echo esc_html__( 'Order ID', 'lumia-factures' ); ?></span>
			<span aria-hidden="true">#</span>
			<input type="number" min="1" step="1" class="lumia-input lumia-input--sm" data-lumia-factures-preview-order placeholder="<?php esc_attr_e( 'Latest order', 'lumia-factures' ); ?>">
		</label>

		<div class="lumia-factures-inv-preview__zoom" role="group" aria-label="<?php esc_attr_e( 'Zoom', 'lumia-factures' ); ?>">
			<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm lumia-factures-inv-preview__icon-btn" data-lumia-factures-preview-zoom="-1" aria-label="<?php esc_attr_e( 'Zoom out', 'lumia-factures' ); ?>">
				<?php echo $this->render_icon( 'minus', 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
			</button>
			<button type="button" class="lumia-factures-inv-preview__zoom-value" data-lumia-factures-preview-zoom="0" aria-label="<?php esc_attr_e( 'Fit to width', 'lumia-factures' ); ?>">100 %</button>
			<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm lumia-factures-inv-preview__icon-btn" data-lumia-factures-preview-zoom="1" aria-label="<?php esc_attr_e( 'Zoom in', 'lumia-factures' ); ?>">
				<?php echo $this->render_icon( 'plus', 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
			</button>
		</div>

		<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm lumia-factures-inv-preview__icon-btn" data-lumia-factures-preview-refresh data-lumia-tip="<?php esc_attr_e( 'Refresh', 'lumia-factures' ); ?>" aria-label="<?php esc_attr_e( 'Refresh', 'lumia-factures' ); ?>">
			<?php echo $this->render_icon( 'refresh-cw', 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
		</button>
	</div>

	<div class="lumia-factures-inv-preview__stage" data-lumia-factures-preview-stage>
		<div class="lumia-notice lumia-notice--error" role="alert" data-lumia-factures-preview-error hidden></div>
		<div class="lumia-factures-inv-preview__pages" data-lumia-factures-preview-pages></div>
		<p class="lumia-factures-inv-preview__note"><?php echo esc_html__( 'Sample: the next invoice number, nothing is saved or numbered.', 'lumia-factures' ); ?></p>
	</div>
</aside>
