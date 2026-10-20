<?php
/**
 * Images embedded in the PDF.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Rendering;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an attachment into a PNG or JPEG data URI Dompdf can embed.
 *
 * An image that cannot be used never breaks the document: the failure is logged and the caller
 * prints the invoice without it. Dompdf only embeds JPEG and PNG reliably, so any other format
 * (WebP, AVIF, GIF) is converted to PNG, and every image is shrunk to the width it is printed at
 * (a 3000 px product picture would make a multi-megabyte PDF). SVG is refused: its rendering
 * differs too much from browsers to be trusted.
 */
final class Images {

	private const REMOTE_TIMEOUT = 5;

	private const REMOTE_MAX_BYTES = 5 * MB_IN_BYTES;

	/**
	 * Results of the current request, keyed by attachment and width.
	 *
	 * @var array<string, string>
	 */
	private static array $cache = [];

	/**
	 * A remote fetch already failed in this request: later images are not tried remotely (each
	 * attempt can cost the full timeout).
	 *
	 * @var bool
	 */
	private static bool $remote_failed = false;

	/**
	 * Data URI of an attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @param int $max_width     Maximum width in pixels, 0 to keep the size.
	 * @return string `data:image/png|jpeg;base64,...`, '' on any failure.
	 */
	public static function data_uri( int $attachment_id, int $max_width ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$key = $attachment_id . '|' . $max_width;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$result = '';
		$path   = get_attached_file( $attachment_id );

		if ( is_string( $path ) && is_readable( $path ) ) {
			$result = self::encode( $path, $max_width );
		} elseif ( ! self::$remote_failed ) {
			// Only a missing local file (offloaded media) justifies a fetch.
			$result = self::fetch( $attachment_id, $max_width );
			if ( '' === $result ) {
				self::$remote_failed = true;
			}
		}

		if ( '' === $result ) {
			self::log( sprintf( 'attachment %d: image skipped, the document is rendered without it.', $attachment_id ) );
		}

		self::$cache[ $key ] = $result;

		return $result;
	}

	/**
	 * Forgets the images of the current request.
	 */
	public static function flush(): void {
		self::$cache         = [];
		self::$remote_failed = false;
	}

