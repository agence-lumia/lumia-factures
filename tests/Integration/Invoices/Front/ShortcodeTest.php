<?php
/**
 * Invoice shortcode and its legacy alias.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * The link is rendered for the order's customer (or a key holder) and never as a dead button.
 */
class ShortcodeTest extends TestCase {

	/**
	 * Archived files written by a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		delete_option( 'lumia_factures_module_invoices' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		foreach ( $this->written as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		unset( $_GET['key'] );
		parent::tear_down();
	}

	public function test_shortcode_and_legacy_alias_render_link_for_owner_only(): void {
		$owner = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_customer_id( $owner );
		$order->save();
		$order->update_status( 'processing' );
		$doc             = Register::find( 'invoice', $order->get_id() );
		$this->written[] = Archive::root() . '/' . $doc->file;
		$id              = $order->get_id();

		// A guest without the key gets nothing, for both tags.
		$this->assertSame( '', do_shortcode( '[lumia_factures_invoice order_id="' . $id . '"]' ) );
		$this->assertSame( '', do_shortcode( '[facture_pdf_lumia order_id="' . $id . '"]' ) );

		// The owner gets the link.
		wp_set_current_user( $owner );
		$new = do_shortcode( '[lumia_factures_invoice order_id="' . $id . '" label="My <b>PDF</b>" class="btn"]' );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/?lumia_factures_invoice=' . $id . '&inline=0' ) ) . '"', $new );
		$this->assertStringContainsString( 'class="btn"', $new );
		$this->assertStringContainsString( '>My &lt;b&gt;PDF&lt;/b&gt;</a>', $new );

		$old = do_shortcode( '[facture_pdf_lumia order_id="' . $id . '" inline="0"]' );
		$this->assertStringContainsString( 'lumia_factures_invoice=' . $id, $old );
		$this->assertStringContainsString( 'class="fpl-download-button"', $old );
		$this->assertStringContainsString( 'Download my invoice', $old );
		$this->assertStringContainsString( 'inline=0', $old );
		$this->assertStringNotContainsString( 'inline=0', do_shortcode( '[facture_pdf_lumia order_id="' . $id . '" inline="1"]' ) );

		// Another customer sees nothing, unless the page carries the order key.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->assertSame( '', do_shortcode( '[lumia_factures_invoice order_id="' . $id . '"]' ) );
		$_GET['key'] = $order->get_order_key();
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), do_shortcode( '[lumia_factures_invoice order_id="' . $id . '"]' ) );
	}

	public function test_no_link_without_issued_invoice_or_order(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		grant_super_admin( get_current_user_id() );
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		$this->assertSame( '', do_shortcode( '[lumia_factures_invoice order_id="' . $order->get_id() . '"]' ) );
		$this->assertSame( '', do_shortcode( '[lumia_factures_invoice]' ) );
		$this->assertSame( '', do_shortcode( '[lumia_factures_invoice order_id="99999999"]' ) );
	}
}
