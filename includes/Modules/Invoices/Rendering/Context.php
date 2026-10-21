<?php
/**
 * Rendering context of an invoice or a credit note.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Rendering;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;
use Lumia\Factures\Modules\Invoices\Amounts\Line;
use Lumia\Factures\Modules\Invoices\Amounts\Money;
use Lumia\Factures\Modules\Invoices\Amounts\Statement;
use Lumia\Factures\Modules\Invoices\Amounts\StatementBuilder;
use WC_Order;

/**
 * Turns an order and the settings into the plain data the templates print.
 *
 * Nothing is computed here: lines and totals come from the `Statement`, formatted as text. A
 * field switched off in the settings is absent from the context, so a template never tests a
 * toggle. Every string is plain text, the templates escape it.
 *
 * A credit note is rendered with the refunded order (parties, payment method, footer) and the
 * statement of its refund. It always names the invoice it credits, and prints neither the
 * customer note nor the invoice title.
 */
final class Context {

	/**
	 * Order (the refunded order for a credit note).
	 *
	 * @var WC_Order
	 */
	private WC_Order $order;

	/**
	 * Document.
	 *
	 * @var DocumentData
	 */
	private DocumentData $doc;

	/**
	 * Invoice settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Amounts of the document.
	 *
	 * @var Statement
	 */
	private Statement $statement;

	/**
	 * Constructor.
	 *
	 * @param WC_Order             $order     Order.
	 * @param DocumentData         $doc       Document.
	 * @param array<string, mixed> $settings  Invoice settings.
	 * @param Statement            $statement Amounts of the document.
	 */
	private function __construct( WC_Order $order, DocumentData $doc, array $settings, Statement $statement ) {
		$this->order     = $order;
		$this->doc       = $doc;
		$this->settings  = $settings;
		$this->statement = $statement;
	}

	/**
	 * Builds the context.
	 *
	 * @param WC_Order             $order     Order; for a credit note, the refunded order.
	 * @param DocumentData         $doc       Document.
	 * @param array<string, mixed> $settings  Invoice settings (`Module::settings()`).
	 * @param Statement|null       $statement Amounts; null for an invoice builds the order's own
	 *                                        (`StatementBuilder`). Required for a credit note
	 *                                        (`CreditNoteStatementBuilder`).
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException For a credit note without its statement or without the invoice it credits.
	 */
	public static function build( WC_Order $order, DocumentData $doc, array $settings, ?Statement $statement = null ): array {
		if ( $doc->is_credit_note() ) {
			// The order's own amounts would be printed, or the mandatory reference left out.
			if ( null === $statement ) {
				throw new InvalidArgumentException( 'A credit note is rendered from the statement of its refund.' );
			}
			if ( '' === $doc->reference_number ) {
				throw new InvalidArgumentException( 'A credit note must name the invoice it credits.' );
			}
		}

		$builder = new self( $order, $doc, $settings, $statement ?? StatementBuilder::build( $order, $settings ) );
		$items   = $builder->items();

		$context = [
			'type'       => $doc->type,
			'preview'    => $doc->preview,
			'incl_tax'   => $builder->statement->incl_tax,
			'header'     => $builder->header(),
			'shop'       => $builder->shop(),
			'customer'   => $builder->customer(),
			'shipping'   => $builder->shipping(),
			'meta'       => $builder->meta(),
			'message'    => $builder->message(),
			'columns'    => $builder->columns( $items ),
			'items'      => $items,
			'totals'     => $builder->totals(),
			'notes'      => $builder->notes(),
			'footer'     => $builder->footer(),
			'legal_text' => $builder->text( 'legal_text' ),
		];

		/**
		 * Filters the rendering context, of an invoice or of a credit note.
		 *
		 * @param array<string, mixed> $context Context.
		 * @param WC_Order             $order   Order (the refunded order for a credit note).
		 * @param DocumentData         $doc     Document.
		 * @param string               $type    Document type, `invoice` or `credit_note` (`$doc->type`).
		 */
		$filtered = apply_filters( 'lumia_factures_invoice_context', $context, $order, $doc, $doc->type );

		return is_array( $filtered ) ? $filtered : $context;
	}

	/**
	 * A setting of a group.
	 *
	 * @param string $group    Settings group.
	 * @param string $key      Key in the group.
	 * @param mixed  $fallback Value when unset.
	 * @return mixed
	 */
	private function setting( string $group, string $key, $fallback = '' ) {
		$values = isset( $this->settings[ $group ] ) && is_array( $this->settings[ $group ] ) ? $this->settings[ $group ] : [];

		return array_key_exists( $key, $values ) ? $values[ $key ] : $fallback;
	}

