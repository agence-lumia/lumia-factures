<?php
/**
 * Invoices screen: header with the save button, then the tabbed settings form.
 *
 * Included from the layout: $this is the Admin instance.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="lumia-page">
	<div class="lumia-page__header">
		<div class="lumia-page__header-content">
			<h1 class="lumia-page__title"><?php echo esc_html__( 'Invoices', 'lumia-factures' ); ?></h1>
			<p class="lumia-page__subtitle"><?php echo esc_html__( 'PDF invoices for WooCommerce orders.', 'lumia-factures' ); ?></p>
		</div>
		<div class="lumia-page__header-actions">
			<button type="submit" form="lumia-factures-module-form" class="lumia-btn lumia-btn--primary lumia-btn--sm">
				<?php echo esc_html__( 'Save', 'lumia-factures' ); ?>
			</button>
		</div>
	</div>

	<?php require LUMIA_FACTURES_INCLUDES_DIR . 'Modules/Invoices/settings-template.php'; ?>
</div>
