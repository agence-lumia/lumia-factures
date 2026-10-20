<?php
/**
 * Invoice settings schema: defaults and sanitizer.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Settings;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Emails\EmailIds;
use Lumia\Factures\Modules\Invoices\Numbering\NumberFormat;

/**
 * Single source of truth of the invoice settings.
 *
 * Settings are grouped (general, company, numbering, credit_notes, content, design), and the
 * stored shape equals the form shape. Keys follow the legacy 1.x schema so that
 * stored settings can be mapped one to one. Label defaults are stored empty:
 * the translated default is resolved at render time.
 */
final class Schema {

	public const GROUPS = [ 'general', 'company', 'numbering', 'credit_notes', 'content', 'design' ];

	/**
	 * Numbered document types, and the group holding their numbering policy (`format`,
	 * `start_number`, `reset_enabled`, `reset_period`).
	 */
	public const NUMBERED = [
		'invoice'     => 'numbering',
		'credit_note' => 'credit_notes',
	];

	/**
	 * Ids of the WooCommerce refund email. WooCommerce renames it `customer_partially_refunded_order`
	 * while it sends a partial refund (see EmailIds). It carries the credit note, never the invoice.
	 */
	public const REFUND_EMAILS = [ EmailIds::REFUND, EmailIds::PARTIAL_REFUND ];

	/**
	 * Keys of a numbering policy: reverted together when the policy is refused.
	 */
	private const POLICY_KEYS = [ 'format', 'reset_enabled', 'reset_period' ];

	/**
	 * Field definitions, cached.
	 *
	 * @var array<string, array<string, array<string, mixed>>>|null
	 */
	private static ?array $fields = null;

	/**
	 * Default values, keyed by group.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		$defaults = [];

		foreach ( self::fields() as $group => $fields ) {
			foreach ( $fields as $key => $field ) {
				$defaults[ $group ][ $key ] = $field['default'];
			}
		}

		return $defaults;
	}

	/**
	 * Sanitizes a (possibly partial) settings payload.
	 *
	 * A key absent from $input keeps its $current value; a present key is
	 * sanitized; unknown groups and keys are dropped. $current is assumed valid,
	 * and its number formats are the ones the documents were issued with (see
	 * separate_series()). A refused numbering policy (format, reset_enabled,
	 * reset_period) keeps its current value and adds an error; the other fields
	 * are saved.
	 *
	 * @param array<string, mixed> $input   Raw payload, already unslashed.
	 * @param array<string, mixed> $current Current valid settings.
	 * @return array{settings: array<string, array<string, mixed>>, errors: string[]}
	 */
	public static function sanitize( array $input, array $current ): array {
		$settings = self::defaults();
		$errors   = [];

		foreach ( self::fields() as $group => $fields ) {
			$posted = isset( $input[ $group ] ) && is_array( $input[ $group ] ) ? $input[ $group ] : [];
			$stored = isset( $current[ $group ] ) && is_array( $current[ $group ] ) ? $current[ $group ] : [];

			foreach ( $fields as $key => $field ) {
				$previous = array_key_exists( $key, $stored ) ? $stored[ $key ] : $field['default'];

				$settings[ $group ][ $key ] = array_key_exists( $key, $posted )
					? self::sanitize_field( $field, $posted[ $key ], $previous )
					: $previous;
			}
		}

		// Each numbering policy is checked on its own: a refused one goes back to its current value.
		foreach ( self::NUMBERED as $type => $group ) {
			$policy    = $settings[ $group ];
			$validated = NumberFormat::validate( (string) $policy['format'], (bool) $policy['reset_enabled'], (string) $policy['reset_period'] );

			if ( true !== $validated ) {
				if ( 'credit_note' === $type ) {
					/* translators: %s: why the credit note number format is refused. */
					$validated = sprintf( __( 'Credit note number: %s', 'lumia-factures' ), $validated );
				}

				$errors[] = $validated;
				$settings = self::revert_policy( $settings, $group, $current );
			}
		}

		// Then the two series must never print the same number.
		$settings = self::separate_series( $settings, $current, $errors );

		return [
			'settings' => $settings,
			'errors'   => $errors,
		];
	}

