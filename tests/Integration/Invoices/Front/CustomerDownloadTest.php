<?php
/**
 * Customer download: access, legacy links, build on first need, rate limit, order context.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Front;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Front\CustomerDownload;
use Lumia\Factures\Modules\Invoices\Front\OrderContext;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Refund;
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
		$this->assertSame( 'Document not found.', $messages['no key'] );
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

	public function test_credit_note_url_uses_refund_id_and_parent_key(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );

		$url = CustomerDownload::credit_note_url( $doc, $order );
		$this->assertStringContainsString( 'lumia_factures_credit_note=' . $refund->get_id(), $url );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $url );
		$this->assertStringNotContainsString( 'lumia_factures_invoice', $url );
		$this->assertStringNotContainsString( 'inline=', $url );
		$this->assertStringContainsString( 'inline=0', CustomerDownload::credit_note_url( $doc, $order, false ) );

		$seen   = [];
		$filter = static function ( $url, $document, $refunded ) use ( &$seen ) {
			$seen = [ $document->id, $refunded->get_id() ];
			return $url . '&filtered=1';
		};
		add_filter( 'lumia_factures_customer_credit_note_url', $filter, 10, 3 );
		$filtered = CustomerDownload::credit_note_url( $doc, $order );
		remove_filter( 'lumia_factures_customer_credit_note_url', $filter, 10 );

		$this->assertStringEndsWith( '&filtered=1', $filtered );
		$this->assertSame( [ $doc->id, $order->get_id() ], $seen );
	}

	public function test_url_with_key_always_carries_key_for_manager(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$this->assertStringNotContainsString( 'key=', CustomerDownload::url( $order ) );
		$this->assertStringNotContainsString( 'key=', CustomerDownload::credit_note_url( $doc, $order ) );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), CustomerDownload::url( $order, true, true ) );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), CustomerDownload::credit_note_url( $doc, $order, true, true ) );
	}

	public function test_url_default_omits_key_for_owner(): void {
		$owner  = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order  = $this->issued_order( $owner );
		$refund = $this->refund( $order, 3.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		wp_set_current_user( $owner );

		$this->assertStringNotContainsString( 'key=', CustomerDownload::url( $order ) );
		$this->assertStringNotContainsString( 'key=', CustomerDownload::credit_note_url( $doc, $order ) );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), CustomerDownload::url( $order, false, true ) );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), CustomerDownload::credit_note_url( $doc, $order, true, true ) );

		// The owner gets in without the key.
		$this->assertSame( Archive::path( $doc ), CustomerDownload::locate( [ 'lumia_factures_credit_note' => (string) $refund->get_id() ] )['path'] );
	}

	public function test_credit_note_download_with_parent_key_serves_archived_file(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );

		$file = CustomerDownload::locate(
			[
				'lumia_factures_credit_note' => (string) $refund->get_id(),
				'key'                        => $order->get_order_key(),
			]
		);

		$this->assertSame( Archive::path( $doc ), $file['path'] );
		$this->assertSame( $doc->number . '.pdf', $file['name'] );
		$this->assertTrue( $file['inline'] );
		$this->assertFalse(
			CustomerDownload::locate(
				[
					'lumia_factures_credit_note' => (string) $refund->get_id(),
					'key'                        => $order->get_order_key(),
					'inline'                     => '0',
				]
			)['inline']
		);
	}

	public function test_credit_note_download_wrong_key_404(): void {
		$order  = $this->issued_order();
		$other  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$id     = (string) $refund->get_id();

		$this->refusal( [ 'lumia_factures_credit_note' => $id ] );
		$this->refusal(
			[
				'lumia_factures_credit_note' => $id,
				'key'                        => 'wc_order_wrong',
			]
		);
		$this->refusal(
			[
				'lumia_factures_credit_note' => $id,
				'key'                        => $other->get_order_key(),
			]
		);
		$this->refusal(
			[
				'lumia_factures_credit_note' => '99999999',
				'key'                        => $order->get_order_key(),
			]
		);
	}

	public function test_credit_note_other_customer_logged_in_404(): void {
		$owner  = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order  = $this->issued_order( $owner );
		$refund = $this->refund( $order, 3.0 );
		$args   = [ 'lumia_factures_credit_note' => (string) $refund->get_id() ];

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->refusal( $args );
		$this->assertStringContainsString( 'key=', CustomerDownload::credit_note_url( Register::find( 'credit_note', $refund->get_id() ), $order ) );

		wp_set_current_user( $owner );
		$this->assertFileExists( CustomerDownload::locate( $args )['path'] );
	}

	public function test_credit_note_of_a_deleted_order_is_404_for_the_order_given_its_id(): void {
		global $wpdb;

		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );

		// Order X deleted for good after its credit note, then its id given to a new order (simulated
		// on the same row: another key, created after the deletion).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- backdates the credit note.
		$wpdb->update( Installer::documents_table(), [ 'issued_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ], [ 'id' => $doc->id ] );
		CreditNoteIssuer::on_order_deleting( $order->get_id(), wc_get_order( $order->get_id() ) );
		CreditNoteIssuer::on_order_deleted( $order->get_id() );
		update_option( CreditNoteIssuer::DELETED_ORDERS_OPTION, [ $order->get_id() => time() - HOUR_IN_SECONDS ] );

		$reused = wc_get_order( $order->get_id() );
		$reused->set_order_key( wc_generate_order_key() );
		$reused->set_date_created( time() - 60 );
		$reused->save();

		$this->refusal(
			[
				'lumia_factures_credit_note' => (string) $refund->get_id(),
				'key'                        => wc_get_order( $order->get_id() )->get_order_key(),
			]
		);

		// Before the id was reused, the same request with the order's own key served it.
		update_option( CreditNoteIssuer::DELETED_ORDERS_OPTION, [] );
		$this->assertSame(
			Archive::path( $doc ),
			CustomerDownload::locate(
				[
					'lumia_factures_credit_note' => (string) $refund->get_id(),
					'key'                        => wc_get_order( $order->get_id() )->get_order_key(),
				]
			)['path']
		);
	}

	public function test_credit_note_var_with_order_id_404(): void {
		$order = $this->issued_order();
		$this->refund( $order, 3.0 );

		$this->refusal(
			[
				'lumia_factures_credit_note' => (string) $order->get_id(),
				'key'                        => $order->get_order_key(),
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$this->refusal( [ 'lumia_factures_credit_note' => (string) $order->get_id() ] );
	}

	public function test_invoice_var_with_refund_id_404(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );

		$this->refusal(
			[
				'lumia_factures_invoice' => (string) $refund->get_id(),
				'key'                    => $order->get_order_key(),
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$this->refusal( [ 'lumia_factures_invoice' => (string) $refund->get_id() ] );
	}

	public function test_both_vars_present_resolves_invoice_only(): void {
		$order   = $this->issued_order();
		$refund  = $this->refund( $order, 3.0 );
		$invoice = Register::find( 'invoice', $order->get_id() );

		$file = CustomerDownload::locate(
			[
				'lumia_factures_invoice'     => (string) $order->get_id(),
				'lumia_factures_credit_note' => (string) $refund->get_id(),
				'key'                        => $order->get_order_key(),
			]
		);
		$this->assertSame( Archive::path( $invoice ), $file['path'] );

		// A wrong invoice var never falls back on the credit note.
		$this->refusal(
			[
				'lumia_factures_invoice'     => (string) $refund->get_id(),
				'lumia_factures_credit_note' => (string) $refund->get_id(),
				'key'                        => $order->get_order_key(),
			]
		);
	}

	public function test_legacy_fpl_download_document_credit_note_404(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );

		foreach ( [ $order->get_id(), $refund->get_id() ] as $id ) {
			$this->refusal(
				[
					'fpl_download' => '1',
					'order'        => (string) $id,
					'document'     => 'credit_note',
					'key'          => $order->get_order_key(),
				]
			);
		}
		$this->refusal(
			[
				'fpl_download' => '1',
				'order'        => (string) $refund->get_id(),
				'key'          => $order->get_order_key(),
			]
		);
	}

	public function test_credit_note_still_served_after_refund_deleted(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );

		add_filter( 'lumia_factures_allow_credited_refund_deletion', '__return_true' );
		$this->assertTrue( wc_get_order( $refund->get_id() )->delete( true ) );
		remove_filter( 'lumia_factures_allow_credited_refund_deletion', '__return_true' );
		$this->assertFalse( wc_get_order( $refund->get_id() ) );

		$file = CustomerDownload::locate(
			[
				'lumia_factures_credit_note' => (string) $refund->get_id(),
				'key'                        => $order->get_order_key(),
			]
		);
		$this->assertSame( Archive::path( $doc ), $file['path'] );
		$this->refusal(
			[
				'lumia_factures_credit_note' => (string) $refund->get_id(),
				'key'                        => 'wc_order_wrong',
			]
		);
	}

	public function test_credit_note_recorded_file_damaged_is_never_rebuilt_404(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		$path   = (string) Archive::path( $doc );
		file_put_contents( $path, '%PDF damaged' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->refusal(
			[
				'lumia_factures_credit_note' => (string) $refund->get_id(),
				'key'                        => $order->get_order_key(),
			]
		);
		$this->assertSame( $doc->file, Register::find( 'credit_note', $refund->get_id() )->file );
		$this->assertSame( '%PDF damaged', file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		wp_delete_file( $path );
		$this->refusal(
			[
				'lumia_factures_credit_note' => (string) $refund->get_id(),
				'key'                        => $order->get_order_key(),
			]
		);
		$this->assertSame( $doc->file, Register::find( 'credit_note', $refund->get_id() )->file );
	}

	public function test_credit_note_null_file_built_under_rate_limit(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$doc    = $this->forget_file( Register::find( 'credit_note', $refund->get_id() ) );
		$args   = [
			'lumia_factures_credit_note' => (string) $refund->get_id(),
			'key'                        => $order->get_order_key(),
		];

		// The quota is shared with the invoice builds: full, nothing is built.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
		$name                   = 'lumia_factures_rate_' . md5( '203.0.113.8' );
		set_transient(
			$name,
			[
				'count' => CustomerDownload::RATE_LIMIT,
				'until' => time() + 60,
			],
			60
		);
		$this->refusal( $args );
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() )->file );

		delete_transient( $name );
		$file  = CustomerDownload::locate( $args );
		$built = Register::find( 'credit_note', $refund->get_id() );
		$this->track( $built );

		$this->assertSame( Archive::path( $built ), $file['path'] );
		$this->assertTrue( Archive::verify( $built ) );
		$this->assertSame( $doc->number, $built->number );
		$this->assertSame( $doc->issued_at, $built->issued_at );
		$this->assertSame( 1, get_transient( $name )['count'] );
		delete_transient( $name );
	}

	public function test_not_found_message_identical_for_both_endpoints(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );

		$messages = [
			$this->refusal( [ 'lumia_factures_invoice' => (string) $order->get_id() ] ),
			$this->refusal( [ 'lumia_factures_credit_note' => (string) $refund->get_id() ] ),
			$this->refusal( [ 'lumia_factures_credit_note' => '99999999' ] ),
			$this->refusal(
				[
					'fpl_download' => '1',
					'order'        => (string) $order->get_id(),
				]
			),
		];

		$this->assertSame( [ 'Document not found.' ], array_values( array_unique( $messages ) ) );
	}

	public function test_can_access_document_filter_receives_type(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$key    = $order->get_order_key();

		$types  = [];
		$record = static function ( $can, $document_order, $document_key, $type ) use ( &$types ) {
			$types[] = [ $type, $document_order->get_id(), $document_key ];
			return $can;
		};
		add_filter( 'lumia_factures_can_access_document', $record, 10, 4 );
		CustomerDownload::locate(
			[
				'lumia_factures_invoice' => (string) $order->get_id(),
				'key'                    => $key,
			]
		);
		CustomerDownload::locate(
			[
				'lumia_factures_credit_note' => (string) $refund->get_id(),
				'key'                        => $key,
			]
		);
		remove_filter( 'lumia_factures_can_access_document', $record, 10 );

		$this->assertSame(
			[
				[ 'invoice', $order->get_id(), $key ],
				[ 'credit_note', $order->get_id(), $key ],
			],
			$types
		);

		// The filter can refuse either type.
		add_filter( 'lumia_factures_can_access_document', '__return_false' );
		$this->refusal(
			[
				'lumia_factures_invoice' => (string) $order->get_id(),
				'key'                    => $key,
			]
		);
		$this->refusal(
			[
				'lumia_factures_credit_note' => (string) $refund->get_id(),
				'key'                        => $key,
			]
		);
		remove_filter( 'lumia_factures_can_access_document', '__return_false' );
	}

	public function test_can_access_invoice_filter_still_applies_to_invoices_only(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$key    = $order->get_order_key();

		$calls  = 0;
		$refuse = static function () use ( &$calls ) {
			++$calls;
			return false;
		};
		add_filter( 'lumia_factures_can_access_invoice', $refuse );
		try {
			$this->refusal(
				[
					'lumia_factures_invoice' => (string) $order->get_id(),
					'key'                    => $key,
				]
			);
			$this->assertSame( 1, $calls );

			$file = CustomerDownload::locate(
				[
					'lumia_factures_credit_note' => (string) $refund->get_id(),
					'key'                        => $key,
				]
			);
			$this->assertFileExists( $file['path'] );
			$this->assertSame( 1, $calls, 'The invoice filter is not asked about a credit note.' );
		} finally {
			remove_filter( 'lumia_factures_can_access_invoice', $refuse );
		}
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
	 * A refund of an amount with its credit note, remembered for tear_down.
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount.
	 */
	private function refund( WC_Order $order, float $amount ): WC_Order_Refund {
		$refund = OrderFactory::refund( wc_get_order( $order->get_id() ), [], $amount );
		$doc    = Register::find( 'credit_note', $refund->get_id() );
		$this->assertNotNull( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->track( $doc );

		return $refund;
	}

	/**
	 * Turns an issued document into one whose PDF was never built (NULL file), as a render failure leaves it.
	 *
	 * @param IssuedDocument $doc Document.
	 * @return IssuedDocument The row as stored now.
	 */
	private function forget_file( IssuedDocument $doc ): IssuedDocument {
		global $wpdb;

		$path = Archive::path( $doc );
		if ( null !== $path ) {
			wp_delete_file( $path );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- simulates a PDF that was never archived.
		$wpdb->update(
			Installer::documents_table(),
			[
				'file'     => null,
				'checksum' => null,
			],
			[ 'id' => $doc->id ]
		);
		$fresh = Register::find( $doc->type, $doc->order_id );
		$this->assertNull( $fresh->file );

		return $fresh;
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
