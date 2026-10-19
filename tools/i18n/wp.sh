#!/bin/sh
# Runs WP-CLI (i18n make-pot / make-mo) wherever it is called from.
#
# - `wp` on the PATH (wordpress:cli image, a dev machine): used as is.
# - Otherwise (composer:2 image, which has php but no wp-cli): the pinned phar is
#   downloaded once into tools/i18n/.cache/ (git-ignored) and checked against the
#   SHA-512 pinned below (the one published next to the release).
set -eu

# WP-CLI refuses to run as root (the composer image) unless told otherwise; it loads no WordPress here.
if [ "$(id -u)" = "0" ]; then
	set -- "$@" --allow-root
fi

if command -v wp >/dev/null 2>&1; then
	exec wp "$@"
fi

version=2.12.0
sha512=be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832
base="https://github.com/wp-cli/wp-cli/releases/download/v$version"
cache="$(cd "$(dirname "$0")" && pwd)/.cache"
phar="$cache/wp-cli-$version.phar"

if [ ! -f "$phar" ]; then
	mkdir -p "$cache"
	tmp="$phar.part"
	php -d allow_url_fopen=1 -r '
		$ctx  = stream_context_create( array( "http" => array( "follow_location" => 1 ) ) );
		$phar = file_get_contents( $argv[1] . ".phar", false, $ctx );
		if ( false === $phar ) { fwrite( STDERR, "Download failed.\n" ); exit( 1 ); }
		if ( ! hash_equals( $argv[3], hash( "sha512", $phar ) ) ) {
			fwrite( STDERR, "SHA-512 mismatch for wp-cli.phar.\n" );
			exit( 1 );
		}
		file_put_contents( $argv[2], $phar );
	' "$base/wp-cli-$version" "$tmp" "$sha512"
	mv "$tmp" "$phar"
fi

# E_ALL & ~E_DEPRECATED: WP-CLI 2.12 emits deprecations on the newest PHP (composer image).
exec php -d memory_limit=1G -d error_reporting=24575 "$phar" "$@"
