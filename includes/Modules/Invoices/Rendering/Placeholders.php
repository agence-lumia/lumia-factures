<?php
/**
 * Placeholders of the free texts.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Rendering;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Amounts\Money;
use Lumia\Factures\Modules\Invoices\Amounts\Statement;
use Lumia\Factures\Modules\Invoices\Module;
use WC_Order;

/**
 * Replaces `{tag}` markers of the message, footer and legal texts.
 *
 * The result is plain text: it is NOT escaped here, the templates escape every value. An unknown
 * tag is kept as typed. A company field switched off in the settings resolves to an empty string,
 * exactly as it is absent from the rest of the document; so does the refund reason when it is not
 * shown.
 *
 * On a credit note, `{invoice_number}` and `{invoice_date}` name the invoice it credits and the
 * order tags the refunded order. The credit note tags are empty on an invoice.
 */
final class Placeholders {

	/**
	 * Tags shown as help in the settings screen. Every one is resolved by values().
	 *
	 * @var string[]
	 */
	public const TAGS = [
		'order_number',
		'order_date',
		'order_total',
		'payment_method',
		'shipping_method',
		'order_items_count',
		'invoice_number',
		'invoice_date',
		'credit_note_number',
		'credit_note_date',
		'refund_amount',
		'refund_reason',
		'customer_first_name',
		'customer_last_name',
		'customer_full_name',
		'customer_company',
		'customer_email',
		'customer_phone',
		'shop_name',
		'shop_legal_name',
		'shop_manager',
		'shop_address',
		'shop_phone',
		'shop_mobile',
		'shop_email',
		'shop_website',
		'shop_siret',
		'shop_vat',
		'current_date',
		'current_year',
	];

	/**
	 * Resolves the tags of a text.
	 *
	 * @param string                    $text      Text with `{tag}` markers.
	 * @param WC_Order                  $order     Order (the refunded order for a credit note).
	 * @param DocumentData              $doc       Document.
	 * @param array<string, mixed>|null $settings  Invoice settings, `Module::settings()` when null.
	 * @param Statement|null            $statement Amounts of the document: `{refund_amount}` is its
	 *                                             grand total, '' without it.
	 * @return string Plain text.
	 */
	public static function resolve( string $text, WC_Order $order, DocumentData $doc, ?array $settings = null, ?Statement $statement = null ): string {
		if ( ! str_contains( $text, '{' ) ) {
			return $text;
		}

		$values = self::values( $order, $doc, $settings ?? Module::settings(), $statement );

		return (string) preg_replace_callback(
			'/\{([a-z0-9_]+)\}/i',
			static fn( array $matches ): string => array_key_exists( $matches[1], $values ) ? $values[ $matches[1] ] : $matches[0],
			$text
		);
	}

	/**
	 * A company field, '' when its toggle is off.
	 *
	 * @param array<string, mixed> $settings Invoice settings.
	 * @param string               $key      Company key (`siret`, `address`...).
	 */
	public static function company_value( array $settings, string $key ): string {
		$company = isset( $settings['company'] ) && is_array( $settings['company'] ) ? $settings['company'] : [];

		if ( array_key_exists( $key . '_enabled', $company ) && empty( $company[ $key . '_enabled' ] ) ) {
			return '';
		}

		return isset( $company[ $key ] ) && is_scalar( $company[ $key ] ) ? trim( (string) $company[ $key ] ) : '';
	}

	/**
	 * A date as text in the shop date format.
	 *
	 * @param int $timestamp Unix timestamp, 0 for none.
	 */
	public static function format_date( int $timestamp ): string {
		return $timestamp > 0 ? (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp ) : '';
	}

	/**
	 * The grand total of a statement as text, '' without one.
	 *
	 * @param Statement|null $statement Statement.
	 */
	private static function grand_total( ?Statement $statement ): string {
		if ( null === $statement ) {
			return '';
		}

		foreach ( $statement->rows as $row ) {
			if ( $row->grand && null !== $row->amount ) {
				return Money::format( $row->amount, $statement->currency );
			}
		}

		return '';
	}