	/**
	 * A content setting.
	 *
	 * @param string $key     Key of the `content` group.
	 * @param mixed  $fallback Value when unset.
	 * @return mixed
	 */
	private function content( string $key, $fallback = '' ) {
		return $this->setting( 'content', $key, $fallback );
	}

	/**
	 * Is a content toggle on.
	 *
	 * @param string $key Key of the `content` group.
	 */
	private function on( string $key ): bool {
		return ! empty( $this->content( $key, false ) );
	}

	/**
	 * A label setting: the stored text, or the translated default when empty.
	 *
	 * @param string $key      Key of the group.
	 * @param string $fallback Translated default.
	 * @param string $group    Settings group.
	 */
	private function label( string $key, string $fallback, string $group = 'content' ): string {
		$value = $this->setting( $group, $key );
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return '' !== $value ? $value : $fallback;
	}

	/**
	 * A free text setting with its placeholders resolved.
	 *
	 * @param string $key Key of the `content` group.
	 */
	private function text( string $key ): string {
		$value = $this->content( $key );
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return '' === $value ? '' : Placeholders::resolve( $value, $this->order, $this->doc, $this->settings, $this->statement );
	}

	/**
	 * Logo, document title and, for a credit note, the invoice it credits.
	 *
	 * @return array{logo: array{src: string, width: int}|null, title: string, number: string, reference: string}
	 */
	private function header(): array {
		$company = isset( $this->settings['company'] ) && is_array( $this->settings['company'] ) ? $this->settings['company'] : [];
		$logo    = null;
		$id      = isset( $company['logo_id'] ) && is_numeric( $company['logo_id'] ) ? (int) $company['logo_id'] : 0;

		if ( ! empty( $company['logo_enabled'] ) && $id > 0 ) {
			$width = isset( $company['logo_width'] ) && is_numeric( $company['logo_width'] ) ? (int) $company['logo_width'] : 180;
			$src   = Images::data_uri( $id, $width * 3 );

			if ( '' !== $src ) {
				$logo = [
					'src'   => $src,
					'width' => $width,
				];
			}
		}

		// The document's own title (decided by the caller) wins, then the setting, then the default.
		$title = $this->doc->title;
		if ( '' === $title ) {
			$title = $this->doc->is_credit_note()
				? $this->label( 'title', __( 'Credit note', 'lumia-factures' ), 'credit_notes' )
				: $this->label( 'invoice_title', __( 'Invoice', 'lumia-factures' ) );
		}

		return [
			'logo'      => $logo,
			'title'     => $title,
			'number'    => $this->doc->number,
			'reference' => $this->reference(),
		];
	}

	/**
	 * The invoice a credit note credits, '' for an invoice.
	 *
	 * A mandatory mention: printed whatever the settings, with the invoice number and date the
	 * register holds.
	 */
	private function reference(): string {
		if ( ! $this->doc->is_credit_note() ) {
			return '';
		}

		$date = Placeholders::format_date( $this->doc->reference_date );
		if ( '' === $date ) {
			/* translators: %s: invoice number */
			return sprintf( __( 'Credit note for invoice %s', 'lumia-factures' ), $this->doc->reference_number );
		}

		/* translators: 1: invoice number, 2: invoice date */
		return sprintf( __( 'Credit note for invoice %1$s of %2$s', 'lumia-factures' ), $this->doc->reference_number, $date );
	}

	/**
	 * Company block, switched-off fields excluded. The first row is the name.
	 *
	 * @return array<int, array{key: string, label: string, value: string}>
	 */
	private function shop(): array {
		$fields = [
			'display_name' => '',
			'legal_name'   => '',
			'legal_form'   => '',
			'manager'      => '',
			'address'      => '',
			'phone'        => __( 'Tel', 'lumia-factures' ),
			'mobile'       => __( 'Mob', 'lumia-factures' ),
			'email'        => '',
			'website'      => '',
		];

		$rows = [];
		foreach ( $fields as $key => $label ) {
			$value = Placeholders::company_value( $this->settings, $key );
			if ( '' !== $value ) {
				$rows[] = [
					'key'   => $key,
					'label' => $label,
					'value' => $value,
				];
			}
		}

		return $rows;
	}

