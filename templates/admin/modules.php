<?php
/**
 * Modules page: activation of the optional modules (core ones are locked on).
 *
 * Included from the layout: $this is the Admin instance.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

$modules = $this->modules->get_all();
?>
<div class="lumia-page">
	<div class="lumia-page__header">
		<div class="lumia-page__header-content">
			<h1 class="lumia-page__title"><?php echo esc_html__( 'Modules', 'lumia-factures' ); ?></h1>
			<p class="lumia-page__subtitle"><?php echo esc_html__( 'Enable or disable modules as needed.', 'lumia-factures' ); ?></p>
		</div>
		<?php if ( ! empty( $modules ) ) : ?>
			<div class="lumia-page__header-actions">
				<button type="submit" form="lumia-factures-modules-form" class="lumia-btn lumia-btn--primary lumia-btn--sm">
					<?php echo esc_html__( 'Save', 'lumia-factures' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</div>

	<div class="lumia-page__scroll">
		<?php if ( empty( $modules ) ) : ?>
			<div class="lumia-empty">
				<?php echo $this->render_icon( 'circle-alert', 'xl' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
				<p><?php echo esc_html__( 'The modules are unavailable until the requirements are met.', 'lumia-factures' ); ?></p>
				<a href="<?php echo esc_url( $this->tab_url( 'dashboard' ) ); ?>" class="lumia-btn lumia-btn--secondary lumia-btn--sm">
					<?php echo esc_html__( 'See the requirements', 'lumia-factures' ); ?>
				</a>
			</div>
		<?php else : ?>
			<form id="lumia-factures-modules-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form">
				<?php wp_nonce_field( 'lumia_factures_save_settings', 'lumia_factures_nonce' ); ?>
				<input type="hidden" name="action" value="lumia_factures_save_settings">
				<input type="hidden" name="lumia_factures_tab" value="modules">

				<div class="lumia-module-grid lumia-page__body">
					<?php
					foreach ( $modules as $module_id => $module ) :
						$is_active = $this->modules->is_active( $module_id );
						$is_locked = $this->modules->is_locked( $module_id );
						?>
						<div class="lumia-module-card <?php echo $is_active ? 'lumia-module-card--active' : ''; ?>">
							<div class="lumia-module-card__header">
								<?php echo $this->render_icon( '' !== $module['icon'] ? $module['icon'] : 'package', 'md' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
								<h3 class="lumia-module-card__title"><?php echo esc_html( $module['name'] ); ?></h3>
								<label class="lumia-toggle"<?php echo $is_locked ? ' data-lumia-tip="' . esc_attr__( 'Core module: always active.', 'lumia-factures' ) . '"' : ''; ?>>
									<input type="checkbox"
										name="lumia_factures_modules[]"
										value="<?php echo esc_attr( $module_id ); ?>"
										aria-label="<?php echo esc_attr( $module['name'] ); ?>"
										<?php checked( $is_active ); ?>
										<?php disabled( $is_locked ); ?>>
									<span class="lumia-toggle__slider"></span>
								</label>
							</div>
							<p class="lumia-module-card__desc"><?php echo esc_html( $module['description'] ); ?></p>
							<div class="lumia-module-card__actions">
								<?php if ( $is_locked ) : ?>
									<span class="lumia-badge lumia-badge--neutral"><?php echo esc_html__( 'Core', 'lumia-factures' ); ?></span>
								<?php endif; ?>
								<?php if ( $is_active ) : ?>
									<a href="<?php echo esc_url( $this->tab_url( 'module_' . $module_id ) ); ?>" class="lumia-btn lumia-btn--sm lumia-btn--secondary">
										<?php echo esc_html__( 'Configure', 'lumia-factures' ); ?>
									</a>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</form>
		<?php endif; ?>
	</div>
</div>
