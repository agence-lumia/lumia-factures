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
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\LegacyFixture;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;
use WC_Order_Refund;

/**
 * The command logic lives in methods returning data: WP-CLI is not loaded in the suite.
 */
class CliTest extends TestCase {

	private LegacyFixture $legacy;

	private string $key = '';

	private string $log_file = '';

	private string $previous_log = '';

	/**
	 * Archived files written by the test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

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
		foreach ( array_unique( $this->written ) as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
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

	public function test_cli_status_does_not_touch_woocommerce_when_it_is_missing(): void {
		$this->legacy_order( 'FA-0004', 4 );

		add_filter( 'lumia_factures_requirements_wc_active', '__return_false' );
		try {
			$rows = Command::status_rows();
		} finally {
			remove_filter( 'lumia_factures_requirements_wc_active', '__return_false' );
		}

		$this->assertSame( 'woocommerce', $this->row( $rows, 'Blocked by' ) );
		$this->assertSame( 'unknown', $this->row( $rows, 'Issuing blocked' ) );
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

	public function test_issue_credit_notes_dry_run_lists_without_reserving_or_numbering(): void {
		$order  = $this->invoiced_order();
		$refund = $this->refund_without_hook( $order, 4.0 );
		$zero   = $this->refund_without_hook( $order, 0.0 );
		$key    = $this->credit_note_key();
		$before = Sequences::current( $key );

		$result = Command::credit_notes_data( '', '', false, true );

		$this->assertSame( 'dry_run', $result['outcome'] );
		$rows = $this->by_refund( $result['rows'] );
		$this->assertSame( 'would_issue', $rows[ $refund->get_id() ]['status'] );
		$this->assertSame( $order->get_id(), $rows[ $refund->get_id() ]['order_id'] );
		$this->assertSame( '4.00', $rows[ $refund->get_id() ]['amount'] );
		$this->assertSame( '-', $rows[ $refund->get_id() ]['number'] );
		$this->assertSame( 'zero_amount', $rows[ $zero->get_id() ]['status'] );

		// Nothing reserved, numbered nor flagged.
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );
		$this->assertSame( $before, Sequences::current( $key ) );
		$fresh = wc_get_order( $refund->get_id() );
		$this->assertSame( '', $fresh->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertSame( '', $fresh->get_meta( CreditNoteIssuer::NUMBER_META ) );

		// A refund refused by the gate says why, and is still not flagged.
		update_option( Migration::OPTION, [ 'status' => 'pending' ] );
		$blocked = Command::credit_notes_data( (string) $order->get_id(), '', false, true );
		$this->assertSame( 'import', $this->by_refund( $blocked['rows'] )[ $refund->get_id() ]['status'] );
		$this->assertSame( '', wc_get_order( $refund->get_id() )->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );
	}

	public function test_issue_credit_notes_order_issues_existing_refunds_in_id_order(): void {
		$order   = $this->invoiced_order();
		$other   = $this->invoiced_order();
		$first   = $this->refund_without_hook( $order, 1.0 );
		$second  = $this->refund_without_hook( $order, 2.0 );
		$foreign = $this->refund_without_hook( $other, 3.0 );
		$key     = $this->credit_note_key();
		$next    = Sequences::current( $key ) + 1;

		$result = Command::credit_notes_data( (string) $order->get_id(), '', false, false );

		$this->assertSame( 'done', $result['outcome'] );
		$this->assertSame( [ $first->get_id(), $second->get_id() ], array_column( $result['rows'], 'refund_id' ) );
		$this->assertSame( [ 'issued', 'issued' ], array_column( $result['rows'], 'status' ) );

		$a = Register::find( 'credit_note', $first->get_id() );
		$b = Register::find( 'credit_note', $second->get_id() );
		$this->track( $a );
		$this->track( $b );
		$this->assertSame( $this->credit_note_number( $next ), $a->number );
		$this->assertSame( $this->credit_note_number( $next + 1 ), $b->number );
		$this->assertSame( [ $a->number, $b->number ], array_column( $result['rows'], 'number' ) );
		$this->assertSame( $order->get_id(), $a->parent_id );
		$this->assertSame( $a->number, wc_get_order( $first->get_id() )->get_meta( CreditNoteIssuer::NUMBER_META ) );

		// The other order's refund is left alone.
		$this->assertNull( Register::find( 'credit_note', $foreign->get_id() ) );

		// Run again: nothing left to issue for the order, no number taken.
		$again = Command::credit_notes_data( (string) $order->get_id(), '', false, false );
		$this->assertSame( 'nothing', $again['outcome'] );
		$this->assertSame( $next + 1, Sequences::current( $key ) );

		// A credit note issued without its refund meta (an interrupted call) is reported, not renumbered.
		$repair = wc_get_order( $first->get_id() );
		$repair->delete_meta_data( CreditNoteIssuer::NUMBER_META );
		$repair->save();
		$rerun = Command::credit_notes_data( (string) $order->get_id(), '', false, false );
		$this->assertSame( [ 'already_issued' ], array_column( $rerun['rows'], 'status' ) );
		$this->assertSame( [ $a->number ], array_column( $rerun['rows'], 'number' ) );
		$this->assertSame( $a->number, wc_get_order( $first->get_id() )->get_meta( CreditNoteIssuer::NUMBER_META ) );
		$this->assertSame( $next + 1, Sequences::current( $key ) );

		// An unknown order, or a refund id passed as order, is refused.
		$this->assertSame( 'invalid', Command::credit_notes_data( (string) $first->get_id(), '', false, false )['outcome'] );
		$this->assertSame( 'invalid', Command::credit_notes_data( 'abc', '', false, false )['outcome'] );
		$this->assertSame( 'invalid', Command::credit_notes_data( '', '2026-13-45', false, true )['outcome'] );
	}

	public function test_issue_credit_notes_refused_while_requirements_fail_or_tables_missing(): void {
		$order  = $this->invoiced_order();
		$refund = $this->refund_without_hook( $order, 4.0 );
		$key    = $this->credit_note_key();
		$before = Sequences::current( $key );

		// A requirement other than WooCommerce fails: the real run is refused, the dry run reads.
		$no_dompdf = static fn(): string => 'Lumia\Factures\Tests\NoSuchDompdf';
		add_filter( 'lumia_factures_requirements_dompdf_class', $no_dompdf );
		try {
			$real = Command::credit_notes_data( '', '', false, false );
			$dry  = Command::credit_notes_data( '', '', false, true );
		} finally {
			remove_filter( 'lumia_factures_requirements_dompdf_class', $no_dompdf );
		}
		$this->assertSame( 'blocked', $real['outcome'] );
		$this->assertNotSame( '', $real['error'] );
		$this->assertSame( 'dry_run', $dry['outcome'] );

		// Without WooCommerce, nothing runs.
		add_filter( 'lumia_factures_requirements_wc_active', '__return_false' );
		try {
			$this->assertSame( 'blocked', Command::credit_notes_data( '', '', false, false )['outcome'] );
			$this->assertSame( 'blocked', Command::credit_notes_data( '', '', false, true )['outcome'] );
		} finally {
			remove_filter( 'lumia_factures_requirements_wc_active', '__return_false' );
		}

		// Without the tables, nothing runs either.
		add_filter( 'lumia_factures_tables_exist', '__return_false' );
		try {
			$missing = Command::credit_notes_data( '', '', false, false );
			$this->assertSame( 'blocked', Command::credit_notes_data( '', '', false, true )['outcome'] );
		} finally {
			remove_filter( 'lumia_factures_tables_exist', '__return_false' );
		}
		$this->assertSame( 'blocked', $missing['outcome'] );
		$this->assertStringContainsString( 'tables', $missing['error'] );

		$this->assertNull( Register::find( 'credit_note', $refund->get_id() ) );
		$this->assertSame( $before, Sequences::current( $key ) );
		$this->assertSame( '', wc_get_order( $refund->get_id() )->get_meta( CreditNoteIssuer::PENDING_META ) );
	}

	public function test_issue_credit_notes_pending_and_since_filters(): void {
		$order = $this->invoiced_order();

		// Refunded while the import blocked issuing: flagged pending by the hook.
		update_option( Migration::OPTION, [ 'status' => 'pending' ] );
		$pending = OrderFactory::refund( wc_get_order( $order->get_id() ), [], 1.0 );
		update_option( Migration::OPTION, [ 'status' => 'done' ] );
		$this->assertSame( 'import', wc_get_order( $pending->get_id() )->get_meta( CreditNoteIssuer::PENDING_META ) );

		// Missed by the hook, made ten days ago (before 2.1).
		$old       = $this->refund_without_hook( $order, 2.0 );
		$backdated = wc_get_order( $old->get_id() );
		$backdated->set_date_created( time() - 10 * DAY_IN_SECONDS );
		$backdated->save();

		$today = (string) wp_date( 'Y-m-d' );

		// --pending: the flagged refund only.
		$flagged = Command::credit_notes_data( (string) $order->get_id(), '', true, true );
		$this->assertSame( [ $pending->get_id() ], array_column( $flagged['rows'], 'refund_id' ) );
		$this->assertSame( [ 'would_issue' ], array_column( $flagged['rows'], 'status' ) );

		// --since: the refunds made from that day on.
		$recent = Command::credit_notes_data( (string) $order->get_id(), $today, false, true );
		$this->assertSame( [ $pending->get_id() ], array_column( $recent['rows'], 'refund_id' ) );
		$all = Command::credit_notes_data( (string) $order->get_id(), (string) wp_date( 'Y-m-d', time() - 20 * DAY_IN_SECONDS ), false, true );
		$this->assertSame( [ $pending->get_id(), $old->get_id() ], array_column( $all['rows'], 'refund_id' ) );
		$this->assertSame( (string) wp_date( 'Y-m-d', time() - 10 * DAY_IN_SECONDS ), $this->by_refund( $all['rows'] )[ $old->get_id() ]['refunded_on'] );

		// Both: still the flagged refund only; a real run issues it and clears the flag.
		$run = Command::credit_notes_data( (string) $order->get_id(), $today, true, false );
		$this->assertSame( [ 'issued' ], array_column( $run['rows'], 'status' ) );
		$doc = Register::find( 'credit_note', $pending->get_id() );
		$this->track( $doc );
		$this->assertSame( 'issued', $doc->status );
		$fresh = wc_get_order( $pending->get_id() );
		$this->assertSame( '', $fresh->get_meta( CreditNoteIssuer::PENDING_META ) );
		$this->assertNull( Register::find( 'credit_note', $old->get_id() ) );

		$this->assertSame( 'nothing', Command::credit_notes_data( (string) $order->get_id(), '', true, true )['outcome'] );
	}

	/**
	 * Rows keyed by refund id.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function by_refund( array $rows ): array {
		return array_column( $rows, null, 'refund_id' );
	}

	/**
	 * An order moved to a trigger status, with its issued invoice.
	 */
	private function invoiced_order(): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ), 2 ] ] );
		$order->update_status( 'processing' );

		$invoice = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $invoice );
		$this->track( $invoice );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * A refund of an amount made without the credit note listener: it has no credit note.
	 * Created past Issuer::STALE_AFTER, so it is not in flight.
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

		// Past Issuer::STALE_AFTER: no longer in flight, the deferred paths take it.
		$refund->set_date_created( time() - Issuer::STALE_AFTER - MINUTE_IN_SECONDS );
		$refund->save();

		return $refund;
	}

	/**
	 * Current counter key of credit notes.
	 */
	private function credit_note_key(): string {
		return Sequences::key( 'credit_note', time(), Module::settings()['credit_notes'] );
	}

	/**
	 * The credit note number the default format gives a sequence value this year.
	 *
	 * @param int $sequence Sequence value.
	 */
	private function credit_note_number( int $sequence ): string {
		return sprintf( 'AV-%s-%05d', wp_date( 'Y' ), $sequence );
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
