<?php
/**
 * Rendering context of an invoice.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Rendering;

defined( 'ABSPATH' ) || exit;

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
 */
final class Context {

	/**
	 * Order.
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
	 * Constructor.
	 *
	 * @param WC_Order             $order    Order.
	 * @param DocumentData         $doc      Document.
	 * @param array<string, mixed> $settings Invoice settings.
	 */
	private function __construct( WC_Order $order, DocumentData $doc, array $settings ) {
		$this->order    = $order;
		$this->doc      = $doc;
		$this->settings = $settings;
	}

	/**
	 * Builds the context.
	 *
	 * @param WC_Order             $order    Order.
	 * @param DocumentData         $doc      Document.
	 * @param array<string, mixed> $settings Invoice settings (`Module::settings()`).
	 * @return array<string, mixed>
	 */
	public static function build( WC_Order $order, DocumentData $doc, array $settings ): array {
		$builder   = new self( $order, $doc, $settings );
		$statement = StatementBuilder::build( $order, $settings );
		$items     = $builder->items( $statement );

		$context = [
			'preview'    => $doc->preview,
			'incl_tax'   => $statement->incl_tax,
			'header'     => $builder->header(),
			'shop'       => $builder->shop(),
			'customer'   => $builder->customer(),
			'shipping'   => $builder->shipping(),
			'meta'       => $builder->meta(),
			'message'    => $builder->message(),
			'columns'    => $builder->columns( $items ),
			'items'      => $items,
			'totals'     => $builder->totals( $statement ),
			'notes'      => $builder->notes(),
			'footer'     => $builder->footer(),
			'legal_text' => $builder->text( 'legal_text' ),
		];

		/**
		 * Filters the rendering context.
		 *
		 * @param array<string, mixed> $context Context.
		 * @param WC_Order             $order   Order.
		 * @param DocumentData         $doc     Document.
		 */
		$filtered = apply_filters( 'lumia_factures_invoice_context', $context, $order, $doc );

		return is_array( $filtered ) ? $filtered : $context;
	}

	/**
	 * A content setting.
	 *
	 * @param string $key     Key of the `content` group.
	 * @param mixed  $fallback Value when unset.
	 * @return mixed
	 */
	private function content( string $key, $fallback = '' ) {
		$content = isset( $this->settings['content'] ) && is_array( $this->settings['content'] ) ? $this->settings['content'] : [];

		return array_key_exists( $key, $content ) ? $content[ $key ] : $fallback;
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
	 * @param string $key     Key of the `content` group.
	 * @param string $fallback Translated default.
	 */
	private function label( string $key, string $fallback ): string {
		$value = $this->content( $key );
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

		return '' === $value ? '' : Placeholders::resolve( $value, $this->order, $this->doc, $this->settings );
	}

	/**
	 * Logo and document title.
	 *
	 * @return array{logo: array{src: string, width: int}|null, title: string, number: string}
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
			$title = $this->label( 'invoice_title', __( 'Invoice', 'lumia-factures' ) );
		}

		return [
			'logo'   => $logo,
			'title'  => $title,
			'number' => $this->doc->number,
		];
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
	 * @return array<int, array{key: string, label: string, value: string}>
	 */
	private function meta(): array {
		$created = $this->order->get_date_created();

		$candidates = [
			[ 'invoice_number', __( 'Invoice no.', 'lumia-factures' ), $this->doc->number ],
			[ 'invoice_date', __( 'Invoice date', 'lumia-factures' ), Placeholders::format_date( $this->doc->date ) ],
			[ 'order_number', __( 'Order no.', 'lumia-factures' ), $this->order->get_id() > 0 ? (string) $this->order->get_order_number() : '' ],
			[ 'order_date', __( 'Order date', 'lumia-factures' ), $created ? Placeholders::format_date( $created->getTimestamp() ) : '' ],
			[ 'payment_method', __( 'Payment method', 'lumia-factures' ), (string) $this->order->get_payment_method_title() ],
		];

		$rows = [];
		foreach ( $candidates as $candidate ) {
			if ( $this->on( 'meta_' . $candidate[0] . '_enabled' ) && '' !== $candidate[2] ) {
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
	 * @param Statement $statement Statement.
	 * @return array<int, array<string, mixed>>
	 */
	private function items( Statement $statement ): array {
		$design    = isset( $this->settings['design'] ) && is_array( $this->settings['design'] ) ? $this->settings['design'] : [];
		$thumb     = isset( $design['thumb_width'] ) && is_numeric( $design['thumb_width'] ) ? (int) $design['thumb_width'] : 46;
		$images    = $this->on( 'col_image_enabled' ) && $thumb > 0;
		$describes = $this->on( 'col_description_enabled' );

		$items = [];
		foreach ( $statement->lines as $line ) {
			$items[] = [
				'name'        => $line->name,
				'attributes'  => $line->attributes,
				'description' => $describes ? self::description( $line ) : '',
				'sku'         => $line->sku,
				'quantity'    => self::quantity( $line->quantity ),
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
	 * to the total.
	 *
	 * @param Statement $statement Statement.
	 * @return array<int, array{key: string, label: string, value: string, grand: bool, informational: bool}>
	 */
	private function totals( Statement $statement ): array {
		$rows = [];

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

		return $rows;
	}

	/**
	 * Customer note.
	 *
	 * @return array<int, array{title: string, body: string}>
	 */
	private function notes(): array {
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
