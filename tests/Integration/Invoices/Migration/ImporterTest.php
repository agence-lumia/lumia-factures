<?php
/**
 * Import of the legacy Facture PDF Lumia invoices.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Migration\Importer;
use Lumia\Factures\Modules\Invoices\Migration\LegacySource;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Settings\Schema;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\LegacyFixture;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
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
		foreach ( $orders as $order ) {
			$meta[ $order->get_id() ] = LegacyFixture::legacy_meta( $order->get_id() );
		}
		$option = get_option( 'fpl_settings' );
		$table  = $this->legacy->sequence_rows();
		$hashes = $this->legacy->file_hashes();

		Importer::run( false );
		$this->track( $orders );

		foreach ( $orders as $order ) {
			$this->assertSame( $meta[ $order->get_id() ], LegacyFixture::legacy_meta( $order->get_id() ) );
		}
		$this->assertSame( $option, get_option( 'fpl_settings' ) );
		$this->assertSame( $table, $this->legacy->sequence_rows() );
		$this->assertSame( $hashes, $this->legacy->file_hashes() );
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
