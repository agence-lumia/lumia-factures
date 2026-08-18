<?php
/**
 * Vérification des prérequis d'environnement.
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contrôle que l'environnement permet au plugin de fonctionner.
 *
 * Appelée à l'activation (mode strict, bloquant) et à chaque chargement
 * (mode souple, notice seulement). Ne dépend d'aucune autre classe du plugin
 * pour rester utilisable avant l'autoload complet.
 */
final class Requirements {

	/**
	 * Liste des échecs constatés lors du dernier appel.
	 *
	 * @var array<int, string>
	 */
	private static array $failures = array();

	/**
	 * L'environnement satisfait-il les prérequis ?
	 *
	 * @param bool $strict Vérifier aussi WooCommerce (faux au chargement,
	 *                     car WooCommerce peut se charger après nous).
	 */
	public static function met( bool $strict = false ): bool {
		self::$failures = array();

		if ( version_compare( PHP_VERSION, FPL_MIN_PHP, '<' ) ) {
			self::$failures[] = sprintf(
				/* translators: 1: version requise, 2: version actuelle */
				__( 'PHP %1$s ou supérieur est requis (version actuelle : %2$s).', 'facture-pdf-lumia' ),
				FPL_MIN_PHP,
				PHP_VERSION
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), FPL_MIN_WP, '<' ) ) {
			self::$failures[] = sprintf(
				/* translators: 1: version requise, 2: version actuelle */
				__( 'WordPress %1$s ou supérieur est requis (version actuelle : %2$s).', 'facture-pdf-lumia' ),
				FPL_MIN_WP,
				get_bloginfo( 'version' )
			);
		}

		foreach ( array( 'mbstring', 'gd', 'dom' ) as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				self::$failures[] = sprintf(
					/* translators: %s: nom de l'extension PHP */
					__( "L'extension PHP « %s » est requise mais absente.", 'facture-pdf-lumia' ),
					$extension
				);
			}
		}

		// WooCommerce n'est vérifié qu'en mode strict : sur `plugins_loaded`
		// à priorité 5, il est déjà là, mais à l'activation d'un lot de
		// plugins l'ordre n'est pas garanti.
		if ( $strict && ! self::woocommerce_available() ) {
			self::$failures[] = sprintf(
				/* translators: %s: version requise */
				__( 'WooCommerce %s ou supérieur doit être installé et actif.', 'facture-pdf-lumia' ),
				FPL_MIN_WC
			);
		}

		return array() === self::$failures;
	}

	/**
	 * WooCommerce est-il présent dans une version suffisante ?
	 */
	public static function woocommerce_available(): bool {
		if ( ! defined( 'WC_VERSION' ) ) {
			// À l'activation, WooCommerce peut ne pas être encore chargé :
			// on se rabat sur la liste des extensions actives.
			$active = (array) get_option( 'active_plugins', array() );

			if ( is_multisite() ) {
				$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			}

			return in_array( 'woocommerce/woocommerce.php', $active, true );
		}

		return version_compare( WC_VERSION, FPL_MIN_WC, '>=' );
	}

	/**
	 * Message d'échec agrégé, pour `wp_die()`.
	 */
	public static function failure_message(): string {
		if ( array() === self::$failures ) {
			self::met( true );
		}

		return __( 'Facture PDF Lumia ne peut pas être activé :', 'facture-pdf-lumia' )
			. ' ' . implode( ' ', self::$failures );
	}

	/**
	 * Notice d'administration listant les prérequis manquants.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( array() === self::$failures ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:20px;">',
			esc_html__( 'Facture PDF Lumia est inactif : prérequis non satisfaits.', 'facture-pdf-lumia' )
		);

		foreach ( self::$failures as $failure ) {
			printf( '<li>%s</li>', esc_html( $failure ) );
		}

		echo '</ul></div>';
	}
}
