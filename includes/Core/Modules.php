<?php
/**
 * Module registry.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles module registration and loading.
 *
 * A module defined with `'locked' => true` is part of the plugin core: it is
 * always active, whatever the stored state, and cannot be deactivated.
 */
class Modules {

	/**
	 * List of registered modules.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $registered = [];

	/**
	 * Instances of the active modules.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $active = [];

	/**
	 * Settings manager.
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings manager.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Built-in module definitions, before the `lumia_factures_module_definitions` filter.
	 *
	 * The single source of the definitions: the registry and the Activator
	 * (initial state of the locked modules) both read it. Translates labels, so
	 * it must be called on `init` or later.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function default_definitions(): array {
		return [
			'invoices' => [
				'name'        => __( 'Invoices', 'lumia-factures' ),
				'description' => __( 'PDF invoices for WooCommerce orders.', 'lumia-factures' ),
				'class'       => 'Lumia\\Factures\\Modules\\Invoices\\Module',
				'icon'        => 'file-text',
				'locked'      => true,
			],
			'bricks'   => [
				'name'        => __( 'Bricks', 'lumia-factures' ),
				'description' => __( 'Dynamic tags to display invoice data in Bricks.', 'lumia-factures' ),
				'class'       => 'Lumia\\Factures\\Modules\\Bricks\\Module',
				'icon'        => 'blocks',
				// Always on: its hooks are Bricks filters, which only fire on a Bricks site.
				'locked'      => true,
			],
		];
	}

	/**
	 * Registers the default modules through a filter plus an imperative hook.
	 * Must be called on the init hook or later (just-in-time i18n of WP 6.7+).
	 *
	 * @param bool $only_active Skip the inactive modules (locked ones are always registered).
	 */
	public function register_default_modules( bool $only_active = false ): void {
		$defaults = self::default_definitions();

		/**
		 * Allows adding/overriding modules from other plugins/themes.
		 * Format: [ 'module_id' => [ 'name' => ..., 'class' => ..., ... ] ]
		 *
		 * @param array<string, array<string, mixed>> $defaults Built-in module definitions.
		 */
		$definitions = apply_filters( 'lumia_factures_module_definitions', $defaults );

		if ( ! is_array( $definitions ) ) {
			$definitions = $defaults;
		}

		foreach ( $definitions as $id => $args ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $args ) ) {
				continue;
			}

			$args = $this->normalize_definition( $args );
			if ( empty( $args['class'] ) ) {
				continue;
			}

			if ( $only_active && ! $args['locked'] && ! $this->is_active( $id ) ) {
				continue;
			}

