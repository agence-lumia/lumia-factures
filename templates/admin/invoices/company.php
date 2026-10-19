<?php
/**
 * Invoices settings, Company tab.
 *
 * Included by includes/Modules/Invoices/settings-template.php ($fields is a Fields instance).
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

$company_logo_id  = (int) $fields->value( 'company', 'logo_id' );
$company_logo_url = $company_logo_id > 0 ? wp_get_attachment_image_url( $company_logo_id, 'medium' ) : false;
?>
<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Logo', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'Printed at the top of the invoice. PNG or JPEG is the safest choice.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<?php $fields->toggle( 'company', 'logo_enabled', __( 'Show the logo', 'lumia-factures' ) ); ?>

		<div class="lumia-form__group">
			<span class="lumia-form__label"><?php echo esc_html__( 'Logo image', 'lumia-factures' ); ?></span>
			<div class="lumia-factures-inv-logo" data-lumia-factures-logo>
				<div class="lumia-factures-inv-logo__thumb" data-lumia-factures-logo-thumb data-lumia-factures-empty="<?php echo esc_attr__( 'No logo', 'lumia-factures' ); ?>">
					<?php if ( $company_logo_url ) : ?>
						<img src="<?php echo esc_url( $company_logo_url ); ?>" alt="">
					<?php else : ?>
						<span><?php echo esc_html__( 'No logo', 'lumia-factures' ); ?></span>
					<?php endif; ?>
				</div>
				<input type="hidden" name="<?php echo esc_attr( $fields::name( 'company', 'logo_id' ) ); ?>" value="<?php echo esc_attr( (string) $company_logo_id ); ?>" data-lumia-factures-logo-id>
				<div class="lumia-inline">
					<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm" data-lumia-factures-logo-pick><?php echo esc_html__( 'Choose a logo', 'lumia-factures' ); ?></button>
					<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm" data-lumia-factures-logo-remove<?php echo $company_logo_url ? '' : ' hidden'; ?>><?php echo esc_html__( 'Remove', 'lumia-factures' ); ?></button>
				</div>
			</div>
		</div>

		<?php $fields->number( 'company', 'logo_width', __( 'Logo width (pixels)', 'lumia-factures' ), __( 'The height follows the proportions of the image.', 'lumia-factures' ) ); ?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Identity', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'Use the switch of a field to show or hide it on the invoice.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content lumia-factures-inv-grid">
		<?php
		$fields->toggled_text( 'company', 'display_name', __( 'Shop name', 'lumia-factures' ) );
		$fields->toggled_text( 'company', 'legal_name', __( 'Legal name', 'lumia-factures' ) );
		$fields->toggled_text( 'company', 'legal_form', __( 'Legal form', 'lumia-factures' ) );
		$fields->toggled_text( 'company', 'manager', __( 'Manager', 'lumia-factures' ) );
		?>
	</div>
	<div class="lumia-section__content">
		<?php $fields->toggled_text( 'company', 'address', __( 'Address', 'lumia-factures' ), '', true ); ?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Contact', 'lumia-factures' ); ?></h2>
	</div>
	<div class="lumia-section__content lumia-factures-inv-grid">
		<?php
		$fields->toggled_text( 'company', 'phone', __( 'Phone', 'lumia-factures' ) );
		$fields->toggled_text( 'company', 'mobile', __( 'Mobile', 'lumia-factures' ) );
		$fields->toggled_text( 'company', 'email', __( 'Email', 'lumia-factures' ) );
		$fields->toggled_text( 'company', 'website', __( 'Website', 'lumia-factures' ) );
		?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Legal and bank details', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'Printed in the footer when the matching switch is on in the Content tab.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content lumia-factures-inv-grid">
		<?php
		$fields->toggled_text( 'company', 'siret', __( 'SIRET', 'lumia-factures' ) );
		$fields->toggled_text( 'company', 'rcs', __( 'RCS', 'lumia-factures' ) );
		$fields->toggled_text( 'company', 'vat_number', __( 'VAT number', 'lumia-factures' ) );
		$fields->toggled_text( 'company', 'share_capital', __( 'Share capital', 'lumia-factures' ) );
		$fields->toggled_text( 'company', 'ape_code', __( 'APE code', 'lumia-factures' ) );
		$fields->toggled_text( 'company', 'iban', __( 'IBAN', 'lumia-factures' ) );
		$fields->toggled_text( 'company', 'bic', __( 'BIC', 'lumia-factures' ) );
		?>
	</div>
</div>
