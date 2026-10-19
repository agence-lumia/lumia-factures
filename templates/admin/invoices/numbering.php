<?php
/**
 * Invoices settings, Numbering tab.
 *
 * Included by includes/Modules/Invoices/settings-template.php ($fields is a Fields instance,
 * $sequences the counters). The "Raise" inputs belong to standalone forms printed after the
 * module form (HTML5 `form` attribute), because forms cannot nest.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Numbering\NumberFormat;

$numbering_tags = [
	'number'       => __( 'Counter. {number:5} pads it with zeros to 5 digits. Required.', 'lumia-factures' ),
	'year'         => __( 'Year. {year:2} gives the last two digits.', 'lumia-factures' ),
	'month'        => __( 'Month, two digits.', 'lumia-factures' ),
	'day'          => __( 'Day, two digits.', 'lumia-factures' ),
	'order_number' => __( 'WooCommerce order number.', 'lumia-factures' ),
	'order_id'     => __( 'WooCommerce order id.', 'lumia-factures' ),
	'customer_id'  => __( 'Customer id.', 'lumia-factures' ),
];
?>
<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Invoice number', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'Numbers are continuous and never reused. Changing the format only affects the next invoices.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<?php $fields->text( 'numbering', 'format', __( 'Format', 'lumia-factures' ), __( 'The {number} tag is required. The characters / \ : * ? " < > | are not allowed.', 'lumia-factures' ) ); ?>

		<div class="lumia-form__group">
			<span class="lumia-form__label"><?php echo esc_html__( 'Next invoice number', 'lumia-factures' ); ?></span>
			<div class="lumia-factures-inv-sample" data-lumia-factures-sample aria-live="polite">&nbsp;</div>
			<p class="lumia-form__help"><?php echo esc_html__( 'Sample with today\'s date, order 14243 and customer 27.', 'lumia-factures' ); ?></p>
		</div>

		<div class="lumia-form__group">
			<span class="lumia-form__label"><?php echo esc_html__( 'Available tags', 'lumia-factures' ); ?></span>
			<ul class="lumia-factures-inv-tags">
				<?php foreach ( $numbering_tags as $numbering_tag => $numbering_help ) : ?>
					<?php if ( in_array( $numbering_tag, NumberFormat::known_tags(), true ) ) : ?>
						<li><code>{<?php echo esc_html( $numbering_tag ); ?>}</code> <?php echo esc_html( $numbering_help ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		</div>

		<?php $fields->number( 'numbering', 'start_number', __( 'First number', 'lumia-factures' ), __( 'Used when a counter starts. An existing counter is never changed by this setting.', 'lumia-factures' ) ); ?>
		<?php $fields->toggle( 'numbering', 'reset_enabled', __( 'Restart the counter each period', 'lumia-factures' ), __( 'When on, the format must contain the year (and the month for a monthly reset).', 'lumia-factures' ) ); ?>
		<?php
		$fields->select(
			'numbering',
			'reset_period',
			__( 'Restart every', 'lumia-factures' ),
			[
				'yearly'  => __( 'Year', 'lumia-factures' ),
				'monthly' => __( 'Month', 'lumia-factures' ),
			]
		);
		?>
	</div>
</div>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php echo esc_html__( 'Counters', 'lumia-factures' ); ?></h2>
		<p class="lumia-section__desc"><?php echo esc_html__( 'A counter can only be raised, never lowered: a number that was issued is never issued again. Raise it to continue after invoices created elsewhere.', 'lumia-factures' ); ?></p>
	</div>
	<div class="lumia-section__content">
		<?php if ( [] === $sequences ) : ?>
			<p class="lumia-form__help"><?php echo esc_html__( 'No counter yet. The first one is created with the first invoice.', 'lumia-factures' ); ?></p>
		<?php else : ?>
			<table class="lumia-server-table lumia-factures-inv-counters" data-lumia-factures-counters>
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Counter', 'lumia-factures' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Last number used', 'lumia-factures' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Updated', 'lumia-factures' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Raise to', 'lumia-factures' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sequences as $numbering_index => $numbering_sequence ) : ?>
						<?php
						$numbering_form  = 'lumia-factures-raise-' . $numbering_index;
						$numbering_input = 'lumia-factures-raise-value-' . $numbering_index;
						?>
						<tr>
							<td><code><?php echo esc_html( $numbering_sequence['key'] ); ?></code></td>
							<td><?php echo esc_html( (string) $numbering_sequence['value'] ); ?></td>
							<td><?php echo esc_html( $numbering_sequence['updated_at'] ); ?></td>
							<td>
								<div class="lumia-inline">
									<label class="screen-reader-text" for="<?php echo esc_attr( $numbering_input ); ?>"><?php echo esc_html__( 'New last number used', 'lumia-factures' ); ?></label>
									<input type="number" class="lumia-input lumia-input--xs" id="<?php echo esc_attr( $numbering_input ); ?>" name="value" form="<?php echo esc_attr( $numbering_form ); ?>" min="<?php echo esc_attr( (string) ( $numbering_sequence['value'] + 1 ) ); ?>" step="1" value="<?php echo esc_attr( (string) ( $numbering_sequence['value'] + 1 ) ); ?>">
									<button type="submit" class="lumia-btn lumia-btn--secondary lumia-btn--sm" form="<?php echo esc_attr( $numbering_form ); ?>" data-lumia-factures-raise><?php echo esc_html__( 'Raise', 'lumia-factures' ); ?></button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