	/**
	 * Encodes a local image file.
	 *
	 * @param string $path      Absolute path.
	 * @param int    $max_width Maximum width in pixels, 0 to keep the size.
	 */
	private static function encode( string $path, int $max_width ): string {
		// SVG logos (the legacy plugin accepted them): Dompdf draws them itself, the width comes from the CSS.
		// wp_check_filetype() does not know SVG on a site that has not allowed the type.
		if ( 'svg' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return self::svg( $path );
		}

		$type = wp_check_filetype( $path );
		$mime = (string) $type['type'];

		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ], true ) ) {
			return '';
		}

		$size = wp_getimagesize( $path );

		if ( in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) && is_array( $size ) && ( $max_width <= 0 || $size[0] <= $max_width ) ) {
			$binary = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			$real   = false === $binary ? '' : self::sniff( $binary );

			if ( '' !== $real && false !== $binary ) {
				return self::uri( $real, $binary );
			}
		}

		return self::convert( $path, $max_width, 'image/jpeg' === $mime );
	}

	/**
	 * Data URI of a local SVG file, '' when it is too big or not an SVG document.
	 *
	 * @param string $path Absolute path.
	 */
	private static function svg( string $path ): string {
		$size = filesize( $path );
		if ( false === $size || $size <= 0 || $size > self::REMOTE_MAX_BYTES ) {
			return '';
		}

		$binary = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( false === $binary || false === stripos( $binary, '<svg' ) ) {
			return '';
		}

		return 'data:image/svg+xml;base64,' . base64_encode( $binary ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Embedding an image in the PDF, not obfuscation.
	}

	/**
	 * Re-encodes an image with the WordPress image editor: shrunk to $max_width, PNG (JPEG stays JPEG).
	 *
	 * @param string $path      Absolute path.
	 * @param int    $max_width Maximum width in pixels, 0 to keep the size.
	 * @param bool   $jpeg      Keep JPEG instead of PNG.
	 */
	private static function convert( string $path, int $max_width, bool $jpeg ): string {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			self::log( 'no image editor for ' . basename( $path ) . ': ' . $editor->get_error_message() );
			return '';
		}

		$size = $editor->get_size();
		if ( $max_width > 0 && is_array( $size ) && (int) $size['width'] > $max_width ) {
			$resized = $editor->resize( $max_width, null, false );
			if ( is_wp_error( $resized ) ) {
				return '';
			}
		}

		// An image optimizer may force WebP/AVIF on every save, which Dompdf cannot render.
		$keep   = static fn(): array => [];
		add_filter( 'image_editor_output_format', $keep, PHP_INT_MAX );
		$target = trailingslashit( get_temp_dir() ) . 'lumia-factures-' . wp_generate_password( 12, false ) . ( $jpeg ? '.jpg' : '.png' );
		$saved  = $editor->save( $target, $jpeg ? 'image/jpeg' : 'image/png' );
		remove_filter( 'image_editor_output_format', $keep, PHP_INT_MAX );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return '';
		}

		$binary = file_get_contents( (string) $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Temporary file we wrote.
		wp_delete_file( (string) $saved['path'] );

		$mime = false === $binary ? '' : self::sniff( $binary );

		return '' === $mime || false === $binary ? '' : self::uri( $mime, $binary );
	}

	/**
	 * Fetches the image from its URL when the file is missing locally (offloaded media).
	 *
	 * @param int $attachment_id Attachment id.
	 * @param int $max_width     Maximum width in pixels.
	 */
	private static function fetch( int $attachment_id, int $max_width ): string {
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return '';
		}

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => self::REMOTE_TIMEOUT,
				'limit_response_size' => self::REMOTE_MAX_BYTES,
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		// A body that reached the cap was cut off.
		if ( '' === $body || strlen( $body ) >= self::REMOTE_MAX_BYTES ) {
			return '';
		}

		$extensions = [
			'image/jpeg' => '.jpg',
			'image/png'  => '.png',
			'image/gif'  => '.gif',
			'image/webp' => '.webp',
			'image/avif' => '.avif',
		];
		$header     = wp_remote_retrieve_header( $response, 'content-type' );
		$type       = strtolower( trim( explode( ';', is_array( $header ) ? (string) reset( $header ) : (string) $header )[0] ) );
		if ( ! isset( $extensions[ $type ] ) ) {
			return '';
		}

		$temp = trailingslashit( get_temp_dir() ) . 'lumia-factures-' . wp_generate_password( 12, false ) . $extensions[ $type ];
		if ( false === file_put_contents( $temp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary file.
			return '';
		}

		$result = self::encode( $temp, $max_width );
		wp_delete_file( $temp );

		return $result;
	}

	/**
	 * MIME type of image bytes, '' unless PNG or JPEG.
	 *
	 * @param string $binary Image bytes.
	 */
	private static function sniff( string $binary ): string {
		if ( str_starts_with( $binary, "\x89PNG\r\n\x1a\n" ) ) {
			return 'image/png';
		}

		return str_starts_with( $binary, "\xFF\xD8\xFF" ) ? 'image/jpeg' : '';
	}

	/**
	 * Flattens a PNG with transparency onto white.
	 *
	 * Dompdf handles an alpha channel through Imagick or GD extra passes that fail on some hosts
	 * (an Imagick without PNG support throws), and a failed render means no invoice. An opaque PNG
	 * goes through the plain path. The bytes are returned unchanged when GD is not available.
	 *
	 * @param string $binary PNG bytes.
	 */
	private static function flatten( string $binary ): string {
		$type = strlen( $binary ) > 25 ? ord( $binary[25] ) : 0;

		if ( ! in_array( $type, [ 4, 6 ], true ) && ! str_contains( substr( $binary, 0, 2048 ), 'tRNS' ) ) {
			return $binary;
		}

		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return $binary;
		}

		$source = imagecreatefromstring( $binary );
		if ( false === $source ) {
			return $binary;
		}

		$width  = imagesx( $source );
		$height = imagesy( $source );
		$canvas = imagecreatetruecolor( $width, $height );
		imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, 255, 255, 255 ) );
		imagealphablending( $canvas, true );
		imagecopy( $canvas, $source, 0, 0, 0, 0, $width, $height );

		ob_start();
		imagepng( $canvas );
		$flat = (string) ob_get_clean();

		return '' === $flat ? $binary : $flat;
	}

	/**
	 * Builds the data URI.
	 *
	 * @param string $mime   MIME type.
	 * @param string $binary Image bytes.
	 */
	private static function uri( string $mime, string $binary ): string {
		if ( 'image/png' === $mime ) {
			$binary = self::flatten( $binary );
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $binary ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Embedding an image in the PDF, not obfuscation.
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
