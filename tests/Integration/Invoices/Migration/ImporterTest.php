<?php
/**
 * Import of the legacy Facture PDF Lumia invoices.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Migration\Importer;
use Lumia\Factures\Modules\Invoices\Migration\LegacySource;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Settings\Schema;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\LegacyFixture;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use RuntimeException;
use WC_Order;

/**
 * Importer: settings, register rows, order meta, legacy PDFs and counters, without touching the legacy data.
 */
class ImporterTest extends TestCase {

	private LegacyFixture $legacy;

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
		update_option( 'timezone_string', 'Europe/Paris' );
		delete_option( 'lumia_factures_module_invoices' );
		delete_option( 'fpl_settings' );

		$this->legacy = new LegacyFixture();

		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
	}

	public function tear_down(): void {
		foreach ( $this->written as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		$this->legacy->cleanup();
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
		wp_delete_file( $this->log_file );
		parent::tear_down();
	}

	public function test_imports_neonat_shaped_data(): void {
		[ $orders, $key ] = $this->neonat();

		$report = Importer::run( false )->to_array();
		$this->track( $orders );

		$this->assertSame( 'imported', $report['settings'] );
		$this->assertSame( 3, $report['invoices_imported'] );
		$this->assertSame( 0, $report['invoices_already'] );
		$this->assertSame( 2, $report['files_copied'] );
		$this->assertSame( 1, $report['files_missing'] );
		$this->assertSame( 0, $report['files_invalid'] );
		$this->assertSame( [], $report['conflicts'] );
		$this->assertSame( [ $key => 29241 ], $report['sequences'] );

		$with_file = 0;
		foreach ( $orders as $i => $order ) {
			$legacy = LegacyFixture::legacy_meta( $order->get_id() );
			$doc    = Register::find( 'invoice', $order->get_id() );

			$this->assertNotNull( $doc );
			$this->assertSame( 'issued', $doc->status );
			$this->assertSame( $legacy['_fpl_invoice_number'], $doc->number );
			$this->assertSame( (int) $legacy['_fpl_invoice_date'], $doc->issued_at );
			$this->assertSame( $key, $doc->sequence_key );
			$this->assertSame( (int) $legacy['_fpl_invoice_sequence'], $doc->sequence );

			$fresh = wc_get_order( $order->get_id() );
			$this->assertSame( $doc->number, $fresh->get_meta( '_lumia_factures_invoice_number' ) );
			$this->assertSame( (string) $doc->issued_at, (string) $fresh->get_meta( '_lumia_factures_invoice_date' ) );
			$this->assertSame( 1, $this->count_notes( $fresh, sprintf( 'Invoice %s imported from Facture PDF Lumia.', $doc->number ) ) );

			if ( 1 === $i ) {
				$this->assertNull( $doc->file );
				$this->assertNull( $doc->checksum );
				continue;
			}

			++$with_file;
			$this->assertTrue( Archive::verify( $doc ) );
			$this->assertMatchesRegularExpression( '#^\d{4}/\d{2}/' . preg_quote( $doc->number, '#' ) . '-[0-9a-f]{16}\.pdf$#', (string) $doc->file );
			$this->assertSame( gmdate( 'Y/m', $doc->issued_at ), substr( (string) $doc->file, 0, 7 ) );
			$this->assertSame( LegacyFixture::pdf( 'order ' . $order->get_id() ), file_get_contents( (string) Archive::path( $doc ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		$this->assertSame( 2, $with_file );
		$this->assertSame( 29241, Sequences::current( $key ) );

		$settings = Module::settings();
		$this->assertSame( '{year}{month}{number:6}', $settings['numbering']['format'] );
		$this->assertSame( [ 'processing', 'on-hold', 'completed', 'refunded' ], $settings['general']['trigger_statuses'] );
	}

	public function test_next_invoice_continues_after_legacy_numbers(): void {
		[ $orders ] = $this->neonat();
		Importer::run( false );
		$this->track( $orders );

		// The importer is run directly: mark the migration done, as Migration::run() does, so the
		// issuing gate (which stays strict while the state is not done) lets the next invoice through.
		update_option( Migration::OPTION, [ 'status' => 'done' ] );

		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $doc );
		$this->written[] = Archive::root() . '/' . $doc->file;

		$this->assertSame( wp_date( 'Y' ) . wp_date( 'm' ) . '029242', $doc->number );
		$this->assertSame( 29242, Sequences::current( 'invoice_' . wp_date( 'Y' ) ) );
	}

	public function test_dry_run_writes_nothing(): void {
		[ $orders, $key ] = $this->neonat();
		$files_before     = $this->archive_files();

		$dry = Importer::run( true )->to_array();

		$this->assertSame( 'imported', $dry['settings'] );
		$this->assertSame( 3, $dry['invoices_imported'] );
		$this->assertSame( 2, $dry['files_copied'] );
		$this->assertSame( 1, $dry['files_missing'] );
		$this->assertSame( [ $key => 29241 ], $dry['sequences'] );

		$this->assertFalse( get_option( 'lumia_factures_module_invoices' ) );
		$this->assertSame( 0, Sequences::current( $key ) );
		$this->assertSame( $files_before, $this->archive_files() );
		foreach ( $orders as $order ) {
			$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
			$fresh = wc_get_order( $order->get_id() );
			$this->assertSame( '', $fresh->get_meta( '_lumia_factures_invoice_number' ) );
			$this->assertFalse( $fresh->meta_exists( '_lumia_factures_invoice_date' ) );
			$this->assertFalse( $fresh->meta_exists( '_lumia_factures_import_noted' ) );
			$this->assertSame( 0, $this->count_notes( $fresh, 'imported from Facture PDF Lumia' ) );
		}

		// The real run reports exactly what the dry run planned.
		$real = Importer::run( false )->to_array();
		$this->track( $orders );
		$this->assertSame( $dry, $real );
	}

	public function test_second_run_is_a_no_op(): void {
		[ $orders, $key ] = $this->neonat();
		Importer::run( false );
		$this->track( $orders );

		$docs = [];
		foreach ( $orders as $order ) {
			$docs[ $order->get_id() ] = Register::find( 'invoice', $order->get_id() );
		}
		$files = $this->archive_files();

		$second = Importer::run( false )->to_array();

		$this->assertSame( 'skipped_existing', $second['settings'] );
		$this->assertSame( 0, $second['invoices_imported'] );
		$this->assertSame( 3, $second['invoices_already'] );
		$this->assertSame( 0, $second['files_copied'] );
		$this->assertSame( 0, $second['files_missing'] );
		$this->assertSame( [], $second['conflicts'] );
		$this->assertSame( [], $second['sequences'] );
		$this->assertSame( 29241, Sequences::current( $key ) );
		$this->assertSame( $files, $this->archive_files() );

		foreach ( $orders as $order ) {
			$this->assertEquals( $docs[ $order->get_id() ], Register::find( 'invoice', $order->get_id() ) );
			$this->assertSame( 1, $this->count_notes( wc_get_order( $order->get_id() ), 'imported from Facture PDF Lumia' ) );
		}

		// A counter already above the legacy values is never lowered.
		Sequences::raise( $key, 30000 );
		Importer::run( false );
		$this->assertSame( 30000, Sequences::current( $key ) );
	}

	public function test_interrupted_run_resumes(): void {
		[ $orders, $key ] = $this->neonat();

		// A run that died right after recording the first invoice: no meta, no file, no counter.
		$first  = $orders[0];
		$legacy = LegacyFixture::legacy_meta( $first->get_id() );
		$this->assertNotNull( Register::import( 'invoice', $first->get_id(), $legacy['_fpl_invoice_number'], $key, (int) $legacy['_fpl_invoice_sequence'], (int) $legacy['_fpl_invoice_date'] ) );

		$report = Importer::run( false )->to_array();
		$this->track( $orders );

		$this->assertSame( 2, $report['invoices_imported'] );
		$this->assertSame( 1, $report['invoices_already'] );
		$this->assertSame( 2, $report['files_copied'] );
		$this->assertSame( 29241, Sequences::current( $key ) );

		$doc = Register::find( 'invoice', $first->get_id() );
		$this->assertTrue( Archive::verify( $doc ) );
		$this->assertSame( $doc->number, wc_get_order( $first->get_id() )->get_meta( '_lumia_factures_invoice_number' ) );

		// The note the dead run never wrote is added once, then never again.
		foreach ( $orders as $order ) {
			$fresh = wc_get_order( $order->get_id() );
			$this->assertSame( 1, $this->count_notes( $fresh, 'imported from Facture PDF Lumia' ) );
			$this->assertSame( '1', (string) $fresh->get_meta( '_lumia_factures_import_noted' ) );
		}
		Importer::run( false );
		$this->assertSame( 1, $this->count_notes( wc_get_order( $first->get_id() ), 'imported from Facture PDF Lumia' ) );
	}

	public function test_counters_are_raised_before_the_invoices_are_imported(): void {
		[ $orders, $key ] = $this->neonat();

		// One order per batch, and the run dies after the first batch.
		add_filter( 'lumia_factures_legacy_import_batch_size', static fn() => 1 );
		$die = static function (): void {
			throw new RuntimeException( 'interrupted' );
		};
		add_action( 'lumia_factures_legacy_import_batch_done', $die );

		try {
			Importer::run( false );
			$this->fail( 'The run should have been interrupted.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'interrupted', $e->getMessage() );
		}
		$this->track( $orders );

		// Only the first invoice is imported, yet the counter already covers every legacy number.
		$this->assertNotNull( Register::find( 'invoice', $orders[0]->get_id() ) );
		$this->assertNull( Register::find( 'invoice', $orders[2]->get_id() ) );
		$this->assertSame( 29241, Sequences::current( $key ) );

		remove_action( 'lumia_factures_legacy_import_batch_done', $die );
		$report = Importer::run( false )->to_array();
		$this->track( $orders );

		$this->assertSame( 2, $report['invoices_imported'] );
		$this->assertSame( 1, $report['invoices_already'] );
		$this->assertSame( [], $report['sequences'] );
		$this->assertSame( 29241, Sequences::current( $key ) );
	}

	public function test_each_imported_order_is_saved_once(): void {
		$key   = 'invoice_' . wp_date( 'Y' );
		$order = $this->order();
		$this->legacy->invoice( $order, 'FA-0004', 4, $key, (int) strtotime( '-1 day' ), null );

		$saves = [];
		$count = static function ( $order_id ) use ( &$saves ): void {
			$saves[ (int) $order_id ] = ( $saves[ (int) $order_id ] ?? 0 ) + 1;
		};
		add_action( 'woocommerce_update_order', $count );
		try {
			$report = Importer::run( false )->to_array();
		} finally {
			remove_action( 'woocommerce_update_order', $count );
		}

		// Meta, note flag and note in one save: hooks and webhooks fire once per order.
		$this->assertSame( 1, $report['invoices_imported'] );
		$this->assertSame( [ $order->get_id() => 1 ], $saves );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'FA-0004', $fresh->get_meta( '_lumia_factures_invoice_number' ) );
		$this->assertSame( '1', $fresh->get_meta( Importer::NOTED_META ) );
		$this->assertSame( 1, $this->count_notes( $fresh, 'imported from Facture PDF Lumia' ) );
	}

	public function test_legacy_number_written_during_the_import_raises_the_counter(): void {
		$key   = 'invoice_' . wp_date( 'Y' );
		$early = $this->order();
		$this->legacy->invoice( $this->order(), 'FA-0004', 4, $key, (int) strtotime( '-1 day' ), null );

		// The legacy plugin, still serving a request, invoices an order the cursor already passed
		// after the counters-first pass: only the final pass can see its number.
		$written = false;
		$late    = function () use ( &$written, $early, $key ): void {
			if ( ! $written ) {
				$written = true;
				$this->legacy->invoice( wc_get_order( $early->get_id() ), 'FA-0009', 9, $key, time(), null );
			}
		};
		add_action( 'lumia_factures_legacy_import_batch_done', $late );
		try {
			$report = Importer::run( false )->to_array();
		} finally {
			remove_action( 'lumia_factures_legacy_import_batch_done', $late );
		}

		$this->assertTrue( $written );
		$this->assertSame( 1, $report['invoices_imported'] );
		$this->assertSame( 9, Sequences::current( $key ) );
		$this->assertSame( [ $key => 9 ], $report['sequences'] );
	}

	public function test_unloadable_order_still_counts_toward_the_counter(): void {
		[ $orders, $key ] = $this->neonat();

		// An order whose row is gone but whose legacy meta remains: wc_get_order() cannot load it.
		$ghost = $this->order();
		$this->legacy->invoice( $ghost, wp_date( 'Ym' ) . '040000', 40000, $key, time(), null );
		$this->delete_order_row( $ghost->get_id() );
		$this->assertFalse( wc_get_order( $ghost->get_id() ) );

		$this->assertSame( [ $key => 40000 ], LegacySource::max_sequences() );

		$dry = Importer::run( true )->to_array();
		$this->assertSame( [ $key => 40000 ], $dry['sequences'] );
		$this->assertSame( 0, Sequences::current( $key ) );

		$report = Importer::run( false )->to_array();
		$this->track( $orders );

		$this->assertSame( 3, $report['invoices_imported'] );
		$this->assertSame( [ $key => 40000 ], $report['sequences'] );
		$this->assertSame( 40000, Sequences::current( $key ) );
	}

	public function test_database_error_is_a_distinct_conflict(): void {
		$key   = 'invoice_' . wp_date( 'Y' );
		$order = $this->order();
		$this->legacy->invoice( $order, 'FA-DBERR', 5, $key, (int) strtotime( '-1 day' ), null );

		// The register INSERT of this number fails for a reason other than a duplicate.
		$break = static function ( string $query ): string {
			return str_contains( $query, 'lumia_factures_documents' ) && str_contains( $query, 'FA-DBERR' ) && str_starts_with( ltrim( $query ), 'INSERT' )
				? 'INSERT INTO lumia_factures_missing_table (x) VALUES (1)'
				: $query;
		};
		add_filter( 'query', $break );
		$report = Importer::run( false )->to_array();
		remove_filter( 'query', $break );

		$this->assertSame( 0, $report['invoices_imported'] );
		$this->assertSame( [ 'database_error' ], array_column( $report['conflicts'], 'code' ) );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( 5, Sequences::current( $key ) );
	}

	public function test_conflict_is_reported_and_skipped(): void {
		$key = 'invoice_' . wp_date( 'Y' );
		$this->legacy->sequences( [ $key => 12 ] );

		$a = $this->order();
		$b = $this->order();
		$c = $this->order();
		$d = $this->order();
		$this->legacy->invoice( $a, 'FA-0010', 10, $key, (int) strtotime( '-3 days' ), null );
		$this->legacy->invoice( $b, 'FA-0011', 11, $key, (int) strtotime( '-2 days' ), null );
		// C1: the legacy plugin gave the same number twice.
		$this->legacy->invoice( $c, 'FA-0011', 11, $key, (int) strtotime( '-2 days' ), null );
		$this->legacy->invoice( $d, 'FA-0012', 12, $key, (int) strtotime( '-1 day' ), null );

		// Order $d already got another number from lumia-factures after a partial run.
		$id = (int) Register::reserve( 'invoice', $d->get_id() );
		$this->assertTrue( Register::assign( $id, 'FA-9999', 'manual', 1, time() ) );

		$dry    = Importer::run( true )->to_array();
		$report = Importer::run( false )->to_array();

		$this->assertSame( $dry, $report );
		$this->assertSame( 2, $report['invoices_imported'] );
		$this->assertCount( 2, $report['conflicts'] );
		$this->assertSame( [ $c->get_id(), $d->get_id() ], array_column( $report['conflicts'], 'order_id' ) );
		$this->assertSame( [ 'FA-0011', 'FA-0012' ], array_column( $report['conflicts'], 'number' ) );
		$this->assertSame( [ 'number_used', 'other_invoice' ], array_column( $report['conflicts'], 'code' ) );
		$this->assertTrue( Importer::run( true )->has_conflicts() );

		$this->assertSame( 'FA-0011', Register::find( 'invoice', $b->get_id() )->number );
		$this->assertNull( Register::find( 'invoice', $c->get_id() ) );
		$this->assertSame( 'FA-9999', Register::find( 'invoice', $d->get_id() )->number );
		$this->assertSame( '', wc_get_order( $c->get_id() )->get_meta( '_lumia_factures_invoice_number' ) );
		$this->assertSame( 12, Sequences::current( $key ) );
	}

	public function test_invalid_legacy_pdf_is_not_imported(): void {
		$key   = 'invoice_' . wp_date( 'Y' );
		$order = $this->order();
		$this->legacy->invoice( $order, 'FA-0001', 1, $key, (int) strtotime( '-1 day' ), '<html>not a pdf</html>' );

		$dry    = Importer::run( true )->to_array();
		$report = Importer::run( false )->to_array();

		$this->assertSame( $dry, $report );
		$this->assertSame( 1, $report['invoices_imported'] );
		$this->assertSame( 0, $report['files_copied'] );
		$this->assertSame( 1, $report['files_invalid'] );
		$this->assertNotEmpty( $report['notes'] );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertSame( 'FA-0001', $doc->number );
		$this->assertNull( $doc->file );
	}

	public function test_legacy_file_outside_the_legacy_folder_is_refused(): void {
		$key   = 'invoice_' . wp_date( 'Y' );
		$order = $this->order();
		$this->legacy->invoice( $order, 'FA-0002', 2, $key, (int) strtotime( '-1 day' ), null );

		// A PDF elsewhere in uploads, reached by a crafted relative path.
		$outside = wp_upload_dir()['basedir'] . '/outside-' . wp_generate_password( 8, false ) . '.pdf';
		file_put_contents( $outside, LegacyFixture::pdf( 'outside' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->written[] = $outside;
		$order->update_meta_data( '_fpl_invoice_file', '../' . basename( $outside ) );
		$order->save();

		$this->assertNull( Archive::import_file( $outside, 'FA-0002', time() ) );

		$report = Importer::run( false )->to_array();
		$this->assertSame( 1, $report['files_invalid'] );
		$this->assertNull( Register::find( 'invoice', $order->get_id() )->file );
	}

	public function test_missing_invoice_date_falls_back_with_note(): void {
		$key   = 'invoice_' . wp_date( 'Y' );
		$order = $this->order();
		$this->legacy->invoice( $order, 'FA-0003', 3, $key, 1760000000, null );
		$order->update_meta_data( '_fpl_invoice_date', '' );
		$order->save();

		$report = Importer::run( false )->to_array();

		$this->assertSame( 1760000005, Register::find( 'invoice', $order->get_id() )->issued_at );
		$this->assertNotEmpty( preg_grep( '/FA-0003/', $report['notes'] ) );
	}

	public function test_existing_settings_are_never_overwritten(): void {
		update_option( 'fpl_settings', LegacySettingsTest::neonat() );
		update_option( 'lumia_factures_module_invoices', [ 'numbering' => [ 'format' => 'MY-{number}' ] ] );

		$report = Importer::run( false )->to_array();

		$this->assertSame( 'skipped_existing', $report['settings'] );
		$this->assertSame( [ 'numbering' => [ 'format' => 'MY-{number}' ] ], get_option( 'lumia_factures_module_invoices' ) );
	}

	public function test_settings_left_at_activation_defaults_are_replaced(): void {
		// The activator stores the defaults before the migration runs: nothing was saved by the user.
		update_option( 'fpl_settings', LegacySettingsTest::neonat() );
		update_option( 'lumia_factures_module_invoices', Schema::defaults() );

		$report = Importer::run( false )->to_array();

		$this->assertSame( 'imported', $report['settings'] );
		$this->assertSame( '{year}{month}{number:6}', Module::settings()['numbering']['format'] );
	}

	public function test_no_legacy_settings(): void {
		$this->assertFalse( LegacySource::has_data() );
		$report = Importer::run( false )->to_array();

		$this->assertSame( 'none', $report['settings'] );
		$this->assertSame( 0, $report['invoices_imported'] );
		$this->assertFalse( get_option( 'lumia_factures_module_invoices' ) );
	}

	public function test_legacy_data_is_untouched(): void {
		[ $orders ] = $this->neonat();

		$meta = [];
		$ids  = [];
		foreach ( $orders as $order ) {
			$meta[ $order->get_id() ] = LegacyFixture::legacy_meta( $order->get_id() );
			$ids[ $order->get_id() ]  = LegacyFixture::legacy_meta_ids( $order->get_id() );
		}
		$folder = $this->legacy_folder_files();
		$option = get_option( 'fpl_settings' );
		$table  = $this->legacy->sequence_rows();
		$hashes = $this->legacy->file_hashes();

		Importer::run( false );
		$this->track( $orders );

		foreach ( $orders as $order ) {
			$this->assertSame( $meta[ $order->get_id() ], LegacyFixture::legacy_meta( $order->get_id() ) );
			$this->assertSame( $ids[ $order->get_id() ], LegacyFixture::legacy_meta_ids( $order->get_id() ) );
		}
		$this->assertSame( $option, get_option( 'fpl_settings' ) );
		$this->assertSame( $table, $this->legacy->sequence_rows() );
		$this->assertSame( $hashes, $this->legacy->file_hashes() );
		$this->assertSame( $folder, $this->legacy_folder_files() );
		$this->assertCount( 2, $folder );
		$this->assertCount( 2, $hashes );
	}

	public function test_legacy_source_reads_in_order_with_a_cursor(): void {
		[ $orders, $key ] = $this->neonat();

		$this->assertTrue( LegacySource::has_data() );
		$this->assertSame( [ $key => 160 ], LegacySource::sequences() );

		$first = LegacySource::invoices( 0, 2 );
		$this->assertSame( [ $orders[0]->get_id(), $orders[1]->get_id() ], array_column( $first, 'order_id' ) );

		$rest = LegacySource::invoices( $orders[1]->get_id(), 2 );
		$this->assertSame( [ $orders[2]->get_id() ], array_column( $rest, 'order_id' ) );
		$this->assertSame( 29241, $rest[0]['sequence'] );
		$this->assertSame( $key, $rest[0]['sequence_key'] );
	}

	public function test_missing_legacy_table_gives_no_sequences(): void {
		$this->assertSame( [], LegacySource::sequences() );
	}

	/**
	 * The Neonat shape: settings, a legacy table at 160 and three invoices (the second one without file).
	 *
	 * @return array{0: WC_Order[], 1: string} Orders and counter key.
	 */
	private function neonat(): array {
		$year  = wp_date( 'Y' );
		$month = wp_date( 'm' );
		$key   = 'invoice_' . $year;

		update_option( 'fpl_settings', LegacySettingsTest::neonat() );
		$this->legacy->sequences( [ $key => 160 ] );

		$orders = [];
		foreach ( [ 25752, 29184, 29241 ] as $i => $sequence ) {
			$order     = $this->order();
			$issued_at = (int) strtotime( '-' . ( 3 - $i ) . ' hours' );
			$this->legacy->invoice( $order, $year . $month . sprintf( '%06d', $sequence ), $sequence, $key, $issued_at, 1 === $i ? null : LegacyFixture::pdf( 'order ' . $order->get_id() ) );
			$orders[] = wc_get_order( $order->get_id() );
		}

		return [ $orders, $key ];
	}

	/**
	 * A pending order (no trigger status, so lumia-factures never issues it by itself).
	 */
	private function order(): WC_Order {
		return OrderFactory::order( [ [ OrderFactory::simple_product( 20.0 ) ] ] );
	}

	/**
	 * Remembers the archived files of the orders for tear_down.
	 *
	 * @param WC_Order[] $orders Orders.
	 */
	private function track( array $orders ): void {
		foreach ( $orders as $order ) {
			$doc = Register::find( 'invoice', $order->get_id() );
			if ( $doc instanceof IssuedDocument && null !== $doc->file ) {
				$this->written[] = Archive::root() . '/' . $doc->file;
			}
		}
	}

	/**
	 * Every PDF of the legacy folder.
	 *
	 * @return string[]
	 */
	private function legacy_folder_files(): array {
		$files = glob( LegacyFixture::root() . '/*/*/*.pdf' );
		$files = is_array( $files ) ? $files : [];
		sort( $files );

		return $files;
	}

	/**
	 * Deletes the row of an order, leaving its meta behind, as a broken database would.
	 *
	 * @param int $order_id Order id.
	 */
	private function delete_order_row( int $order_id ): void {
		global $wpdb;

		$table = OrderUtil::get_table_for_orders();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", $order_id ) );
		clean_post_cache( $order_id );
		wp_cache_flush();
	}

	/**
	 * Every archived PDF on disk.
	 *
	 * @return string[]
	 */
	private function archive_files(): array {
		$files = glob( Archive::root() . '/*/*/*.pdf' );
		$files = is_array( $files ) ? $files : [];
		sort( $files );

		return $files;
	}

	/**
	 * Number of notes of an order containing a text.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $text  Part of the note.
	 */
	private function count_notes( WC_Order $order, string $text ): int {
		$count = 0;
		foreach ( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) as $note ) {
			if ( str_contains( $note->content, $text ) ) {
				++$count;
			}
		}

		return $count;
	}
}