	/**
	 * Tag values.
	 *
	 * @param WC_Order             $order     Order.
	 * @param DocumentData         $doc       Document.
	 * @param array<string, mixed> $settings  Invoice settings.
	 * @param Statement|null       $statement Amounts of the document.
	 * @return array<string, string>
	 */
	private static function values( WC_Order $order, DocumentData $doc, array $settings, ?Statement $statement ): array {
		$credit  = $doc->is_credit_note();
		$group   = isset( $settings['credit_notes'] ) && is_array( $settings['credit_notes'] ) ? $settings['credit_notes'] : [];
		$reason  = $credit && ! empty( $group['reason_enabled'] ) ? trim( $doc->reason ) : '';
		$created = $order->get_date_created();
		$items   = 0;
		foreach ( $order->get_items() as $item ) {
			$items += (int) $item->get_quantity();
		}

		$shipping_method = '';
		foreach ( $order->get_shipping_methods() as $method ) {
			$shipping_method = $method->get_method_title();
			break;
		}

		$first = $order->get_billing_first_name();
		$last  = $order->get_billing_last_name();

		$values = [
			'order_number'        => (string) $order->get_order_number(),
			'order_date'          => $created ? self::format_date( $created->getTimestamp() ) : '',
			'order_total'         => Money::format( Money::to_minor( $order->get_total() ), $order->get_currency() ),
			'payment_method'      => (string) $order->get_payment_method_title(),
			'shipping_method'     => $shipping_method,
			'order_items_count'   => (string) $items,
			'invoice_number'      => $credit ? $doc->reference_number : $doc->number,
			'invoice_date'        => self::format_date( $credit ? $doc->reference_date : $doc->date ),
			'credit_note_number'  => $credit ? $doc->number : '',
			'credit_note_date'    => $credit ? self::format_date( $doc->date ) : '',
			'refund_amount'       => $credit ? self::grand_total( $statement ) : '',
			'refund_reason'       => $reason,
			'due_date'            => '',
			'customer_first_name' => $first,
			'customer_last_name'  => $last,
			'customer_full_name'  => trim( $first . ' ' . $last ),
			'customer_company'    => $order->get_billing_company(),
			'customer_email'      => $order->get_billing_email(),
			'customer_phone'      => $order->get_billing_phone(),
			'billing_first_name'  => $first,
			'billing_last_name'   => $last,
			'billing_company'     => $order->get_billing_company(),
			'billing_email'       => $order->get_billing_email(),
			'billing_phone'       => $order->get_billing_phone(),
			'shop_name'           => self::company_value( $settings, 'display_name' ),
			'shop_legal_name'     => self::company_value( $settings, 'legal_name' ),
			'shop_manager'        => self::company_value( $settings, 'manager' ),
			'shop_address'        => self::company_value( $settings, 'address' ),
			'shop_phone'          => self::company_value( $settings, 'phone' ),
			'shop_mobile'         => self::company_value( $settings, 'mobile' ),
			'shop_email'          => self::company_value( $settings, 'email' ),
			'shop_website'        => self::company_value( $settings, 'website' ),
			'shop_siret'          => self::company_value( $settings, 'siret' ),
			'shop_vat'            => self::company_value( $settings, 'vat_number' ),
			'current_date'        => self::format_date( time() ),
			'current_year'        => wp_date( 'Y' ),
		];

		/**
		 * Filters the placeholder values. Values must be plain text: the templates escape them.
		 *
		 * @param array<string, mixed> $values Tag => value.
		 * @param WC_Order              $order  Order.
		 * @param DocumentData          $doc    Document.
		 */
		$filtered = apply_filters( 'lumia_factures_placeholders', $values, $order, $doc );

		return is_array( $filtered ) ? array_map( static fn( $value ): string => is_scalar( $value ) ? (string) $value : '', $filtered ) : $values;
	}
}
