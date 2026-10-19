<?php
/**
 * Settings of an active module.
 *
 * Included from the layout: $this is the Admin instance, $tab the current tab,
 * $module_id the module identifier (active: checked by the layout).
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

$module   = $this->modules->get( $module_id );
$instance = $this->modules->get_active_instances()[ $module_id ] ?? null;

if ( null === $module || null === $instance ) {
	return;
}

$module_settings = $instance->get_settings();

// A module may provide its own form: includes/Modules/{Module}/settings-template.php.
$module_dir      = str_replace( ' ', '', ucwords( str_replace( '_', ' ', $module_id ) ) );
$module_template = LUMIA_FACTURES_INCLUDES_DIR . 'Modules/' . $module_dir . '/settings-template.php';
$has_form        = file_exists( $module_template ) || ! empty( $module_settings );
?>
<div class="lumia-page">
	<div class="lumia-page__header">
		<div class="lumia-page__header-content">
			<h1 class="lumia-page__title"><?php echo esc_html( $module['name'] ); ?></h1>
			<p class="lumia-page__subtitle"><?php echo esc_html( $module['description'] ); ?></p>
		</div>
		<?php if ( $has_form ) : ?>
			<div class="lumia-page__header-actions">
				<button type="submit" form="lumia-factures-module-form" class="lumia-btn lumia-btn--primary lumia-btn--sm">
					<?php echo esc_html__( 'Save', 'lumia-factures' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( file_exists( $module_template ) ) : ?>
		<?php include $module_template; ?>
	<?php elseif ( ! empty( $module_settings ) ) : ?>
		<form id="lumia-factures-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form lumia-module-form">
			<?php wp_nonce_field( 'lumia_factures_save_settings', 'lumia_factures_nonce' ); ?>
			<input type="hidden" name="action" value="lumia_factures_save_settings">
			<input type="hidden" name="lumia_factures_tab" value="<?php echo esc_attr( $tab ); ?>">

			<div class="lumia-module-form__scroll">
				<div class="lumia-section">
					<div class="lumia-section__content">
						<?php foreach ( $module_settings as $key => $value ) : ?>
							<div class="lumia-form__group lumia-form__group--toggle">
								<div class="lumia-form__toggle-label">
									<label for="lumia_factures_<?php echo esc_attr( $key ); ?>" class="lumia-form__label"><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $key ) ) ); ?></label>
								</div>
								<label class="lumia-toggle">
									<input type="checkbox"
										id="lumia_factures_<?php echo esc_attr( $key ); ?>"
										name="lumia_factures_module_settings[<?php echo esc_attr( $key ); ?>]"
										value="1"
										<?php checked( (bool) $value ); ?>>
									<span class="lumia-toggle__slider"></span>
								</label>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</form>
	<?php else : ?>
		<div class="lumia-empty">
			<?php echo $this->render_icon( '' !== $module['icon'] ? $module['icon'] : 'package', 'xl' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
			<p><?php echo esc_html__( 'This module has no settings yet.', 'lumia-factures' ); ?></p>
		</div>
	<?php endif; ?>
</div>