	/**
	 * Keeps the invoice and credit note series from ever printing the same number.
	 *
	 * First each type whose prefix changes must leave alone the numbers of the other type
	 * (prefix_is_free()): a refused one reverts on its own. Then the two formats being saved must
	 * have distinct prefixes (NumberFormat::prefixes_differ()): the credit note policy goes back
	 * first, and the invoice policy too when the conflict came from the invoice format alone.
	 * One error at most, about the formats posted.
	 *
	 * @param array<string, array<string, mixed>> $settings Settings being sanitized.
	 * @param array<string, mixed>                $current  Current valid settings.
	 * @param string[]                            $errors   Errors, appended to.
	 * @return array<string, array<string, mixed>>
	 */
	private static function separate_series( array $settings, array $current, array &$errors ): array {
		$invoice_free = self::prefix_is_free( 'invoice', $settings, $current );
		$credit_free  = self::prefix_is_free( 'credit_note', $settings, $current );

		if ( ! self::prefixes_differ( $settings ) ) {
			$errors[] = __( 'The credit note number format must start with a prefix that differs from the invoice number format, e.g. AV- and FA-.', 'lumia-factures' );
		} elseif ( ! $invoice_free || ! $credit_free ) {
			$errors[] = __( 'A number format cannot take a prefix that the other document type uses or has already printed (swapping FA- and AV-, for example): an invoice and a credit note would share a number.', 'lumia-factures' );
		}

		if ( ! $invoice_free ) {
			$settings = self::revert_policy( $settings, self::NUMBERED['invoice'], $current );
		}

		if ( ! $credit_free ) {
			$settings = self::revert_policy( $settings, self::NUMBERED['credit_note'], $current );
		}

		if ( ! self::prefixes_differ( $settings ) ) {
			$settings = self::revert_policy( $settings, self::NUMBERED['credit_note'], $current );

			if ( ! self::prefixes_differ( $settings ) ) {
				$settings = self::revert_policy( $settings, self::NUMBERED['invoice'], $current );
			}
		}

		return $settings;
	}

	/**
	 * Whether the prefix of a type's new format leaves alone the numbers of the other type.
	 *
	 * Comparing the two formats being saved is not enough: Module::carry_counter() keeps each
	 * series going, so after a swap of the prefixes (FA- and AV-) the next credit note would print
	 * the number of an issued invoice, and Pipeline::number() would refuse value after value. A
	 * prefix that changes must therefore also differ from the other type's current format, and no
	 * number the register holds for the other type (printed by an earlier format) may start the way
	 * the new numbers do. LIKE follows the collation of the register, as prefixes_differ() folds.
	 * An unchanged prefix is not checked: the current settings are assumed valid.
	 *
	 * @param string                              $type     Numbered type, a key of NUMBERED.
	 * @param array<string, array<string, mixed>> $settings Settings being sanitized.
	 * @param array<string, mixed>                $current  Current valid settings.
	 */
	private static function prefix_is_free( string $type, array $settings, array $current ): bool {
		$other  = 'invoice' === $type ? 'credit_note' : 'invoice';
		$format = (string) $settings[ self::NUMBERED[ $type ] ]['format'];

		if ( NumberFormat::prefix( $format ) === NumberFormat::prefix( self::current_format( $current, $type ) ) ) {
			return true;
		}

		$other_format = self::current_format( $current, $other );
		$apart        = 'invoice' === $type
			? NumberFormat::prefixes_differ( $format, $other_format )
			: NumberFormat::prefixes_differ( $other_format, $format );

		return $apart && ! Register::number_starts_with( $other, ...NumberFormat::number_starts( $format ) );
	}

	/**
	 * Number format of a type in the current settings (the default when they have none).
	 *
	 * @param array<string, mixed> $current Current valid settings.
	 * @param string               $type    Numbered type, a key of NUMBERED.
	 */
	private static function current_format( array $current, string $type ): string {
		$group  = self::NUMBERED[ $type ];
		$stored = isset( $current[ $group ] ) && is_array( $current[ $group ] ) ? $current[ $group ] : [];

		return (string) ( $stored['format'] ?? self::fields()[ $group ]['format']['default'] );
	}

	/**
	 * Whether the invoice and credit note formats of a settings array have distinct prefixes.
	 *
	 * @param array<string, array<string, mixed>> $settings Settings.
	 */
	private static function prefixes_differ( array $settings ): bool {
		return NumberFormat::prefixes_differ(
			(string) $settings[ self::NUMBERED['invoice'] ]['format'],
			(string) $settings[ self::NUMBERED['credit_note'] ]['format']
		);
	}

