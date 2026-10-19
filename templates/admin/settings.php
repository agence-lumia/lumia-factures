<?php
/**
 * Global settings page.
 *
 * Included from the layout: $this is the Admin instance.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

$update_channel = (string) $this->settings->get( 'global.update_channel', 'stable' );

$environment = [
	[ __( 'WordPress', 'lumia-factures' ), (string) get_bloginfo( 'version' ), true ],
	[ __( 'PHP', 'lumia-factures' ), PHP_VERSION, true ],
	[ __( 'WooCommerce', 'lumia-factures' ), defined( 'WC_VERSION' ) ? (string) WC_VERSION : __( 'Not active', 'lumia-factures' ), defined( 'WC_VERSION' ) ],
	[ __( 'PDF engine (Dompdf)', 'lumia-factures' ), class_exists( 'Dompdf\Dompdf' ) ? __( 'Available', 'lumia-factures' ) : __( 'Missing', 'lumia-factures' ), class_exists( 'Dompdf\Dompdf' ) ],
	[ __( 'PHP memory', 'lumia-factures' ), (string) ini_get( 'memory_limit' ), true ],
];
?>
<div class="lumia-page">
	<div class="lumia-page__header">
		<div class="lumia-page__header-content">
			<h1 class="lumia-page__title"><?php echo esc_html__( 'Settings', 'lumia-factures' ); ?></h1>
			<p class="lumia-page__subtitle"><?php echo esc_html__( 'Global plugin configuration.', 'lumia-factures' ); ?></p>
		</div>
		<div class="lumia-page__header-actions">
			<button type="submit" form="lumia-factures-settings-form" class="lumia-btn lumia-btn--primary lumia-btn--sm">
				<?php echo esc_html__( 'Save', 'lumia-factures' ); ?>
			</button>
		</div>
	</div>

	<div class="lumia-page__scroll">
		<form id="lumia-factures-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form">
			<?php wp_nonce_field( 'lumia_factures_save_settings', 'lumia_factures_nonce' ); ?>
			<input type="hidden" name="action" value="lumia_factures_save_settings">
			<input type="hidden" name="lumia_factures_tab" value="settings">

			<div class="lumia-section">
				<div class="lumia-section__header">
					<h2 class="lumia-section__title"><?php echo esc_html__( 'Updates', 'lumia-factures' ); ?></h2>
					<p class="lumia-section__desc"><?php echo esc_html__( 'Choose the plugin update channel.', 'lumia-factures' ); ?></p>
				</div>
				<div class="lumia-section__content">
					<div class="lumia-option">
						<div class="lumia-option__content">
							<span class="lumia-option__label"><?php echo esc_html__( 'Current version', 'lumia-factures' ); ?></span>
							<p class="lumia-option__desc"><?php echo esc_html__( 'Plugin version installed on this site.', 'lumia-factures' ); ?></p>
						</div>
						<div class="lumia-option__control">
							<span class="lumia-badge lumia-badge--info">v<?php echo esc_html( LUMIA_FACTURES_VERSION ); ?></span>
						</div>
					</div>

					<div class="lumia-option">
						<div class="lumia-option__content">
							<label for="lumia_factures_update_channel" class="lumia-option__label"><?php echo esc_html__( 'Update channel', 'lumia-factures' ); ?></label>
							<p class="lumia-option__desc"><?php echo esc_html__( 'Choose between stable versions and development pre-releases.', 'lumia-factures' ); ?></p>
						</div>
						<div class="lumia-option__control">
							<select id="lumia_factures_update_channel" name="lumia_factures_global[update_channel]" class="lumia-select lumia-select--sm">
								<option value="stable" <?php selected( $update_channel, 'stable' ); ?>><?php echo esc_html__( 'Stable (main)', 'lumia-factures' ); ?></option>
								<option value="dev" <?php selected( $update_channel, 'dev' ); ?>><?php echo esc_html__( 'Dev (pre-release)', 'lumia-factures' ); ?></option>
							</select>
						</div>
					</div>
				</div>
			</div>
		</form>

		<div class="lumia-divider"></div>

		<details class="lumia-section lumia-section--collapsible">
			<summary class="lumia-section__header lumia-section__header--summary">
				<div>
					<h2 class="lumia-section__title"><?php echo esc_html__( 'Environment', 'lumia-factures' ); ?></h2>
					<p class="lumia-section__desc"><?php echo esc_html__( 'Versions and components the plugin relies on.', 'lumia-factures' ); ?></p>
				</div>
				<span class="lumia-section__toggle-icon" aria-hidden="true">
					<?php echo $this->render_icon( 'chevron-down', 'sm', 'lumia-section__chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?>
				</span>
			</summary>
			<div class="lumia-section__content">
				<table class="lumia-server-table">
					<tbody>
						<?php foreach ( $environment as [ $label, $value, $ok ] ) : ?>
							<tr>
								<td class="lumia-server-table__label"><?php echo esc_html( $label ); ?></td>
								<td><span class="lumia-badge <?php echo $ok ? 'lumia-badge--success' : 'lumia-badge--danger'; ?>"><?php echo esc_html( $value ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>
	</div>
</div>
