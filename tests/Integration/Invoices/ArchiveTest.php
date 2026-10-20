<?php
/**
 * Archive of issued PDFs.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;
use Lumia\Factures\Modules\Invoices\Rendering\PdfEngine;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * Archive: write-once storage, folder protection, path and checksum checks.
 */
class ArchiveTest extends TestCase {

	/**
	 * Absolute paths written by the test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

	public function tear_down(): void {
		foreach ( $this->written as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		parent::tear_down();
	}

	public function test_store_writes_file_with_checksum(): void {
		$time   = (int) strtotime( '2026-03-15 10:00:00 UTC' );
		$stored = Archive::store( '%PDF-1.7 sample', 'FA/2026 00001', $time );

		$this->assertIsArray( $stored );
		$this->written[] = Archive::root() . '/' . $stored['file'];

		$this->assertMatchesRegularExpression( '#^2026/03/FA2026-00001-[0-9a-f]{16}\.pdf$#', $stored['file'] );
		$this->assertSame( hash( 'sha256', '%PDF-1.7 sample' ), $stored['checksum'] );
		$this->assertSame( '%PDF-1.7 sample', file_get_contents( Archive::root() . '/' . $stored['file'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_store_refuses_empty_pdf(): void {
		$this->assertNull( Archive::store( '', 'FA-1', time() ) );
	}

	public function test_archive_refuses_overwrite(): void {
		$time   = (int) strtotime( '2026-04-01 10:00:00 UTC' );
		$first  = Archive::store( '%PDF first', 'FA-OVR', $time );
		$second = Archive::store( '%PDF second', 'FA-OVR', $time );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->written[] = Archive::root() . '/' . $first['file'];
		$this->written[] = Archive::root() . '/' . $second['file'];

		// Same number and month: a distinct file, the first one untouched.
		$this->assertNotSame( $first['file'], $second['file'] );
		$this->assertSame( '%PDF first', file_get_contents( Archive::root() . '/' . $first['file'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// An existing path is refused at the filesystem level.
		$this->assertFalse( Archive::write_new( Archive::root() . '/' . $first['file'], '%PDF third' ) );
		$this->assertSame( '%PDF first', file_get_contents( Archive::root() . '/' . $first['file'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_archive_folders_are_protected(): void {
		$time   = (int) strtotime( '2026-05-20 10:00:00 UTC' );
		$stored = Archive::store( '%PDF protected', 'FA-PROT', $time );

		$this->assertIsArray( $stored );
		$this->written[] = Archive::root() . '/' . $stored['file'];

		foreach ( [ Archive::root(), Archive::root() . '/2026', Archive::root() . '/2026/05' ] as $dir ) {
			$this->assertFileExists( $dir . '/.htaccess', $dir );
			$this->assertFileExists( $dir . '/index.php', $dir );

			$htaccess = (string) file_get_contents( $dir . '/.htaccess' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertStringContainsString( 'Require all denied', $htaccess );
			$this->assertStringContainsString( 'Deny from all', $htaccess );
		}
	}

	public function test_render_alone_protects_root_and_fonts(): void {
		foreach ( [ Archive::root(), Archive::root() . '/fonts' ] as $dir ) {
			foreach ( [ '.htaccess', 'index.php' ] as $guard ) {
				if ( is_file( $dir . '/' . $guard ) ) {
					wp_delete_file( $dir . '/' . $guard );
				}
			}
		}

		$pdf = PdfEngine::render( '<html><body><p>Guard test</p></body></html>', [], false );
		$this->assertStringStartsWith( '%PDF', $pdf );

		foreach ( [ Archive::root(), Archive::root() . '/fonts' ] as $dir ) {
			$this->assertFileExists( $dir . '/.htaccess', $dir );
			$this->assertFileExists( $dir . '/index.php', $dir );
			$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $dir . '/.htaccess' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}

	public function test_path_and_verify(): void {
		$stored = Archive::store( '%PDF verify', 'FA-VER', time() );
		$this->assertIsArray( $stored );
		$this->written[] = Archive::root() . '/' . $stored['file'];

		$doc = $this->doc( $stored['file'], $stored['checksum'] );
		$this->assertSame( realpath( Archive::root() . '/' . $stored['file'] ), Archive::path( $doc ) );
		$this->assertTrue( Archive::verify( $doc ) );

		$this->assertFalse( Archive::verify( $this->doc( $stored['file'], hash( 'sha256', 'other' ) ) ) );
		$this->assertNull( Archive::path( $this->doc( null, null ) ) );
		$this->assertNull( Archive::path( $this->doc( '2026/01/missing-0000000000000000.pdf', null ) ) );
		$this->assertFalse( Archive::verify( $this->doc( null, null ) ) );
	}

	public function test_path_rejects_outside_and_font_files(): void {
		$this->assertNull( Archive::path( $this->doc( '../../../wp-config.php', null ) ) );
		$this->assertNull( Archive::path( $this->doc( 'index.php', null ) ) );

		wp_mkdir_p( Archive::root() . '/fonts' );
		$font = Archive::root() . '/fonts/sample-test.pdf';
		file_put_contents( $font, '%PDF font' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->written[] = $font;

		$this->assertNull( Archive::path( $this->doc( 'fonts/sample-test.pdf', hash( 'sha256', '%PDF font' ) ) ) );
	}

	/**
	 * An issued document pointing at a file.
	 *
	 * @param string|null $file     Relative path.
	 * @param string|null $checksum SHA-256.
	 */
	private function doc( ?string $file, ?string $checksum ): IssuedDocument {
		return new IssuedDocument( 1, 'invoice', 1, 'issued', 'FA-1', 'invoice', 1, time(), $file, $checksum );
	}

