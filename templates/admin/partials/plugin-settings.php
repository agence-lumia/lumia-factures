<?php
/**
 * Plugin settings sections: updates and environment.
 *
 * Printed inside a settings form (the invoices form, or the standalone form of settings.php):
 * the fields post as `lumia_factures_global[...]`. The "Check for updates" button submits the
 * standalone form of partials/check-updates-form.php, printed after the settings form (HTML forms
 * cannot nest). $this is the Admin instance.
 *
 * @package Lumia\Factures
 */

defined( 'ABSPATH' ) || exit;

$update_channel = (string) $this->settings->get( 'global.update_channel', 'stable' );

// Automatic update: the source of truth is the WordPress option `auto_update_plugins`,
// shared with the plugins list.
$can_auto_update = wp_is_auto_update_enabled_for_type( 'plugin' ) && current_user_can( 'update_plugins' );
$auto_update_on  = in_array( plugin_basename( LUMIA_FACTURES_PLUGIN_FILE ), (array) get_site_option( 'auto_update_plugins', [] ), true );

$environment = [
	[ __( 'WordPress', 'lumia-factures' ), (string) get_bloginfo( 'version' ), true ],
	[ __( 'PHP', 'lumia-factures' ), PHP_VERSION, true ],
	[ __( 'WooCommerce', 'lumia-factures' ), defined( 'WC_VERSION' ) ? (string) WC_VERSION : __( 'Not active', 'lumia-factures' ), defined( 'WC_VERSION' ) ],
	[ __( 'PDF engine (Dompdf)', 'lumia-factures' ), class_exists( 'Dompdf\Dompdf' ) ? __( 'Available', 'lumia-factures' ) : __( 'Missing', 'lumia-factures' ), class_exists( 'Dompdf\Dompdf' ) ],
	[ __( 'PHP memory', 'lumia-factures' ), (string) ini_get( 'memory_limit' ), true ],
];
?>
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
			<div class="lumia-option__control lumia-inline">
				<span class="lumia-badge lumia-badge--info">v<?php echo esc_html( LUMIA_FACTURES_VERSION ); ?></span>
				<button type="submit" class="lumia-btn lumia-btn--secondary lumia-btn--sm" form="lumia-factures-check-updates-form">
					<?php echo esc_html__( 'Check for updates', 'lumia-factures' ); ?>
				</button>
			</div>
		</div>

		<div class="lumia-option">
			<div class="lumia-option__content">
				<label for="lumia_factures_update_channel" class="lumia-option__label"><?php echo esc_html__( 'Update channel', 'lumia-factures' ); ?></label>
				<p class="lumia-option__desc"><?php echo esc_html__( 'Choose between stable versions and development pre-releases.', 'lumia-factures' ); ?></p>
			</div>
			<div class="lumia-option__control">
				<select id="lumia_factures_update_channel" name="lumia_factures_global[update_channel]" class="lumia-select lumia-select--sm" <?php disabled( ! current_user_can( 'update_plugins' ) ); ?>>
					<option value="stable" <?php selected( $update_channel, 'stable' ); ?>><?php echo esc_html__( 'Stable (main)', 'lumia-factures' ); ?></option>
					<option value="dev" <?php selected( $update_channel, 'dev' ); ?>><?php echo esc_html__( 'Dev (pre-release)', 'lumia-factures' ); ?></option>
				</select>
			</div>
		</div>

		<div class="lumia-option">
			<div class="lumia-option__content">
				<label for="lumia_factures_auto_update" class="lumia-option__label"><?php echo esc_html__( 'Automatic updates', 'lumia-factures' ); ?></label>
				<p class="lumia-option__desc">
					<?php
					echo esc_html(
						$can_auto_update
							? __( 'Installs new versions of the chosen channel without intervention. Same setting as the "Automatic updates" column of the plugins list.', 'lumia-factures' )
							: __( 'Automatic plugin updates are disabled on this site, or your account cannot manage them.', 'lumia-factures' )
					);
					?>
				</p>
			</div>
			<div class="lumia-option__control">
				<input type="hidden" name="lumia_factures_global[auto_update_initial]" value="<?php echo $auto_update_on ? '1' : '0'; ?>">
				<label class="lumia-toggle">
					<input type="checkbox" id="lumia_factures_auto_update" name="lumia_factures_global[auto_update]" value="1" <?php checked( $auto_update_on ); ?> <?php disabled( ! $can_auto_update ); ?>>
					<span class="lumia-toggle__slider"></span>
				</label>
			</div>
		</div>
	</div>
</div>

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
				<?php foreach ( $environment as [ $env_label, $env_value, $env_ok ] ) : ?>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html( $env_label ); ?></td>
						<td><span class="lumia-badge <?php echo $env_ok ? 'lumia-badge--success' : 'lumia-badge--danger'; ?>"><?php echo esc_html( $env_value ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</details>
