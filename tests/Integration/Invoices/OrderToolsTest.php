<?php
/**
 * Order screen tools: meta box, list column, admin download, issue and restore actions, credit
 * notes and the pending credit notes notice.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Admin\CreditNoteNotice;
use Lumia\Factures\Modules\Invoices\Admin\Download;
use Lumia\Factures\Modules\Invoices\Admin\OrderTools;
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Module;
use RuntimeException;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Refund;
use WPDieException;

/**
 * Order tools: capability, archive path, issue/restore helpers, columns, meta box.
 */
class OrderToolsTest extends TestCase {

	/**
	 * Archived files written by the test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

	private string $log_file = '';

	private string $previous_log = '';

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		delete_option( 'lumia_factures_module_invoices' );
		delete_option( Migration::OPTION );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		// Capture the plugin log lines (credit note refusals) instead of spilling them into the test output.
		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
	}

	public function tear_down(): void {
		foreach ( array_unique( $this->written ) as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		unset( $_GET['order_id'], $_GET['refund_id'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['lumia_factures_notice'], $_GET['lumia_factures_issued'], $_GET['lumia_factures_left'] );
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
		wp_delete_file( $this->log_file );
		parent::tear_down();
	}

	public function test_meta_box_lists_refunds_with_and_without_credit_note_and_pending_reason(): void {
		$order   = $this->issued_order();
		$issued  = $this->refund( $order, 3.0 );
		$missing = $this->refund_without_hook( $order, 2.0 );
		$flagged = $this->refund_without_hook( $order, 1.0 );
		$flagged->update_meta_data( CreditNoteIssuer::PENDING_META, 'vetoed' );
		$flagged->save();
		$doc = Register::find( 'credit_note', $issued->get_id() );

		$html = $this->capture_metabox( wc_get_order( $order->get_id() ) );

		$this->assertStringContainsString( 'Credit notes', $html );
		$this->assertStringContainsString( (string) $doc->number, $html );
		$this->assertStringContainsString( 'Refund #' . $issued->get_id() . ' (', $html );
		$this->assertStringContainsString( 'action=lumia_factures_invoices_download_credit_note&#038;order_id=' . $order->get_id() . '&#038;refund_id=' . $issued->get_id(), $html );
		$this->assertStringNotContainsString( 'lumia_factures_invoices_restore_credit_note', $html );

		$this->assertStringContainsString( 'action=lumia_factures_invoices_issue_credit_note&#038;order_id=' . $order->get_id() . '&#038;refund_id=' . $missing->get_id(), $html );
		$this->assertStringContainsString( 'action=lumia_factures_invoices_issue_credit_note&#038;order_id=' . $order->get_id() . '&#038;refund_id=' . $flagged->get_id(), $html );
		$this->assertStringContainsString( 'No credit note yet (was: Refused by the lumia_factures_can_issue_credit_note filter.)', $html );
		$this->assertSame( 2, substr_count( $html, 'Issue credit note' ) );

		// While the reason holds, no button: the reason is shown instead.
		add_filter( 'lumia_factures_can_issue_credit_note', '__return_false' );
		$vetoed = $this->capture_metabox( wc_get_order( $order->get_id() ) );
		remove_filter( 'lumia_factures_can_issue_credit_note', '__return_false' );

		$this->assertStringNotContainsString( 'lumia_factures_invoices_issue_credit_note', $vetoed );
		$this->assertSame( 2, substr_count( $vetoed, 'Refused by the lumia_factures_can_issue_credit_note filter.' ) );
		$this->assertStringContainsString( (string) $doc->number, $vetoed );

		// The missing file of a credit note offers the rebuild.
		wp_delete_file( (string) Archive::path( $doc ) );
		$this->assertStringContainsString( 'lumia_factures_invoices_restore_credit_note', $this->capture_metabox( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( [], wc_get_order_notes( [ 'order_id' => $missing->get_id() ] ), 'Viewing the order never writes.' );
		$this->assertSame( '', $this->refund_of( $missing )->get_meta( CreditNoteIssuer::PENDING_META ), 'Viewing the order never flags a refund.' );
	}

	public function test_meta_box_lists_credit_note_of_deleted_refund(): void {
		$order  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$id     = $refund->get_id();
		$doc    = Register::find( 'credit_note', $id );

		add_filter( 'lumia_factures_allow_credited_refund_deletion', '__return_true' );
		$refund->delete( true );
		remove_filter( 'lumia_factures_allow_credited_refund_deletion', '__return_true' );
		$this->assertFalse( wc_get_order( $id ) );

		$html = $this->capture_metabox( wc_get_order( $order->get_id() ) );

		$this->assertStringContainsString( (string) $doc->number, $html );
		$this->assertStringContainsString( 'Refund #' . $id . ' deleted', $html );
		$this->assertStringContainsString( 'action=lumia_factures_invoices_download_credit_note&#038;order_id=' . $order->get_id() . '&#038;refund_id=' . $id, $html );
		$this->assertStringNotContainsString( 'lumia_factures_invoices_issue_credit_note', $html );
	}

	public function test_issue_credit_note_action_requires_nonce_and_manage_woocommerce(): void {
		$order  = $this->issued_order();
		$refund = $this->refund_without_hook( $order, 2.0 );
		$id     = $refund->get_id();

		$_GET['order_id']  = (string) $order->get_id();
		$_GET['refund_id'] = (string) $id;

		$handlers = [
			'issue_credit_note'    => [ OrderTools::class, 'handle_issue_credit_note' ],
			'restore_credit_note'  => [ OrderTools::class, 'handle_restore_credit_note' ],
			'download_credit_note' => [ Download::class, 'handle_credit_note' ],
		];

		// Valid nonce, no capability.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		foreach ( $handlers as $name => $callback ) {
			$valid                = wp_create_nonce( 'lumia_factures_invoices_' . $name . '_' . $id );
			$_GET['_wpnonce']     = $valid;
			$_REQUEST['_wpnonce'] = $valid;
			$this->assertHandlerDies( $callback, 403, $name );
		}

		// Capability, missing, bad or other refund's nonce.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		foreach ( $handlers as $name => $callback ) {
			foreach ( [ null, 'badnonce', wp_create_nonce( 'lumia_factures_invoices_' . $name . '_' . ( $id + 1 ) ) ] as $nonce ) {
				unset( $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );
				if ( null !== $nonce ) {
					$_GET['_wpnonce']     = $nonce;
					$_REQUEST['_wpnonce'] = $nonce;
				}
				$this->assertHandlerDies( $callback, 403, $name );
			}
		}

		$this->assertNull( Register::find( 'credit_note', $id ) );

		// The URL of the meta box carries the refund's nonce.
		parse_str( (string) wp_parse_url( wp_specialchars_decode( Download::credit_note_url( $order->get_id(), $id ) ), PHP_URL_QUERY ), $query );
		$this->assertSame( 1, wp_verify_nonce( (string) $query['_wpnonce'], 'lumia_factures_invoices_download_credit_note_' . $id ) );
	}

	public function test_issue_credit_note_action_refuses_refund_of_another_order(): void {
		$order  = $this->issued_order();
		$other  = $this->issued_order();
		$refund = $this->refund_without_hook( $order, 2.0 );

		$this->assertSame( 'credit_note_not_issued', OrderTools::issue_credit_note_for( $other->get_id(), $refund->get_id() ) );
		$this->assertSame( 'credit_note_not_issued', OrderTools::issue_credit_note_for( $order->get_id(), $order->get_id() ), 'An order id is not a refund.' );
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );

		$this->assertSame( 'credit_note_issued', OrderTools::issue_credit_note_for( $order->get_id(), $refund->get_id() ) );
		$doc = Register::find( 'credit_note', $refund->get_id() );
		$this->track( $doc );
		$this->assertSame( $order->get_id(), $doc->parent_id );
	}

	public function test_restore_credit_note_action_rebuilds_only_missing_file(): void {
		$order  = $this->issued_order();
		$other  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$doc    = Register::find( 'credit_note', $refund->get_id() );

		$this->assertSame( 'credit_note_restore_refused', OrderTools::restore_credit_note_for( $order->get_id(), $refund->get_id() ), 'An intact file is never rebuilt.' );

		wp_delete_file( (string) Archive::path( $doc ) );
		$this->assertSame( 'credit_note_restore_refused', OrderTools::restore_credit_note_for( $other->get_id(), $refund->get_id() ) );
		$this->assertSame( $doc->file, Register::find( 'credit_note', $refund->get_id() )->file );

		$this->assertSame( 'credit_note_restored', OrderTools::restore_credit_note_for( $order->get_id(), $refund->get_id() ) );
		$new = Register::find( 'credit_note', $refund->get_id() );
		$this->track( $new );
		$this->assertTrue( Archive::verify( $new ) );
		$this->assertNotSame( $doc->file, $new->file );
		$this->assertSame( $doc->number, $new->number );
		$this->assertSame( $doc->issued_at, $new->issued_at );
	}

	public function test_download_credit_note_serves_register_file_after_refund_deleted(): void {
		$order  = $this->issued_order();
		$other  = $this->issued_order();
		$refund = $this->refund( $order, 3.0 );
		$id     = $refund->get_id();
		$doc    = Register::find( 'credit_note', $id );

		$this->assertSame( Archive::path( $doc ), Download::credit_note_file_for( $order->get_id(), $id ) );

		add_filter( 'lumia_factures_allow_credited_refund_deletion', '__return_true' );
		$refund->delete( true );
		remove_filter( 'lumia_factures_allow_credited_refund_deletion', '__return_true' );

		$this->assertSame( Archive::path( $doc ), Download::credit_note_file_for( $order->get_id(), $id ) );

		foreach ( [ [ $other->get_id(), $id ], [ $order->get_id(), $order->get_id() ], [ $order->get_id(), 0 ] ] as [ $order_id, $refund_id ] ) {
			try {
				Download::credit_note_file_for( $order_id, $refund_id );
				$this->fail( 'Expected wp_die.' );
			} catch ( WPDieException $e ) {
				$this->assertSame( 404, $e->getCode() );
			}
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		try {
			Download::credit_note_file_for( $order->get_id(), $id );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
	}

	public function test_pending_notice_counts_only_refunds_after_since_or_flagged_and_button_issues_them(): void {
		$order = $this->issued_order();

		// Before credit notes existed: not counted, issued on demand only.
		$old = $this->refund_without_hook( $order, 1.0 );
		$old->set_date_created( time() - DAY_IN_SECONDS );
		$old->save();

		// Old too, but flagged pending: counted.
		$flagged = $this->refund_without_hook( $order, 2.0 );
		$flagged->set_date_created( time() - DAY_IN_SECONDS );
		$flagged->update_meta_data( CreditNoteIssuer::PENDING_META, 'import' );
		$flagged->save();

		update_option( CreditNoteIssuer::SINCE_OPTION, time() - HOUR_IN_SECONDS );

		// Since the option, missed by the hook: counted.
		$missed = $this->refund_without_hook( $order, 3.0 );

		// Since the option and issued: not counted.
		$this->refund( $order, 1.5 );

		$this->assertSame( [ $flagged->get_id(), $missed->get_id() ], CreditNoteNotice::pending_ids() );

		ob_start();
		CreditNoteNotice::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '2 refunds have no credit note yet.', $html );
		$this->assertStringContainsString( 'value="lumia_factures_invoices_issue_pending_credit_notes"', $html );
		$this->assertStringNotContainsString( 'id="_wpnonce"', $html );
		preg_match( '/name="_wpnonce" value="([^"]+)"/', $html, $match );
		$this->assertSame( 1, wp_verify_nonce( $match[1], 'lumia_factures_invoices_issue_pending_credit_notes' ) );

		// Not for a user who cannot manage WooCommerce.
		$manager = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		ob_start();
		CreditNoteNotice::render();
		$this->assertSame( '', ob_get_clean() );
		try {
			CreditNoteNotice::handle();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		wp_set_current_user( $manager );

		$this->assertSame(
			[
				'issued' => 2,
				'left'   => 0,
			],
			CreditNoteNotice::issue_pending()
		);
		$this->track( Register::find( 'credit_note', $flagged->get_id() ) );
		$this->track( Register::find( 'credit_note', $missed->get_id() ) );
		$this->assertNull( Register::find( 'credit_note', $old->get_id() ) );

		ob_start();
		CreditNoteNotice::render();
		$this->assertSame( '', ob_get_clean() );

		$_GET['lumia_factures_notice'] = 'credit_notes_pending_done';
		$_GET['lumia_factures_issued'] = '2';
		$_GET['lumia_factures_left']   = '0';
		ob_start();
		CreditNoteNotice::render();
		$this->assertStringContainsString( '2 credit notes issued.', (string) ob_get_clean() );
	}

	public function test_orders_list_column_shows_credit_note_numbers(): void {
		$order  = $this->issued_order();
		$first  = $this->refund( $order, 3.0 );
		$second = $this->refund( $order, 2.0 );

		ob_start();
		OrderTools::render_column( 'lumia_factures_invoice', $order->get_id() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( (string) Register::find( 'invoice', $order->get_id() )->number, $html );
		foreach ( [ $first, $second ] as $refund ) {
			$this->assertStringContainsString( (string) Register::find( 'credit_note', $refund->get_id() )->number, $html );
			$this->assertStringContainsString( 'refund_id=' . $refund->get_id(), $html );
		}
		$this->assertStringContainsString( 'lumia_factures_invoices_download_credit_note', $html );
		$this->assertLessThan(
			strpos( $html, (string) Register::find( 'credit_note', $first->get_id() )->number ),
			strpos( $html, (string) Register::find( 'invoice', $order->get_id() )->number ),
			'The credit notes come under the invoice number.'
		);
	}

	public function test_file_for_requires_capability(): void {
		$order = $this->issued_order();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );

		$this->expectException( WPDieException::class );
		Download::file_for( $order->get_id() );
	}

	public function test_file_for_returns_archived_path(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );

		$this->assertSame( Archive::path( $doc ), Download::file_for( $order->get_id() ) );
	}

	public function test_file_for_unissued_order_is_404(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		try {
			Download::file_for( $order->get_id() );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 404, $e->getCode() );
		}
	}

	public function test_file_for_builds_a_never_built_archive(): void {
		$fail = static function (): array {
			throw new RuntimeException( 'Render broken.' );
		};
		add_filter( 'lumia_factures_dompdf_options', $fail );
		$log = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		$old = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
		$order = $this->issued_order();
		ini_set( 'error_log', $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
		wp_delete_file( $log );
		remove_filter( 'lumia_factures_dompdf_options', $fail );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNull( $doc->file );

		$path = Download::file_for( $order->get_id() );
		$new  = Register::find( 'invoice', $order->get_id() );
		$this->track( $new );

		$this->assertSame( Archive::path( $new ), $path );
		$this->assertTrue( Archive::verify( $new ) );
		$this->assertSame( $doc->number, $new->number );
		$this->assertSame( $doc->issued_at, $new->issued_at );
	}

	public function test_file_for_missing_recorded_archive_is_404_and_points_to_rebuild(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );
		wp_delete_file( (string) Archive::path( $doc ) );

		try {
			Download::file_for( $order->get_id() );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 404, $e->getCode() );
			$this->assertStringContainsString( 'Rebuild missing PDF', $e->getMessage() );
		}

		// Nothing was rebuilt behind the admin's back.
		$after = Register::find( 'invoice', $order->get_id() );
		$this->assertSame( $doc->file, $after->file );
		$this->assertSame( $doc->checksum, $after->checksum );
	}

	public function test_issue_action_issues_invoice(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_status( 'processing' );
		remove_action( 'woocommerce_order_status_changed', [ \Lumia\Factures\Modules\Invoices\Documents\Issuer::class, 'on_status_changed' ], 10 );
		$order->save();
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );

		$this->assertSame( 'invoice_issued', OrderTools::issue_for( $order->get_id() ) );
		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $doc );
		$this->track( $doc );
		$this->assertSame( 'issued', $doc->status );

		$this->assertSame( 'invoice_not_issued', OrderTools::issue_for( 0 ) );
	}

	public function test_issue_action_refused_when_status_does_not_allow_it(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_status( 'pending' );
		$order->save();

		$this->assertSame( 'invoice_not_issued', OrderTools::issue_for( $order->get_id() ) );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
	}

	public function test_restore_action_refuses_intact_file(): void {
		$order = $this->issued_order();

		$this->assertSame( 'invoice_restore_refused', OrderTools::restore_for( $order->get_id() ) );
	}

	public function test_restore_action_rebuilds_missing_file(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );
		wp_delete_file( (string) Archive::path( $doc ) );

		$this->assertSame( 'invoice_restored', OrderTools::restore_for( $order->get_id() ) );
		$new = Register::find( 'invoice', $order->get_id() );
		$this->track( $new );
		$this->assertTrue( Archive::verify( $new ) );
	}

	public function test_download_url_carries_action_and_nonce(): void {
		$url = wp_specialchars_decode( Download::url( 42 ) ); // wp_nonce_url() escapes the ampersands.
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'lumia_factures_invoices_download', $query['action'] );
		$this->assertSame( '42', $query['order_id'] );
		$this->assertSame( 1, wp_verify_nonce( (string) $query['_wpnonce'], 'lumia_factures_invoices_download_42' ) );
		$this->assertFalse( wp_verify_nonce( (string) $query['_wpnonce'], 'lumia_factures_invoices_download_43' ) );
	}

	public function test_handlers_refuse_user_without_capability(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$id    = $order->get_id();
		$this->without_issue_hook();
		$order->set_status( 'processing' );
		$order->save();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$_GET['order_id'] = (string) $id;

		foreach ( [
			'issue'    => [ OrderTools::class, 'handle_issue' ],
			'restore'  => [ OrderTools::class, 'handle_restore' ],
			'download' => [ Download::class, 'handle' ],
		] as $name => $callback ) {
			$_GET['_wpnonce'] = wp_create_nonce( 'lumia_factures_invoices_' . $name . '_' . $id );
			try {
				$callback();
				$this->fail( 'Expected wp_die for ' . $name );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $e->getCode(), $name );
			}
		}

		$this->assertNull( Register::find( 'invoice', $id ) );
		unset( $_GET['order_id'], $_GET['_wpnonce'] );
	}

	public function test_handlers_refuse_missing_or_bad_nonce(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$id    = $order->get_id();
		$this->without_issue_hook();
		$order->set_status( 'processing' );
		$order->save();

		$_GET['order_id'] = (string) $id;

		foreach ( [ null, 'badnonce', wp_create_nonce( 'lumia_factures_invoices_issue_' . ( $id + 1 ) ) ] as $nonce ) {
			unset( $_GET['_wpnonce'] );
			if ( null !== $nonce ) {
				$_GET['_wpnonce'] = $nonce;
			}
			foreach ( [ [ OrderTools::class, 'handle_issue' ], [ OrderTools::class, 'handle_restore' ], [ Download::class, 'handle' ] ] as $callback ) {
				try {
					$callback();
					$this->fail( 'Expected wp_die for ' . $callback[1] );
				} catch ( WPDieException $e ) {
					$this->assertSame( 403, $e->getCode(), $callback[1] );
				}
			}
		}

		$this->assertNull( Register::find( 'invoice', $id ) );
		unset( $_GET['order_id'], $_GET['_wpnonce'] );
	}

	public function test_damaged_archive_is_never_served_nor_rebuilt(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );
		file_put_contents( (string) Archive::path( $doc ), 'damaged' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test corrupts the archive.

		try {
			Download::file_for( $order->get_id() );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 404, $e->getCode() );
			$this->assertStringContainsString( 'Rebuild missing PDF', $e->getMessage() );
		}

		$this->assertSame( $doc->file, Register::find( 'invoice', $order->get_id() )->file );
	}

	public function test_never_built_archive_that_cannot_be_built_is_404(): void {
		$fail = static function (): array {
			throw new RuntimeException( 'Render broken.' );
		};
		add_filter( 'lumia_factures_dompdf_options', $fail );
		$log = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		$old = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.

		try {
			$order = $this->issued_order();
			Download::file_for( $order->get_id() );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 404, $e->getCode() );
		} finally {
			ini_set( 'error_log', $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
			wp_delete_file( $log );
			remove_filter( 'lumia_factures_dompdf_options', $fail );
		}
	}

	public function test_list_column_hooks_are_registered_with_the_callback(): void {
		OrderTools::register();

		foreach ( [ 'manage_woocommerce_page_wc-orders_custom_column', 'manage_shop_order_posts_custom_column' ] as $hook ) {
			$this->assertSame( 10, has_action( $hook, [ OrderTools::class, 'render_column' ] ), $hook );
		}

		$order = $this->issued_order();
		ob_start();
		do_action( 'manage_woocommerce_page_wc-orders_custom_column', 'lumia_factures_invoice', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.ValidHookName.UseUnderscores -- WooCommerce hook.
		$hpos = (string) ob_get_clean();
		ob_start();
		do_action( 'manage_shop_order_posts_custom_column', 'lumia_factures_invoice', $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
		$legacy = (string) ob_get_clean();

		$this->assertStringContainsString( (string) Register::find( 'invoice', $order->get_id() )->number, $hpos );
		$this->assertSame( $hpos, $legacy );
	}

	public function test_module_init_registers_admin_hooks_only_in_admin(): void {
		remove_all_actions( 'add_meta_boxes' );
		remove_all_actions( 'admin_post_lumia_factures_invoices_download' );
		( new Module( 'invoices' ) )->init();
		$this->assertFalse( has_action( 'add_meta_boxes', [ OrderTools::class, 'add_meta_box' ] ) );
		$this->assertFalse( has_action( 'admin_post_lumia_factures_invoices_download', [ Download::class, 'handle' ] ) );

		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );
		( new Module( 'invoices' ) )->init();
		$this->assertSame( 10, has_action( 'add_meta_boxes', [ OrderTools::class, 'add_meta_box' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_lumia_factures_invoices_download', [ Download::class, 'handle' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_lumia_factures_invoices_issue', [ OrderTools::class, 'handle_issue' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_lumia_factures_invoices_restore', [ OrderTools::class, 'handle_restore' ] ) );
		// lumia-tools moves `admin_notices` into its drawer: the action result is printed on `all_admin_notices`.
		$this->assertSame( 10, has_action( 'all_admin_notices', [ OrderTools::class, 'render_notice' ] ) );
		$this->assertFalse( has_action( 'admin_notices', [ OrderTools::class, 'render_notice' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_lumia_factures_invoices_issue_credit_note', [ OrderTools::class, 'handle_issue_credit_note' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_lumia_factures_invoices_restore_credit_note', [ OrderTools::class, 'handle_restore_credit_note' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_lumia_factures_invoices_download_credit_note', [ Download::class, 'handle_credit_note' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_lumia_factures_invoices_cancel_invoice', [ OrderTools::class, 'handle_cancel' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_lumia_factures_invoices_issue_pending_credit_notes', [ CreditNoteNotice::class, 'handle' ] ) );
		$this->assertSame( 10, has_action( 'all_admin_notices', [ CreditNoteNotice::class, 'render' ] ) );
		$this->assertFalse( has_action( 'admin_notices', [ CreditNoteNotice::class, 'render' ] ) );
	}

	/**
	 * Stops the status change from issuing the invoice.
	 */
	private function without_issue_hook(): void {
		remove_action( 'woocommerce_order_status_changed', [ \Lumia\Factures\Modules\Invoices\Documents\Issuer::class, 'on_status_changed' ], 10 );
	}

