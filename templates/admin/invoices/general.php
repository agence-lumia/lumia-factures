<?php
/**
 * Invoices settings, General tab.
 *
 * Included by includes/Modules/Invoices/settings-template.php ($fields is a Fields instance).
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Settings\Schema;

$general_statuses = [];
foreach ( wc_get_order_statuses() as $general_slug => $general_label ) {
	$general_statuses[ (string) preg_replace( '/^wc-/', '', (string) $general_slug ) ] = (string) $general_label;
}

$general_emails = [];
if ( function_exists( 'WC' ) && WC()->mailer() ) {
	$general_known = Schema::email_options();
	foreach ( WC()->mailer()->get_emails() as $general_email ) {
		if ( is_object( $general_email ) && ! empty( $general_email->id ) && in_array( (string) $general_email->id, $general_known, true ) ) {
			$general_title = (string) $general_email->get_title();

			$general_emails[ (string) $general_email->id ] = '' !== $general_title ? $general_title : (string) $general_email->id;
		}
	}
}
?>
<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Issuing', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'The invoice is issued once, when an order reaches one of these statuses. An issued invoice never changes.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<?php $fields->checklist( 'general', 'trigger_statuses', __( 'Issue the invoice when the order status becomes', 'lumia-factures' ), $general_statuses ); ?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Emails', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'The issued invoice is attached to these WooCommerce emails.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<?php $fields->checklist( 'general', 'email_attachments', __( 'Attach the invoice to', 'lumia-factures' ), $general_emails ); ?>
	</div>
</div>
