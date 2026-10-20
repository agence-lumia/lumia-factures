<?php
/**
 * Standalone "Check for updates" form, submitted by the button of partials/plugin-settings.php.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;
?>
<form id="lumia-factures-check-updates-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
	<?php wp_nonce_field( 'lumia_factures_check_updates', 'lumia_factures_check_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_factures_check_updates">
</form>