	public function test_import_file_copies_a_legacy_pdf_unchanged(): void {
		$source = $this->legacy_file( '%PDF-1.4 legacy bytes' );
		$time   = (int) strtotime( '2026-02-10 10:00:00 UTC' );

		$stored = Archive::import_file( $source, '202602000042', $time );

		$this->assertIsArray( $stored );
		$this->written[] = Archive::root() . '/' . $stored['file'];
		$this->assertMatchesRegularExpression( '#^2026/02/202602000042-[0-9a-f]{16}\.pdf$#', $stored['file'] );
		$this->assertSame( hash( 'sha256', '%PDF-1.4 legacy bytes' ), $stored['checksum'] );
		$this->assertSame( '%PDF-1.4 legacy bytes', file_get_contents( Archive::root() . '/' . $stored['file'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertTrue( Archive::verify( $this->doc( $stored['file'], $stored['checksum'] ) ) );

		// The legacy file stays where it was.
		$this->assertSame( '%PDF-1.4 legacy bytes', file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_import_file_refuses_what_is_not_a_legacy_pdf(): void {
		$this->assertNull( Archive::import_file( $this->legacy_file( '<html>error page</html>' ), 'FA-X', time() ) );
		$this->assertNull( Archive::import_file( $this->legacy_file( '' ), 'FA-X', time() ) );
		$this->assertNull( Archive::import_file( Archive::legacy_root() . '/2026/01/missing.pdf', 'FA-X', time() ) );

		// A PDF outside the legacy folder, directly or through `..`.
		$outside = wp_upload_dir()['basedir'] . '/outside-' . wp_generate_password( 8, false ) . '.pdf';
		file_put_contents( $outside, '%PDF-1.4 outside' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->written[] = $outside;
		$this->legacy_file( '%PDF-1.4 keeps the folder' );

		$this->assertNull( Archive::import_file( $outside, 'FA-X', time() ) );
		$this->assertNull( Archive::import_file( Archive::legacy_root() . '/../' . basename( $outside ), 'FA-X', time() ) );
	}

	/**
	 * Writes a file in the legacy folder.
	 *
	 * @param string $bytes Content.
	 * @return string Absolute path.
	 */
	private function legacy_file( string $bytes ): string {
		$path = Archive::legacy_root() . '/2026/02/invoice-1-' . bin2hex( random_bytes( 16 ) ) . '.pdf';
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->written[] = $path;

		return $path;
	}
}
