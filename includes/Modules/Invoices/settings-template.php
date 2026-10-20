<?php
/**
 * Settings form of the Invoices module: the whole plugin screen.
 *
 * Included by templates/admin/invoices.php: $this is the Admin instance. The header "Save" button
 * submits this form (id lumia-factures-module-form), the Settings tab included (global settings,
 * `lumia_factures_global[...]`). The "Raise counter" and "Check for updates" forms are standalone
 * forms printed after it: HTML forms cannot nest.
 *
 * The preview pane sits next to the fields of the tabs that change the PDF (`data-lumia-factures-preview-tabs`).
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Admin\Counters;
use Lumia\Factures\Modules\Invoices\Admin\Fields;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;

$invoices_settings = Module::settings();
$fields            = new Fields( $invoices_settings );
$sequences         = Sequences::all();

// Errors left by Module::save_settings(): shown once.
$invoices_errors = get_transient( 'lumia_factures_invoices_errors' );
delete_transient( 'lumia_factures_invoices_errors' );
$invoices_errors = is_array( $invoices_errors ) ? array_filter( array_map( 'strval', $invoices_errors ) ) : [];

$invoices_tabs = [
	'general'   => __( 'General', 'lumia-factures' ),
	'company'   => __( 'Company', 'lumia-factures' ),
	'numbering' => __( 'Numbering', 'lumia-factures' ),
	'content'   => __( 'Content', 'lumia-factures' ),
	'design'    => __( 'Design', 'lumia-factures' ),
	'settings'  => __( 'Settings', 'lumia-factures' ),
];
?>
<form id="lumia-factures-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form lumia-module-form">
	<?php wp_nonce_field( 'lumia_factures_save_settings', 'lumia_factures_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_factures_save_settings">
	<input type="hidden" name="lumia_factures_tab" value="module_invoices">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="invoices">
		<?php foreach ( $invoices_tabs as $invoices_tab => $invoices_tab_label ) : ?>
			<button type="button" class="lumia-tabs__tab<?php echo 'general' === $invoices_tab ? ' is-active' : ''; ?>" role="tab" data-lumia-tab="<?php echo esc_attr( $invoices_tab ); ?>"><?php echo esc_html( $invoices_tab_label ); ?></button>
		<?php endforeach; ?>
	</div>

	<div class="lumia-factures-inv-split" data-lumia-factures-split>
		<div class="lumia-module-form__scroll">
			<div class="lumia-factures-inv-body">
				<?php if ( [] !== $invoices_errors ) : ?>
					<div class="lumia-notice lumia-notice--error" role="alert">
						<?php foreach ( $invoices_errors as $invoices_error ) : ?>
							<p><?php echo esc_html( $invoices_error ); ?></p>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php foreach ( $invoices_tabs as $invoices_tab => $invoices_tab_label ) : ?>
					<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="invoices" data-lumia-tab-panel="<?php echo esc_attr( $invoices_tab ); ?>"<?php echo 'general' === $invoices_tab ? '' : ' hidden'; ?>>
						<?php
						if ( 'settings' === $invoices_tab ) {
							include LUMIA_FACTURES_TEMPLATES_DIR . 'admin/partials/plugin-settings.php';
						} else {
							include LUMIA_FACTURES_TEMPLATES_DIR . 'admin/invoices/' . $invoices_tab . '.php';
						}
						?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<?php require LUMIA_FACTURES_TEMPLATES_DIR . 'admin/invoices/preview.php'; ?>
	</div>
</form>

<?php foreach ( $sequences as $invoices_index => $invoices_sequence ) : ?>
	<form id="lumia-factures-raise-<?php echo esc_attr( (string) $invoices_index ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-factures-inv-raise-form">
		<input type="hidden" name="action" value="<?php echo esc_attr( Counters::ACTION ); ?>">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( Counters::ACTION ) ); ?>">
		<input type="hidden" name="sequence_key" value="<?php echo esc_attr( $invoices_sequence['key'] ); ?>">
	</form>
<?php endforeach; ?>

<?php require LUMIA_FACTURES_TEMPLATES_DIR . 'admin/partials/check-updates-form.php'; ?>
