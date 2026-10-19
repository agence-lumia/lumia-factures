<?php
/**
 * Invoice rendering: HTML and PDF.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Rendering\DocumentData;
use Lumia\Factures\Modules\Invoices\Rendering\Images;
use Lumia\Factures\Modules\Invoices\Rendering\PdfEngine;
use Lumia\Factures\Modules\Invoices\Rendering\Placeholders;
use Lumia\Factures\Modules\Invoices\Rendering\Renderer;
use Lumia\Factures\Modules\Invoices\Rendering\Styles;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WP_Error;

/**
 * Renderer: what the HTML prints, and that Dompdf turns it into a PDF.
 */
class RenderingTest extends TestCase {

	private string $log_file = '';

	private string $previous_log = '';

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

	/**
	 * Document identity used by the tests.
	 */
	private function doc(): DocumentData {
		return new DocumentData( '', 'FA-2026-00042', (int) gmmktime( 12, 0, 0, 3, 15, 2026 ), false );
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