	/**
	 * Puts a numbering policy back to its current value (the default when it has none).
	 *
	 * @param array<string, array<string, mixed>> $settings Settings being sanitized.
	 * @param string                              $group    Group of the policy.
	 * @param array<string, mixed>                $current  Current valid settings.
	 * @return array<string, array<string, mixed>>
	 */
	private static function revert_policy( array $settings, string $group, array $current ): array {
		$stored = isset( $current[ $group ] ) && is_array( $current[ $group ] ) ? $current[ $group ] : [];

		foreach ( self::POLICY_KEYS as $key ) {
			$settings[ $group ][ $key ] = $stored[ $key ] ?? self::fields()[ $group ][ $key ]['default'];
		}

		return $settings;
	}

	/**
	 * Sanitizes one value according to its definition.
	 *
	 * @param array<string, mixed> $field    Field definition.
	 * @param mixed                $value    Raw value.
	 * @param mixed                $previous Value kept when the input is unusable.
	 * @return mixed
	 */
	private static function sanitize_field( array $field, $value, $previous ) {
		switch ( $field['type'] ) {
			case 'bool':
				return in_array( $value, [ true, 1, '1', 'true', 'on', 'yes' ], true );

			case 'int':
			case 'float':
				if ( ! is_numeric( $value ) ) {
					return $previous;
				}
				$number = (float) $value;
				$number = max( (float) $field['min'], min( (float) $field['max'], $number ) );
				return 'int' === $field['type'] ? (int) round( $number ) : $number;

			case 'color':
				$color = is_string( $value ) ? sanitize_hex_color( trim( $value ) ) : null;
				return null === $color ? $previous : $color;

			case 'select':
				return is_string( $value ) && in_array( $value, $field['options'], true ) ? $value : $previous;

			case 'textarea':
				// Browsers post line breaks as CRLF: stored as LF so a saved form round-trips unchanged.
				return is_scalar( $value ) ? sanitize_textarea_field( str_replace( [ "\r\n", "\r" ], "\n", (string) $value ) ) : $previous;

			case 'statuses':
				return self::sanitize_list( $value, self::status_options() );

			case 'emails':
				return self::sanitize_list( $value, self::email_options( (string) ( $field['scope'] ?? 'all' ) ) );

			case 'text':
			default:
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $previous;
		}
	}

	/**
	 * Keeps the known entries of a list, dropping empty strings and duplicates.
	 *
	 * @param mixed    $value   Raw value.
	 * @param string[] $allowed Allowed entries.
	 * @return string[]
	 */
	private static function sanitize_list( $value, array $allowed ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$clean = [];

		foreach ( $value as $entry ) {
			if ( is_string( $entry ) && '' !== $entry && in_array( $entry, $allowed, true ) ) {
				$clean[] = $entry;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Order status slugs, without the `wc-` prefix.
	 *
	 * @return string[]
	 */
	public static function status_options(): array {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return [];
		}

		return array_map(
			static fn( $slug ): string => (string) preg_replace( '/^wc-/', '', (string) $slug ),
			array_keys( wc_get_order_statuses() )
		);
	}

	/**
	 * WooCommerce email ids, canonical (EmailIds::normalize()).
	 *
	 * The ids are read from the email objects, and WooCommerce renames the refund email object
	 * `customer_partially_refunded_order` for the rest of the request once it sent a partial refund:
	 * read as is, the stored `customer_refunded_order` would be dropped by the next save.
	 *
	 * @param string $scope `all`, `invoice` (every email but the refund one) or `refund` (the refund
	 *                      email only, see REFUND_EMAILS).
	 * @return string[]
	 */
	public static function email_options( string $scope = 'all' ): array {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return [];
		}

		$ids = [];

		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( is_object( $email ) && ! empty( $email->id ) ) {
				$ids[] = EmailIds::normalize( (string) $email->id );
			}
		}

		$ids = array_values( array_unique( $ids ) );

		if ( 'invoice' === $scope ) {
			return array_values( array_diff( $ids, self::REFUND_EMAILS ) );
		}

		if ( 'refund' === $scope ) {
			return array_values( array_intersect( $ids, self::REFUND_EMAILS ) );
		}

		return $ids;
	}

	/**
	 * Field definitions, keyed by group then key.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function fields(): array {
		if ( null === self::$fields ) {
			self::$fields = [
				'general'      => self::general(),
				'company'      => self::company(),
				'numbering'    => self::numbering(),
				'credit_notes' => self::credit_notes(),
				'content'      => self::content(),
				'design'       => self::design(),
			];
		}

		return self::$fields;
	}

	/**
	 * Builds a field definition.
	 *
	 * @param string               $type    Field type.
	 * @param mixed                $value Default value.
	 * @param array<string, mixed> $extra   min, max, options.
	 * @return array<string, mixed>
	 */
	private static function field( string $type, $value, array $extra = [] ): array {
		return array_merge(
			[
				'type'    => $type,
				'default' => $value,
			],
			$extra
		);
	}

