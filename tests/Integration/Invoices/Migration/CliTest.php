<?php
/**
 * WP-CLI commands of the legacy migration.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\Requirements;
use Lumia\Factures\Modules\Invoices\Cli\Command;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Tests\Support\LegacyFixture;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;

/**
 * The command logic lives in methods returning data: WP-CLI is not loaded in the suite.
 */
class CliTest extends TestCase {

	private LegacyFixture $legacy;

	private string $key = '';

	private string $log_file = '';

	private string $previous_log = '';

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		delete_option( 'lumia_factures_module_invoices' );
		delete_option( 'fpl_settings' );
		delete_option( Migration::OPTION );
		delete_option( Migration::LOCK );

		$this->legacy = new LegacyFixture();
		$this->key    = 'invoice_' . wp_date( 'Y' );

		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
	}

	public function tear_down(): void {
		$this->legacy->cleanup();
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
		wp_delete_file( $this->log_file );
		parent::tear_down();
	}

	public function test_cli_dry_run_reports_without_writing(): void {
		$a = $this->legacy_order( 'FA-0004', 4 );
		$b = $this->legacy_order( 'FA-0004', 4 );
		$this->legacy->sequences( [ $this->key => 4 ] );

		$result = Command::migrate_data( true, false );

		$this->assertSame( 'dry_run', $result['outcome'] );
		$this->assertSame( '1', $this->row( $result['rows'], 'Invoices imported' ) );
		$this->assertSame( '4', $this->row( $result['rows'], 'Counter ' . $this->key ) );
		$this->assertSame( [ $b->get_id() ], array_column( $result['conflicts'], 'order_id' ) );

		$this->assertNull( Register::find( 'invoice', $a->get_id() ) );
		$this->assertSame( 0, Sequences::current( $this->key ) );
		$this->assertFalse( get_option( Migration::OPTION ) );
		$this->assertFalse( get_option( Migration::LOCK ) );
	}

	public function test_cli_migrate_runs_once_unless_forced(): void {
		$order = $this->legacy_order( 'FA-0004', 4 );

		$first = Command::migrate_data( false, false );
		$this->assertSame( 'done', $first['outcome'] );
		$this->assertSame( '1', $this->row( $first['rows'], 'Invoices imported' ) );
		$this->assertSame( 'FA-0004', Register::find( 'invoice', $order->get_id() )->number );
		$this->assertSame( 'done', Migration::state()['status'] );

		$second = Command::migrate_data( false, false );
		$this->assertSame( 'already_done', $second['outcome'] );
		$this->assertSame( [], $second['rows'] );

		$forced = Command::migrate_data( false, true );
		$this->assertSame( 'done', $forced['outcome'] );
		$this->assertSame( '0', $this->row( $forced['rows'], 'Invoices imported' ) );
		$this->assertSame( '1', $this->row( $forced['rows'], 'Invoices already imported' ) );
	}

	public function test_cli_conflicts_end_done_with_conflicts(): void {
		$this->legacy_order( 'FA-0004', 4 );
		$this->legacy_order( 'FA-0004', 4 );

		$result = Command::migrate_data( false, false );

		$this->assertSame( 'done_with_conflicts', $result['outcome'] );
		$this->assertCount( 1, $result['conflicts'] );
		$this->assertSame( 'done_with_conflicts', Migration::state()['status'] );
		$this->assertFalse( Migration::blocks_issuing() );
	}

	public function test_cli_without_legacy_data_does_nothing(): void {
		$result = Command::migrate_data( false, false );

		$this->assertSame( 'no_data', $result['outcome'] );
		$this->assertFalse( get_option( Migration::OPTION ) );

		// --force does not change that: nothing to import, nothing written.
		$forced = Command::migrate_data( false, true );
		$this->assertSame( 'no_data', $forced['outcome'] );
		$this->assertFalse( get_option( Migration::OPTION ) );
		$this->assertFalse( get_option( 'lumia_factures_module_invoices' ) );
	}

	public function test_cli_status_rows(): void {
		$this->legacy_order( 'FA-0004', 4 );
		Migration::prepare();

		$rows = Command::status_rows();
		$this->assertSame( 'pending', $this->row( $rows, 'Status' ) );
		$this->assertSame( 'yes', $this->row( $rows, 'Issuing blocked' ) );

		Command::migrate_data( false, false );
		$rows = Command::status_rows();
		$this->assertSame( 'done', $this->row( $rows, 'Status' ) );
		$this->assertSame( 'no', $this->row( $rows, 'Issuing blocked' ) );
		$this->assertSame( '1', $this->row( $rows, 'Invoices imported' ) );
	}

	/**
	 * The runbook's dry run happens while the legacy plugin is still active: the modules do not
	 * start, but the read-only commands work and a real run is refused.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cli_dry_run_and_status_work_while_the_legacy_plugin_is_active(): void {
		define( 'FPL_VERSION', '1.1.1' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the legacy plugin.
		$this->assertArrayHasKey( 'legacy_active', Requirements::check() );

		$order = $this->legacy_order( 'FA-0004', 4 );
		$this->legacy->sequences( [ $this->key => 4 ] );

		$dry = Command::migrate_data( true, false );
		$this->assertSame( 'dry_run', $dry['outcome'] );
		$this->assertSame( '1', $this->row( $dry['rows'], 'Invoices imported' ) );
		$this->assertSame( '4', $this->row( $dry['rows'], 'Counter ' . $this->key ) );

		// A real run, forced or not, is refused with the reason.
		foreach ( [ false, true ] as $force ) {
			$real = Command::migrate_data( false, $force );
			$this->assertSame( 'blocked', $real['outcome'] );
			$this->assertStringContainsString( 'Facture PDF Lumia', $real['error'] );
		}

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( 0, Sequences::current( $this->key ) );
		$this->assertFalse( get_option( Migration::OPTION ) );
		$this->assertFalse( get_option( Migration::LOCK ) );

		// The status works too, and says what blocks the plugin.
		$rows = Command::status_rows();
		$this->assertSame( 'none', $this->row( $rows, 'Status' ) );
		$this->assertSame( 'legacy_active', $this->row( $rows, 'Blocked by' ) );
		$this->assertSame( '4', $this->row( $rows, 'Highest legacy sequence ' . $this->key ) );
	}

	public function test_cli_status_compares_counters_with_the_legacy_sequences(): void {
		$this->legacy_order( 'FA-0004', 4 );

		$rows = Command::status_rows();
		$this->assertSame( '-', $this->row( $rows, 'Blocked by' ) );
		$this->assertSame( '4', $this->row( $rows, 'Highest legacy sequence ' . $this->key ) );
		$this->assertNull( $this->row( $rows, 'Current counter ' . $this->key ) );

		Command::migrate_data( false, false );
		$this->assertSame( '4', $this->row( Command::status_rows(), 'Current counter ' . $this->key ) );
	}

	public function test_cli_dry_run_without_our_tables_reads_an_empty_register(): void {
		$order = $this->legacy_order( 'FA-0004', 4 );
		$this->assertSame( 'done', Command::migrate_data( false, false )['outcome'] );
		Sequences::raise( $this->key, 10 );

		// Our tables do not exist yet (the plugin never booted): empty register, counters at 0.
		add_filter( 'lumia_factures_tables_exist', '__return_false' );
		try {
			$dry  = Command::migrate_data( true, false );
			$rows = Command::status_rows();
		} finally {
			remove_filter( 'lumia_factures_tables_exist', '__return_false' );
		}

		$this->assertSame( 'dry_run', $dry['outcome'] );
		$this->assertSame( '1', $this->row( $dry['rows'], 'Invoices imported' ) );
		$this->assertSame( '0', $this->row( $dry['rows'], 'Invoices already imported' ) );
		$this->assertSame( '4', $this->row( $dry['rows'], 'Counter ' . $this->key ) );
		$this->assertNull( $this->row( $rows, 'Current counter ' . $this->key ) );
		$this->assertSame( 'FA-0004', Register::find( 'invoice', $order->get_id() )->number );
		$this->assertSame( 10, Sequences::current( $this->key ) );
	}

	/**
	 * Value of a field row.
	 *
	 * @param array<int, array{field: string, value: string}> $rows  Rows.
	 * @param string                                          $field Field label.
	 */
	private function row( array $rows, string $field ): ?string {
		foreach ( $rows as $row ) {
			if ( $field === $row['field'] ) {
				return $row['value'];
			}
		}

		return null;
	}

	/**
	 * An order invoiced by the legacy plugin.
	 *
	 * @param string $number   Legacy number.
	 * @param int    $sequence Legacy counter value.
	 */
	private function legacy_order( string $number, int $sequence ): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$this->legacy->invoice( $order, $number, $sequence, $this->key, (int) strtotime( '-1 day' ), null );

		return wc_get_order( $order->get_id() );
	}
}
