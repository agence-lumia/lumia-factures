<?php
/**
 * Lifecycle of the legacy migration and the issuing gate.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Admin\MigrationNotice;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\LegacyFixture;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use RuntimeException;
use WC_Order;
use WPDieException;

/**
 * Migration state, run guard, issuing gate and the retry action.
 */
class MigrationTest extends TestCase {

	private const GATE_NOTE = 'Invoice not issued: the Facture PDF Lumia data is being imported.';

	private LegacyFixture $legacy;

	private string $key = '';

	private string $log_file = '';

	private string $previous_log = '';

	/**
	 * Orders whose archived files are removed in tear_down.
	 *
	 * @var int[]
	 */
	private array $orders = [];

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'timezone_string', 'Europe/Paris' );
		delete_option( 'lumia_factures_module_invoices' );
		delete_option( 'fpl_settings' );
		delete_option( Migration::OPTION );
		delete_option( Migration::LOCK );

		$this->legacy = new LegacyFixture();
		$this->key    = 'invoice_' . wp_date( 'Y' );

		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
	}

	public function tear_down(): void {
		foreach ( $this->orders as $order_id ) {
			$doc  = Register::find( 'invoice', $order_id );
			$path = null !== $doc ? Archive::path( $doc ) : null;
			if ( null !== $path && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		$this->legacy->cleanup();
		$_POST    = [];
		$_GET     = [];
		$_REQUEST = [];
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
		wp_delete_file( $this->log_file );
		parent::tear_down();
	}

	public function test_activation_with_legacy_data_runs_migration(): void {
		$order = $this->legacy_order( 'FA-0005', 5, true );

		$this->activate();

		$state = Migration::state();
		$this->assertSame( 'done', $state['status'] );
		$this->assertGreaterThan( 0, $state['started_at'] );
		$this->assertGreaterThanOrEqual( $state['started_at'], $state['finished_at'] );
		$this->assertSame( 1, $state['report']['invoices_imported'] );
		$this->assertSame( 1, $state['report']['files_copied'] );
		$this->assertFalse( Migration::blocks_issuing() );
		$this->assertFalse( get_option( Migration::LOCK ) );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $doc );
		$this->assertSame( 'FA-0005', $doc->number );
		$this->assertSame( 5, Sequences::current( $this->key ) );
	}

	public function test_site_without_legacy_data_is_unaffected(): void {
		$this->activate();
		Migration::prepare();
		Migration::maybe_run();

		$this->assertSame( 'none', Migration::state()['status'] );
		$this->assertFalse( get_option( Migration::OPTION ) );
		$this->assertFalse( Migration::blocks_issuing() );

		$order = $this->order();
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->assertSame( 0, $this->count_notes( $order->get_id(), self::GATE_NOTE ) );
	}

	public function test_issuing_is_blocked_until_migration_done(): void {
		$legacy = $this->legacy_order( 'FA-0005', 5, false );
		Migration::prepare();
		$this->assertSame( 'pending', Migration::state()['status'] );
		$this->assertTrue( Migration::blocks_issuing() );

		$order = $this->order();
		$order->update_status( 'processing' );

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( 0, Sequences::current( $this->key ) );
		$this->assertSame( 1, $this->count_notes( $order->get_id(), self::GATE_NOTE ) );

		// The email fires too: still no number, no attachment, and no second note.
		$fresh = wc_get_order( $order->get_id() );
		$this->assertNull( Issuer::issue( $fresh ) );
		$this->assertSame( [], \Lumia\Factures\Modules\Invoices\Emails\Attachments::attach( [], 'customer_processing_order', $fresh ) );
		$this->assertSame( 1, $this->count_notes( $order->get_id(), self::GATE_NOTE ) );

		Migration::maybe_run();
		$this->assertSame( 'done', Migration::state()['status'] );
		$this->assertFalse( Migration::blocks_issuing() );

		// The next trigger issues normally, after the legacy numbers.
		wc_get_order( $order->get_id() )->update_status( 'completed' );
		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->assertSame( 6, $doc->sequence );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( Issuer::GATE_FLAG ) );

		// The legacy-invoiced order resolves to its imported invoice.
		$this->assertSame( 'FA-0005', Issuer::issue( wc_get_order( $legacy->get_id() ) )->number );
	}

	public function test_legacy_invoiced_order_is_never_renumbered(): void {
		$legacy = $this->legacy_order( 'FA-0005', 5, false );
		Migration::prepare();
		Migration::maybe_run();
		$this->assertSame( 'done', Migration::state()['status'] );

		wc_get_order( $legacy->get_id() )->update_status( 'processing' );
		$doc = Register::find( 'invoice', $legacy->get_id() );
		$this->assertSame( 'FA-0005', $doc->number );
		$this->assertSame( 5, Sequences::current( $this->key ) );

		// A legacy number with no register row (e.g. a conflict) is refused, even when a filter says yes.
		$orphan = $this->order();
		$orphan->update_meta_data( '_fpl_invoice_number', 'FA-0004' );
		$orphan->save();
		add_filter( 'lumia_factures_can_issue', '__return_true' );
		try {
			wc_get_order( $orphan->get_id() )->update_status( 'processing' );
			$this->assertFalse( Issuer::can_issue( wc_get_order( $orphan->get_id() ) ) );
		} finally {
			remove_filter( 'lumia_factures_can_issue', '__return_true' );
		}

		$this->assertNull( Register::find( 'invoice', $orphan->get_id() ) );
		$this->assertSame( 5, Sequences::current( $this->key ) );
		$this->assertSame( 1, $this->count_notes( $orphan->get_id(), self::GATE_NOTE ) );
	}

	public function test_filter_cannot_bypass_the_pending_migration(): void {
		$this->legacy_order( 'FA-0005', 5, false );
		Migration::prepare();

		$order = $this->order();
		add_filter( 'lumia_factures_can_issue', '__return_true' );
		try {
			$order->update_status( 'processing' );
		} finally {
			remove_filter( 'lumia_factures_can_issue', '__return_true' );
		}

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
	}

	public function test_concurrent_run_is_refused(): void {
		$order = $this->legacy_order( 'FA-0005', 5, false );
		Migration::prepare();

		// Another request holds the lock.
		add_option( Migration::LOCK, time() . '-other', '', false );
		Migration::maybe_run();
		$this->assertNull( Migration::run() );

		$this->assertSame( 'pending', Migration::state()['status'] );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( time() . '-other', substr( (string) get_option( Migration::LOCK ), 0, strlen( time() . '-other' ) ) );

		// A run in progress (fresh running state) is left alone too.
		delete_option( Migration::LOCK );
		update_option(
			Migration::OPTION,
			[
				'status'     => 'running',
				'started_at' => time(),
			]
		);
		Migration::maybe_run();
		$this->assertSame( 'running', Migration::state()['status'] );
		$this->assertTrue( Migration::blocks_issuing() );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
	}

	public function test_stale_running_state_is_retried(): void {
		$order = $this->legacy_order( 'FA-0005', 5, false );

		// A request died mid-run 20 minutes ago: its state and its lock were never cleared.
		$started = time() - 20 * MINUTE_IN_SECONDS;
		update_option(
			Migration::OPTION,
			[
				'status'     => 'running',
				'started_at' => $started,
			]
		);
		add_option( Migration::LOCK, $started . '-dead', '', false );

		$this->assertSame( 'failed', Migration::state()['status'] );
		$this->assertTrue( Migration::blocks_issuing() );

		Migration::maybe_run();

		$this->assertSame( 'done', Migration::state()['status'] );
		$this->assertSame( 'FA-0005', Register::find( 'invoice', $order->get_id() )->number );
		$this->assertFalse( get_option( Migration::LOCK ) );
	}

	public function test_failed_run_is_recorded_and_retried(): void {
		$order = $this->legacy_order( 'FA-0005', 5, false );
		Migration::prepare();

		// The importer throws on its first read.
		$boom = static function () {
			throw new RuntimeException( 'boom' );
		};
		add_filter( 'pre_option_fpl_settings', $boom );
		try {
			Migration::maybe_run();
		} finally {
			remove_filter( 'pre_option_fpl_settings', $boom );
		}

		$state = Migration::state();
		$this->assertSame( 'failed', $state['status'] );
		$this->assertStringContainsString( 'boom', $state['error'] );
		$this->assertFalse( get_option( Migration::LOCK ) );
		$this->assertTrue( Migration::blocks_issuing() );

		Migration::maybe_run();
		$this->assertSame( 'done', Migration::state()['status'] );
		$this->assertSame( 'FA-0005', Register::find( 'invoice', $order->get_id() )->number );
	}

	public function test_retry_action_requires_nonce_and_capability(): void {
		$order = $this->legacy_order( 'FA-0005', 5, false );
		Migration::prepare();

		// No capability, valid nonce.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->post( wp_create_nonce( MigrationNotice::ACTION ) );
		$this->assert_dies( 403 );

		// Capability, missing then bad nonce.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$this->post( null );
		$this->assert_dies( 403 );
		$this->post( 'badnonce' );
		$this->assert_dies( 403 );

		$this->assertSame( 'pending', Migration::state()['status'] );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );

		// Capability and nonce: runs, then redirects with the outcome.
		$this->post( wp_create_nonce( MigrationNotice::ACTION ) );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the test.
			}
		);
		try {
			MigrationNotice::handle();
			$this->fail( 'No redirect.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'lumia_factures_notice=migration_done', $e->getMessage() );
		}

		$this->assertSame( 'done', Migration::state()['status'] );
		$this->assertSame( 'FA-0005', Register::find( 'invoice', $order->get_id() )->number );
	}

	public function test_blocking_notice_is_shown_to_managers_only(): void {
		$this->legacy_order( 'FA-0005', 5, false );
		Migration::prepare();

		$html = $this->notice();
		$this->assertStringContainsString( 'Run the import now', $html );
		$this->assertStringContainsString( 'value="' . MigrationNotice::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->assertSame( '', $this->notice() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		Migration::maybe_run();
		$this->assertSame( '', $this->notice() );
	}

	/**
	 * Runs the module activation without its dbDelta: dbDelta may ALTER the existing tables, and
	 * any DDL commits the test transaction, which would leak the legacy fixtures into the next
	 * tests. The tables already exist (tests/bootstrap.php).
	 */
	private function activate(): void {
		add_filter( 'dbdelta_queries', '__return_empty_array' );
		try {
			( new Module( 'invoices' ) )->on_activate();
		} finally {
			remove_filter( 'dbdelta_queries', '__return_empty_array' );
		}
	}

	/**
	 * A saved order with one product.
	 */
	private function order(): WC_Order {
		$order          = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$this->orders[] = $order->get_id();

		return $order;
	}

	/**
	 * An order invoiced by the legacy plugin, with the legacy counter table.
	 *
	 * @param string $number   Legacy number.
	 * @param int    $sequence Legacy counter value.
	 * @param bool   $pdf      Whether the legacy PDF is still on disk.
	 */
	private function legacy_order( string $number, int $sequence, bool $pdf ): WC_Order {
		$this->legacy->sequences( [ $this->key => $sequence ] );
		$order = $this->order();
		$this->legacy->invoice( $order, $number, $sequence, $this->key, (int) strtotime( '-1 day' ), $pdf ? LegacyFixture::pdf( $number ) : null );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Notes of an order containing a text.
	 *
	 * @param int    $order_id Order id.
	 * @param string $text     Text.
	 */
	private function count_notes( int $order_id, string $text ): int {
		$count = 0;
		foreach ( wc_get_order_notes( [ 'order_id' => $order_id ] ) as $note ) {
			if ( str_contains( $note->content, $text ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Sets the retry request.
	 *
	 * @param string|null $nonce Nonce, null for none.
	 */
	private function post( ?string $nonce ): void {
		$_POST = [ 'action' => MigrationNotice::ACTION ];
		if ( null !== $nonce ) {
			$_POST['_wpnonce'] = $nonce;
		}
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test sets the request.
	}

	/**
	 * Runs the handler and expects wp_die with a status code.
	 *
	 * @param int $code HTTP status.
	 */
	private function assert_dies( int $code ): void {
		try {
			MigrationNotice::handle();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $code, $e->getCode() );
		}
	}

	/**
	 * Output of the blocking notice.
	 */
	private function notice(): string {
		ob_start();
		MigrationNotice::render();

		return (string) ob_get_clean();
	}
}
