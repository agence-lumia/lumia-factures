<?php
/**
 * Invoice number format rules.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Numbering;

defined( 'ABSPATH' ) || exit;

/**
 * Number format: tags, validation (rendering is added with the register).
 */
final class NumberFormat {

	/**
	 * Matches a tag: `{name}` or `{name:argument}`.
	 */
	public const TAG_PATTERN = '/\{([a-z_]+)(?::([^}]*))?\}/';

	/**
	 * Names of the tags a format may use.
	 *
	 * @return string[]
	 */
	public static function known_tags(): array {
		$tags = [ 'number', 'year', 'month', 'day', 'order_number', 'order_id', 'customer_id' ];

		/**
		 * Filters the tag names accepted in a number format.
		 *
		 * @param string[] $tags Tag names, without braces.
		 */
		$filtered = apply_filters( 'lumia_factures_number_tags', $tags );

		return is_array( $filtered ) ? array_values( array_map( 'strval', $filtered ) ) : $tags;
	}

	/**
	 * Validates a number format against the reset policy.
	 *
	 * @param string $format        Format, e.g. `FA-{year}-{number:5}`.
	 * @param bool   $reset_enabled Whether the counter restarts each period.
	 * @param string $reset_period  `yearly` or `monthly`.
	 * @return true|string True when valid, an error message otherwise.
	 */
	public static function validate( string $format, bool $reset_enabled, string $reset_period ) {
		$format = trim( $format );

		if ( '' === $format ) {
			return __( 'The format cannot be empty.', 'lumia-factures' );
		}

		if ( ! preg_match( '/\{number(?::\d+)?\}/', $format ) ) {
			return __( 'The format must contain the {number} tag, otherwise several invoices would share the same number.', 'lumia-factures' );
		}

		// A misspelled tag would render as an empty string and go unnoticed until the first invoice.
		$known   = self::known_tags();
		$unknown = [];

		if ( preg_match_all( self::TAG_PATTERN, $format, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				if ( ! in_array( $tag, $known, true ) && ! in_array( '{' . $tag . '}', $unknown, true ) ) {
					$unknown[] = '{' . $tag . '}';
				}
			}
		}

		if ( [] !== $unknown ) {
			return sprintf(
				/* translators: %s: list of unknown tags */
				__( 'Unknown tag: %s. Check the spelling against the list of available tags.', 'lumia-factures' ),
				implode( ', ', $unknown )
			);
		}

		$rendered = preg_replace( self::TAG_PATTERN, '', $format );

		if ( preg_match( '#[/\\\\:*?"<>|]#', (string) $rendered ) ) {
			return __( 'The format contains a character that is forbidden in a file name ( / \\ : * ? " < > | ).', 'lumia-factures' );
		}

		// Without the period in the number, two periods would print the same numbers.
		if ( $reset_enabled ) {
			$has_year = (bool) preg_match( '/\{year(?::[^}]*)?\}/', $format );

			if ( ! $has_year ) {
				return __( 'With a periodic reset, the format must contain {year}, otherwise two periods would print the same numbers.', 'lumia-factures' );
			}

			if ( 'monthly' === $reset_period && ! preg_match( '/\{month(?::[^}]*)?\}/', $format ) ) {
				return __( 'With a monthly reset, the format must contain {year} and {month}, otherwise two months would print the same numbers.', 'lumia-factures' );
			}
		}

		return true;
	}
}
