<?php
/**
 * Customer download: access, legacy links, build on first need, rate limit, order context.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Front\CustomerDownload;
use Lumia\Factures\Modules\Invoices\Front\OrderContext;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WPDieException;

/**
 * The public endpoint serves the archived PDF to the order's customer or to the holder of the order key.
 */
class CustomerDownloadTest extends TestCase {

	/**
	 * Archived files written by a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

	/**
	 * REQUEST_URI before the test.
	 */
	private string $request_uri = '';

	public function set_up(): void {
		parent::set_up();
		$this->request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to be restored.
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
		set_query_var( 'view-order', '' );
		set_query_var( 'order-received', '' );
		$_SERVER['REQUEST_URI'] = $this->request_uri;
		unset( $_GET['key'] );
		parent::tear_down();
	}

	public function test_owner_can_download(): void {
		$owner = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order = $this->issued_order( $owner );
		$doc   = Register::find( 'invoice', $order->get_id() );

		wp_set_current_user( $owner );
		$file = CustomerDownload::locate( [ 'lumia_factures_invoice' => (string) $order->get_id() ] );

		$this->assertSame( Archive::path( $doc ), $file['path'] );
		$this->assertSame( $doc->number . '.pdf', $file['name'] );
		$this->assertTrue( $file['inline'] );
		$this->assertStringNotContainsString( 'key=', CustomerDownload::url( $order ) );
	}

	public function test_guest_with_order_key_can_download(): void {
		$order = $this->issued_order();

		$url = CustomerDownload::url( $order );
		$this->assertStringContainsString( 'lumia_factures_invoice=' . $order->get_id(), $url );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $url );