	/**
	 * Group: general.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function general(): array {
		return [
			'trigger_statuses'  => self::field( 'statuses', [ 'processing', 'completed' ] ),
			'email_attachments' => self::field( 'emails', [ 'customer_processing_order', 'customer_completed_order', 'customer_invoice' ], [ 'scope' => 'invoice' ] ),
		];
	}

	/**
	 * Adds a `{key}_enabled` toggle and a `{key}` text for each entry.
	 *
	 * @param array<string, bool> $entries Key => enabled by default.
	 * @return array<string, array<string, mixed>>
	 */
	private static function toggled_texts( array $entries ): array {
		$fields = [];

		foreach ( $entries as $key => $enabled ) {
			$fields[ $key . '_enabled' ] = self::field( 'bool', $enabled );
			$fields[ $key ]              = self::field( 'text', '' );
		}

		return $fields;
	}

	/**
	 * Group: company.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function company(): array {
		return array_merge(
			[
				'logo_enabled' => self::field( 'bool', true ),
				'logo_id'      => self::field(
					'int',
					0,
					[
						'min' => 0,
						'max' => PHP_INT_MAX,
					]
				),
				'logo_width'   => self::field(
					'int',
					180,
					[
						'min' => 40,
						'max' => 500,
					]
				),
			],
			self::toggled_texts(
				[
					'display_name' => true,
					'legal_name'   => true,
					'legal_form'   => false,
					'manager'      => true,
				]
			),
			[
				'address_enabled' => self::field( 'bool', true ),
				'address'         => self::field( 'textarea', '' ),
			],
			self::toggled_texts(
				[
					'phone'   => true,
					'mobile'  => true,
					'email'   => false,
					'website' => false,
				]
			),
			self::toggled_texts(
				[
					'siret'         => true,
					'rcs'           => false,
					'vat_number'    => false,
					'share_capital' => false,
					'ape_code'      => false,
					'iban'          => false,
					'bic'           => false,
				]
			)
		);
	}

	/**
	 * Group: numbering.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function numbering(): array {
		return self::numbering_policy( 'FA-{year}-{number:5}' );
	}

	/**
	 * Fields of a numbering policy.
	 *
	 * @param string $format Default format.
	 * @return array<string, array<string, mixed>>
	 */
	private static function numbering_policy( string $format ): array {
		return [
			'format'        => self::field( 'text', $format ),
			'start_number'  => self::field(
				'int',
				1,
				[
					'min' => 1,
					'max' => 999999999,
				]
			),
			'reset_enabled' => self::field( 'bool', true ),
			'reset_period'  => self::field( 'select', 'yearly', [ 'options' => [ 'yearly', 'monthly' ] ] ),
		];
	}

