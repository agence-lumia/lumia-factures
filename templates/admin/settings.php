<?php
/**
 * Plugin settings alone, shown while the requirements fail (no module runs, so no invoice
 * settings): the blocking reasons, the updates and the environment.
 *
 * Included from the layout: $this is the Admin instance.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

$requirement_errors = $this->requirement_errors();
?>
<div class="lumia-page">
	<div class="lumia-page__header">
		<div class="lumia-page__header-content">
			<h1 class="lumia-page__title"><?php echo esc_html__( 'Invoices', 'lumia-factures' ); ?></h1>
			<p class="lumia-page__subtitle"><?php echo esc_html__( 'PDF invoices for WooCommerce orders.', 'lumia-factures' ); ?></p>
		</div>
		<div class="lumia-page__header-actions">
			<button type="submit" form="lumia-factures-settings-form" class="lumia-btn lumia-btn--primary lumia-btn--sm">
				<?php echo esc_html__( 'Save', 'lumia-factures' ); ?>
			</button>
		</div>
	</div>

	<div class="lumia-page__scroll">
		<?php if ( [] !== $requirement_errors ) : ?>
			<div class="lumia-section">
				<div class="lumia-section__content">
					<div class="lumia-notice lumia-notice--error" role="alert">
						<p><strong><?php echo esc_html__( 'Invoices are paused until these requirements are met:', 'lumia-factures' ); ?></strong></p>
						<ul>
							<?php foreach ( $requirement_errors as $requirement_error ) : ?>
								<li><?php echo esc_html( $requirement_error ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<form id="lumia-factures-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form">
			<?php wp_nonce_field( 'lumia_factures_save_settings', 'lumia_factures_nonce' ); ?>
			<input type="hidden" name="action" value="lumia_factures_save_settings">
			<input type="hidden" name="lumia_factures_tab" value="settings">
			<?php require LUMIA_FACTURES_TEMPLATES_DIR . 'admin/partials/plugin-settings.php'; ?>
		</form>

		<?php require LUMIA_FACTURES_TEMPLATES_DIR . 'admin/partials/check-updates-form.php'; ?>
	</div>
</div>
