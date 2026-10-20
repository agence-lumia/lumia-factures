<?php
/**
 * Issuing invoices.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Documents\Notes;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use RuntimeException;
use WC_Order;

/**
 * Issuer: one number per order, a PDF from fresh data, a write-once archive.
 */
class IssuerTest extends TestCase {

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

		// Capture the plugin log lines instead of spilling them into the test output.
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
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation.
		wp_delete_file( $this->log_file );
		parent::tear_down();
	}

	public function test_status_change_issues_invoice(): void {
		$order = $this->order();
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $doc );
		$this->track( $doc );

		$this->assertSame( 'issued', $doc->status );
		$this->assertNotEmpty( $doc->number );
		$this->assertSame( $doc->number, wc_get_order( $order->get_id() )->get_meta( '_lumia_factures_invoice_number' ) );
		$this->assertSame( $doc->issued_at, (int) wc_get_order( $order->get_id() )->get_meta( '_lumia_factures_invoice_date' ) );

		$path = Archive::path( $doc );
		$this->assertNotNull( $path );
		$this->assertStringStartsWith( '%PDF', (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( hash_file( 'sha256', $path ), $doc->checksum );
		$this->assertTrue( Archive::verify( $doc ) );
		$this->assertTrue( $this->has_note( $order, sprintf( 'Invoice %s issued.', $doc->number ) ) );
	}

	public function test_issue_is_idempotent(): void {
		$key    = $this->key();
		$before = Sequences::current( $key );
		$order  = $this->order();

		$order->update_status( 'processing' );
		$first  = Issuer::issue( wc_get_order( $order->get_id() ) );
		$second = Issuer::issue( wc_get_order( $order->get_id() ) );

		$this->assertNotNull( $first );
		$this->track( $first );
		$this->assertNotNull( $second );
		$this->assertSame( $first->number, $second->number );
		$this->assertSame( $first->id, $second->id );
		$this->assertSame( $before + 1, Sequences::current( $key ) );
	}

	public function test_issued_action_fires_once(): void {
		$calls = [];
		add_action(
			'lumia_factures_invoice_issued',
			static function ( IssuedDocument $doc, WC_Order $order ) use ( &$calls ): void {
				$calls[] = [ $doc->number, $order->get_id() ];
			},
			10,
			2
		);

		$order = $this->order();
		$order->update_status( 'processing' );
		Issuer::issue( wc_get_order( $order->get_id() ) );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		$this->assertSame( [ [ $doc->number, $order->get_id() ] ], $calls );
	}

	public function test_issue_when_reserved_by_other_call_does_not_number_twice(): void {
		$key    = $this->key();
		$before = Sequences::current( $key );
		$order  = $this->order();
		$id     = Register::reserve( 'invoice', $order->get_id() );

		$order->update_status( 'processing' );
		$result = Issuer::issue( wc_get_order( $order->get_id() ) );

		$this->assertNull( $result );
		$this->assertSame( $before, Sequences::current( $key ) );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertSame( $id, $doc->id );
		$this->assertSame( 'reserved', $doc->status );
		$this->assertNull( $doc->number );
	}

	public function test_non_trigger_status_does_not_issue(): void {
		$order = $this->order();
		$order->update_status( 'on-hold' );

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertFalse( Issuer::can_issue( wc_get_order( $order->get_id() ) ) );
		$this->assertNull( Issuer::issue( wc_get_order( $order->get_id() ) ) );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
	}

	public function test_can_issue_filter(): void {
		$order = $this->order();
		$order->set_status( 'on-hold' );

		add_filter( 'lumia_factures_can_issue', '__return_true' );
		$this->assertTrue( Issuer::can_issue( $order ) );
		remove_filter( 'lumia_factures_can_issue', '__return_true' );

		$order->set_status( 'processing' );
		add_filter( 'lumia_factures_can_issue', '__return_false' );
		$this->assertFalse( Issuer::can_issue( $order ) );
		remove_filter( 'lumia_factures_can_issue', '__return_false' );
	}

	public function test_archived_pdf_never_changes(): void {
		$order = $this->order();
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		$bytes = (string) file_get_contents( (string) Archive::path( $doc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$order = wc_get_order( $order->get_id() );
		$order->set_billing_last_name( 'Changed' );
		foreach ( $order->get_items() as $item ) {
			$item->set_quantity( 5 );
			$item->save();
		}
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'completed' );

		$again = Issuer::issue( wc_get_order( $order->get_id() ) );
		$after = Register::find( 'invoice', $order->get_id() );

		$this->assertSame( $doc->number, $again->number );
		$this->assertSame( $doc->file, $after->file );
		$this->assertSame( $doc->checksum, $after->checksum );
		$this->assertSame( $doc->issued_at, $after->issued_at );
		$this->assertSame( $bytes, file_get_contents( (string) Archive::path( $after ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_restore_only_when_missing(): void {
		$order = $this->order();
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		$old_path = (string) Archive::path( $doc );

		$this->assertFalse( Issuer::restore( $doc, wc_get_order( $order->get_id() ) ) );
		$this->assertSame( $doc->file, Register::find( 'invoice', $order->get_id() )->file );

		wp_delete_file( $old_path );

		$this->assertTrue( Issuer::restore( $doc, wc_get_order( $order->get_id() ) ) );

		$restored = Register::find( 'invoice', $order->get_id() );
		$this->track( $restored );
		$this->assertSame( $doc->number, $restored->number );
		$this->assertSame( $doc->issued_at, $restored->issued_at );
		$this->assertSame( $doc->sequence, $restored->sequence );
		$this->assertNotSame( $doc->file, $restored->file );
		$this->assertTrue( Archive::verify( $restored ) );
		$this->assertTrue(
			$this->has_note(
				$order,
				sprintf( 'Invoice PDF %s was missing or damaged and has been rebuilt from the current order data.', $doc->number )
			)
		);
	}

	public function test_restore_keeps_damaged_file(): void {
		$order = $this->order();
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		$old_path = (string) Archive::path( $doc );
		file_put_contents( $old_path, '%PDF damaged' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertTrue( Issuer::restore( $doc, wc_get_order( $order->get_id() ) ) );

		$restored = Register::find( 'invoice', $order->get_id() );
		$this->track( $restored );
		$this->assertNotSame( $doc->file, $restored->file );
		$this->assertTrue( Archive::verify( $restored ) );
		$this->assertSame( '%PDF damaged', file_get_contents( $old_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_rendered_pdf_contains_number(): void {
		$captured = [];
		$capture  = static function ( string $html ) use ( &$captured ): string {
			$captured[] = $html;
			return $html;
		};
		add_filter( 'lumia_factures_invoice_html', $capture );

		$order = $this->order();
		$order->update_status( 'processing' );

		remove_filter( 'lumia_factures_invoice_html', $capture );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );

		// The HTML rendered by issue() itself carries the number (legacy bug C2).
		$this->assertCount( 1, $captured );
		$this->assertStringContainsString( (string) $doc->number, $captured[0] );
	}

	public function test_issued_note_is_written_before_rendering(): void {
		$notes   = null;
		$capture = function ( string $html, WC_Order $order ) use ( &$notes ): string {
			$notes = array_map( static fn( $note ): string => (string) $note->content, wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
			return $html;
		};
		add_filter( 'lumia_factures_invoice_html', $capture, 10, 2 );

		$order = $this->order();
		$order->update_status( 'processing' );

		remove_filter( 'lumia_factures_invoice_html', $capture, 10 );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		$this->assertIsArray( $notes );
		$this->assertContains( sprintf( 'Invoice %s issued.', $doc->number ), $notes );
		$this->assertFalse( $this->has_note_containing( $order, 'could not be' ) );
	}

	public function test_exception_after_assign_keeps_number_and_meta_is_backfilled(): void {
		$throw = static function ( WC_Order $saved ): void {
			if ( '' !== (string) $saved->get_meta( '_lumia_factures_invoice_number' ) ) {
				throw new RuntimeException( 'Third-party save hook failure.' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $throw );

		$fired = 0;
		$count = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'lumia_factures_invoice_issued', $count );

		$order = $this->order();
		$order->update_status( 'processing' );

		remove_action( 'woocommerce_before_order_object_save', $throw );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->assertNotEmpty( $doc->number );
		$this->assertTrue( Archive::verify( $doc ) );
		$this->assertTrue( $this->has_note( $order, sprintf( 'Invoice %s issued.', $doc->number ) ) );
		$this->assertSame( 1, $fired );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_lumia_factures_invoice_number' ) );

		// A later call repairs the missing meta, without a second number.
		$again = Issuer::issue( wc_get_order( $order->get_id() ) );
		$this->assertSame( $doc->number, $again->number );
		$this->assertSame( $doc->number, wc_get_order( $order->get_id() )->get_meta( '_lumia_factures_invoice_number' ) );
		$this->assertSame( (string) $doc->issued_at, (string) wc_get_order( $order->get_id() )->get_meta( '_lumia_factures_invoice_date' ) );
		$this->assertSame( 1, $fired );

		remove_action( 'lumia_factures_invoice_issued', $count );
	}

	public function test_number_build_failure_burns_sequence_with_note(): void {
		update_option( 'lumia_factures_module_invoices', [ 'numbering' => [ 'format' => 'FA-{boom}-{number}' ] ] );
		$throw = static function (): string {
			throw new RuntimeException( 'Broken tag.' );
		};
		add_filter( 'lumia_factures_number_tag', $throw );

		$key   = $this->key();
		$next  = Sequences::current( $key ) + 1;
		$order = $this->order();
		$order->update_status( 'processing' );

		remove_filter( 'lumia_factures_number_tag', $throw );

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( $next, Sequences::current( $key ) );
		$this->assertTrue(
			$this->has_note(
				$order,
				sprintf( 'Invoice sequence value %d could not be turned into a number; this sequence value is skipped.', $next )
			)
		);
	}

	public function test_stale_reservation_is_released_and_order_issued(): void {
		global $wpdb;

		$order = $this->order();
		$stale = (int) Register::reserve( 'invoice', $order->get_id() );
		$wpdb->update( Installer::documents_table(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ) ], [ 'id' => $stale ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->assertNotEmpty( $doc->number );
	}

	public function test_fresh_reservation_is_left_alone(): void {
		global $wpdb;

		$order = $this->order();
		$fresh = (int) Register::reserve( 'invoice', $order->get_id() );
		$wpdb->update( Installer::documents_table(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 300 ) ], [ 'id' => $fresh ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertSame( $fresh, $doc->id );
		$this->assertSame( 'reserved', $doc->status );
		$this->assertFalse( Register::release_stale( $fresh, 600 ) );
	}

	public function test_store_failure_keeps_number_and_restore_attaches_later(): void {
		// Uploads pointing below a file: no folder can be created there, even as root.
		$broken = static function ( array $dirs ): array {
			$dirs['basedir'] = '/dev/null/uploads';
			return $dirs;
		};
		// Dompdf keeps a working font cache, so only the archive fails.
		$fonts = trailingslashit( get_temp_dir() ) . 'lf-test-fonts';
		wp_mkdir_p( $fonts );
		$font_dir = static function ( $options ) use ( $fonts ) {
			$options->setFontDir( $fonts );
			$options->setFontCache( $fonts );
			return $options;
		};
		add_filter( 'upload_dir', $broken );
		add_filter( 'lumia_factures_dompdf_options', $font_dir );

		$order = $this->order();
		$order->update_status( 'processing' );

		remove_filter( 'upload_dir', $broken );
		remove_filter( 'lumia_factures_dompdf_options', $font_dir );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertSame( 'issued', $doc->status );
		$this->assertNotEmpty( $doc->number );
		$this->assertNull( $doc->file );
		$this->assertTrue(
			$this->has_note( $order, sprintf( 'The PDF of invoice %s could not be stored; the PDF will be built on first download.', $doc->number ) )
		);

		$this->assertTrue( Issuer::restore( $doc, wc_get_order( $order->get_id() ) ) );
		$restored = Register::find( 'invoice', $order->get_id() );
		$this->track( $restored );
		$this->assertSame( $doc->number, $restored->number );
		$this->assertTrue( Archive::verify( $restored ) );
	}

	public function test_render_failure_keeps_number(): void {
		$fail = static function (): void {
			throw new RuntimeException( 'Rendering disabled by the test.' );
		};
		add_filter( 'lumia_factures_dompdf_options', $fail );

		$order = $this->order();
		$order->update_status( 'processing' );

		remove_filter( 'lumia_factures_dompdf_options', $fail );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertSame( 'issued', $doc->status );
		$this->assertNotEmpty( $doc->number );
		$this->assertNull( $doc->file );
		$this->assertNull( $doc->checksum );
		$this->assertSame( $doc->number, wc_get_order( $order->get_id() )->get_meta( '_lumia_factures_invoice_number' ) );
		$this->assertTrue( $this->has_note( $order, sprintf( 'Invoice %s issued.', $doc->number ) ) );
		$this->assertTrue( $this->has_note( $order, sprintf( 'The PDF of invoice %s could not be generated; the PDF will be built on first download.', $doc->number ) ) );

		// The first restore records the file once, keeping the number.
		$this->assertTrue( Issuer::restore( $doc, wc_get_order( $order->get_id() ) ) );
		$restored = Register::find( 'invoice', $order->get_id() );
		$this->track( $restored );
		$this->assertSame( $doc->number, $restored->number );
		$this->assertTrue( Archive::verify( $restored ) );
	}

	public function test_number_already_issued_is_skipped_without_note(): void {
		$this->flat_format();
		$key  = $this->key();
		$next = Sequences::current( $key ) + 1;

		// Other invoices already hold the numbers the next two sequence values would give.
		$this->occupy( 'FA-' . $next, 987654 );
		$this->occupy( 'FA-' . ( $next + 1 ), 987655 );

		$order = $this->order();
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->assertSame( 'FA-' . ( $next + 2 ), $doc->number );
		$this->assertSame( $next + 2, $doc->sequence );
		$this->assertSame( $next + 2, Sequences::current( $key ) );
		// The skipped values are printed on other invoices: the printed series has no gap, so no note.
		$this->assertFalse( $this->has_note_containing( $order, 'skipped' ) );
	}

	public function test_number_retry_is_bounded(): void {
		$this->flat_format();
		$key  = $this->key();
		$next = Sequences::current( $key ) + 1;

		for ( $i = 0; $i < 20; $i++ ) {
			$this->occupy( 'FA-' . ( $next + $i ), 900000 + $i );
		}

		$order = $this->order();
		$order->update_status( 'processing' );

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( $next + 19, Sequences::current( $key ) );
		$this->assertTrue( $this->has_note( $order, 'Invoice could not be numbered.' ) );
		$this->assertFalse( $this->has_note_containing( $order, 'skipped' ) );

		// The next call goes on from there.
		$again = Issuer::issue( wc_get_order( $order->get_id() ) );
		$this->track( $again );
		$this->assertSame( 'FA-' . ( $next + 20 ), $again->number );
	}

	public function test_record_failure_that_is_not_a_duplicate_burns_sequence_with_note(): void {
		$this->flat_format();
		$key  = $this->key();
		$next = Sequences::current( $key ) + 1;

		$break = static function ( string $query ): string {
			return str_contains( $query, "SET status = 'issued'" ) ? 'SELECT 0 FROM DUAL WHERE 1 = 0' : $query;
		};
		add_filter( 'query', $break );

		$order = $this->order();
		$order->update_status( 'processing' );

		remove_filter( 'query', $break );

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( $next, Sequences::current( $key ) );
		$this->assertTrue(
			$this->has_note(
				$order,
				sprintf( 'Invoice number %1$s (sequence %2$d) could not be recorded; this sequence value is skipped.', 'FA-' . $next, $next )
			)
		);
	}

	public function test_invoice_order_notes_unchanged(): void {
		// The texts 2.0.1 wrote on the order, byte for byte: the shared pipeline must not reword them.
		$notes = Notes::invoice();

		$this->assertSame( 'Invoice could not be numbered.', $notes->not_numbered );
		$this->assertSame( 'Invoice sequence value %d could not be turned into a number; this sequence value is skipped.', $notes->not_built );
		$this->assertSame( 'Invoice number %1$s (sequence %2$d) could not be recorded; this sequence value is skipped.', $notes->not_recorded );
		$this->assertSame( 'The PDF of invoice %s could not be stored; the PDF will be built on first download.', $notes->store_failed );
		$this->assertSame( 'The PDF of invoice %s could not be generated; the PDF will be built on first download.', $notes->render_failed );
		$this->assertSame( 'The PDF of invoice FA-7 could not be stored; the PDF will be built on first download.', $notes->pdf_failure( 'store_failed', 'FA-7' ) );
		$this->assertSame( 'The PDF of invoice FA-7 could not be generated; the PDF will be built on first download.', $notes->pdf_failure( 'render_failed', 'FA-7' ) );

		// And the order really receives them through the pipeline.
		$this->flat_format();
		$next  = Sequences::current( $this->key() ) + 1;
		$break = static function ( string $query ): string {
			return str_contains( $query, "SET status = 'issued'" ) ? 'SELECT 0 FROM DUAL WHERE 1 = 0' : $query;
		};
		add_filter( 'query', $break );

		$order = $this->order();
		$order->update_status( 'processing' );

		remove_filter( 'query', $break );

		$this->assertTrue( $this->has_note( $order, sprintf( 'Invoice number %1$s (sequence %2$d) could not be recorded; this sequence value is skipped.', 'FA-' . $next, $next ) ) );
	}

	public function test_number_used_by_other_type_is_refused_not_skipped(): void {
		$this->flat_format();
		$key  = $this->key();
		$next = Sequences::current( $key ) + 1;

		// A document of another type already prints the number the next invoice value gives.
		$other = (int) Register::reserve( 'credit_note', 987650, 987651 );
		$this->assertTrue( Register::assign( $other, 'FA-' . $next, 'credit_note', 1, time() ) );

		$order = $this->order();
		$order->update_status( 'processing' );

		// Refused, never skipped to the next value: the counter moved by exactly one.
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( $next, Sequences::current( $key ) );
		$this->assertTrue(
			$this->has_note(
				$order,
				sprintf(
					'Invoice number %1$s (sequence %2$d) is already used by another document type; this sequence value is skipped and the invoice is not numbered. Give each document type its own number prefix.',
					'FA-' . $next,
					$next
				)
			)
		);
		$this->assertStringContainsString( 'already used by another document type', (string) file_get_contents( $this->log_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local log file.

		// The other document is untouched.
		$kept = Register::find( 'credit_note', 987650 );
		$this->assertNotNull( $kept );
		$this->assertSame( $other, $kept->id );
		$this->assertSame( 'issued', $kept->status );
		$this->assertSame( 'FA-' . $next, $kept->number );
		$this->assertSame( 987651, $kept->parent_id );

		// The next call takes the next value: the refused one stays burned, never reused.
		$again = Issuer::issue( wc_get_order( $order->get_id() ) );
		$this->assertNotNull( $again );
		$this->track( $again );
		$this->assertSame( 'FA-' . ( $next + 1 ), $again->number );
		$this->assertSame( $next + 1, Sequences::current( $key ) );
	}

	public function test_switch_yearly_to_no_reset_continues_the_series(): void {
		Sequences::raise( Sequences::key( 'invoice', time(), Module::settings()['numbering'] ), 41 );
		$first = $this->issued();
		$this->assertSame( 'FA-' . wp_date( 'Y' ) . '-00042', $first->number );

		$this->save( [ 'numbering' => [ 'reset_enabled' => '0' ] ] );
		$this->assertSame( 'invoice', $this->key() );

		$second = $this->issued();
		$this->assertSame( 'FA-' . wp_date( 'Y' ) . '-00043', $second->number );
		$this->assertFalse( $this->has_note_containing( wc_get_order( $second->order_id ), 'skipped' ) );
	}

	public function test_switch_monthly_to_yearly_continues_the_series(): void {
		update_option(
			'lumia_factures_module_invoices',
			[
				'numbering' => [
					'format'        => 'FA-{year}-{month}-{number}',
					'reset_enabled' => true,
					'reset_period'  => 'monthly',
				],
			]
		);
		Sequences::raise( $this->key(), 7 );
		$prefix = 'FA-' . wp_date( 'Y' ) . '-' . wp_date( 'm' ) . '-';
		$first  = $this->issued();
		$this->assertSame( $prefix . '8', $first->number );

		$this->save( [ 'numbering' => [ 'reset_period' => 'yearly' ] ] );
		$this->assertSame( 'invoice_' . wp_date( 'Y' ), $this->key() );

		$second = $this->issued();
		$this->assertSame( $prefix . '9', $second->number );
		$this->assertFalse( $this->has_note_containing( wc_get_order( $second->order_id ), 'skipped' ) );
	}

	public function test_switch_no_reset_to_yearly_continues_the_series(): void {
		update_option(
			'lumia_factures_module_invoices',
			[
				'numbering' => [
					'format'        => 'FA-{year}-{number:5}',
					'reset_enabled' => false,
				],
			]
		);
		Sequences::raise( 'invoice', 41 );
		$first = $this->issued();
		$this->assertSame( 'FA-' . wp_date( 'Y' ) . '-00042', $first->number );

		$this->save( [ 'numbering' => [ 'reset_enabled' => '1' ] ] );
		$this->assertSame( 'invoice_' . wp_date( 'Y' ), $this->key() );

		$second = $this->issued();
		$this->assertSame( 'FA-' . wp_date( 'Y' ) . '-00043', $second->number );
		$this->assertFalse( $this->has_note_containing( wc_get_order( $second->order_id ), 'skipped' ) );
	}

	public function test_policy_change_never_lowers_the_new_counter(): void {
		Sequences::raise( 'invoice', 90 );
		Sequences::raise( $this->key(), 5 );

		$this->save( [ 'numbering' => [ 'reset_enabled' => '0' ] ] );

		$this->assertSame( 90, Sequences::current( 'invoice' ) );
	}

	public function test_sequence_failure_releases_row(): void {
		global $wpdb;

		$table = Installer::sequences_table();
		$break = static function ( string $query ) use ( $table ): string {
			if ( str_contains( $query, 'LAST_INSERT_ID(current_value' ) ) {
				return "UPDATE {$table} SET current_value = current_value WHERE 1 = 0";
			}
			return $query;
		};
		add_filter( 'query', $break );

		$order = $this->order();
		$order->update_status( 'processing' );

		remove_filter( 'query', $break );

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertTrue( $this->has_note( $order, 'Invoice could not be numbered.' ) );
		$this->assertSame( '', (string) $wpdb->last_error );
	}

	public function test_status_hook_survives_exception(): void {
		$fail = static function (): bool {
			throw new RuntimeException( 'Broken filter.' );
		};
		add_filter( 'lumia_factures_can_issue', $fail );

		$order = $this->order();
		$order->update_status( 'processing' );

		remove_filter( 'lumia_factures_can_issue', $fail );

		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
	}

	/**
	 * A saved pending order with one line.
	 */
	private function order(): WC_Order {
		return OrderFactory::order( [ [ OrderFactory::simple_product( 10.0, '', 'Croquettes' ), 2 ] ] );
	}

	/**
	 * An order moved to a trigger status, and its issued invoice.
	 */
	private function issued(): IssuedDocument {
		$order = $this->order();
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->assertNotNull( $doc );
		$this->assertSame( 'issued', $doc->status );
		$this->track( $doc );

		return $doc;
	}

	/**
	 * Saves settings through the module, as the settings form does.
	 *
	 * @param array<string, mixed> $settings Posted settings.
	 */
	private function save( array $settings ): void {
		( new Module( 'invoices' ) )->save_settings( $settings );
	}

	/**
	 * A flat `FA-{number}` format without reset.
	 */
	private function flat_format(): void {
		update_option(
			'lumia_factures_module_invoices',
			[
				'numbering' => [
					'format'        => 'FA-{number}',
					'reset_enabled' => false,
				],
			]
		);
	}

	/**
	 * Records an invoice number on another order.
	 *
	 * @param string $number   Number.
	 * @param int    $order_id Order id.
	 */
	private function occupy( string $number, int $order_id ): void {
		$id = (int) Register::reserve( 'invoice', $order_id );
		$this->assertTrue( Register::assign( $id, $number, 'manual', 1, time() ) );
	}

	/**
	 * Current counter key of invoices.
	 */
	private function key(): string {
		return Sequences::key( 'invoice', time(), Module::settings()['numbering'] );
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
	 * Whether the order has a note with exactly this text.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $text  Note text.
	 */
	private function has_note( WC_Order $order, string $text ): bool {
		foreach ( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) as $note ) {
			if ( $text === $note->content ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the order has a note containing this text.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $text  Part of the note.
	 */
	private function has_note_containing( WC_Order $order, string $text ): bool {
		foreach ( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) as $note ) {
			if ( str_contains( $note->content, $text ) ) {
				return true;
			}
		}
		return false;
	}
}
