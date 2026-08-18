<?php
/**
 * Plugin Name:       Facture PDF Lumia
 * Plugin URI:        https://lumia.fr
 * Description:       Génération de factures PDF pour WooCommerce, entièrement personnalisable.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Lumia
 * Author URI:        https://lumia.fr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       facture-pdf-lumia
 * Domain Path:       /languages
 *
 * WC requires at least: 8.0
 * WC tested up to:      9.9
 *
 * @package Lumia\FacturePDF
 */

declare( strict_types=1 );

namespace Lumia\FacturePDF;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
|--------------------------------------------------------------------------
| Constantes
|--------------------------------------------------------------------------
*/

define( 'FPL_VERSION', '0.2.0' );
define( 'FPL_PLUGIN_FILE', __FILE__ );
define( 'FPL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FPL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FPL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

define( 'FPL_MIN_PHP', '8.1' );
define( 'FPL_MIN_WP', '6.4' );
define( 'FPL_MIN_WC', '8.0' );

/*
|--------------------------------------------------------------------------
| Autoload
|--------------------------------------------------------------------------
|
| Composer si disponible (il apportera Dompdf au lot 3), sinon repli sur un
| autoloader PSR-4 maison. Le plugin doit rester activable sur un site où
| `composer install` n'a jamais été lancé : c'est ce qui permet de le livrer
| en ZIP sans étape de build.
|
*/

if ( is_readable( FPL_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once FPL_PLUGIN_DIR . 'vendor/autoload.php';
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Lumia\\FacturePDF\\';

		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = FPL_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/*
|--------------------------------------------------------------------------
| Compatibilité HPOS
|--------------------------------------------------------------------------
|
| Doit être déclaré sur `before_woocommerce_init`, avant toute autre
| initialisation. C'est la seule fenêtre où WooCommerce écoute.
|
*/

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			FPL_PLUGIN_FILE,
			true
		);

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			FPL_PLUGIN_FILE,
			true
		);
	}
);

/*
|--------------------------------------------------------------------------
| Démarrage
|--------------------------------------------------------------------------
|
| Priorité 5 sur `plugins_loaded` : après WooCommerce (qui se charge à 0),
| avant les extensions tierces qui utilisent la priorité par défaut.
|
*/

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! Support\Requirements::met() ) {
			add_action( 'admin_notices', array( Support\Requirements::class, 'render_notice' ) );

			return;
		}

		Plugin::instance()->boot();
	},
	5
);

/*
|--------------------------------------------------------------------------
| Activation / désactivation
|--------------------------------------------------------------------------
*/

register_activation_hook(
	__FILE__,
	static function (): void {
		require_once FPL_PLUGIN_DIR . 'src/Support/Requirements.php';
		require_once FPL_PLUGIN_DIR . 'src/Support/Installer.php';

		if ( ! Support\Requirements::met( true ) ) {
			deactivate_plugins( FPL_PLUGIN_BASENAME );

			wp_die(
				esc_html( Support\Requirements::failure_message() ),
				esc_html__( 'Activation impossible', 'facture-pdf-lumia' ),
				array( 'back_link' => true )
			);
		}

		Support\Installer::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		require_once FPL_PLUGIN_DIR . 'src/Support/Installer.php';

		Support\Installer::deactivate();
	}
);
