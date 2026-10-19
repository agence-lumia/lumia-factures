<?php
/**
 * Lifecycle of the legacy migration and the issuing gate.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;
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

	private const CONFLICT_NOTE = 'Invoice not issued: this order has a Facture PDF Lumia invoice that could not be imported; see the migration report.';

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
		delete_transient( Migration::NO_DATA_TRANSIENT );

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
		unset( $GLOBALS['current_screen'] );
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
		wp_delete_file( $this->log_file );
		parent::tear_down();
	}

	public function test_activation_with_legacy_data_marks_the_import_pending(): void {
		$order = $this->legacy_order( 'FA-0005', 5, true );

		$this->activate();

		// Inside activate_plugin() the plugin has not booted: the import waits for the next request.
		$this->assertSame( 'pending', Migration::state()['status'] );
		$this->assertTrue( Migration::blocks_issuing() );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( 0, Sequences::current( $this->key ) );
		$this->assertFalse( get_option( Migration::LOCK ) );

		// The next manager admin request runs it.
		set_current_screen( 'dashboard' );
		Migration::boot();

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
		// The import is done: the note names the permanent conflict, once.
		$this->assertSame( 0, $this->count_notes( $orphan->get_id(), self::GATE_NOTE ) );
		$this->assertSame( 1, $this->count_notes( $orphan->get_id(), self::CONFLICT_NOTE ) );
	}

	public function test_import_note_then_conflict_note_are_each_written_once(): void {
		// Pending import: the order with a legacy number gets the import note.
		$this->legacy_order( 'FA-0005', 5, false );
		$orphan = $this->order();
		$orphan->update_meta_data( '_fpl_invoice_number', 'FA-0005' );
		$orphan->save();
		Migration::prepare();

		wc_get_order( $orphan->get_id() )->update_status( 'processing' );
		$this->assertSame( 1, $this->count_notes( $orphan->get_id(), self::GATE_NOTE ) );

		// The import reports it as a conflict (duplicate number): the conflict note follows, once.
		Migration::maybe_run();
		$this->assertSame( 'done_with_conflicts', Migration::state()['status'] );
		wc_get_order( $orphan->get_id() )->update_status( 'completed' );
		Issuer::issue( wc_get_order( $orphan->get_id() ) );

		$this->assertNull( Register::find( 'invoice', $orphan->get_id() ) );
		$this->assertSame( 1, $this->count_notes( $orphan->get_id(), self::GATE_NOTE ) );
		$this->assertSame( 1, $this->count_notes( $orphan->get_id(), self::CONFLICT_NOTE ) );
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

		// Back-off: an automatic retry waits 15 minutes after the failure.
		Migration::maybe_run();
		$this->assertSame( 'failed', Migration::state()['status'] );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );

		// A manual retry (notice button, WP-CLI) does not wait.
		Migration::maybe_run( true );
		$this->assertSame( 'done', Migration::state()['status'] );
		$this->assertSame( 'FA-0005', Register::find( 'invoice', $order->get_id() )->number );
	}

	public function test_failed_run_is_retried_automatically_after_the_delay(): void {
		$order = $this->legacy_order( 'FA-0005', 5, false );
		update_option(
			Migration::OPTION,
			[
				'status'      => 'failed',
				'started_at'  => time() - 20 * MINUTE_IN_SECONDS,
				'finished_at' => time() - 16 * MINUTE_IN_SECONDS,
				'error'       => 'boom',
			]
		);

		Migration::maybe_run();

		$this->assertSame( 'done', Migration::state()['status'] );
		$this->assertSame( 'FA-0005', Register::find( 'invoice', $order->get_id() )->number );
	}

	public function test_anonymous_admin_request_does_not_run_the_import(): void {
		$order = $this->legacy_order( 'FA-0005', 5, false );
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );

		// admin-post.php or any /wp-admin/ URL fires init before authentication.
		wp_set_current_user( 0 );
		Migration::boot();
		$this->assertSame( 'none', Migration::state()['status'] );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );

		// A customer neither.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		Migration::boot();
		$this->assertSame( 'none', Migration::state()['status'] );

		// The retry action runs the import itself, not the init auto-run.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$_REQUEST['action'] = MigrationNotice::ACTION;
		Migration::boot();
		$this->assertSame( 'none', Migration::state()['status'] );

		// A manager on an admin page does.
		unset( $_REQUEST['action'] );
		Migration::boot();
		$this->assertSame( 'done', Migration::state()['status'] );
		$this->assertSame( 'FA-0005', Register::find( 'invoice', $order->get_id() )->number );
	}

	public function test_front_end_request_does_not_run_the_import(): void {
		$this->legacy_order( 'FA-0005', 5, false );
		$this->assertFalse( is_admin() );

		Migration::boot();

		$this->assertSame( 'none', Migration::state()['status'] );
	}

	public function test_wp_cli_auto_runs_only_for_cron_event_run(): void {
		$this->assertTrue( Migration::cli_may_auto_run( [ 'cron', 'event', 'run', '--due-now' ] ) );
		$this->assertTrue( Migration::cli_may_auto_run( [ 'cron', 'event', 'run', 'woocommerce_cleanup_sessions' ] ) );

		// Other commands never start it: our own migrate runs it explicitly, its dry run must not.
		$this->assertFalse( Migration::cli_may_auto_run( [ 'lumia-factures', 'migrate', '--dry-run' ] ) );
		$this->assertFalse( Migration::cli_may_auto_run( [ 'lumia-factures', 'migration-status' ] ) );
		$this->assertFalse( Migration::cli_may_auto_run( [ 'cron', 'event', 'list' ] ) );
		$this->assertFalse( Migration::cli_may_auto_run( [ 'option', 'get', 'siteurl' ] ) );
		$this->assertFalse( Migration::cli_may_auto_run( [ 'plugin', 'deactivate', 'facture-pdf-lumia' ] ) );
		$this->assertFalse( Migration::cli_may_auto_run( [] ) );
	}

	public function test_heartbeat_keeps_a_long_run_from_being_taken_over(): void {
		global $wpdb;

		$this->legacy_order( 'FA-0005', 5, false );
		Migration::prepare();

		$old     = ( time() - 20 * MINUTE_IN_SECONDS ) . '-long';
		$checked = false;

		// Before the heartbeat: the run looks 20 minutes old.
		$age = static function () use ( $wpdb, $old ): void {
			$wpdb->update( $wpdb->options, [ 'option_value' => $old ], [ 'option_name' => Migration::LOCK ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( Migration::LOCK, 'options' );
			$token = new \ReflectionProperty( Migration::class, 'token' );
			$token->setAccessible( true );
			$token->setValue( null, $old );
		};

		// After the heartbeat: a second request that saw the old value cannot take the lock over.
		$takeover = function () use ( $wpdb, $old, &$checked ): void {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->assertSame( 0, $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = 'thief' WHERE option_name = %s AND option_value = %s", Migration::LOCK, $old ) ) );
			$this->assertNull( Migration::run() );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Migration::LOCK ) );
			$this->assertGreaterThanOrEqual( time() - 5, (int) $current );
			$this->assertGreaterThanOrEqual( time() - 5, Migration::state()['started_at'] );
			$this->assertSame( 'running', Migration::state()['status'] );
			$checked = true;
		};

		add_action( 'lumia_factures_legacy_import_batch_done', $age, 5 );
		add_action( 'lumia_factures_legacy_import_batch_done', $takeover, 20 );
		try {
			Migration::maybe_run( true );
		} finally {
			remove_action( 'lumia_factures_legacy_import_batch_done', $age, 5 );
			remove_action( 'lumia_factures_legacy_import_batch_done', $takeover, 20 );
		}

		$this->assertTrue( $checked );
		$this->assertSame( 'done', Migration::state()['status'] );
		$this->assertFalse( get_option( Migration::LOCK ) );
	}

	public function test_negative_legacy_lookup_is_cached_and_cleared_by_the_retry(): void {
		$this->assertFalse( Migration::blocks_issuing() );
		$this->assertNotFalse( get_transient( Migration::NO_DATA_TRANSIENT ) );

		// Legacy data found later: the cached answer holds until the retry action or an activation.
		$order = $this->legacy_order( 'FA-0005', 5, false );
		$this->assertFalse( Migration::blocks_issuing() );

		$this->assertSame( 'migration_done', MigrationNotice::migrate_for() );
		$this->assertFalse( get_transient( Migration::NO_DATA_TRANSIENT ) );
		$this->assertSame( 'FA-0005', Register::find( 'invoice', $order->get_id() )->number );
	}

	public function test_activation_clears_the_negative_legacy_lookup(): void {
		$this->assertFalse( Migration::blocks_issuing() );
		$order = $this->legacy_order( 'FA-0005', 5, false );

		$this->activate();
		$this->assertSame( 'pending', Migration::state()['status'] );
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

	public function test_conflict_notice_lists_the_orders_until_dismissed(): void {
		$this->legacy_order( 'FA-0005', 5, false );
		$second = $this->legacy_order( 'FA-0005', 5, false );
		Migration::prepare();
		Migration::maybe_run();
		$this->assertSame( 'done_with_conflicts', Migration::state()['status'] );

		$html = $this->conflicts_notice();
		$this->assertStringContainsString( 'lumia-factures-conflicts-notice', $html );
		$this->assertStringContainsString( '1 Facture PDF Lumia invoice was not imported', $html );
		$this->assertStringContainsString( 'href="' . esc_url( OrderUtil::get_order_admin_edit_url( $second->get_id() ) ) . '"', $html );
		$this->assertStringContainsString( 'FA-0005', $html );
		$this->assertStringContainsString( 'value="' . MigrationNotice::DISMISS_ACTION . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		// The blocking notice is gone: the import is finished.
		$this->assertSame( '', $this->notice() );

		// Managers only.
		$manager = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->assertSame( '', $this->conflicts_notice() );

		// Dismissal needs the capability and the nonce.
		$this->post( wp_create_nonce( MigrationNotice::DISMISS_ACTION ), MigrationNotice::DISMISS_ACTION );
		$this->assert_dies( 403, [ MigrationNotice::class, 'dismiss' ] );
		wp_set_current_user( $manager );
		$this->post( 'badnonce', MigrationNotice::DISMISS_ACTION );
		$this->assert_dies( 403, [ MigrationNotice::class, 'dismiss' ] );
		$this->assertNotSame( '', $this->conflicts_notice() );

		// Dismissed: stored per user, until a new run reports conflicts again.
		$this->post( wp_create_nonce( MigrationNotice::DISMISS_ACTION ), MigrationNotice::DISMISS_ACTION );
		add_filter( 'wp_redirect', [ $this, 'throw_redirect' ] );
		try {
			MigrationNotice::dismiss();
			$this->fail( 'No redirect.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringStartsWith( 'redirect:', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', [ $this, 'throw_redirect' ] );
		}
		$this->assertSame( '', $this->conflicts_notice() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$this->assertNotSame( '', $this->conflicts_notice() );

		wp_set_current_user( $manager );
		$state                = Migration::state();
		$state['finished_at'] = $state['finished_at'] + 60;
		update_option( Migration::OPTION, $state );
		$this->assertNotSame( '', $this->conflicts_notice() );
	}

	/**
	 * Turns a redirect into an exception the test catches.
	 *
	 * @param string $location Location.
	 * @throws RuntimeException Always.
	 */
	public function throw_redirect( $location ): void {
		throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the test.
	}

	/**
	 * Output of the conflicts notice.
	 */
	private function conflicts_notice(): string {
		ob_start();
		MigrationNotice::render_conflicts();

		return (string) ob_get_clean();
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
	 * Sets an admin-post request (the retry by default).
	 *
	 * @param string|null $nonce  Nonce, null for none.
	 * @param string      $action Admin-post action.
	 */
	private function post( ?string $nonce, string $action = MigrationNotice::ACTION ): void {
		$_POST = [ 'action' => $action ];
		if ( null !== $nonce ) {
			$_POST['_wpnonce'] = $nonce;
		}
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test sets the request.
	}

	/**
	 * Runs a handler (the retry by default) and expects wp_die with a status code.
	 *
	 * @param int           $code    HTTP status.
	 * @param callable|null $handler Handler.
	 */
	private function assert_dies( int $code, ?callable $handler = null ): void {
		try {
			( $handler ?? [ MigrationNotice::class, 'handle' ] )();
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
