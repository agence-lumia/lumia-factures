<?php
/**
 * Write-once archive of issued PDFs.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Storage;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\IssuedDocument;

/**
 * Archived PDFs live in `uploads/lumia-factures/Y/m/`. A file is created once and never
 * overwritten nor deleted: a rebuilt PDF gets a new file name.
 */
final class Archive {

	/**
	 * Relative path of an archived PDF: `Y/m/{name}.pdf`. Anything else under the root
	 * (the Dompdf `fonts/` cache, the guards) is not an invoice file.
	 */
	private const FILE_PATTERN = '#^\d{4}/\d{2}/[^/]+\.pdf$#';

	/**
	 * Apache rules denying any web access (2.4, then the 2.2 fallback).
	 */
	private const HTACCESS = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	/**
	 * Absolute path of the storage root, without trailing slash.
	 */
	public static function root(): string {
		return wp_upload_dir()['basedir'] . '/lumia-factures';
	}

	/**
	 * Archives PDF bytes under a new, never used file name.
	 *
	 * @param string $pdf       PDF bytes.
	 * @param string $number    Invoice number (part of the file name).
	 * @param int    $issued_at UTC timestamp, gives the `Y/m` folder.
	 * @return array{file: string, checksum: string}|null Relative path and SHA-256, null on failure.
	 */
	public static function store( string $pdf, string $number, int $issued_at ): ?array {
		if ( '' === $pdf ) {
			return null;
		}

		$root   = self::root();
		$year   = gmdate( 'Y', $issued_at );
		$month  = gmdate( 'm', $issued_at );
		$folder = $root . '/' . $year . '/' . $month;

		self::ensure_root();

		if ( ! wp_mkdir_p( $folder ) ) {
			self::log( 'Could not create the archive folder ' . $folder . '.' );
			return null;
		}

		foreach ( [ $root . '/' . $year, $folder ] as $dir ) {
			self::protect( $dir );
		}

		$name = sanitize_file_name( $number );
		$name = '' === $name ? 'invoice' : $name;
		$file = $year . '/' . $month . '/' . $name . '-' . bin2hex( random_bytes( 8 ) ) . '.pdf';

		if ( ! self::write_new( $root . '/' . $file, $pdf ) ) {
			self::log( 'Could not write the archived PDF ' . $file . '.' );
			return null;
		}

		return [
			'file'     => $file,
			'checksum' => hash( 'sha256', $pdf ),
		];
	}

	/**
	 * Writes a file that must not exist yet. The exclusive mode `x` makes the filesystem
	 * refuse an existing path, so an archived file can never be overwritten.
	 *
	 * @param string $path  Absolute path.
	 * @param string $bytes Content.
	 * @return bool False when the path exists or the write fails.
	 */
	public static function write_new( string $path, string $bytes ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive create, a failure is the expected answer for an existing path.
		$handle = @fopen( $path, 'xb' );
		if ( false === $handle ) {
			return false;
		}

		$written = fwrite( $handle, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Exclusive create.
		$closed  = fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Exclusive create.

		if ( strlen( $bytes ) !== $written || ! $closed ) {
			// A truncated file was never recorded in the register: it is not an archive yet.
			wp_delete_file( $path );
			return false;
		}

		return true;
	}

	/**
	 * Absolute path of a document's archived file.
	 *
	 * @param IssuedDocument $doc Document.
	 * @return string|null Null when there is no file, it is missing, or it resolves outside the root.
	 */
	public static function path( IssuedDocument $doc ): ?string {
		if ( null === $doc->file || ! preg_match( self::FILE_PATTERN, $doc->file ) ) {
			return null;
		}

		$root = realpath( self::root() );
		$path = realpath( self::root() . '/' . $doc->file );

		if ( false === $root || false === $path || ! is_file( $path ) ) {
			return null;
		}

		return str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) ? $path : null;
	}

	/**
	 * Whether the archived file exists and still matches its recorded checksum.
	 *
	 * @param IssuedDocument $doc Document.
	 */
	public static function verify( IssuedDocument $doc ): bool {
		$path = self::path( $doc );
		if ( null === $path || null === $doc->checksum || '' === $doc->checksum ) {
			return false;
		}

		$hash = hash_file( 'sha256', $path );

		return is_string( $hash ) && hash_equals( $doc->checksum, $hash );
	}

	/**
	 * Creates the storage root and the Dompdf `fonts/` folder with their web-access guards.
	 *
	 * Called before any render or store, so nothing lands in an unprotected folder.
	 *
	 * @return bool False when a folder or a guard file could not be written (logged).
	 */
	public static function ensure_root(): bool {
		$ok = true;

		foreach ( [ self::root(), self::root() . '/fonts' ] as $dir ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				self::log( 'Could not create the storage folder ' . $dir . '.' );
				return false;
			}

			$ok = self::protect( $dir ) && $ok;
		}

		return $ok;
	}

	/**
	 * Puts the web-access guards in a folder: `.htaccess` (Apache) and an empty `index.php`.
	 *
	 * @param string $dir Absolute folder path.
	 * @return bool False when a guard file could not be written (logged).
	 */
	private static function protect( string $dir ): bool {
		$guards = [
			'.htaccess' => self::HTACCESS,
			'index.php' => '<?php // Silence is golden.',
		];
		$ok     = true;

		foreach ( $guards as $name => $content ) {
			if ( file_exists( $dir . '/' . $name ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Access guard.
			if ( false === file_put_contents( $dir . '/' . $name, $content ) ) {
				self::log( 'Could not write the guard file ' . $dir . '/' . $name . '.' );
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Logs a line.
	 *
	 * @param string $message Message.
	 */
	private static function log( string $message ): void {
		error_log( '[lumia-factures] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
	}
}
