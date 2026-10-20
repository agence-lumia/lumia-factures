<?php
/**
 * The few WP-CLI symbols the plugin uses, for PHPStan only (php-stubs/wp-cli-stubs requires
 * wordpress-stubs < 7). Tooling file: never loaded by the plugin.
 *
 * @package Lumia\Factures
 */

// phpcs:ignoreFile -- Stubs: signatures mirror WP-CLI, not the plugin conventions.

namespace {
	class WP_CLI {
		/**
		 * @param string          $name     Command name.
		 * @param callable|string $callable Command class or callable.
		 * @param array<string, mixed> $args Registration arguments.
		 */
		public static function add_command( $name, $callable, $args = [] ): bool {
			return true;
		}

		public static function get_runner(): \WP_CLI\Runner {
			return new \WP_CLI\Runner();
		}

		/**
		 * @param string $message Message.
		 */
		public static function log( $message ): void {}

		/**
		 * @param string $message Message.
		 */
		public static function success( $message ): void {}

		/**
		 * @param string $message Message.
		 */
		public static function warning( $message ): void {}

		/**
		 * @param string    $message Message.
		 * @param bool|int  $exit    Exit code.
		 * @return never
		 */
		public static function error( $message, $exit = true ) {
			exit( 1 );
		}
	}
}

namespace WP_CLI {
	class Runner {
		/**
		 * Positional arguments of the command line.
		 *
		 * @var mixed
		 */
		public $arguments = [];
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string                    $format Output format.
	 * @param array<int, array<string, mixed>> $items  Rows.
	 * @param array<int, string>|string $fields Fields.
	 */
	function format_items( $format, $items, $fields ): void {}
}
