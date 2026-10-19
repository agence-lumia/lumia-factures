<?php
/**
 * Sidebar: Overview, Modules, then the active modules, then Settings.
 *
 * Included from the layout: $this is the Admin instance, $tab the current tab.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

$sidebar_groups = [
	[
		[
			'tab'   => 'dashboard',
			'label' => __( 'Overview', 'lumia-factures' ),
			'desc'  => __( 'Dashboard', 'lumia-factures' ),
			'icon'  => 'layout-dashboard',
		],
		[
			'tab'   => 'modules',
			'label' => __( 'Modules', 'lumia-factures' ),
			'desc'  => __( 'Manage modules', 'lumia-factures' ),
			'icon'  => 'package',
		],
	],
	[],
	[
		[
			'tab'   => 'settings',
			'label' => __( 'Settings', 'lumia-factures' ),
			'desc'  => __( 'Global configuration', 'lumia-factures' ),
			'icon'  => 'settings',
		],
	],
];

// Active modules only; the registry is empty when the requirements are not met.
foreach ( $this->modules->get_all() as $sidebar_module_id => $sidebar_module ) {
	if ( ! $this->modules->is_active( $sidebar_module_id ) ) {
		continue;
	}
	$sidebar_groups[1][] = [
		'tab'   => 'module_' . $sidebar_module_id,
		'label' => '' !== $sidebar_module['menu_label'] ? $sidebar_module['menu_label'] : $sidebar_module['name'],
		'desc'  => '' !== $sidebar_module['menu_desc'] ? $sidebar_module['menu_desc'] : $sidebar_module['description'],
		'icon'  => '' !== $sidebar_module['icon'] ? $sidebar_module['icon'] : 'package',
	];
}

$sidebar_groups = array_values( array_filter( $sidebar_groups ) );
?>
<aside class="lumia-sidebar">
	<div class="lumia-sidebar__header">
		<h2 class="lumia-sidebar__title"><?php echo esc_html__( 'Navigation', 'lumia-factures' ); ?></h2>
		<div class="lumia-sidebar__actions"></div>
	</div>

	<nav class="lumia-sidebar__nav">
		<?php foreach ( $sidebar_groups as $group_index => $items ) : ?>
			<?php if ( $group_index > 0 ) : ?>
				<div class="lumia-sidebar__divider"></div>
			<?php endif; ?>
			<ul class="lumia-sidebar__menu">
				<?php foreach ( $items as $item ) : ?>
					<li class="lumia-sidebar__item">
						<a href="<?php echo esc_url( $this->tab_url( $item['tab'] ) ); ?>"
							class="lumia-sidebar__link <?php echo $item['tab'] === $tab ? 'is-active' : ''; ?>"
							<?php echo $item['tab'] === $tab ? 'aria-current="page"' : ''; ?>>
							<div class="lumia-sidebar__icon-wrapper">
								<?php echo $this->render_icon( $item['icon'], 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
							</div>
							<div class="lumia-sidebar__link-text">
								<span class="lumia-sidebar__link-label"><?php echo esc_html( $item['label'] ); ?></span>
								<span class="lumia-sidebar__link-desc"><?php echo esc_html( $item['desc'] ); ?></span>
							</div>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
	</nav>
</aside>