		$file = CustomerDownload::locate(
			[
				'lumia_factures_invoice' => (string) $order->get_id(),
				'key'                    => $order->get_order_key(),
				'inline'                 => '0',
			]
		);
		$this->assertFalse( $file['inline'] );
		$this->assertFileExists( $file['path'] );
	}

	public function test_wrong_key_or_other_customer_gets_404(): void {
		$owner = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order = $this->issued_order( $owner );
		$other = $this->issued_order();

		$messages = [];
		$attempts = [
			'no key'               => [ 'lumia_factures_invoice' => (string) $order->get_id() ],
			'wrong key'            => [
				'lumia_factures_invoice' => (string) $order->get_id(),
				'key'                    => 'wc_order_wrong',
			],
			'key of another order' => [
				'lumia_factures_invoice' => (string) $order->get_id(),
				'key'                    => $other->get_order_key(),
			],
			'unknown order'        => [
				'lumia_factures_invoice' => '99999999',
				'key'                    => 'wc_order_wrong',
			],
		];
		foreach ( $attempts as $name => $request ) {
			$messages[ $name ] = $this->refusal( $request );
		}
		$this->assertCount( 1, array_unique( $messages ), 'Every refusal gives the same message.' );

		// Another logged-in customer is not the owner and has no key.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->assertSame( $messages['no key'], $this->refusal( [ 'lumia_factures_invoice' => (string) $order->get_id() ] ) );
		$this->assertStringContainsString( 'key=', CustomerDownload::url( $order ), 'A non-owner keeps the key in the URL.' );

		// An order without an issued invoice looks the same.
		$plain = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$this->assertSame(
			$messages['no key'],
			$this->refusal(
				[
					'lumia_factures_invoice' => (string) $plain->get_id(),
					'key'                    => $plain->get_order_key(),
				]
			)
		);
		$this->assertSame( 'Invoice not found.', $messages['no key'] );
	}

	public function test_legacy_url_is_served(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );

		$request = [
			'fpl_download' => '1',
			'order'        => (string) $order->get_id(),
			'key'          => $order->get_order_key(),
		];
		$file    = CustomerDownload::locate( $request );

		$this->assertSame( Archive::path( $doc ), $file['path'] );
		$this->assertFalse( $file['inline'], 'The legacy link is an attachment by default.' );
		$this->assertTrue( CustomerDownload::locate( $request + [ 'inline' => '1' ] )['inline'] );

		// Another document type or a wrong key is refused.
		$this->refusal( [ 'document' => 'quote' ] + $request );
		$this->refusal( [ 'key' => 'wc_order_wrong' ] + $request );
	}

	public function test_legacy_query_var_is_ours_only_when_it_is_1(): void {
		$order = $this->issued_order();

		// Another value of `fpl_download` is not a legacy invoice link: the request goes on untouched.
		foreach ( [ '0', 'yes', '' ] as $value ) {
			$_GET = [
				'fpl_download' => $value,
				'order'        => (string) $order->get_id(),
				'key'          => $order->get_order_key(),
			];
			try {
				CustomerDownload::handle();
			} finally {
				$_GET = [];
			}
		}

		// `1` is handled: a wrong key gets the 404.
		$_GET = [
			'fpl_download' => '1',
			'order'        => (string) $order->get_id(),
			'key'          => 'wc_order_wrong',
		];
		try {
			CustomerDownload::handle();
			$this->fail( 'Expected the 404.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 404, $e->getCode() );
		} finally {
			$_GET = [];
		}
	}

	public function test_never_built_invoice_is_built_on_first_download(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$id    = Register::import( 'invoice', $order->get_id(), '202610029241', 'invoice_2026', 29241, 1760000000 );
		$this->assertNotNull( $id );
		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNull( $doc->file );

		$file = CustomerDownload::locate(
			[
				'lumia_factures_invoice' => (string) $order->get_id(),
				'key'                    => $order->get_order_key(),
			]
		);
		$new  = Register::find( 'invoice', $order->get_id() );
		$this->track( $new );

		$this->assertSame( Archive::path( $new ), $file['path'] );
		$this->assertTrue( Archive::verify( $new ) );
		$this->assertSame( '202610029241', $new->number );
		$this->assertSame( 1760000000, $new->issued_at );
		$this->assertSame( '%PDF', substr( (string) file_get_contents( $file['path'] ), 0, 4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_rate_limit_applies_only_to_builds(): void {
		$order = $this->issued_order();
		$args  = [
			'lumia_factures_invoice' => (string) $order->get_id(),
			'key'                    => $order->get_order_key(),
		];

		// The visitor has used the whole quota (a guest, identified by the IP).
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$name                   = 'lumia_factures_rate_' . md5( '203.0.113.7' );
		set_transient(
			$name,
			[
				'count' => CustomerDownload::RATE_LIMIT,
				'until' => time() + 60,
			],
			60
		);

		// An archived file is still served, any number of times.
		for ( $i = 0; $i < CustomerDownload::RATE_LIMIT + 2; ++$i ) {
			$this->assertFileExists( CustomerDownload::locate( $args )['path'] );
		}

		// A build is refused over the limit and nothing is built.
		$never = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		Register::import( 'invoice', $never->get_id(), '202610000001', 'invoice_2026', 1, 1760000000 );
		$this->refusal(
			[
				'lumia_factures_invoice' => (string) $never->get_id(),
				'key'                    => $never->get_order_key(),
			]
		);
		$this->assertNull( Register::find( 'invoice', $never->get_id() )->file );

		// A fresh window builds again and counts the build.
		delete_transient( $name );
		$file = CustomerDownload::locate(
			[
				'lumia_factures_invoice' => (string) $never->get_id(),
				'key'                    => $never->get_order_key(),
			]
		);
		$this->track( Register::find( 'invoice', $never->get_id() ) );
		$this->assertFileExists( $file['path'] );
		$this->assertSame( 1, get_transient( $name )['count'] );
		delete_transient( $name );
	}

	public function test_recorded_missing_file_is_404_and_not_rebuilt(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );
		wp_delete_file( (string) Archive::path( $doc ) );

		$this->refusal(
			[
				'lumia_factures_invoice' => (string) $order->get_id(),
				'key'                    => $order->get_order_key(),
			]
		);
		$this->assertSame( $doc->file, Register::find( 'invoice', $order->get_id() )->file );
	}

	public function test_order_context_from_view_order_endpoint(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		$this->assertNull( OrderContext::resolve() );
		$this->assertSame( $order->get_id(), OrderContext::resolve( $order->get_id() )->get_id() );

		// The query var wins over the (misleading) subject.
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		set_query_var( 'view-order', (string) $order->get_id() );
		$this->assertSame( $order->get_id(), OrderContext::current_order_id() );
		$this->assertSame( $order->get_id(), OrderContext::resolve( get_post( $page ) )->get_id() );
		set_query_var( 'view-order', '' );

		// The request URI, with the configured endpoint.
		$_SERVER['REQUEST_URI'] = '/my-account/view-order/' . $order->get_id() . '/';
		$this->assertSame( $order->get_id(), OrderContext::resolve()->get_id() );
		$_SERVER['REQUEST_URI'] = '/my-account/orders/';
		$this->assertNull( OrderContext::resolve() );

		$_GET['key'] = ' wc_order_abc ';
		$this->assertSame( 'wc_order_abc', OrderContext::current_order_key() );
		unset( $_GET['key'] );
		$this->assertNull( OrderContext::current_order_key() );
	}

	/**
	 * Runs a request that must be refused with the 404.
	 *
	 * @param array<string, string> $request Query.
	 * @return string The message shown.
	 */
	private function refusal( array $request ): string {
		try {
			CustomerDownload::locate( $request );
		} catch ( WPDieException $e ) {
			$this->assertSame( 404, $e->getCode() );

			return wp_strip_all_tags( $e->getMessage() );
		}
		$this->fail( 'Expected a 404.' );
	}

	/**
	 * An order with an issued, archived invoice.
	 *
	 * @param int $customer_id Customer user id (0 for a guest).
	 */
	private function issued_order( int $customer_id = 0 ): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		if ( $customer_id > 0 ) {
			$order->set_customer_id( $customer_id );
			$order->save();
		}
		$order->update_status( 'processing' );
		$this->track( Register::find( 'invoice', $order->get_id() ) );

		return wc_get_order( $order->get_id() );
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
