<?php
/**
 * Invoice rendering: HTML and PDF.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;
use Lumia\Factures\Modules\Invoices\Amounts\CreditNoteStatementBuilder;
use Lumia\Factures\Modules\Invoices\Amounts\StatementBuilder;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Rendering\Context;
use Lumia\Factures\Modules\Invoices\Rendering\DocumentData;
use Lumia\Factures\Modules\Invoices\Rendering\Images;
use Lumia\Factures\Modules\Invoices\Rendering\PdfEngine;
use Lumia\Factures\Modules\Invoices\Rendering\Placeholders;
use Lumia\Factures\Modules\Invoices\Rendering\Renderer;
use Lumia\Factures\Modules\Invoices\Rendering\Styles;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;
use WP_Error;

/**
 * Renderer: what the HTML prints, and that Dompdf turns it into a PDF.
 */
class RenderingTest extends TestCase {

	private string $log_file = '';

	private string $previous_log = '';

	/**
	 * Credit notes recorded by credit_note_doc() in the current test.
	 */
	private int $documents = 0;

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_currency_pos', 'right_space' );
		update_option( 'woocommerce_price_decimal_sep', ',' );
		update_option( 'woocommerce_price_thousand_sep', ' ' );
		update_option( 'woocommerce_price_num_decimals', '2' );
		update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
		update_option( 'date_format', 'd/m/Y' );
		Images::flush();

