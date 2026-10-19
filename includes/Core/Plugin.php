<?php
/**
 * Main plugin class.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 * Singleton pattern to guarantee a single instance.
 */
class Plugin {

	/**
	 * Unique plugin instance.
	 */
	private static ?Plugin $instance = null;

	/**
	 * Modules manager.
	 */
	private Modules $modules;

	/**
	 * Settings manager.
	 */
	private Settings $settings;

	/**
	 * Blocking errors found by the last boot (reason => message).
	 *
	 * @var array<string, string>
	 */
	private array $requirement_errors = [];

	/**
	 * Private constructor (Singleton).
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->modules  = new Modules( $this->settings );

		$this->init();
	}

	/**
	 * Returns the unique plugin instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Modules manager.
	 */
	public function modules(): Modules {
		return $this->modules;
	}

	/**
	 * Settings manager.
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Initializes the plugin.
	 *
	 * Translations and modules wait for `init`, to avoid the just-in-time
	 * translation warning of WP 6.7+. The requirements are checked there too:
	 * every plugin (WooCommerce, the legacy plugin) is loaded by then.
	 */
	private function init(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'init', [ $this, 'boot' ] );
	}

	/**
	 * Init hook: starts the modules, or shows why they cannot start.
	 */
	public function boot(): void {
		$this->requirement_errors = Requirements::check();

		if ( ! empty( $this->requirement_errors ) ) {
			add_action( 'admin_notices', [ $this, 'render_requirements_notice' ] );
			return;
		}

		$this->modules->register_default_modules();
		$this->modules->init_active_modules();
	}

	/**
	 * Blocking errors found by the last boot (reason => message).
	 *
	 * @return array<string, string>
	 */
	public function requirement_errors(): array {
		return $this->requirement_errors;
	}

	/**
	 * Notice listing why the modules did not start.
	 */
	public function render_requirements_notice(): void {
		if ( empty( $this->requirement_errors ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$items = '';
		foreach ( $this->requirement_errors as $message ) {
			$items .= '<li>' . esc_html( $message ) . '</li>';
		}

		wp_admin_notice(
			'<p><strong>' . esc_html__( 'Lümia Factures is not running:', 'lumia-factures' ) . '</strong></p><ul>' . $items . '</ul>',
			[
				'type'               => 'error',
				'id'                 => 'lumia-factures-requirements-notice',
				'paragraph_wrap'     => false,
				'additional_classes' => [ 'lumia-factures-requirements' ],
			]
		);
	}

	/**
	 * Loads the translation text domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'lumia-factures',
			false,
			dirname( plugin_basename( LUMIA_FACTURES_PLUGIN_FILE ) ) . '/languages/'
		);
	}
}
