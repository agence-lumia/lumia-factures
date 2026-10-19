<?php
/**
 * Overview: plugin version, requirements, active modules and shortcuts.
 *
 * Included from the layout: $this is the Admin instance.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

$requirement_errors = $this->requirement_errors();
$active_modules     = array_filter(
	$this->modules->get_all(),
	fn( $id ) => $this->modules->is_active( (string) $id ),
	ARRAY_FILTER_USE_KEY
);

$shortcuts = [
	[
		'url'   => $this->tab_url( 'modules' ),
		'label' => __( 'Manage modules', 'lumia-factures' ),
		'icon'  => 'package',
	],
	[
		'url'   => $this->tab_url( 'settings' ),
		'label' => __( 'Settings', 'lumia-factures' ),
		'icon'  => 'settings',
	],
];
if ( empty( $requirement_errors ) && function_exists( 'wc_get_container' ) ) {
	$shortcuts[] = [
		'url'   => class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? admin_url( 'admin.php?page=wc-orders' )
			: admin_url( 'edit.php?post_type=shop_order' ),
		'label' => __( 'WooCommerce orders', 'lumia-factures' ),
		'icon'  => 'external-link',
	];
}
?>
<div class="lumia-page">
	<div class="lumia-page__header">
		<div class="lumia-page__header-content">
			<h1 class="lumia-page__title"><?php echo esc_html__( 'Overview', 'lumia-factures' ); ?></h1>
			<p class="lumia-page__subtitle"><?php echo esc_html__( 'PDF invoices for WooCommerce orders.', 'lumia-factures' ); ?></p>
		</div>
	</div>

	<div class="lumia-cards">
		<div class="lumia-card lumia-card--stat">
			<div class="lumia-card__icon lumia-card__icon--info">
				<?php echo $this->render_icon( 'info', 'lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
			</div>
			<div class="lumia-card__content">
				<span class="lumia-card__value"><?php echo esc_html( LUMIA_FACTURES_VERSION ); ?></span>
				<span class="lumia-card__label"><?php echo esc_html__( 'Plugin version', 'lumia-factures' ); ?></span>
			</div>
		</div>

		<div class="lumia-card lumia-card--stat">
			<?php if ( empty( $requirement_errors ) ) : ?>
				<div class="lumia-card__icon lumia-card__icon--success">
					<?php echo $this->render_icon( 'check-circle', 'lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
				</div>
				<div class="lumia-card__content">
					<span class="lumia-card__value"><?php echo esc_html__( 'OK', 'lumia-factures' ); ?></span>
					<span class="lumia-card__label"><?php echo esc_html__( 'Requirements met', 'lumia-factures' ); ?></span>
				</div>
			<?php else : ?>
				<div class="lumia-card__icon">
					<?php echo $this->render_icon( 'circle-alert', 'lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
				</div>
				<div class="lumia-card__content">
					<span class="lumia-card__value"><?php echo esc_html( (string) count( $requirement_errors ) ); ?></span>
					<span class="lumia-card__label"><?php echo esc_html__( 'Requirements not met', 'lumia-factures' ); ?></span>
				</div>
			<?php endif; ?>
		</div>

		<div class="lumia-card lumia-card--stat">
			<div class="lumia-card__icon">
				<?php echo $this->render_icon( 'package', 'lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
			</div>
			<div class="lumia-card__content">
				<span class="lumia-card__value"><?php echo esc_html( (string) count( $active_modules ) ); ?></span>
				<span class="lumia-card__label"><?php echo esc_html__( 'Active modules', 'lumia-factures' ); ?></span>
			</div>
		</div>
	</div>

	<?php if ( ! empty( $requirement_errors ) ) : ?>
		<div class="lumia-section">
			<div class="lumia-section__header">
				<h2 class="lumia-section__title"><?php echo esc_html__( 'Requirements', 'lumia-factures' ); ?></h2>
				<p class="lumia-section__desc"><?php echo esc_html__( 'The modules do not start until these problems are solved.', 'lumia-factures' ); ?></p>
			</div>
			<div class="lumia-section__content">
				<?php foreach ( $requirement_errors as $reason => $message ) : ?>
					<div class="lumia-notice lumia-notice--error" data-reason="<?php echo esc_attr( (string) $reason ); ?>"><?php echo esc_html( $message ); ?></div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Active modules', 'lumia-factures' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'These are the modules currently active on your site.', 'lumia-factures' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<?php if ( ! empty( $active_modules ) ) : ?>
				<div class="lumia-module-list">
					<?php foreach ( $active_modules as $module_id => $module ) : ?>
						<div class="lumia-module-card lumia-module-card--active">
							<div class="lumia-module-card__header">
								<?php echo $this->render_icon( '' !== $module['icon'] ? $module['icon'] : 'package', 'md' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
								<h3 class="lumia-module-card__title"><?php echo esc_html( $module['name'] ); ?></h3>
								<span class="lumia-badge lumia-badge--success"><?php echo esc_html__( 'Active', 'lumia-factures' ); ?></span>
							</div>
							<p class="lumia-module-card__desc"><?php echo esc_html( $module['description'] ); ?></p>
							<div class="lumia-module-card__actions">
								<a href="<?php echo esc_url( $this->tab_url( 'module_' . $module_id ) ); ?>" class="lumia-btn lumia-btn--sm lumia-btn--secondary">
									<?php echo esc_html__( 'Configure', 'lumia-factures' ); ?>
								</a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="lumia-empty">
					<?php echo $this->render_icon( 'package', 'xl' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
					<p><?php echo esc_html__( 'No module is running.', 'lumia-factures' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Shortcuts', 'lumia-factures' ); ?></h2>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-inline">
				<?php foreach ( $shortcuts as $shortcut ) : ?>
					<a href="<?php echo esc_url( $shortcut['url'] ); ?>" class="lumia-btn lumia-btn--sm lumia-btn--secondary">
						<?php echo $this->render_icon( $shortcut['icon'], 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
						<?php echo esc_html( $shortcut['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</div>