		// Capture the plugin log lines instead of spilling them into the test output.
		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
	}

	public function tear_down(): void {
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
		wp_delete_file( $this->log_file );
		parent::tear_down();
	}

	public function test_html_contains_number_date_and_lines(): void {
		$order = OrderFactory::order(
			[
				[ OrderFactory::simple_product( 10.0, '', 'Croquettes poulet' ), 2 ],
				[ OrderFactory::simple_product( 5.0, '', 'Friandises' ) ],
			]
		);

		$html = Renderer::html( $order, $this->doc(), $this->settings() );

		$this->assertStringContainsString( 'FA-2026-00042', $html );
		$this->assertStringContainsString( '15/03/2026', $html );
		$this->assertStringContainsString( 'Croquettes poulet', $html );
		$this->assertStringContainsString( 'Friandises', $html );
		$this->assertStringContainsString( 'Invoice', $html );
		$this->assertStringContainsString( '25,00', $html );
	}

	public function test_title_precedence(): void {
		$order    = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$date     = (int) gmmktime( 12, 0, 0, 3, 15, 2026 );
		$titled   = new DocumentData( 'Credit note', 'FA-2026-00042', $date, false );
		$untitled = $this->doc();
		$setting  = $this->settings( [ 'content' => [ 'invoice_title' => 'Proforma' ] ] );

		// The document's own title wins over the setting, the setting over the default.
		$this->assertSame( 'Credit note', Context::build( $order, $titled, $setting )['header']['title'] );
		$this->assertSame( 'Credit note', Context::build( $order, $titled, $this->settings() )['header']['title'] );
		$this->assertSame( 'Proforma', Context::build( $order, $untitled, $setting )['header']['title'] );
		$this->assertSame( 'Invoice', Context::build( $order, $untitled, $this->settings() )['header']['title'] );
	}

	public function test_html_has_no_coupon_code_on_lines(): void {
		$order = OrderFactory::order(
			[ [ OrderFactory::simple_product( 35.0 ) ] ],
			[
				'coupon' => [
					'code'   => 'lecomte5',
					'type'   => 'fixed_cart',
					'amount' => 5,
				],
			]
		);

		$html = Renderer::html( $order, $this->doc(), $this->settings() );

		$this->assertSame( 1, substr_count( $html, 'LECOMTE5' ) );
		$this->assertStringNotContainsString( 'lecomte5', $html );
	}

	public function test_variation_attribute_printed_under_parent_name(): void {
		$variation = OrderFactory::variable_product( 'Conditionnement', 'Sac 2 kg', 12.0, 'Croquettes' );
		$order     = OrderFactory::order( [ [ $variation ] ] );

		$html = Renderer::html( $order, $this->doc(), $this->settings() );

		$this->assertStringContainsString( 'Croquettes', $html );
		$this->assertMatchesRegularExpression( '/Conditionnement : Sac 2 kg/', $html );
		$this->assertLessThan( strpos( $html, 'Conditionnement : Sac 2 kg' ), strpos( $html, 'Croquettes' ) );
	}

	public function test_placeholders_are_escaped(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_billing_first_name( '<b>x</b>' );
		$order->save();

		$html = Renderer::html(
			$order,
			$this->doc(),
			$this->settings( [ 'content' => [ 'message_body' => "Thank you {billing_first_name}\nSecond line" ] ] )
		);

		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringContainsString( 'Second line', $html );
		$this->assertStringContainsString( '<br />', $html );
	}

	public function test_placeholders_resolve_to_plain_text(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		$text = Placeholders::resolve( '{invoice_number} {order_total} {unknown}', $order, $this->doc() );

		$this->assertSame( "FA-2026-00042 10,00\u{00A0}€ {unknown}", $text );
	}

	public function test_disabled_fields_are_absent(): void {
		$order    = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$company  = [
			'siret'        => '12345678900011',
			'phone'        => '0102030405',
			'iban'         => 'FR7612345678901234567890123',
			'iban_enabled' => true,
		];
		$enabled  = Renderer::html( $order, $this->doc(), $this->settings( [ 'company' => $company ] ) );
		$disabled = Renderer::html( $order, $this->doc(), $this->settings( [ 'company' => $company + [ 'siret_enabled' => false ] ] ) );

		$this->assertStringContainsString( '12345678900011', $enabled );
		$this->assertStringNotContainsString( '12345678900011', $disabled );
		$this->assertStringContainsString( '0102030405', $disabled );
	}

	public function test_disabled_company_field_is_absent_from_placeholders(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		$html = Renderer::html(
			$order,
			$this->doc(),
			$this->settings(
				[
					'company' => [
						'siret'         => '12345678900011',
						'siret_enabled' => false,
					],
					'content' => [ 'message_body' => 'SIRET {shop_siret}.' ],
				]
			)
		);

		$this->assertStringNotContainsString( '12345678900011', $html );
	}

	public function test_summed_rows_always_print_and_toggles_hide_only_outside_the_sum(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order(
			[ [ OrderFactory::simple_product( 35.0 ) ] ],
			[
				'prices_include_tax' => true,
				'shipping'           => 5,
				'coupon'             => [
					'code'   => 'lecomte5',
					'type'   => 'fixed_cart',
					'amount' => 5,
				],
			]
		);

		$off  = [
			'total_subtotal_enabled'      => false,
			'total_discount_enabled'      => false,
			'total_shipping_enabled'      => false,
			'total_fees_enabled'          => false,
			'total_grand_enabled'         => false,
			'total_discount_code_enabled' => false,
			'total_tax_enabled'           => false,
		];
		$html = Renderer::html( $order, $this->doc(), $this->settings( [ 'content' => $off ] ) );

		$this->assertStringContainsString( 'Subtotal', $html, 'The subtotal is part of the sum: it still prints.' );
		$this->assertStringContainsString( 'Discount', $html );
		$this->assertStringContainsString( 'Shipping', $html );
		$this->assertStringContainsString( 'lf-total-row--grand', $html, 'The grand total always prints.' );
		$this->assertStringNotContainsString( 'LECOMTE5', $html, 'The coupon code is outside the sum: its toggle hides it.' );
		$this->assertStringNotContainsString( 'Total excl. VAT', $html, 'Informational rows follow the VAT toggle in incl. VAT mode.' );
		$this->assertStringNotContainsString( 'VAT 20', $html );

		$on = Renderer::html( $order, $this->doc(), $this->settings() );
		$this->assertSame( 1, substr_count( $on, 'LECOMTE5' ) );
		$this->assertStringContainsString( 'Total excl. VAT', $on );
		$this->assertStringContainsString( 'VAT 20', str_replace( "\u{00A0}", ' ', $on ) );
	}

	public function test_excl_mode_keeps_summed_vat_rows_whatever_the_toggle(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 100.0 ) ] ] );

		$html = Renderer::html(
			$order,
			$this->doc(),
			$this->settings(
				[
					'content' => [
						'price_display'     => 'excl',
						'total_tax_enabled' => false,
					],
				]
			)
		);

		$this->assertStringContainsString( 'VAT 20', str_replace( "\u{00A0}", ' ', $html ) );
		$this->assertStringContainsString( '20,00', $html );
	}

	public function test_legal_text_is_printed_after_the_totals(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		$html = Renderer::html( $order, $this->doc(), $this->settings( [ 'content' => [ 'legal_text' => "VAT not applicable.\nLate payment penalties." ] ] ) );

		$this->assertStringContainsString( 'Late payment penalties.', $html );
		$this->assertGreaterThan( strpos( $html, 'lf-totals' ), strpos( $html, 'Late payment penalties.' ) );
	}

	public function test_pdf_bytes(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0, '', 'Croquettes' ) ] ] );

		$pdf = Renderer::pdf( $order, $this->doc(), $this->settings() );

		$this->assertStringStartsWith( '%PDF-', $pdf );
		$this->assertGreaterThan( 2000, strlen( $pdf ) );
	}

	public function test_pdf_with_dejavu_font_and_no_pagination(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		$pdf = Renderer::pdf(
			$order,
			$this->doc(),
			$this->settings(
				[
					'design'  => [
						'font_body'    => 'dejavu-sans',
						'font_heading' => 'dejavu-sans',
					],
					'content' => [ 'footer_pagination_enabled' => false ],
				]
			)
		);

		$this->assertStringStartsWith( '%PDF-', $pdf );
	}

	public function test_styles_declare_inter_only_when_chosen(): void {
		$inter  = Styles::css( [ 'font_body' => 'inter' ] );
		$dejavu = Styles::css(
			[
				'font_body'    => 'dejavu-sans',
				'font_heading' => 'dejavu-sans',
			]
		);

		$this->assertStringContainsString( '@font-face', $inter );
		$this->assertStringContainsString( 'Inter-Regular.ttf', $inter );
		$this->assertStringNotContainsString( '@font-face', $dejavu );
		$this->assertStringNotContainsString( 'var(--lf-', $inter );
		$this->assertStringNotContainsString( 'calc(', $inter );
	}

	public function test_logo_is_embedded(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$logo  = $this->attachment( 'png' );

		$html = Renderer::html( $order, $this->doc(), $this->settings( [ 'company' => [ 'logo_id' => $logo ] ] ) );

		$this->assertStringContainsString( '<img src="data:image/png;base64,', $html );
	}

	public function test_svg_logo_is_embedded(): void {
		$file = trailingslashit( get_temp_dir() ) . 'lf-logo-' . wp_generate_password( 8, false ) . '.svg';
		file_put_contents( $file, '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40"><rect width="120" height="40" fill="#2f6b3f"/></svg>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$logo = self::factory()->attachment->create(
			[
				'file'           => $file,
				'post_mime_type' => 'image/svg+xml',
			]
		);
		update_attached_file( $logo, $file );

		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$html  = Renderer::html( $order, $this->doc(), $this->settings( [ 'company' => [ 'logo_id' => $logo ] ] ) );
		$this->assertStringContainsString( '<img src="data:image/svg+xml;base64,', $html );
		$this->assertStringStartsWith( '%PDF-', Renderer::pdf( $order, $this->doc(), $this->settings( [ 'company' => [ 'logo_id' => $logo ] ] ) ) );

		// A file named .svg that is not an SVG document is skipped.
		file_put_contents( $file, 'not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		Images::flush();
		$this->assertSame( '', Images::data_uri( $logo, 0 ) );
		wp_delete_file( $file );
	}

	public function test_title_band_has_no_number(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$html  = Renderer::html( $order, $this->doc(), $this->settings() );

		preg_match( '#<table class="lf-titlebar">.*?</table>#s', $html, $band );
		$this->assertNotEmpty( $band );
		$this->assertStringNotContainsString( $this->doc()->number, $band[0], 'The number is in the details block only.' );
		$this->assertStringContainsString( $this->doc()->number, $html );
	}

	public function test_product_image_sits_in_the_product_column(): void {
		$product = OrderFactory::simple_product( 10.0 );
		$product->set_image_id( $this->attachment( 'png', 120 ) );
		$product->save();
		$order = OrderFactory::order( [ [ $product ] ] );

		$html = Renderer::html( $order, $this->doc(), $this->settings( [ 'content' => [ 'col_image_enabled' => true ] ] ) );

		$this->assertStringContainsString( 'lf-item__thumb', $html );
		preg_match( '#<thead>.*?</thead>#s', $html, $head );
		preg_match_all( '#<th #', $head[0], $headers );
		preg_match_all( '#<col #', $html, $cols );
		$this->assertSame( count( $cols[0] ), count( $headers[0] ), 'One column per header: no empty header above the images.' );
	}

	public function test_informational_totals_come_before_the_grand_total(): void {
		OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 36.0 ) ] ], [ 'prices_include_tax' => true ] );
		$html  = str_replace( "\u{00A0}", ' ', Renderer::html( $order, $this->doc(), $this->settings() ) );
		$html  = substr( $html, (int) strpos( $html, '<table class="lf-totals">' ) );

		$excl  = strpos( $html, 'Total excl. VAT' );
		$vat   = strpos( $html, 'VAT 20' );
		$grand = strpos( $html, 'lf-total-row--grand' );
		$this->assertNotFalse( $excl );
		$this->assertNotFalse( $vat );
		$this->assertLessThan( $grand, $excl );
		$this->assertLessThan( $grand, $vat );
	}

	public function test_logo_missing_file_still_renders(): void {
		$logo = $this->attachment( 'png' );
		wp_delete_file( (string) get_attached_file( $logo ) );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'offline', 'Offline' ) );

		$order    = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$settings = $this->settings( [ 'company' => [ 'logo_id' => $logo ] ] );

		$html = Renderer::html( $order, $this->doc(), $settings );
		$pdf  = Renderer::pdf( $order, $this->doc(), $settings );

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringStartsWith( '%PDF-', $pdf );
		$this->assertStringContainsString( '[lumia-factures]', (string) file_get_contents( $this->log_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local log file.
	}

	public function test_deleted_logo_attachment_still_renders(): void {
		$logo = $this->attachment( 'png' );
		wp_delete_attachment( $logo, true );

		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$pdf   = Renderer::pdf( $order, $this->doc(), $this->settings( [ 'company' => [ 'logo_id' => $logo ] ] ) );

		$this->assertStringStartsWith( '%PDF-', $pdf );
	}

	public function test_webp_logo_is_converted_to_png(): void {
		if ( ! wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) || ! function_exists( 'imagewebp' ) ) {
			$this->markTestSkipped( 'No WebP support on this server.' );
		}

		$logo = $this->attachment( 'webp' );

		$this->assertStringStartsWith( 'data:image/png', Images::data_uri( $logo, 600 ) );
	}

	public function test_large_logo_is_shrunk(): void {
		$logo = $this->attachment( 'png', 1600 );

		$uri    = Images::data_uri( $logo, 300 );
		$binary = base64_decode( substr( $uri, strpos( $uri, ',' ) + 1 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test.
		$size   = getimagesizefromstring( (string) $binary );

		$this->assertIsArray( $size );
		$this->assertSame( 300, $size[0] );
	}

	public function test_remote_fetch_is_tried_once_per_request(): void {
		$first  = $this->attachment( 'png' );
		$second = $this->attachment( 'png' );
		wp_delete_file( (string) get_attached_file( $first ) );
		wp_delete_file( (string) get_attached_file( $second ) );

		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;
				return new WP_Error( 'offline', 'Offline' );
			}
		);

		$this->assertSame( '', Images::data_uri( $first, 300 ) );
		$this->assertSame( '', Images::data_uri( $second, 300 ) );
		$this->assertSame( 1, $calls );
	}

	public function test_unusable_local_file_does_not_trigger_a_remote_fetch(): void {
		$logo = $this->attachment( 'png' );
		file_put_contents( (string) get_attached_file( $logo ), 'not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test.

		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;
				return new WP_Error( 'offline', 'Offline' );
			}
		);

		$this->assertSame( '', Images::data_uri( $logo, 300 ) );
		$this->assertSame( 0, $calls );
	}

	public function test_injected_color_falls_back_to_the_default(): void {
		$css = Styles::css( [ 'color_primary' => 'red;}body{display:none' ] );

		$this->assertStringNotContainsString( 'display:none', $css );
		$this->assertStringContainsString( 'background: #111111', $css );
	}

	public function test_font_directory_is_in_uploads(): void {
		$dir = trailingslashit( (string) wp_upload_dir()['basedir'] ) . 'lumia-factures/fonts';

		$this->assertSame( $dir, PdfEngine::options()->getFontDir() );
		$this->assertFileExists( $dir . '/index.php' );
	}

	public function test_transparent_png_logo_is_flattened_and_renders(): void {
		$file  = trailingslashit( get_temp_dir() ) . 'lf-alpha-' . wp_generate_password( 8, false ) . '.png';
		$image = imagecreatetruecolor( 300, 100 );
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		imagefill( $image, 0, 0, (int) imagecolorallocatealpha( $image, 20, 120, 200, 64 ) );
		imagepng( $image, $file );
		$logo = (int) self::factory()->attachment->create_upload_object( $file );

		$uri    = Images::data_uri( $logo, 600 );
		$binary = (string) base64_decode( substr( $uri, strpos( $uri, ',' ) + 1 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test.

		$this->assertSame( 2, ord( $binary[25] ), 'Colour type 2: no alpha channel left for Dompdf.' );

		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$pdf   = Renderer::pdf( $order, $this->doc(), $this->settings( [ 'company' => [ 'logo_id' => $logo ] ] ) );
		$this->assertStringStartsWith( '%PDF-', $pdf );
	}

	public function test_columns_without_data_are_dropped_and_widths_sum_to_100(): void {
		$product = OrderFactory::simple_product( 10.0 );
		$product->set_sku( '' );
		$product->save();
		$order = OrderFactory::order( [ [ $product ] ] );
		// Image, description and SKU (switched on here) are empty on every line.
		$html = Renderer::html( $order, $this->doc(), $this->settings( [ 'content' => [ 'col_sku_enabled' => true ] ] ) );

		$this->assertStringNotContainsString( '>SKU<', $html );
		$this->assertStringNotContainsString( '>Description<', $html );
		$this->assertSame( 5, substr_count( $html, '<col ' ), 'name, quantity, unit price, VAT, total remain.' );

		preg_match_all( '/<col style="width: ([0-9.]+)%;">/', $html, $matches );
		$this->assertEqualsWithDelta( 100.0, array_sum( array_map( 'floatval', $matches[1] ) ), 0.05 );

		// Dompdf only honours the widths of a fixed layout when the header cells carry them too.
		preg_match_all( '/<th class="lf-col-[a-z]+" style="width: ([0-9.]+)%;">/', $html, $headers );
		$this->assertSame( $matches[1], $headers[1] );
		$this->assertStringContainsString( '<table class="lf-items" style="table-layout: fixed; width: 100%;">', $html );
	}

	public function test_description_column_kept_when_a_line_has_one(): void {
		$product = OrderFactory::simple_product( 10.0 );
		$product->set_short_description( 'Grain free' );
		$product->save();
		$order = OrderFactory::order( [ [ $product ] ] );

		$html = Renderer::html( $order, $this->doc(), $this->settings() );

		$this->assertStringContainsString( 'Grain free', $html );
		$this->assertStringContainsString( '>Description<', $html );
	}

	public function test_chroot_is_plugin_dir_only(): void {
		$chroot = PdfEngine::options()->getChroot();

		$this->assertCount( 1, $chroot );
		$this->assertSame( rtrim( (string) realpath( LUMIA_FACTURES_PLUGIN_DIR ), '/\\' ), rtrim( (string) $chroot[0], '/\\' ) );
		$this->assertFalse( PdfEngine::options()->getIsRemoteEnabled() );
	}

	public function test_template_path_filter_overrides_a_partial(): void {
		$override = (string) tempnam( sys_get_temp_dir(), 'lf-tpl' );
		file_put_contents( $override, '<p>CUSTOM FOOTER</p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test.
		add_filter(
			'lumia_factures_template_path',
			static fn( string $path, string $name ): string => 'partials/notes.php' === $name ? $override : $path,
			10,
			2
		);

		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$html  = Renderer::html( $order, $this->doc(), $this->settings() );
		wp_delete_file( $override );

		$this->assertStringContainsString( 'CUSTOM FOOTER', $html );
	}

	public function test_credit_note_title_and_meta_labels(): void {
		$order     = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$refund    = $this->refund( $order );
		$doc       = $this->credit_note_doc( $order, $refund );
		$parent    = $this->parent( $order );
		$statement = CreditNoteStatementBuilder::build( $refund, $parent, $this->settings() );

		// The invoice title setting never names a credit note: its own setting, else the default.
		$context = Context::build( $parent, $doc, $this->settings( [ 'content' => [ 'invoice_title' => 'Proforma' ] ] ), $statement );
		$this->assertSame( 'credit_note', $context['type'] );
		$this->assertSame( 'Credit note', $context['header']['title'] );
		$this->assertSame( 'AV-2026-00001', $context['header']['number'] );

		$named = $this->settings( [ 'credit_notes' => [ 'title' => 'Avoir commercial' ] ] );
		$this->assertSame( 'Avoir commercial', Context::build( $parent, $doc, $named, $statement )['header']['title'] );

		// The document's own title wins over the setting.
		$titled = new DocumentData( 'Custom', $doc->number, $doc->date, false, $doc->type, $doc->reference_number, $doc->reference_date, $doc->refund_date, $doc->reason );
		$this->assertSame( 'Custom', Context::build( $parent, $titled, $named, $statement )['header']['title'] );

		$this->assertSame( [ 'credit_note_number', 'credit_note_date', 'refund_date', 'order_number', 'order_date' ], array_column( $context['meta'], 'key' ) );
		$meta = array_column( $context['meta'], 'value', 'label' );
		$this->assertSame( 'AV-2026-00001', $meta['Credit note no.'] );
		$this->assertSame( '20/10/2026', $meta['Credit note date'] );
		$this->assertSame( '18/10/2026', $meta['Refund date'] );

		$html = Renderer::html( $parent, $doc, $this->settings(), $statement );
		$this->assertStringContainsString( '<title>Credit note AV-2026-00001</title>', $html );
		$this->assertStringContainsString( 'Total refunded', $html );
		$this->assertStringNotContainsString( 'Invoice no.', $html );
		$this->assertStringNotContainsString( 'Invoice date', $html );
	}

	public function test_credit_note_always_references_invoice_number_and_date(): void {
		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$refund = $this->refund( $order, 'Damaged parcel' );
		$doc    = $this->credit_note_doc( $order, $refund );
		$parent = $this->parent( $order );

		// Every toggle off: the reference and the credit note's own number and date still print.
		$off = [];
		foreach ( array_keys( Module::settings()['content'] ) as $key ) {
			if ( str_ends_with( $key, '_enabled' ) ) {
				$off[ $key ] = false;
			}
		}
		$settings = $this->settings(
			[
				'content'      => $off,
				'credit_notes' => [ 'reason_enabled' => false ],
			]
		);

		$context = Context::build( $parent, $doc, $settings, CreditNoteStatementBuilder::build( $refund, $parent, $settings ) );
		$this->assertSame( 'Credit note for invoice FA-2026-00042 of 15/03/2026', $context['header']['reference'] );
		$this->assertSame( [ 'credit_note_number', 'credit_note_date', 'refund_date' ], array_column( $context['meta'], 'key' ) );

		$html = Renderer::html( $parent, $doc, $settings, CreditNoteStatementBuilder::build( $refund, $parent, $settings ) );
		$this->assertStringContainsString( 'Credit note for invoice FA-2026-00042 of 15/03/2026', $html );
		$this->assertGreaterThan( strpos( $html, 'lf-titlebar' ), strpos( $html, 'Credit note for invoice' ), 'The reference sits under the title band.' );
		$this->assertStringContainsString( 'AV-2026-00001', $html );
	}

	public function test_credit_note_references_imported_legacy_invoice_number_and_date(): void {
		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$issued = (int) gmmktime( 10, 0, 0, 11, 5, 2024 );
		$this->assertIsInt( Register::import( 'invoice', $order->get_id(), 'F2024-0157', '', 0, $issued ) );

		$refund = $this->refund( $order );
		$doc    = $this->credit_note_doc( $order, $refund );

		// Read from the register: the imported invoice keeps its legacy number and date.
		$this->assertSame( 'F2024-0157', $doc->reference_number );
		$this->assertSame( $issued, $doc->reference_date );

		$parent = $this->parent( $order );
		$html   = Renderer::html( $parent, $doc, $this->settings(), CreditNoteStatementBuilder::build( $refund, $parent, $this->settings() ) );
		$this->assertStringContainsString( 'Credit note for invoice F2024-0157 of 05/11/2024', $html );
	}

	public function test_credit_note_document_data_needs_an_issued_invoice_and_its_own_refund(): void {
		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$refund = $this->refund( $order );
		$row    = (int) Register::reserve( 'credit_note', $refund->get_id(), $order->get_id() );

		// Reserved only: no number yet.
		$this->assertNull( DocumentData::credit_note( Register::find( 'credit_note', $refund->get_id() ), $refund ) );

		Register::assign( $row, 'AV-2026-00001', 'credit_note', 1, (int) gmmktime( 12, 0, 0, 10, 20, 2026 ) );
		$credit_note = Register::find( 'credit_note', $refund->get_id() );

		// No issued invoice for the order: the mandatory reference cannot be printed.
		$this->assertNull( DocumentData::credit_note( $credit_note, $refund ) );
		$invoice = (int) Register::reserve( 'invoice', $order->get_id() );
		$this->assertNull( DocumentData::credit_note( $credit_note, $refund ) );
		Register::assign( $invoice, 'FA-2026-00042', 'invoice', 42, (int) gmmktime( 12, 0, 0, 3, 15, 2026 ) );
		$this->assertNotNull( DocumentData::credit_note( $credit_note, $refund ) );

		// A refund of another order, or another refund, is never paired with the row.
		$other = $this->refund( OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] ) );
		$this->assertNull( DocumentData::credit_note( $credit_note, $other ) );

		// An invoice row is not a credit note.
		$this->assertNull( DocumentData::credit_note( Register::find( 'invoice', $order->get_id() ), $refund ) );
	}

	public function test_credit_note_needs_its_statement_and_reference(): void {
		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$refund = $this->refund( $order );
		$doc    = $this->credit_note_doc( $order, $refund );
		$parent = $this->parent( $order );

		// Without a reference: refused, never printed without its invoice.
		$unreferenced = new DocumentData( '', $doc->number, $doc->date, false, 'credit_note' );
		try {
			Context::build( $parent, $unreferenced, $this->settings(), CreditNoteStatementBuilder::build( $refund, $parent, $this->settings() ) );
			$this->fail( 'A credit note without the invoice it credits was rendered.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'invoice', $e->getMessage() );
		}

		// Without its statement: the order's own amounts would be printed.
		$this->expectException( InvalidArgumentException::class );
		Context::build( $parent, $doc, $this->settings() );
	}

	public function test_refund_reason_follows_toggle(): void {
		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$refund = $this->refund( $order, 'Damaged parcel' );
		$doc    = $this->credit_note_doc( $order, $refund );
		$parent = $this->parent( $order );
		$this->assertSame( 'Damaged parcel', $doc->reason );

		$on = Renderer::html( $parent, $doc, $this->settings(), CreditNoteStatementBuilder::build( $refund, $parent, $this->settings() ) );
		$this->assertStringContainsString( 'Refund reason', $on );
		$this->assertStringContainsString( 'Damaged parcel', $on );

		$settings = $this->settings( [ 'credit_notes' => [ 'reason_enabled' => false ] ] );
		$off      = Renderer::html( $parent, $doc, $settings, CreditNoteStatementBuilder::build( $refund, $parent, $settings ) );
		$this->assertStringNotContainsString( 'Refund reason', $off );
		$this->assertStringNotContainsString( 'Damaged parcel', $off );

		// No reason entered: no empty block.
		$plain        = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$plain_refund = $this->refund( $plain );
		$plain_parent = $this->parent( $plain );
		$context      = Context::build( $plain_parent, $this->credit_note_doc( $plain, $plain_refund ), $this->settings(), CreditNoteStatementBuilder::build( $plain_refund, $plain_parent, $this->settings() ) );
		$this->assertSame( [], $context['notes'] );
	}

	public function test_credit_note_parties_come_from_parent_order(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_billing_first_name( 'Marie' );
		$order->set_billing_last_name( 'Curie' );
		$order->set_billing_address_1( '12 rue des Lilas' );
		$order->set_billing_postcode( '69001' );
		$order->set_billing_city( 'Lyon' );
		$order->set_billing_email( 'marie@example.com' );
		$order->set_payment_method_title( 'Bank transfer' );
		$order->save();

		$refund   = $this->refund( $order );
		$parent   = $this->parent( $order );
		$settings = $this->settings( [ 'company' => [ 'siret' => '12345678900011' ] ] );
		$context  = Context::build( $parent, $this->credit_note_doc( $order, $refund ), $settings, CreditNoteStatementBuilder::build( $refund, $parent, $settings ) );

		$this->assertStringContainsString( 'Marie Curie', $context['customer']['address'] );
		$this->assertStringContainsString( '12 rue des Lilas', $context['customer']['address'] );
		$this->assertSame( 'marie@example.com', $context['customer']['email'] );

		$meta = array_column( $context['meta'], 'value', 'key' );
		$this->assertSame( (string) $parent->get_order_number(), $meta['order_number'] );
		$this->assertSame( 'Bank transfer', $meta['payment_method'] );
		$this->assertContains( 'SIRET: 12345678900011', $context['footer']['lines'] );
	}

	public function test_customer_note_not_printed_on_credit_note(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_customer_note( 'Leave it at the door' );
		$order->save();

		$refund = $this->refund( $order );
		$doc    = $this->credit_note_doc( $order, $refund );
		$parent = $this->parent( $order );

		$this->assertStringContainsString( 'Leave it at the door', Renderer::html( $parent, $this->doc(), $this->settings() ) );

		$context = Context::build( $parent, $doc, $this->settings(), CreditNoteStatementBuilder::build( $refund, $parent, $this->settings() ) );
		$this->assertSame( [], $context['notes'] );
		$this->assertStringNotContainsString( 'Leave it at the door', Renderer::html( $parent, $doc, $this->settings(), CreditNoteStatementBuilder::build( $refund, $parent, $this->settings() ) ) );
	}

	public function test_invoice_context_unchanged_with_default_document_data(): void {
		$doc = $this->doc();
		$this->assertSame( 'invoice', $doc->type );
		$this->assertFalse( $doc->is_credit_note() );
		$this->assertSame( [ '', 0, 0, '' ], [ $doc->reference_number, $doc->reference_date, $doc->refund_date, $doc->reason ] );

		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_customer_note( 'Leave it at the door' );
		$order->save();

		$context = Context::build( $order, $doc, $this->settings() );

		// The statement defaults to the order's own: passing it changes nothing.
		$this->assertSame( $context, Context::build( $order, $doc, $this->settings(), StatementBuilder::build( $order, $this->settings() ) ) );

		$this->assertSame( 'invoice', $context['type'] );
		$this->assertSame( 'Invoice', $context['header']['title'] );
		$this->assertSame( '', $context['header']['reference'] );
		$this->assertSame( [ 'invoice_number', 'invoice_date', 'order_number', 'order_date' ], array_column( $context['meta'], 'key' ) );
		$this->assertSame( [ 'Invoice no.', 'Invoice date', 'Order no.', 'Order date' ], array_column( $context['meta'], 'label' ) );
		$this->assertSame(
			[
				[
					'title' => 'Customer note',
					'body'  => 'Leave it at the door',
				],
			],
			$context['notes']
		);
		$this->assertSame( 'Total', $context['totals'][ count( $context['totals'] ) - 1 ]['label'] );

		// The meta toggles still hide the invoice number and date.
		$hidden = Context::build(
			$order,
			$doc,
			$this->settings(
				[
					'content' => [
						'meta_invoice_number_enabled' => false,
						'meta_invoice_date_enabled'   => false,
					],
				]
			)
		);
		$this->assertSame( [ 'order_number', 'order_date' ], array_column( $hidden['meta'], 'key' ) );

		$html = Renderer::html( $order, $doc, $this->settings() );
		$body = substr( $html, (int) strpos( $html, '<body>' ) );
		$this->assertStringNotContainsString( 'lf-reference', $body );
		$this->assertStringNotContainsString( 'Credit note', $body );
	}

	public function test_invoice_filters_receive_credit_note_type(): void {
		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$refund = $this->refund( $order );
		$doc    = $this->credit_note_doc( $order, $refund );
		$parent = $this->parent( $order );

		$seen = [];
		add_filter(
			'lumia_factures_invoice_context',
			static function ( $context, $filtered_order, $filtered_doc, $type ) use ( &$seen ) {
				$seen[] = [ 'context', $filtered_doc->type, $type, $filtered_order->get_id() ];
				return $context;
			},
			10,
			4
		);
		add_filter(
			'lumia_factures_invoice_html',
			static function ( $html, $filtered_order, $filtered_doc, $type ) use ( &$seen ) {
				$seen[] = [ 'html', $filtered_doc->type, $type, $filtered_order->get_id() ];
				return $html;
			},
			10,
			4
		);

		Renderer::html( $parent, $this->doc(), $this->settings() );
		Renderer::html( $parent, $doc, $this->settings(), CreditNoteStatementBuilder::build( $refund, $parent, $this->settings() ) );

		$id = $order->get_id();
		$this->assertSame(
			[
				[ 'context', 'invoice', 'invoice', $id ],
				[ 'html', 'invoice', 'invoice', $id ],
				[ 'context', 'credit_note', 'credit_note', $id ],
				[ 'html', 'credit_note', 'credit_note', $id ],
			],
			$seen,
			'The filters receive the parent order and the document type.'
		);
	}

	public function test_credit_note_placeholders_resolve(): void {
		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$refund = $this->refund( $order, 'Damaged parcel' );
		$doc    = $this->credit_note_doc( $order, $refund );
		$parent = $this->parent( $order );
		$text   = '{credit_note_number}|{credit_note_date}|{refund_amount}|{refund_reason}|{invoice_number}|{invoice_date}';

		$settings = $this->settings( [ 'content' => [ 'message_body' => $text ] ] );
		$context  = Context::build( $parent, $doc, $settings, CreditNoteStatementBuilder::build( $refund, $parent, $settings ) );
		$this->assertSame( "AV-2026-00001|20/10/2026|10,00\u{00A0}€|Damaged parcel|FA-2026-00042|15/03/2026", $context['message']['body'] );

		// The reason follows its toggle, like a company field switched off.
		$off     = $this->settings(
			[
				'content'      => [ 'message_body' => $text ],
				'credit_notes' => [ 'reason_enabled' => false ],
			]
		);
		$context = Context::build( $parent, $doc, $off, CreditNoteStatementBuilder::build( $refund, $parent, $off ) );
		$this->assertSame( "AV-2026-00001|20/10/2026|10,00\u{00A0}€||FA-2026-00042|15/03/2026", $context['message']['body'] );

		// On an invoice the credit note tags are empty.
		$this->assertSame( '||||FA-2026-00042|15/03/2026', Placeholders::resolve( $text, $parent, $this->doc() ) );

		foreach ( [ 'credit_note_number', 'credit_note_date', 'refund_amount', 'refund_reason' ] as $tag ) {
			$this->assertContains( $tag, Placeholders::TAGS );
		}
	}

	public function test_credit_note_zero_quantity_line_has_an_empty_quantity_cell(): void {
		$ids    = OrderFactory::tax_rates( [ '' => 20.0 ] );
		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$item   = current( $order->get_items() );
		$refund = OrderFactory::refund(
			$order,
			[
				$item->get_id() => [
					'qty'          => 0,
					'refund_total' => 0,
					'refund_tax'   => [ $ids[''] => 2.0 ],
				],
			],
			null
		);
		$doc    = $this->credit_note_doc( $order, $refund );
		$parent = $this->parent( $order );

		$context = Context::build( $parent, $doc, $this->settings(), CreditNoteStatementBuilder::build( $refund, $parent, $this->settings() ) );
		$this->assertSame( '', $context['items'][0]['quantity'] );
		$this->assertSame( '1', Context::build( $parent, $this->doc(), $this->settings() )['items'][0]['quantity'] );
	}

	public function test_credit_note_pdf_bytes(): void {
		$order  = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0, '', 'Croquettes' ) ] ] );
		$refund = $this->refund( $order, 'Damaged parcel' );
		$doc    = $this->credit_note_doc( $order, $refund );
		$parent = $this->parent( $order );

		$pdf = Renderer::pdf( $parent, $doc, $this->settings(), CreditNoteStatementBuilder::build( $refund, $parent, $this->settings() ) );

		$this->assertStringStartsWith( '%PDF-', $pdf );
	}

	/**
	 * Document identity used by the tests.
	 */
	private function doc(): DocumentData {
		return new DocumentData( '', 'FA-2026-00042', (int) gmmktime( 12, 0, 0, 3, 15, 2026 ), false );
	}

	/**
	 * A full refund of the order (no payment, no restock), dated 18/10/2026.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Refund reason.
	 */
	private function refund( WC_Order $order, string $reason = '' ): WC_Order_Refund {
		$lines = [];
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$lines[ $item->get_id() ] = OrderFactory::refund_line( $item );
			}
		}

		$refund = OrderFactory::refund( $order, $lines, null, $reason );
		$refund->set_date_created( (int) gmmktime( 9, 0, 0, 10, 18, 2026 ) );
		$refund->save();

		$fresh = wc_get_order( $refund->get_id() );
		$this->assertInstanceOf( WC_Order_Refund::class, $fresh );

		return $fresh;
	}

	/**
	 * The order as stored now (a full refund changed its status).
	 *
	 * @param WC_Order $order Order.
	 */
	private function parent( WC_Order $order ): WC_Order {
		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh );

		return $fresh;
	}

	/**
	 * Records the order's invoice (FA-2026-00042 of 15/03/2026, unless one is already in the
	 * register) and the refund's credit note (AV-2026-00001 of 20/10/2026), then reads the credit
	 * note's identity back from the register. Later calls in a test take the next numbers.
	 *
	 * @param WC_Order        $order  Order.
	 * @param WC_Order_Refund $refund Refund of the order.
	 */
	private function credit_note_doc( WC_Order $order, WC_Order_Refund $refund ): DocumentData {
		$sequence = ++$this->documents;

		if ( null === Register::find( 'invoice', $order->get_id() ) ) {
			$invoice = (int) Register::reserve( 'invoice', $order->get_id() );
			$this->assertTrue( Register::assign( $invoice, sprintf( 'FA-2026-%05d', 41 + $sequence ), 'invoice', 41 + $sequence, (int) gmmktime( 12, 0, 0, 3, 15, 2026 ) ) );
		}

		$row = (int) Register::reserve( 'credit_note', $refund->get_id(), $order->get_id() );
		$this->assertTrue( Register::assign( $row, sprintf( 'AV-2026-%05d', $sequence ), 'credit_note', $sequence, (int) gmmktime( 12, 0, 0, 10, 20, 2026 ) ) );

		$doc = DocumentData::credit_note( Register::find( 'credit_note', $refund->get_id() ), $refund );
		$this->assertInstanceOf( DocumentData::class, $doc );

		return $doc;
	}

	/**
	 * Default settings with overrides.
	 *
	 * @param array<string, array<string, mixed>> $overrides Overrides by group.
	 * @return array<string, array<string, mixed>>
	 */
	private function settings( array $overrides = [] ): array {
		return array_replace_recursive( Module::settings(), $overrides );
	}

	/**
	 * An image attachment.
	 *
	 * @param string $format png or webp.
	 * @param int    $width  Width in pixels.
	 */
	private function attachment( string $format, int $width = 400 ): int {
		$file  = trailingslashit( get_temp_dir() ) . 'lf-logo-' . wp_generate_password( 8, false ) . '.' . $format;
		$image = imagecreatetruecolor( $width, (int) ( $width / 2 ) );
		imagefill( $image, 0, 0, (int) imagecolorallocate( $image, 20, 120, 200 ) );
		'webp' === $format ? imagewebp( $image, $file ) : imagepng( $image, $file );

		return (int) self::factory()->attachment->create_upload_object( $file );
	}
}