	/**
	 * Billing block.
	 *
	 * @return array{title: string, address: string, email: string, phone: string}
	 */
	private function customer(): array {
		return [
			'title'   => $this->label( 'billing_title', __( 'Bill to', 'lumia-factures' ) ),
			'address' => $this->on( 'billing_address_enabled' ) ? self::plain_address( $this->order->get_formatted_billing_address() ) : '',
			'email'   => $this->on( 'billing_email_enabled' ) ? $this->order->get_billing_email() : '',
			'phone'   => $this->on( 'billing_phone_enabled' ) ? $this->order->get_billing_phone() : '',
		];
	}

	/**
	 * Shipping block, `show` false when hidden.
	 *
	 * @return array{show: bool, title: string, address: string, phone: string}
	 */
	private function shipping(): array {
		$block = [
			'show'    => false,
			'title'   => $this->label( 'shipping_title', __( 'Ship to', 'lumia-factures' ) ),
			'address' => '',
			'phone'   => '',
		];

		if ( ! $this->on( 'shipping_enabled' ) ) {
			return $block;
		}

		$address = self::plain_address( $this->order->get_formatted_shipping_address() );
		if ( '' === $address ) {
			return $block;
		}

		// A fully virtual order has nothing to ship, even when WooCommerce copied the billing address.
		if ( $this->on( 'shipping_hide_virtual' ) && ! $this->order->needs_shipping_address() ) {
			return $block;
		}

		if ( $this->on( 'shipping_hide_if_same' ) && self::normalize( $address ) === self::normalize( self::plain_address( $this->order->get_formatted_billing_address() ) ) ) {
			return $block;
		}

		$block['show']    = true;
		$block['address'] = $address;
		$block['phone']   = $this->on( 'shipping_phone_enabled' ) ? $this->order->get_shipping_phone() : '';

		return $block;
	}

	/**
	 * Numbers and dates of the document and of the order.
	 *
	 * A credit note's own number and date and the refund date have no toggle: they always print
	 * (the invoice toggles name the invoice). The order rows follow their toggles on both documents.
	 *
	 * @return array<int, array{key: string, label: string, value: string}>
	 */
	private function meta(): array {
		$created = $this->order->get_date_created();

		// Candidate: key, label, value, toggle key of the `content` group ('' for none).
		$document = $this->doc->is_credit_note()
			? [
				[ 'credit_note_number', __( 'Credit note no.', 'lumia-factures' ), $this->doc->number, '' ],
				[ 'credit_note_date', __( 'Credit note date', 'lumia-factures' ), Placeholders::format_date( $this->doc->date ), '' ],
				[ 'refund_date', __( 'Refund date', 'lumia-factures' ), Placeholders::format_date( $this->doc->refund_date ), '' ],
			]
			: [
				[ 'invoice_number', __( 'Invoice no.', 'lumia-factures' ), $this->doc->number, 'meta_invoice_number_enabled' ],
				[ 'invoice_date', __( 'Invoice date', 'lumia-factures' ), Placeholders::format_date( $this->doc->date ), 'meta_invoice_date_enabled' ],
			];

		$candidates = array_merge(
			$document,
			[
				[ 'order_number', __( 'Order no.', 'lumia-factures' ), $this->order->get_id() > 0 ? (string) $this->order->get_order_number() : '', 'meta_order_number_enabled' ],
				[ 'order_date', __( 'Order date', 'lumia-factures' ), $created ? Placeholders::format_date( $created->getTimestamp() ) : '', 'meta_order_date_enabled' ],
				[ 'payment_method', __( 'Payment method', 'lumia-factures' ), (string) $this->order->get_payment_method_title(), 'meta_payment_method_enabled' ],
			]
		);

		$rows = [];
		foreach ( $candidates as $candidate ) {
			if ( ( '' === $candidate[3] || $this->on( $candidate[3] ) ) && '' !== $candidate[2] ) {
				$rows[] = [
					'key'   => $candidate[0],
					'label' => $candidate[1],
					'value' => $candidate[2],
				];
			}
		}

		return $rows;
	}

	/**
	 * Free message under the parties.
	 *
	 * @return array{title: string, body: string}|null
	 */
	private function message(): ?array {
		if ( ! $this->on( 'message_enabled' ) ) {
			return null;
		}

		$title = $this->text( 'message_title' );
		$body  = $this->text( 'message_body' );

		if ( '' === $title && '' === $body ) {
			return null;
		}

		return [
			'title' => $title,
			'body'  => $body,
		];
	}

