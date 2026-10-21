<?php
/**
 * Invoice number format rules.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Numbering;

defined( 'ABSPATH' ) || exit;

use WC_Order;

/**
 * Number format: tags, validation and rendering.
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

	/**
	 * Literal text of a format before its first tag (`FA-` for `FA-{year}-{number:5}`).
	 *
	 * @param string $format Format.
	 */
	public static function prefix( string $format ): string {
		$format = trim( $format );

		if ( preg_match( self::TAG_PATTERN, $format, $matches, PREG_OFFSET_CAPTURE ) ) {
			return substr( $format, 0, (int) $matches[0][1] );
		}

		return $format;
	}

	/**
	 * Whether the numbers of another document type can never equal the invoice numbers, judged on
	 * the literal prefixes of the two formats.
	 *
	 * The other prefix must not be empty. Behind a literal invoice prefix, neither prefix may start
	 * the other (`FA-` and `FA-AV-` could meet). An invoice format that starts with a tag prints
	 * digits first (`{year}{month}{number:6}`), so the other prefix must start with a character that
	 * is not a digit (`2026-` would meet `{year}-{number:5}`). The comparison ignores letter case and
	 * accents, like the collation of the register's unique keys.
	 *
	 * @param string $invoice_format Invoice number format.
	 * @param string $other_format   Number format of the other document type.
	 */
	public static function prefixes_differ( string $invoice_format, string $other_format ): bool {
		$invoice = self::fold( self::prefix( $invoice_format ) );
		$other   = self::fold( self::prefix( $other_format ) );

		if ( '' === $other ) {
			return false;
		}

		if ( '' === $invoice ) {
			return 1 !== preg_match( '/^\d/', $other );
		}

		return ! str_starts_with( $invoice, $other ) && ! str_starts_with( $other, $invoice );
	}

	/**
	 * What every number of a format starts with: its literal prefix, or one of the ten digits when
	 * the format starts with a tag (the core tags print digits, see prefixes_differ()).
	 *
	 * @param string $format Format.
	 * @return string[]
	 */
	public static function number_starts( string $format ): array {
		$prefix = self::prefix( $format );

		return '' !== $prefix ? [ $prefix ] : [ '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ];
	}

	/**
	 * Case and accent folding of a prefix.
	 *
	 * @param string $text Text.
	 */
	private static function fold( string $text ): string {
		$text = remove_accents( $text );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Builds an invoice number.
	 *
	 * @param string   $format    Format, e.g. `FA-{year}-{number:5}`.
	 * @param int      $sequence  Sequence value.
	 * @param WC_Order $order     Order.
	 * @param int      $timestamp UTC timestamp the date tags refer to.
	 */
	public static function build( string $format, int $sequence, WC_Order $order, int $timestamp ): string {
		return self::render( $format, $sequence, $timestamp, $order );
	}

	/**
	 * Previews a format with sample order values (order 14243, customer 27) and the current date.
	 *
	 * @param string $format   Format to preview.
	 * @param int    $sequence Sequence value to show.
	 */
	public static function preview( string $format, int $sequence ): string {
		return self::render( $format, $sequence, time(), null );
	}

	/**
	 * Replaces every tag of a format.
	 *
	 * @param string        $format    Format.
	 * @param int           $sequence  Sequence value.
	 * @param int           $timestamp UTC timestamp.
	 * @param WC_Order|null $order     Order, or null for a preview with sample values.
	 */
	private static function render( string $format, int $sequence, int $timestamp, ?WC_Order $order ): string {
		return (string) preg_replace_callback(
			self::TAG_PATTERN,
			static function ( array $matches ) use ( $sequence, $timestamp, $order ): string {
				$tag      = $matches[1];
				$argument = $matches[2] ?? '';

				switch ( $tag ) {
					case 'number':
						$padding = '' !== $argument ? absint( $argument ) : 0;

						return $padding > 0 ? str_pad( (string) $sequence, $padding, '0', STR_PAD_LEFT ) : (string) $sequence;

					case 'year':
						$year = (string) wp_date( 'Y', $timestamp );

						return '2' === $argument ? substr( $year, -2 ) : $year;

					case 'month':
						return (string) wp_date( 'm', $timestamp );

					case 'day':
						return (string) wp_date( 'd', $timestamp );

					case 'order_number':
						return null === $order ? '14243' : (string) $order->get_order_number();

					case 'order_id':
						return null === $order ? '14243' : (string) $order->get_id();

					case 'customer_id':
						return null === $order ? '27' : (string) $order->get_customer_id();

					default:
						if ( null === $order ) {
							return '';
						}

						/**
						 * Filters the value of a tag the core does not resolve.
						 *
						 * @param string   $value    Value, empty by default.
						 * @param string   $tag      Tag name.
						 * @param string   $argument Argument after the colon, if any.
						 * @param int      $sequence Sequence value.
						 * @param WC_Order $order    Order.
						 */
						return (string) apply_filters( 'lumia_factures_number_tag', '', $tag, $argument, $sequence, $order );
				}
			},
			$format
		);
	}
}
