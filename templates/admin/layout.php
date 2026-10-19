<?php
/**
 * Main template of the admin interface.
 *
 * Included from Admin::render_page(): $this is the Admin instance.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

$tab       = $this->current_tab();
$module_id = 0 === strpos( $tab, 'module_' ) ? substr( $tab, strlen( 'module_' ) ) : '';

// An unknown tab or an inactive module falls back to the overview (also in the sidebar).
if ( ! in_array( $tab, [ 'modules', 'settings' ], true ) && ! isset( $this->modules->get_active_instances()[ $module_id ] ) ) {
	$tab = 'dashboard';
}

$page_templates = [
	'dashboard' => 'admin/dashboard.php',
	'modules'   => 'admin/modules.php',
	'settings'  => 'admin/settings.php',
];
?>
<div class="lumia-admin-wrap lumia-factures-admin">
	<div class="lumia-admin-container">
		<?php require LUMIA_FACTURES_TEMPLATES_DIR . 'components/sidebar.php'; ?>

		<main class="lumia-admin-main">
			<?php require LUMIA_FACTURES_TEMPLATES_DIR . ( $page_templates[ $tab ] ?? 'admin/module-settings.php' ); ?>
		</main>
	</div>
</div>