	/**
	 * Group: credit_notes. Its own number series (the prefix must differ from the invoice one, see
	 * sanitize()), title, refund reason and emails. There is no on/off switch: every refund of an
	 * invoiced order gets a credit note.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function credit_notes(): array {
		return array_merge(
			self::numbering_policy( 'AV-{year}-{number:5}' ),
			[
				'title'             => self::field( 'text', '' ),
				'reason_enabled'    => self::field( 'bool', true ),
				'email_attachments' => self::field( 'emails', [ 'customer_refunded_order' ], [ 'scope' => 'refund' ] ),
			]
		);
	}

	/**
	 * Group: content.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function content(): array {
		$fields = [
			'invoice_title'               => self::field( 'text', '' ),
			'price_display'               => self::field( 'select', 'incl', [ 'options' => [ 'incl', 'excl' ] ] ),

			'billing_title'               => self::field( 'text', '' ),
			'billing_address_enabled'     => self::field( 'bool', true ),
			'billing_email_enabled'       => self::field( 'bool', true ),
			'billing_phone_enabled'       => self::field( 'bool', true ),

			'shipping_enabled'            => self::field( 'bool', true ),
			'shipping_title'              => self::field( 'text', '' ),
			'shipping_hide_if_same'       => self::field( 'bool', true ),
			'shipping_hide_virtual'       => self::field( 'bool', true ),
			'shipping_phone_enabled'      => self::field( 'bool', false ),

			'meta_invoice_number_enabled' => self::field( 'bool', true ),
			'meta_invoice_date_enabled'   => self::field( 'bool', true ),
			'meta_order_number_enabled'   => self::field( 'bool', true ),
			'meta_order_date_enabled'     => self::field( 'bool', true ),
			'meta_payment_method_enabled' => self::field( 'bool', true ),

			'message_enabled'             => self::field( 'bool', true ),
			'message_title'               => self::field( 'text', '' ),
			'message_body'                => self::field( 'textarea', '' ),
		];

		// Product table columns: key => [enabled, width].
		$columns = [
			'image'       => [ true, 8 ],
			'name'        => [ true, 22 ],
			'description' => [ true, 30 ],
			'sku'         => [ false, 10 ],
			'quantity'    => [ true, 6 ],
			'unit_price'  => [ true, 12 ],
			'tax_rate'    => [ true, 8 ],
			'total'       => [ true, 14 ],
		];

		foreach ( $columns as $key => $column ) {
			$fields[ 'col_' . $key . '_enabled' ] = self::field( 'bool', $column[0] );
			$fields[ 'col_' . $key . '_label' ]   = self::field( 'text', '' );
			$fields[ 'col_' . $key . '_width' ]   = self::field(
				'int',
				$column[1],
				[
					'min' => 2,
					'max' => 60,
				]
			);
		}

		$fields += [
			'item_meta_enabled'           => self::field( 'bool', true ),
			'item_parent_name_enabled'    => self::field( 'bool', true ),

			'total_subtotal_enabled'      => self::field( 'bool', true ),
			'total_discount_enabled'      => self::field( 'bool', true ),
			'total_discount_code_enabled' => self::field( 'bool', true ),
			'total_shipping_enabled'      => self::field( 'bool', true ),
			'total_shipping_always'       => self::field( 'bool', false ),
			'total_fees_enabled'          => self::field( 'bool', true ),
			'total_tax_enabled'           => self::field( 'bool', true ),
			'total_grand_enabled'         => self::field( 'bool', true ),

			'customer_note_enabled'       => self::field( 'bool', true ),
			'customer_note_label'         => self::field( 'text', '' ),

			'footer_text'                 => self::field( 'textarea', '' ),
			'footer_pagination_enabled'   => self::field( 'bool', true ),
		];

		$footer_items = [ 'phone', 'mobile', 'email', 'website', 'siret', 'rcs', 'vat_number', 'share_capital', 'ape_code', 'iban', 'bic' ];

		foreach ( $footer_items as $key ) {
			$fields[ 'footer_' . $key . '_enabled' ] = self::field( 'bool', in_array( $key, [ 'phone', 'mobile', 'siret' ], true ) );
		}

		$fields['legal_text'] = self::field( 'textarea', '' );

		return $fields;
	}

	/**
	 * Group: design.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function design(): array {
		$fields = [];

		$colors = [
			'color_primary'    => '#111111',
			'color_text'       => '#1a1a1a',
			'color_muted'      => '#6b6b6b',
			'color_thead_bg'   => '#111111',
			'color_thead_text' => '#ffffff',
			'color_row_alt'    => '#fafafa',
			'color_block_bg'   => '#f7f7f7',
			'color_border'     => '#dddddd',
			'color_page'       => '#ffffff',
		];

		foreach ( $colors as $key => $color ) {
			$fields[ $key ] = self::field( 'color', $color );
		}

		$fonts = [ 'inter', 'dejavu-sans' ];

		$fields['font_heading'] = self::field( 'select', 'inter', [ 'options' => $fonts ] );
		$fields['font_body']    = self::field( 'select', 'inter', [ 'options' => $fonts ] );

		$numbers = [
			'font_size'     => [ 'float', 9.5, 7, 14 ],
			'heading_scale' => [ 'float', 1.6, 1.1, 2.4 ],
			'line_height'   => [ 'float', 1.45, 1.1, 2 ],
			'radius'        => [ 'int', 4, 0, 20 ],
			'border_width'  => [ 'float', 0.6, 0, 3 ],
			'thumb_width'   => [ 'int', 46, 0, 100 ],
			'margin_top'    => [ 'int', 18, 5, 40 ],
			'margin_bottom' => [ 'int', 26, 12, 50 ],
			'margin_sides'  => [ 'int', 16, 5, 40 ],
		];

		foreach ( $numbers as $key => $number ) {
			$fields[ $key ] = self::field(
				$number[0],
				$number[1],
				[
					'min' => $number[2],
					'max' => $number[3],
				]
			);
		}

		$fields['heading_bold'] = self::field( 'bool', true );
		$fields['density']      = self::field( 'select', 'normal', [ 'options' => [ 'compact', 'normal', 'airy' ] ] );

		return $fields;
	}
}
