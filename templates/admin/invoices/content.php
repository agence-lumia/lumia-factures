<?php
/**
 * Invoices settings, Content tab.
 *
 * Included by includes/Modules/Invoices/settings-template.php ($fields is a Fields instance).
 * A label left empty prints the default label shown as placeholder.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Rendering\Placeholders;

$content_tags = '{' . implode( '} {', Placeholders::TAGS ) . '}';
$content_help = sprintf(
	/* translators: %s: list of tags such as {order_number}. */
	__( 'Tags replaced on each invoice: %s', 'lumia-factures' ),
	$content_tags
) . ' ' . __( 'Prefer {invoice_date} to {current_date}: a rebuilt PDF would print the date of the rebuild.', 'lumia-factures' );

// Product table columns: key => [label, default label].
$content_columns = [
	'image'       => [ __( 'Image', 'lumia-factures' ), '' ],
	'name'        => [ __( 'Product name', 'lumia-factures' ), __( 'Product', 'lumia-factures' ) ],
	'description' => [ __( 'Description', 'lumia-factures' ), __( 'Description', 'lumia-factures' ) ],
	'sku'         => [ __( 'SKU', 'lumia-factures' ), __( 'SKU', 'lumia-factures' ) ],
	'quantity'    => [ __( 'Quantity', 'lumia-factures' ), __( 'Qty', 'lumia-factures' ) ],
	'unit_price'  => [ __( 'Unit price', 'lumia-factures' ), __( 'Unit price', 'lumia-factures' ) ],
	'tax_rate'    => [ __( 'Tax rate', 'lumia-factures' ), __( 'VAT', 'lumia-factures' ) ],
	'total'       => [ __( 'Line total', 'lumia-factures' ), __( 'Total', 'lumia-factures' ) ],
];
?>
<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Document', 'lumia-factures' ); ?></h2>
	</div>
	<div class="lumia-section__content">
		<?php
		$fields->text( 'content', 'invoice_title', __( 'Document title', 'lumia-factures' ), '', __( 'Invoice', 'lumia-factures' ) );
		$fields->select(
			'content',
			'price_display',
			__( 'Prices shown', 'lumia-factures' ),
			[
				'incl' => __( 'Including tax', 'lumia-factures' ),
				'excl' => __( 'Excluding tax', 'lumia-factures' ),
			],
			__( 'The grand total always equals the WooCommerce order total.', 'lumia-factures' )
		);
		$fields->toggle( 'content', 'meta_invoice_number_enabled', __( 'Show the invoice number', 'lumia-factures' ) );
		$fields->toggle( 'content', 'meta_invoice_date_enabled', __( 'Show the invoice date', 'lumia-factures' ) );
		$fields->toggle( 'content', 'meta_order_number_enabled', __( 'Show the order number', 'lumia-factures' ) );
		$fields->toggle( 'content', 'meta_order_date_enabled', __( 'Show the order date', 'lumia-factures' ) );
		$fields->toggle( 'content', 'meta_payment_method_enabled', __( 'Show the payment method', 'lumia-factures' ) );
		?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Credit note', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'A credit note uses the layout of the invoice and always names the number and date of the invoice it credits.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<?php
		$fields->text( 'credit_notes', 'title', __( 'Document title', 'lumia-factures' ), '', __( 'Credit note', 'lumia-factures' ) );
		$fields->toggle( 'credit_notes', 'reason_enabled', __( 'Show the refund reason', 'lumia-factures' ), __( 'The reason entered with the refund, when there is one.', 'lumia-factures' ) );
		?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Addresses', 'lumia-factures' ); ?></h2>
	</div>
	<div class="lumia-section__content">
		<?php
		$fields->text( 'content', 'billing_title', __( 'Billing block title', 'lumia-factures' ), '', __( 'Bill to', 'lumia-factures' ) );
		$fields->toggle( 'content', 'billing_address_enabled', __( 'Show the billing address', 'lumia-factures' ) );
		$fields->toggle( 'content', 'billing_email_enabled', __( 'Show the billing email', 'lumia-factures' ) );
		$fields->toggle( 'content', 'billing_phone_enabled', __( 'Show the billing phone', 'lumia-factures' ) );
		$fields->toggle( 'content', 'shipping_enabled', __( 'Show the shipping block', 'lumia-factures' ) );
		$fields->text( 'content', 'shipping_title', __( 'Shipping block title', 'lumia-factures' ), '', __( 'Ship to', 'lumia-factures' ) );
		$fields->toggle( 'content', 'shipping_hide_if_same', __( 'Hide it when it equals the billing address', 'lumia-factures' ) );
		$fields->toggle( 'content', 'shipping_hide_virtual', __( 'Hide it for orders without shipping', 'lumia-factures' ) );
		$fields->toggle( 'content', 'shipping_phone_enabled', __( 'Show the shipping phone', 'lumia-factures' ) );
		?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Message', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'A free text printed before the product table.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<?php
		$fields->toggle( 'content', 'message_enabled', __( 'Show the message', 'lumia-factures' ) );
		$fields->text( 'content', 'message_title', __( 'Message title', 'lumia-factures' ) );
		$fields->textarea( 'content', 'message_body', __( 'Message', 'lumia-factures' ), $content_help );
		?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Product table', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'Columns, their label (empty keeps the default one) and their relative width.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<table class="lumia-server-table lumia-factures-inv-columns">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Column', 'lumia-factures' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Show', 'lumia-factures' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Label', 'lumia-factures' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Width (%)', 'lumia-factures' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $content_columns as $content_key => $content_column ) : ?>
					<?php
					$content_enabled = 'col_' . $content_key . '_enabled';
					$content_label   = 'col_' . $content_key . '_label';
					$content_width   = 'col_' . $content_key . '_width';
					?>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html( $content_column[0] ); ?></td>
						<td>
							<label class="lumia-toggle">
								<input type="hidden" name="<?php echo esc_attr( $fields::name( 'content', $content_enabled ) ); ?>" value="0">
								<input type="checkbox" id="<?php echo esc_attr( $fields::id( 'content', $content_enabled ) ); ?>" name="<?php echo esc_attr( $fields::name( 'content', $content_enabled ) ); ?>" value="1" aria-label="<?php echo esc_attr( $content_column[0] ); ?>" <?php checked( ! empty( $fields->value( 'content', $content_enabled ) ) ); ?>>
								<span class="lumia-toggle__slider"></span>
							</label>
						</td>
						<td>
							<?php if ( 'image' === $content_key ) : ?>
								<input type="hidden" name="<?php echo esc_attr( $fields::name( 'content', $content_label ) ); ?>" value="<?php echo esc_attr( (string) $fields->value( 'content', $content_label ) ); ?>">
							<?php else : ?>
								<input type="text" class="lumia-input" id="<?php echo esc_attr( $fields::id( 'content', $content_label ) ); ?>" name="<?php echo esc_attr( $fields::name( 'content', $content_label ) ); ?>" value="<?php echo esc_attr( (string) $fields->value( 'content', $content_label ) ); ?>" placeholder="<?php echo esc_attr( $content_column[1] ); ?>" aria-label="<?php echo esc_attr( $content_column[0] ); ?>">
							<?php endif; ?>
						</td>
						<td>
							<input type="number" class="lumia-input lumia-input--xs" id="<?php echo esc_attr( $fields::id( 'content', $content_width ) ); ?>" name="<?php echo esc_attr( $fields::name( 'content', $content_width ) ); ?>" value="<?php echo esc_attr( (string) $fields->value( 'content', $content_width ) ); ?>" min="2" max="60" step="1" aria-label="<?php echo esc_attr( $content_column[0] ); ?>">
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$fields->toggle( 'content', 'item_meta_enabled', __( 'Show variation attributes and product options', 'lumia-factures' ) );
		$fields->toggle( 'content', 'item_parent_name_enabled', __( 'Show the parent product name for variations', 'lumia-factures' ) );
		?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Totals', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'Rows of the totals block. The amounts always add up to the WooCommerce order total.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<?php
		$fields->toggle( 'content', 'total_subtotal_enabled', __( 'Subtotal', 'lumia-factures' ) );
		$fields->toggle( 'content', 'total_discount_enabled', __( 'Discount', 'lumia-factures' ) );
		$fields->toggle( 'content', 'total_discount_code_enabled', __( 'Show the coupon code in the discount row', 'lumia-factures' ) );
		$fields->toggle( 'content', 'total_shipping_enabled', __( 'Shipping', 'lumia-factures' ) );
		$fields->toggle( 'content', 'total_shipping_always', __( 'Show the shipping row even when it is free', 'lumia-factures' ) );
		$fields->toggle( 'content', 'total_fees_enabled', __( 'Fees', 'lumia-factures' ) );
		$fields->toggle( 'content', 'total_tax_enabled', __( 'Tax', 'lumia-factures' ) );
		$fields->toggle( 'content', 'total_grand_enabled', __( 'Grand total', 'lumia-factures' ) );
		?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Customer note', 'lumia-factures' ); ?></h2>
	</div>
	<div class="lumia-section__content">
		<?php
		$fields->toggle( 'content', 'customer_note_enabled', __( 'Show the customer note', 'lumia-factures' ) );
		$fields->text( 'content', 'customer_note_label', __( 'Customer note title', 'lumia-factures' ), '', __( 'Customer note', 'lumia-factures' ) );
		?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Footer', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'The company details come from the Company tab.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<?php
		$fields->textarea( 'content', 'footer_text', __( 'Footer text', 'lumia-factures' ), $content_help );
		$fields->toggle( 'content', 'footer_pagination_enabled', __( 'Show the page numbers', 'lumia-factures' ) );
		$fields->toggle( 'content', 'footer_phone_enabled', __( 'Phone', 'lumia-factures' ) );
		$fields->toggle( 'content', 'footer_mobile_enabled', __( 'Mobile', 'lumia-factures' ) );
		$fields->toggle( 'content', 'footer_email_enabled', __( 'Email', 'lumia-factures' ) );
		$fields->toggle( 'content', 'footer_website_enabled', __( 'Website', 'lumia-factures' ) );
		$fields->toggle( 'content', 'footer_siret_enabled', __( 'SIRET', 'lumia-factures' ) );
		$fields->toggle( 'content', 'footer_rcs_enabled', __( 'RCS', 'lumia-factures' ) );
		$fields->toggle( 'content', 'footer_vat_number_enabled', __( 'VAT number', 'lumia-factures' ) );
		$fields->toggle( 'content', 'footer_share_capital_enabled', __( 'Share capital', 'lumia-factures' ) );
		$fields->toggle( 'content', 'footer_ape_code_enabled', __( 'APE code', 'lumia-factures' ) );
		$fields->toggle( 'content', 'footer_iban_enabled', __( 'IBAN', 'lumia-factures' ) );
		$fields->toggle( 'content', 'footer_bic_enabled', __( 'BIC', 'lumia-factures' ) );
		$fields->textarea( 'content', 'legal_text', __( 'Legal text', 'lumia-factures' ), $content_help );
		?>
	</div>
</div>
