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
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Rendering\DocumentData;
use Lumia\Factures\Modules\Invoices\Rendering\Renderer;
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
		$order = $this->order();
		$order->update_status( 'processing' );

		$doc = Register::find( 'invoice', $order->get_id() );
		$this->track( $doc );

		$html = Renderer::html( wc_get_order( $order->get_id() ), new DocumentData( '', (string) $doc->number, $doc->issued_at ), Module::settings() );

		$this->assertStringContainsString( (string) $doc->number, $html );
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
		$this->assertTrue( $this->has_note_containing( $order, 'PDF will be built on first download' ) );

		// The first restore records the file once, keeping the number.
		$this->assertTrue( Issuer::restore( $doc, wc_get_order( $order->get_id() ) ) );
		$restored = Register::find( 'invoice', $order->get_id() );
		$this->track( $restored );
		$this->assertSame( $doc->number, $restored->number );
		$this->assertTrue( Archive::verify( $restored ) );
	}

	public function test_duplicate_number_burns_sequence_with_note(): void {
		update_option(
			'lumia_factures_module_invoices',
			[
				'numbering' => [
					'format'        => 'FA-{number}',
					'reset_enabled' => false,
				],
			]
		);
		$key  = $this->key();
		$next = Sequences::current( $key ) + 1;

		// Another row already holds the number the next sequence value would give.
		$other = (int) Register::reserve( 'invoice', 987654 );
		Register::assign( $other, 'FA-' . $next, 'manual', 1, time() );

		$order = $this->order();
		$order->update_status( 'processing' );

		$this->assertNull( Register::find( 'invoice', $order->get_id() ) );
		$this->assertSame( $next, Sequences::current( $key ) );
		$this->assertTrue(
			$this->has_note(
				$order,
				sprintf( 'Invoice number %1$s (sequence %2$d) could not be recorded; this sequence value is skipped.', 'FA-' . $next, $next )
			)
		);
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