	public function test_column_added_on_hpos_and_legacy_screens(): void {
		OrderTools::register();
		$columns = [
			'cb'           => '',
			'order_status' => 'Status',
			'order_total'  => 'Total',
		];

		foreach ( [ 'manage_woocommerce_page_wc-orders_columns', 'manage_edit-shop_order_columns' ] as $hook ) {
			$result = apply_filters( $hook, $columns ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core WooCommerce hook names.
			$this->assertSame( [ 'cb', 'order_status', 'lumia_factures_invoice', 'order_total' ], array_keys( $result ), $hook );
		}
	}

	public function test_column_renders_linked_number_or_dash(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );

		ob_start();
		OrderTools::render_column( 'lumia_factures_invoice', $order );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( (string) $doc->number, $html );
		$this->assertStringContainsString( 'target="_blank" rel="noopener"', $html );
		$this->assertStringContainsString( 'lumia_factures_invoices_download', $html );

		$other = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		ob_start();
		OrderTools::render_column( 'lumia_factures_invoice', $other->get_id() );
		$this->assertSame( '&#8212;', ob_get_clean() );
	}

	public function test_metabox_shows_number_when_issued(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );

		$html = $this->capture_metabox( $order );

		$this->assertStringContainsString( (string) $doc->number, $html );
		$this->assertStringContainsString( 'lumia_factures_invoices_download', $html );
		$this->assertStringNotContainsString( 'lumia_factures_invoices_restore', $html );

