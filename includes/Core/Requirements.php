<?php
/**
 * Blocking runtime requirements.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Checks what the plugin needs before starting its modules.
 *
 * Evaluated once every plugin is loaded (WooCommerce and the legacy plugin may
 * load after this one): Plugin calls it on `init`.
 */
class Requirements {

	public const MIN_PHP = '8.1';
	public const MIN_WP  = '6.9';
	public const MIN_WC  = '9.0';

	/**
	 * Returns the blocking errors, keyed by reason.
	 *
	 * Reasons: `php`, `wp`, `woocommerce`, `extensions`, `dompdf`, `legacy_active`.
	 * An empty array means the plugin can start its modules.
	 *
	 * @return array<string, string> Reason => human-readable message.
	 */
	public static function check(): array {
		$errors = [];

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			$errors['php'] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'Lümia Factures requires PHP %1$s or later (current version: %2$s).', 'lumia-factures' ),
				self::MIN_PHP,
				PHP_VERSION
			);
		}

		$wp_version = (string) get_bloginfo( 'version' );
		if ( version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			$errors['wp'] = sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version. */
				__( 'Lümia Factures requires WordPress %1$s or later (current version: %2$s).', 'lumia-factures' ),
				self::MIN_WP,
				$wp_version
			);
		}

		/**
		 * Whether WooCommerce is loaded.
		 *
		 * @internal Test seam only, not a public extension point.
		 *
		 * @param bool $active True when the WooCommerce main class exists.
		 */
		$wc_active = (bool) apply_filters( 'lumia_factures_requirements_wc_active', class_exists( 'WooCommerce' ) );
		if ( ! $wc_active ) {
			$errors['woocommerce'] = sprintf(
				/* translators: %s: required WooCommerce version. */
				__( 'Lümia Factures requires WooCommerce %s or later to be installed and active.', 'lumia-factures' ),
				self::MIN_WC
			);
		} elseif ( ! defined( 'WC_VERSION' ) || version_compare( (string) WC_VERSION, self::MIN_WC, '<' ) ) {
			$errors['woocommerce'] = sprintf(
				/* translators: 1: required WooCommerce version, 2: current WooCommerce version. */
				__( 'Lümia Factures requires WooCommerce %1$s or later (current version: %2$s).', 'lumia-factures' ),
				self::MIN_WC,
				defined( 'WC_VERSION' ) ? (string) WC_VERSION : '?'
			);
		}

		/**
		 * Class probed to make sure the PDF engine can be loaded.
		 *
		 * @internal Test seam only, not a public extension point.
		 *
		 * @param string $class Fully qualified Dompdf class name.
		 */
		$dompdf_class = (string) apply_filters( 'lumia_factures_requirements_dompdf_class', 'Dompdf\Dompdf' );
		if ( ! class_exists( $dompdf_class ) ) {
			$errors['dompdf'] = __( 'The PDF engine (Dompdf) cannot be loaded: the vendor/ folder of Lümia Factures is missing or damaged. Reinstall the plugin from its release ZIP.', 'lumia-factures' );
		}

		/**
		 * PHP extensions the invoicing engine needs (Dompdf, DOM and image handling).
		 *
		 * @param string[] $extensions Extension names checked with extension_loaded().
		 */
		$extensions = apply_filters( 'lumia_factures_required_extensions', [ 'dom', 'mbstring', 'gd' ] );
		$missing    = is_array( $extensions ) ? array_values( array_filter( $extensions, static fn( $extension ) => is_string( $extension ) && ! extension_loaded( $extension ) ) ) : [];
		if ( [] !== $missing ) {
			$errors['extensions'] = sprintf(
				/* translators: %s: comma-separated list of missing PHP extensions. */
				__( 'Lümia Factures requires the following PHP extensions: %s. Ask your host to enable them.', 'lumia-factures' ),
				implode( ', ', $missing )
			);
		}

		// The legacy "Facture PDF Lumia" plugin defines FPL_VERSION. Running both
		// would attach two invoices to the emails and advance two counters.
		if ( defined( 'FPL_VERSION' ) ) {
			$errors['legacy_active'] = __( 'The legacy "Facture PDF Lumia" plugin is still active: deactivate it so that Lümia Factures can start. Both cannot run at the same time.', 'lumia-factures' );
		}

		return $errors;
	}
}
