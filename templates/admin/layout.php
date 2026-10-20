<?php
/**
 * Main template of the admin interface.
 *
 * Included from Admin::render_page(): $this is the Admin instance. One screen: the invoice
 * settings, or the plugin settings alone while the requirements fail (no module runs then).
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="lumia-admin-wrap lumia-factures-admin">
	<div class="lumia-admin-container">
		<main class="lumia-admin-main">
			<?php require LUMIA_FACTURES_TEMPLATES_DIR . ( $this->shows_invoices() ? 'admin/invoices.php' : 'admin/settings.php' ); ?>
		</main>
	</div>
</div>
