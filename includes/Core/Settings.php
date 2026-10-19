<?php
/**
 * Global settings of the plugin.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the plugin's global settings.
 *
 * Unlike lumia-tools, no copy of the option is kept on the instance: the
 * option is read through get_option() every time (WordPress already caches it
 * in alloptions). A private copy went stale as soon as the option was written
 * elsewhere (Activator, another instance, a rolled-back test transaction).
 */
class Settings {

	/**
	 * WordPress option key.
	 */
	public const OPTION_KEY = 'lumia_factures_settings';

	/**
	 * Gets all the settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_all(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Gets a setting value.
	 *
	 * @param string $key      Setting key (can be nested with dots).
	 * @param mixed  $fallback Default value.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		$settings = $this->get_all();

		// Nested keys support (e.g. "modules.bricks").
		$keys  = explode( '.', $key );
		$value = $settings;

		foreach ( $keys as $k ) {
			if ( ! is_array( $value ) || ! array_key_exists( $k, $value ) ) {
				return $fallback;
			}
			$value = $value[ $k ];
		}

		return $value;
	}

	/**
	 * Updates a setting value.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 */
	public function set( string $key, $value ): bool {
		$settings = $this->get_all();

		// Nested keys support.
		$keys   = explode( '.', $key );
		$target = &$settings;

		foreach ( $keys as $k ) {
			if ( ! is_array( $target ) ) {
				$target = [];
			}
			if ( ! array_key_exists( $k, $target ) ) {
				$target[ $k ] = [];
			}
			$target = &$target[ $k ];
		}

		$target = $value;
		unset( $target );

		return update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Updates several settings at once.
	 *
	 * @param array<string, mixed> $data Settings array.
	 */
	public function update( array $data ): bool {
		$settings = $this->merge_recursive( $this->get_all(), $data );

		return update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Merges two arrays recursively, replacing values.
	 *
	 * @param array<string, mixed> $base    Current values.
	 * @param array<string, mixed> $updates New values.
	 * @return array<string, mixed>
	 */
	private function merge_recursive( array $base, array $updates ): array {
		foreach ( $updates as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = $this->merge_recursive( $base[ $key ], $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}
}