			$this->register( $id, $args );
		}

		/**
		 * Imperative hook to register modules through $modules->register(...).
		 *
		 * @param Modules $modules     Module registry.
		 * @param bool    $only_active Whether only the active modules are registered.
		 */
		do_action( 'lumia_factures_register_modules', $this, $only_active );
	}

	/**
	 * Default keys of a module definition.
	 *
	 * @return array<string, mixed>
	 */
	private static function definition_defaults(): array {
		return [
			'name'        => '',
			'description' => '',
			'class'       => '',
			'icon'        => 'package',
			'locked'      => false,
		];
	}

	/**
	 * Normalizes a module definition.
	 *
	 * @param array<string, mixed> $args Raw definition.
	 * @return array<string, mixed>
	 */
	private function normalize_definition( array $args ): array {
		$normalized = wp_parse_args( $args, self::definition_defaults() );

		$normalized['name']        = is_string( $normalized['name'] ) ? $normalized['name'] : '';
		$normalized['description'] = is_string( $normalized['description'] ) ? $normalized['description'] : '';
		$normalized['class']       = is_string( $normalized['class'] ) ? ltrim( $normalized['class'], '\\' ) : '';
		$normalized['icon']        = is_string( $normalized['icon'] ) ? sanitize_key( $normalized['icon'] ) : 'package';
		$normalized['locked']      = (bool) $normalized['locked'];

		return $normalized;
	}

	/**
	 * Registers a module.
	 *
	 * @param string               $id   Module identifier.
	 * @param array<string, mixed> $args Module definition.
	 */
	public function register( string $id, array $args ): void {
		$this->registered[ $id ] = wp_parse_args( $args, self::definition_defaults() );
	}

	/**
	 * Returns all the registered modules.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all(): array {
		return $this->registered;
	}

	/**
	 * Returns a specific module.
	 *
	 * @param string $id Module identifier.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->registered[ $id ] ?? null;
	}

	/**
	 * Whether a module is part of the core (always active, cannot be deactivated).
	 *
	 * @param string $id Module identifier.
	 */
	public function is_locked( string $id ): bool {
		return ! empty( $this->registered[ $id ]['locked'] );
	}

	/**
	 * Checks whether a module is active.
	 *
	 * @param string $id Module identifier.
	 */
	public function is_active( string $id ): bool {
		if ( $this->is_locked( $id ) ) {
			return true;
		}

		return (bool) $this->settings->get( "modules.{$id}", false );
	}

	/**
	 * Activates a module and calls its on_activate() hook.
	 *
	 * An already reached state is a success, not a failure: update_option()
	 * returns false when the value does not change.
	 *
	 * @param string $id Module identifier.
	 */
	public function activate( string $id ): bool {
		if ( ! isset( $this->registered[ $id ] ) ) {
			return false;
		}

		if ( $this->is_active( $id ) ) {
			return true;
		}

		$result = $this->settings->set( "modules.{$id}", true );

		if ( $result ) {
			$instance = $this->make_instance( $id );
			if ( $instance ) {
				$instance->on_activate();
			}
		}

		return $result;
	}

	/**
	 * Deactivates a module and calls its on_deactivate() hook.
	 *
	 * A locked module is never deactivated: the call is a no-op returning false.
	 * See activate(): an already reached state is a success.
	 *
	 * @param string $id Module identifier.
	 */
	public function deactivate( string $id ): bool {
		if ( ! isset( $this->registered[ $id ] ) || $this->is_locked( $id ) ) {
			return false;
		}

		if ( ! $this->is_active( $id ) ) {
			return true;
		}

		// Use the active instance when available, otherwise create a temporary one.
		$instance = $this->active[ $id ] ?? $this->make_instance( $id );

		$result = $this->settings->set( "modules.{$id}", false );

		if ( $result ) {
			unset( $this->active[ $id ] );
			if ( $instance ) {
				$instance->on_deactivate();
			}
		}

		return $result;
	}

	/**
	 * Instantiates and initializes all the active modules.
	 */
	public function init_active_modules(): void {
		foreach ( $this->registered as $id => $module ) {
			if ( isset( $this->active[ $id ] ) || ! $this->is_active( $id ) ) {
				continue;
			}

			$instance = $this->make_instance( $id );

			if ( $instance ) {
				$instance->init();
				$this->active[ $id ] = $instance;
			}
		}
	}

	/**
	 * Returns the instances of the active modules.
	 *
	 * @return array<string, ModuleInterface>
	 */
	public function get_active_instances(): array {
		return $this->active;
	}

	/**
	 * Returns an instance of a registered module, active or not.
	 *
	 * The active instance is reused when it exists; otherwise an uninitialized
	 * instance is created.
	 *
	 * @param string $id Module identifier.
	 */
	public function get_instance( string $id ): ?ModuleInterface {
		return $this->active[ $id ] ?? $this->make_instance( $id );
	}

	/**
	 * Creates a module instance without initializing it (for the lifecycle hooks).
	 *
	 * @param string $id Module identifier.
	 */
	private function make_instance( string $id ): ?ModuleInterface {
		$class = $this->registered[ $id ]['class'] ?? '';

		if ( ! is_string( $class ) || '' === $class || ! class_exists( $class ) ) {
			return null;
		}

		$instance = new $class( $id );

		return $instance instanceof ModuleInterface ? $instance : null;
	}
}
