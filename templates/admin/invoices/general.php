<?php
/**
 * Invoices settings, General tab.
 *
 * Included by includes/Modules/Invoices/settings-template.php ($fields is a Fields instance).
 * The refund email is offered for the credit note only (Schema::email_options() scopes); the
 * document links go to the customer emails only, refund email included.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Emails\EmailIds;
use Lumia\Factures\Modules\Invoices\Settings\Schema;

$general_statuses = [];
foreach ( wc_get_order_statuses() as $general_slug => $general_label ) {
	$general_statuses[ (string) preg_replace( '/^wc-/', '', (string) $general_slug ) ] = (string) $general_label;
}

// Email id => title, in the WooCommerce order. Canonical ids, like Schema::email_options(): the refund
// email object keeps the partial refund id once WooCommerce sent one in this request.
$general_titles = [];
if ( function_exists( 'WC' ) && WC()->mailer() ) {
	foreach ( WC()->mailer()->get_emails() as $general_email ) {
		if ( is_object( $general_email ) && ! empty( $general_email->id ) ) {
			$general_id    = EmailIds::normalize( (string) $general_email->id );
			$general_title = (string) $general_email->get_title();

			$general_titles[ $general_id ] = '' !== $general_title ? $general_title : $general_id;
		}
	}
}

$general_emails   = array_intersect_key( $general_titles, array_flip( Schema::email_options( 'invoice' ) ) );
$general_refund   = array_intersect_key( $general_titles, array_flip( Schema::email_options( 'refund' ) ) );
$general_customer = array_intersect_key( $general_titles, array_flip( Schema::email_options( 'customer' ) ) );
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

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Credit notes', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'Each refund of an invoiced order issues a credit note. An issued credit note never changes, and the refund email never carries the invoice.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<?php $fields->checklist( 'credit_notes', 'email_attachments', __( 'Attach the credit note to', 'lumia-factures' ), $general_refund ); ?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Customer access', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'Where customers find the links to their invoice and credit notes. A link appears once the document is issued.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<?php
		$fields->toggle( 'customer', 'account_orders', __( 'Invoice button in the My Account orders list', 'lumia-factures' ) );
		$fields->toggle( 'customer', 'order_details', __( 'Documents on the order page', 'lumia-factures' ), __( 'The order view of My Account and the thank-you page.', 'lumia-factures' ) );
		$fields->checklist( 'customer', 'email_links', __( 'Add the document links to', 'lumia-factures' ), $general_customer );
		?>
		<?php if ( defined( 'BRICKS_VERSION' ) ) : ?>
			<p class="lumia-form__help"><?php echo esc_html__( 'Bricks is active: if your templates already show the documents with the Lümia Factures dynamic tags, turn off the matching options to avoid showing them twice.', 'lumia-factures' ); ?></p>
		<?php endif; ?>
	</div>
</div>
