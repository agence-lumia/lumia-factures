<?php
/**
 * The PDF is rendered in the site language, whatever the language of the current user.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Admin\Preview;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use ReflectionProperty;
use WC_Order;

/**
 * Site locale fr_FR, current user locale en_US: issued, rebuilt and previewed invoices are French.
 *
 * wp-env ships no WordPress core language files: the test makes fr_FR available to the locale
 * switcher and relies on the plugin's own `lumia-factures-fr_FR.mo`, which carries every PDF label.
 */
class LocaleTest extends TestCase {

	/**
	 * Archived files written by the test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

	/**
	 * Languages the locale switcher knew before the test.
	 *
	 * @var string[]
	 */
	private array $languages = [];

	/**
	 * HTML rendered during the test.
	 *
	 * @var string[]
	 */
	private array $html = [];

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		delete_option( 'lumia_factures_module_invoices' );

		$this->languages = $this->available_languages();
		$this->available_languages( array_values( array_unique( array_merge( $this->languages, [ 'fr_FR' ] ) ) ) );

		// Site in French, current user in English, on an admin screen (where the user locale applies).
		// The WPLANG option is filtered rather than saved: saving it requires the core fr_FR files.
		add_filter( 'pre_option_WPLANG', [ $this, 'site_locale' ] );
		unset( $GLOBALS['locale'] );
		wp_set_current_user(
			self::factory()->user->create(
				[
					'role'   => 'shop_manager',
					'locale' => 'en_US',
				]
			)
		);
		set_current_screen( 'dashboard' );

		add_filter( 'lumia_factures_invoice_html', [ $this, 'capture' ] );
	}

	public function tear_down(): void {
		remove_filter( 'lumia_factures_invoice_html', [ $this, 'capture' ] );
		foreach ( $this->written as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		$this->available_languages( $this->languages );
		remove_filter( 'pre_option_WPLANG', [ $this, 'site_locale' ] );
		unset( $GLOBALS['locale'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Filter callback: the site language.
	 */
	public function site_locale(): string {
		return 'fr_FR';
	}

	/**
	 * Filter callback: keeps the rendered HTML.
	 *
	 * @param string $html HTML.
	 */
	public function capture( string $html ): string {
		$this->html[] = $html;
		return $html;
	}

	public function test_context_is_english_for_the_user(): void {
		$this->assertSame( 'fr_FR', get_locale() );
		$this->assertSame( 'en_US', determine_locale() );
		$this->assertSame( 'Invoice', __( 'Invoice', 'lumia-factures' ) );
	}

	public function test_issued_invoice_is_in_the_site_language(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );

		$this->assertCount( 1, $this->html );
		$this->assertFrench( $this->html[0] );
		$this->assertUserLocaleIsBack();
	}

	public function test_pdf_page_footer_is_in_the_site_language(): void {
		$footer = [];
		$spy    = static function ( $translation, $text, $domain ) use ( &$footer ) {
			if ( 'lumia-factures' === $domain && 'Page %1$s / %2$s' === $text ) {
				$footer[] = determine_locale();
			}
			return $translation;
		};
		add_filter( 'gettext', $spy, 10, 3 );

		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->update_status( 'processing' );

		remove_filter( 'gettext', $spy, 10 );
		$this->track( Register::find( 'invoice', $order->get_id() ) );

		// The French label equals the source one: what matters is the locale it is translated in.
		$this->assertSame( [ 'fr_FR' ], $footer );
		$this->assertUserLocaleIsBack();
	}

	public function test_rebuilt_invoice_is_in_the_site_language(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->update_status( 'processing' );
		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		wp_delete_file( (string) Archive::path( $doc ) );
		$this->html = [];

		$this->assertTrue( Issuer::restore( $doc, wc_get_order( $order->get_id() ) ) );
		$this->track( Register::find( 'invoice', $order->get_id() ) );

		$this->assertCount( 1, $this->html );
		$this->assertFrench( $this->html[0] );
		$this->assertUserLocaleIsBack();
	}

	public function test_preview_is_in_the_site_language(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		$html = Preview::render( [], $order->get_id(), 'html' );

		$this->assertIsString( $html );
		$this->assertFrench( $html );
		$this->assertUserLocaleIsBack();
	}

	/**
	 * The invoice labels are the French ones.
	 *
	 * @param string $html Rendered HTML.
	 */
	private function assertFrench( string $html ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit assertion naming.
		$this->assertStringContainsString( 'Facture', $html );
		$this->assertStringContainsString( 'Total TTC', $html );
		$this->assertStringNotContainsString( '>Invoice<', $html );
	}

	/**
	 * After rendering, the request is back in the user's language.
	 */
	private function assertUserLocaleIsBack(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit assertion naming.
		$this->assertSame( 'en_US', determine_locale() );
		$this->assertSame( 'Invoice', __( 'Invoice', 'lumia-factures' ) );
	}

	/**
	 * Reads, or replaces, the languages known to the global locale switcher (computed once at boot).
	 *
	 * @param string[]|null $languages New list, null to only read it.
	 * @return string[]
	 */
	private function available_languages( ?array $languages = null ): array {
		$property = new ReflectionProperty( \WP_Locale_Switcher::class, 'available_languages' );
		$property->setAccessible( true );
		if ( null !== $languages ) {
			$property->setValue( $GLOBALS['wp_locale_switcher'], $languages );
		}

		return (array) $property->getValue( $GLOBALS['wp_locale_switcher'] );
	}

	/**
	 * Remembers the archived file of a document for tear_down.
	 *
	 * @param IssuedDocument|null $doc Document.
	 */
	private function track( ?IssuedDocument $doc ): void {
		if ( null !== $doc && null !== $doc->file ) {
			$this->written[] = Archive::root() . '/' . $doc->file;
		}
	}
}