	/**
	 * Active columns of the products table, widths scaled to 100 %.
	 *
	 * The image, description and SKU columns are dropped when no line has anything for them: an
	 * empty column only squeezes the others.
	 *
	 * @param array<int, array<string, mixed>> $items Lines, as built by items().
	 * @return array<int, array{key: string, label: string, width: float, align: string}>
	 */
	private function columns( array $items ): array {
		$definitions = [
			'image'       => [ '', 'left' ],
			'name'        => [ __( 'Product', 'lumia-factures' ), 'left' ],
			'description' => [ __( 'Description', 'lumia-factures' ), 'left' ],
			'sku'         => [ __( 'SKU', 'lumia-factures' ), 'left' ],
			'quantity'    => [ __( 'Qty', 'lumia-factures' ), 'center' ],
			'unit_price'  => [ __( 'Unit price', 'lumia-factures' ), 'right' ],
			'tax_rate'    => [ __( 'VAT', 'lumia-factures' ), 'center' ],
			'total'       => [ __( 'Total', 'lumia-factures' ), 'right' ],
		];

		$columns = [];
		$sum     = 0.0;
		foreach ( $definitions as $key => $definition ) {
			if ( ! $this->on( 'col_' . $key . '_enabled' ) ) {
				continue;
			}

			if ( in_array( $key, [ 'image', 'description', 'sku' ], true ) && [] === array_filter( array_column( $items, $key ) ) ) {
				continue;
			}

			$width     = $this->content( 'col_' . $key . '_width', 10 );
			$width     = is_numeric( $width ) ? max( 1.0, (float) $width ) : 10.0;
			$sum      += $width;
			$columns[] = [
				'key'   => $key,
				'label' => $this->label( 'col_' . $key . '_label', $definition[0] ),
				'width' => $width,
				'align' => $definition[1],
			];
		}

		foreach ( $columns as $index => $column ) {
			$columns[ $index ]['width'] = round( $column['width'] / $sum * 100, 2 );
		}

		return $columns;
	}

	/**
	 * Product lines, as text.
	 *
	 * On a credit note, a line refunded without quantity (an amount or a tax only) leaves the
	 * quantity cell empty; its unit price is the line total.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function items(): array {
		$statement = $this->statement;
		$design    = isset( $this->settings['design'] ) && is_array( $this->settings['design'] ) ? $this->settings['design'] : [];
		$thumb     = isset( $design['thumb_width'] ) && is_numeric( $design['thumb_width'] ) ? (int) $design['thumb_width'] : 46;
		$images    = $this->on( 'col_image_enabled' ) && $thumb > 0;
		$describes = $this->on( 'col_description_enabled' );
		$credit    = $this->doc->is_credit_note();

		$items = [];
		foreach ( $statement->lines as $line ) {
			$items[] = [
				'name'        => $line->name,
				'attributes'  => $line->attributes,
				'description' => $describes ? self::description( $line ) : '',
				'sku'         => $line->sku,
				'quantity'    => $credit && 0.0 === (float) $line->quantity ? '' : self::quantity( $line->quantity ),
				'unit_price'  => Money::format( $line->unit, $statement->currency ),
				'tax_rate'    => null === $line->tax_rate ? '' : StatementBuilder::format_rate( $line->tax_rate ),
				'total'       => Money::format( $line->total, $statement->currency ),
				'image'       => $images && $line->image_id > 0 ? Images::data_uri( $line->image_id, $thumb * 3 ) : '',
			];
		}

		return $items;
	}

	/**
	 * Totals rows as text.
	 *
	 * The toggle hides only what is outside the sum: in incl. VAT mode, the informational "excl. VAT" and VAT rows. A summed row always prints, so the rows printed add up
	 * to the total (to the refunded amount on a credit note, whose statement labels it "Total refunded").
	 *
	 * @return array<int, array{key: string, label: string, value: string, grand: bool, informational: bool}>
	 */
	private function totals(): array {
		$statement = $this->statement;
		$rows      = [];

		foreach ( $statement->rows as $row ) {
			if ( $statement->incl_tax && $row->informational && in_array( $row->key, [ 'total_excl', 'tax' ], true ) && ! $this->on( 'total_tax_enabled' ) ) {
				continue;
			}

			$rows[] = [
				'key'           => $row->key,
				'label'         => $row->label,
				'value'         => null !== $row->amount ? Money::format( $row->amount, $statement->currency ) : (string) $row->text,
				'grand'         => $row->grand,
				'informational' => $row->informational,
			];
		}

		return self::informational_before_grand( $rows );
	}