		wp_delete_file( (string) Archive::path( $doc ) );
		$this->assertStringContainsString( 'lumia_factures_invoices_restore', $this->capture_metabox( $order ) );
	}

	public function test_metabox_offers_issue_or_explains_status(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->set_status( 'processing' );
		remove_action( 'woocommerce_order_status_changed', [ \Lumia\Factures\Modules\Invoices\Documents\Issuer::class, 'on_status_changed' ], 10 );
		$order->save();
		$this->assertStringContainsString( 'lumia_factures_invoices_issue', $this->capture_metabox( $order ) );

		$order->set_status( 'pending' );
		$order->save();
		$html = $this->capture_metabox( wc_get_order( $order->get_id() ) );
		$this->assertStringNotContainsString( 'lumia_factures_invoices_issue', $html );
		$this->assertStringContainsString( 'does not allow an invoice', $html );
	}

	public function test_metabox_explains_a_legacy_conflict(): void {
		// The import is done (no legacy data is pending), but this order carries a legacy number
		// the import could not record: a conflict, not an import still running.
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->update_meta_data( '_fpl_invoice_number', 'FA-0004' );
		$order->save();
		update_option( \Lumia\Factures\Modules\Invoices\Migration\Migration::OPTION, [ 'status' => 'done_with_conflicts' ] );

		try {
			$html = $this->capture_metabox( wc_get_order( $order->get_id() ) );
		} finally {
			delete_option( \Lumia\Factures\Modules\Invoices\Migration\Migration::OPTION );
		}

		$this->assertStringContainsString( 'could not be imported', $html );
		$this->assertStringNotContainsString( 'until the Facture PDF Lumia data is imported', $html );
		$this->assertStringNotContainsString( 'lumia_factures_invoices_issue', $html );
		$this->assertSame( [], wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'Viewing the order never notes it.' );
	}

	public function test_notice_is_shown_only_on_order_screen_for_known_code(): void {
		$_GET['lumia_factures_notice']      = 'invoice_issued';
		$_GET['lumia_factures_notice_type'] = 'success';

		set_current_screen( 'dashboard' );
		ob_start();
		OrderTools::render_notice();
		$this->assertSame( '', ob_get_clean() );

		set_current_screen( 'shop_order' );
		ob_start();
		OrderTools::render_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice notice-success is-dismissible', $html );

		$_GET['lumia_factures_notice'] = 'bogus';
		ob_start();
		OrderTools::render_notice();
		$this->assertSame( '', ob_get_clean() );

		unset( $_GET['lumia_factures_notice'], $_GET['lumia_factures_notice_type'] );
	}

	/**
	 * Output of the meta box callback.
	 *
	 * @param WC_Order $order Order.
	 */
	private function capture_metabox( WC_Order $order ): string {
		ob_start();
		OrderTools::render_meta_box( $order );
		return (string) ob_get_clean();
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
		$this->track( $doc );

		return $refund;
	}

	/**
	 * A refund of an amount created without the `woocommerce_order_refunded` listener: no credit note.
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount.
	 */
	private function refund_without_hook( WC_Order $order, float $amount ): WC_Order_Refund {
		remove_action( 'woocommerce_order_refunded', [ CreditNoteIssuer::class, 'on_refunded' ], 10 );
		try {
			$refund = OrderFactory::refund( wc_get_order( $order->get_id() ), [], $amount );
		} finally {
			add_action( 'woocommerce_order_refunded', [ CreditNoteIssuer::class, 'on_refunded' ], 10, 2 );
		}
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );

		return $refund;
	}

	/**
	 * The refund as stored now.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 */
	private function refund_of( WC_Order_Refund $refund ): WC_Order_Refund {
		$fresh = wc_get_order( $refund->get_id() );
		$this->assertInstanceOf( WC_Order_Refund::class, $fresh );

		return $fresh;
	}

	/**
	 * Calls an admin-post handler and expects it to die with this code.
	 *
	 * @param callable $callback Handler.
	 * @param int      $code     HTTP code.
	 * @param string   $name     Handler name, for the failure message.
	 */
	private function assertHandlerDies( callable $callback, int $code, string $name ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit assertion style.
		try {
			$callback();
			$this->fail( 'Expected wp_die for ' . $name );
		} catch ( WPDieException $e ) {
			$this->assertSame( $code, $e->getCode(), $name );
		}
	}

	/**
	 * An order whose status change issued its invoice.
	 */
	private function issued_order(): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
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