	/**
	 * Moves the informational rows printed after the grand total (incl. VAT mode: "Total excl. VAT",
	 * the VAT rates) just above it: the grand total closes the table, as customers read it. Only the
	 * order changes, never a value.
	 *
	 * @param array<int, array{key: string, label: string, value: string, grand: bool, informational: bool}> $rows Rows in statement order.
	 * @return array<int, array{key: string, label: string, value: string, grand: bool, informational: bool}>
	 */
	private static function informational_before_grand( array $rows ): array {
		$grand = null;
		foreach ( $rows as $index => $row ) {
			if ( $row['grand'] ) {
				$grand = $index;
				break;
			}
		}
		if ( null === $grand ) {
			return $rows;
		}

		$before = array_slice( $rows, 0, $grand );
		$after  = array_slice( $rows, $grand + 1 );
		$moved  = array_values( array_filter( $after, static fn( array $row ): bool => $row['informational'] ) );
		$kept   = array_values( array_filter( $after, static fn( array $row ): bool => ! $row['informational'] ) );

		return array_merge( $before, $moved, [ $rows[ $grand ] ], $kept );
	}

	/**
	 * Customer note on an invoice; on a credit note, the refund reason (never the customer note,
	 * which was written for the order).
	 *
	 * @return array<int, array{title: string, body: string}>
	 */
	private function notes(): array {
		if ( $this->doc->is_credit_note() ) {
			$reason = trim( $this->doc->reason );
			if ( '' === $reason || empty( $this->setting( 'credit_notes', 'reason_enabled', false ) ) ) {
				return [];
			}

			return [
				[
					'title' => __( 'Refund reason', 'lumia-factures' ),
					'body'  => $reason,
				],
			];
		}

		$note = trim( $this->order->get_customer_note() );

		if ( ! $this->on( 'customer_note_enabled' ) || '' === $note ) {
			return [];
		}

		return [
			[
				'title' => $this->label( 'customer_note_label', __( 'Customer note', 'lumia-factures' ) ),
				'body'  => $note,
			],
		];
	}

	/**
	 * Footer text and legal lines. The pagination is drawn by the PDF engine, not by the HTML.
	 *
	 * @return array{text: string, lines: array<int, string>}
	 */
	private function footer(): array {
		$entries = [
			'phone'         => __( 'Tel', 'lumia-factures' ),
			'mobile'        => __( 'Mob', 'lumia-factures' ),
			'email'         => '',
			'website'       => '',
			'siret'         => __( 'SIRET', 'lumia-factures' ),
			'rcs'           => __( 'RCS', 'lumia-factures' ),
			'vat_number'    => __( 'VAT no.', 'lumia-factures' ),
			'share_capital' => __( 'Share capital', 'lumia-factures' ),
			'ape_code'      => __( 'APE', 'lumia-factures' ),
			'iban'          => __( 'IBAN', 'lumia-factures' ),
			'bic'           => __( 'BIC', 'lumia-factures' ),
		];

		$lines = [];
		foreach ( $entries as $key => $label ) {
			$value = $this->on( 'footer_' . $key . '_enabled' ) ? Placeholders::company_value( $this->settings, $key ) : '';
			if ( '' !== $value ) {
				$lines[] = '' !== $label ? $label . ': ' . $value : $value;
			}
		}

		return [
			'text'  => $this->text( 'footer_text' ),
			'lines' => $lines,
		];
	}

	/**
	 * Quantity as text: no decimals for a whole number.
	 *
	 * @param int|float $quantity Quantity.
	 */
	private static function quantity( int|float $quantity ): string {
		return (string) ( floor( (float) $quantity ) === (float) $quantity ? (int) $quantity : $quantity );
	}

	/**
	 * Short description of a line's product (its parent's for a variation), plain text.
	 *
	 * @param Line $line Line.
	 */
	private static function description( Line $line ): string {
		$product = $line->product_id > 0 ? wc_get_product( $line->product_id ) : false;
		if ( ! $product ) {
			return '';
		}

		$text = trim( $product->get_short_description() );
		if ( '' === $text && $product->get_parent_id() > 0 ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$text   = $parent ? trim( $parent->get_short_description() ) : '';
		}

		return trim( wp_strip_all_tags( $text ) );
	}

	/**
	 * A WooCommerce formatted address as plain text, one line per row.
	 *
	 * @param string $html Address with `<br/>` separators.
	 */
	private static function plain_address( string $html ): string {
		$text  = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$text  = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$lines = array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', $text ) ) ), static fn( string $line ): bool => '' !== $line );

		return implode( "\n", $lines );
	}

	/**
	 * Comparison form of an address.
	 *
	 * @param string $address Plain address.
	 */
	private static function normalize( string $address ): string {
		return strtolower( (string) preg_replace( '/\s+/', ' ', trim( $address ) ) );
	}
}
