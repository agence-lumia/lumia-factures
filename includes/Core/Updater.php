<?php
/**
 * GitHub release updater.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Core;

defined( 'ABSPATH' ) || exit;

/**
 * GitHub updater for the plugin.
 * Checks for updates from the GitHub releases of the repository.
 */
class Updater {

	/**
	 * GitHub repository (owner/name).
	 */
	public const REPO = 'agence-lumia/lumia-factures';

	/** Prefix of the update transients (suffixed by the channel). */
	private const TRANSIENT_PREFIX = 'lumia_factures_github_update_';

	/** Cache duration (12 hours). */
	private const CACHE_DURATION = 43200;

	/**
	 * Cache duration on failure (15 minutes).
	 *
	 * Without a negative cache, a network outage or an exceeded GitHub quota
	 * (60 requests/hour when anonymous) triggers an HTTP call with a 10 s
	 * timeout on EVERY update check, so on every admin screen load.
	 */
	private const FAILURE_CACHE_DURATION = 900;

	/** Sentinel stored in the transient to remember a failure. */
	private const FAILURE_MARKER = 'lumia_factures_update_check_failed';

	/** Maximum number of release notes kept (dev channel). */
	private const MAX_NOTES = 10;

	/** Release asset name pattern. */
	private const ASSET_PATTERN = '/^lumia-factures-.*\.zip$/';

	/**
	 * Settings manager (the channel is read at each use, it can change at any time).
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings manager.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the update hooks.
	 */
	public function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'authorize_asset_download' ), 10, 2 );
		// Requests re-sends the original headers on a redirect: the token must not follow it off the API.
		add_action( 'requests-requests.before_redirect', array( $this, 'strip_token_on_redirect' ), 10, 5 );

		// After a plugin update, purge the remote version cache (12 h) and the WP
		// updates transient, otherwise the "update available" badge persists until
		// a manual check.
		add_action( 'upgrader_process_complete', array( $this, 'purge_cache_after_update' ), 10, 2 );
	}

	/**
	 * Whether a URL is the GitHub API (https and host exactly api.github.com).
	 *
	 * The only place allowed to receive the optional token.
	 *
	 * @param string $url URL to test.
	 */
	public static function is_api_url( string $url ): bool {
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME )
			&& 'api.github.com' === wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Whether a URL is a GitHub API URL of this repository (token scope).
	 *
	 * @param string $url URL to test.
	 */
	public static function is_repo_api_url( string $url ): bool {
		if ( ! self::is_api_url( $url ) ) {
			return false;
		}

		return 0 === strpos( (string) wp_parse_url( $url, PHP_URL_PATH ), '/repos/' . self::REPO . '/' );
	}

	/**
	 * Drops the Authorization header when a redirect leaves the GitHub API.
	 *
	 * The release asset endpoint answers 302 to a storage host: the token must
	 * not be sent there (and that host rejects it).
	 *
	 * @param string $location New location (by reference).
	 * @param mixed  $headers  Request headers of the redirected request (by reference).
	 * @param mixed  $data     Request data (unused).
	 * @param mixed  $options  Request options (unused).
	 * @param mixed  $response Response that triggered the redirect (its url is the pre-redirect URL).
	 */
	public function strip_token_on_redirect( &$location, &$headers, &$data = null, &$options = null, $response = null ): void {
		// This hook sees every HTTP request of the site: act only on our own
		// (pre-redirect URL in this repository, and our own token as header).
		if ( '' === self::token() || self::is_api_url( (string) $location ) ) {
			return;
		}

		if ( is_object( $response ) && isset( $response->url ) && ! self::is_repo_api_url( (string) $response->url ) ) {
			return;
		}

		$ours = 'Bearer ' . self::token();

		if ( is_array( $headers ) ) {
			foreach ( $headers as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, 'Authorization' ) && $ours === $value ) {
					unset( $headers[ $name ] );
				}
			}
		} elseif ( $headers instanceof \ArrayAccess && isset( $headers['Authorization'] ) && $ours === $headers['Authorization'] ) {
			unset( $headers['Authorization'] );
		}
	}

	/**
	 * Optional GitHub token (private repository or higher API quota).
	 */
	private static function token(): string {
		return defined( 'LUMIA_FACTURES_GITHUB_TOKEN' ) && is_string( LUMIA_FACTURES_GITHUB_TOKEN ) ? LUMIA_FACTURES_GITHUB_TOKEN : '';
	}

	/**
	 * Active update channel.
	 */
	private function channel(): string {
		$channel = sanitize_key( (string) $this->settings->get( 'global.update_channel', 'stable' ) );

		return 'dev' === $channel ? 'dev' : 'stable';
	}

	/**
	 * Transient key of the active channel.
	 */
	private function cache_key(): string {
		return self::TRANSIENT_PREFIX . $this->channel();
	}

	/**
	 * Adds the token (and the binary Accept header) to the requests sent to the
	 * GitHub API only, i.e. the API lookup and the release asset download.
	 *
	 * @param array<string, mixed> $args HTTP request arguments.
	 * @param string               $url  Request URL.
	 * @return array<string, mixed>
	 */
	public function authorize_asset_download( $args, $url ): array {
		$args = (array) $args;
		if ( '' === self::token() || ! self::is_repo_api_url( (string) $url ) ) {
			return $args;
		}

		$args['headers'] = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();

		// Never overwrite an Authorization header set by the caller.
		foreach ( array_keys( $args['headers'] ) as $name ) {
			if ( 0 === strcasecmp( (string) $name, 'Authorization' ) ) {
				return $args;
			}
		}

		$args['headers']['Authorization'] = 'Bearer ' . self::token();

		// Asset download through the API: the binary, not the JSON metadata.
		if ( false !== strpos( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
	}

	/**
	 * Clears the remote version caches and the WordPress updates transient.
	 */
	public function purge_caches(): void {
		delete_transient( self::TRANSIENT_PREFIX . 'stable' );
		delete_transient( self::TRANSIENT_PREFIX . 'dev' );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Clears the update caches after a new version is installed.
	 *
	 * @param object               $upgrader Upgrader instance (unused).
	 * @param array<string, mixed> $options  Operation context.
	 */
	public function purge_cache_after_update( $upgrader, array $options ): void {
		if ( ( $options['action'] ?? '' ) !== 'update' || ( $options['type'] ?? '' ) !== 'plugin' ) {
			return;
		}

		$updated = (array) ( $options['plugins'] ?? array() );

		if ( ! in_array( plugin_basename( LUMIA_FACTURES_PLUGIN_FILE ), $updated, true ) ) {
			return;
		}

		$this->purge_caches();
	}

	/**
	 * Checks for available updates.
	 *
	 * @param \stdClass $transient Transient data.
	 *
	 * @return \stdClass
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->get_remote_version();

		if ( false === $remote ) {
			return $transient;
		}

		$plugin_file = plugin_basename( LUMIA_FACTURES_PLUGIN_FILE );
		$has_update  = $this->compare_versions( LUMIA_FACTURES_VERSION, $remote['version'] );

		$item = (object) array(
			'slug'         => dirname( $plugin_file ),
			'plugin'       => $plugin_file,
			'new_version'  => $has_update ? $remote['version'] : LUMIA_FACTURES_VERSION,
			'url'          => $remote['url'],
			'package'      => $remote['download_url'],
			'icons'        => array(),
			'banners'      => array(),
			// No `tested` field: filling it with the site's current version
			// declared the plugin as tested on any version.
			'requires'     => '6.9',
			'requires_php' => '8.1',
		);

		if ( $has_update ) {
			$transient->response[ $plugin_file ] = $item;
		} else {
			// No update, but declare the known source: WordPress then shows the
			// "Automatic updates" column and can handle the native auto-update.
			$transient->no_update[ $plugin_file ] = $item;
		}

		return $transient;
	}

	/**
	 * Tells whether the remote version is newer than the installed one.
	 *
	 * Pre-releases target the next patch (1.1.0 to 1.1.1-dev.N), so the SemVer
	 * order of version_compare() is enough: 1.1.0 < 1.1.1-dev.2 <
	 * 1.1.1-dev.10 < 1.1.1.
	 *
	 * @param string $installed_version Installed version.
	 * @param string $remote_version    Remote version.
	 */
	private function compare_versions( string $installed_version, string $remote_version ): bool {
		return version_compare( $installed_version, $remote_version, '<' );
	}

	/**
	 * Provides the plugin information for the details screen.
	 *
	 * @param false|object|array<string, mixed> $result Default value.
	 * @param string                            $action Requested action.
	 * @param object                            $args   Arguments.
	 * @return false|object|array<string, mixed>
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) ) {
			return $result;
		}

		$plugin_file = plugin_basename( LUMIA_FACTURES_PLUGIN_FILE );

		if ( dirname( $plugin_file ) !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$remote = $this->get_remote_version();

		if ( false === $remote ) {
			return $result;
		}

		return (object) array(
			'name'           => 'Lümia Factures',
			'slug'           => dirname( $plugin_file ),
			'author'         => '<a href="https://agence-lumia.com">Agence Lümia</a>',
			'author_profile' => 'https://agence-lumia.com',
			'homepage'       => $remote['url'],
			'download_link'  => $remote['download_url'],
			'version'        => $remote['version'],
			'requires'       => '6.9',
			'requires_php'   => '8.1',
			'last_updated'   => $remote['published_at'],
			'sections'       => array(
				'description' => __( 'PDF invoices for WooCommerce orders.', 'lumia-factures' ),
				'changelog'   => $this->render_changelog( $remote['notes'] ?? array() ),
			),
		);
	}

	/**
	 * Builds the "Changelog" tab of the details modal.
	 *
	 * On the dev channel, an update can skip several pre-releases: show all the
	 * notes newer than the installed version. If the site is up to date, fall
	 * back to the notes of the latest version.
	 *
	 * @param mixed $notes Normalized notes (version, date, html).
	 */
	private function render_changelog( $notes ): string {
		if ( ! is_array( $notes ) || array() === $notes ) {
			return '<p>' . esc_html__( 'No release notes available.', 'lumia-factures' ) . '</p>';
		}

		$newer = array_filter(
			$notes,
			static function ( $note ) {
				return version_compare( LUMIA_FACTURES_VERSION, $note['version'], '<' );
			}
		);

		if ( array() === $newer ) {
			$newer = array( reset( $notes ) );
		}

		$html = '';

		foreach ( $newer as $note ) {
			$date  = '' !== $note['date'] ? (string) mysql2date( (string) get_option( 'date_format' ), $note['date'] ) : '';
			$html .= '<h4>' . esc_html( 'v' . $note['version'] ) . ( '' !== $date ? ' - ' . esc_html( $date ) : '' ) . '</h4>';
			// HTML rendered by GitHub: filtered here, then again by WordPress when
			// the modal is displayed.
			$html .= wp_kses_post( $note['html'] );
		}

		return $html;
	}

	/**
	 * Known update state, without any network call.
	 *
	 * Only reads the cache: the dashboard must never trigger a 10 s HTTP call.
	 * Empty cache or recent failure: `remote` is null.
	 *
	 * @return array{channel: string, remote: ?string, has_update: bool}
	 */
	public function get_status(): array {
		$cached = get_transient( $this->cache_key() );
		$remote = is_array( $cached ) && ! empty( $cached['version'] ) ? (string) $cached['version'] : null;

		return array(
			'channel'    => $this->channel(),
			'remote'     => $remote,
			'has_update' => null !== $remote && $this->compare_versions( LUMIA_FACTURES_VERSION, $remote ),
		);
	}

	/**
	 * Fetches the latest version from GitHub.
	 *
	 * @return array<string, mixed>|false Release data, or false on error.
	 */
	private function get_remote_version() {
		$cache_key = $this->cache_key();
		$cached    = get_transient( $cache_key );

		// A recent failure is remembered as such: GitHub is not queried again
		// before the negative cache expires.
		if ( self::FAILURE_MARKER === $cached ) {
			return false;
		}

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			$this->get_api_url(),
			array(
				'timeout' => 10,
				'headers' => array(
					// "html" variant: GitHub returns the notes already rendered
					// (`body_html`), which avoids bundling a Markdown parser.
					'Accept'     => 'application/vnd.github.html+json',
					'User-Agent' => 'LumiaFactures',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $this->remember_failure( $cache_key );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = $this->normalize_release_data( $body );

		if ( false === $data ) {
			return $this->remember_failure( $cache_key );
		}

		set_transient( $cache_key, $data, self::CACHE_DURATION );

		return $data;
	}

	/**
	 * Remembers a lookup failure and returns false.
	 *
	 * @param string $cache_key Transient key of the current channel.
	 * @return false
	 */
	private function remember_failure( string $cache_key ) {
		set_transient( $cache_key, self::FAILURE_MARKER, self::FAILURE_CACHE_DURATION );

		return false;
	}

	/**
	 * Returns the API URL for the channel.
	 */
	private function get_api_url(): string {
		$base = 'https://api.github.com/repos/' . self::REPO . '/releases';

		return 'dev' === $this->channel() ? $base : $base . '/latest';
	}

	/**
	 * Normalizes the release data according to the channel.
	 *
	 * @param mixed $body Decoded JSON body of the GitHub API.
	 * @return array<string, mixed>|false
	 */
	private function normalize_release_data( $body ) {
		if ( 'dev' === $this->channel() ) {
			if ( ! is_array( $body ) ) {
				return false;
			}

			// Pre-releases AND stable ones: the dev channel follows the highest of
			// both, otherwise a site on 1.0.13-dev.19 never sees the stable 1.1.0.
			$releases = array_values(
				array_filter(
					$body,
					static function ( $release ) {
						return is_array( $release ) && empty( $release['draft'] ) && ! empty( $release['tag_name'] );
					}
				)
			);

			if ( array() === $releases ) {
				return false;
			}

			// Descending version, whatever the API order.
			usort(
				$releases,
				static function ( $a, $b ) {
					return version_compare(
						ltrim( (string) $b['tag_name'], 'v' ),
						ltrim( (string) $a['tag_name'], 'v' )
					);
				}
			);

			$payload          = $this->format_release_payload( $releases[0] );
			$payload['notes'] = array_map( array( $this, 'format_release_note' ), array_slice( $releases, 0, self::MAX_NOTES ) );

			return $payload;
		}

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return false;
		}

		return $this->format_release_payload( $body );
	}

	/**
	 * Converts a GitHub release into an updater payload.
	 *
	 * @param array<string, mixed> $release GitHub release.
	 * @return array<string, mixed>
	 */
	private function format_release_payload( array $release ): array {
		$download_url = $this->find_asset_download_url( $release );

		return array(
			'version'      => ltrim( (string) ( $release['tag_name'] ?? '' ), 'v' ),
			'url'          => $release['html_url'] ?? '',
			'download_url' => '' !== $download_url ? $download_url : ( $release['zipball_url'] ?? '' ),
			'published_at' => $release['published_at'] ?? '',
			'notes'        => array( $this->format_release_note( $release ) ),
		);
	}

	/**
	 * Extracts a release's note (version, date, HTML rendered by GitHub).
	 *
	 * @param array<string, mixed> $release GitHub release.
	 * @return array{version: string, date: string, html: string}
	 */
	private function format_release_note( array $release ): array {
		return array(
			'version' => ltrim( (string) ( $release['tag_name'] ?? '' ), 'v' ),
			'date'    => (string) ( $release['published_at'] ?? '' ),
			'html'    => (string) ( $release['body_html'] ?? '' ),
		);
	}

	/**
	 * Gets the zip asset download URL, if available.
	 *
	 * Public URL by default. With a token, the API asset URL is used instead
	 * (the only one a private repository serves); the token is then added by
	 * authorize_asset_download(), for api.github.com only.
	 *
	 * @param array<string, mixed> $release GitHub release.
	 */
	private function find_asset_download_url( array $release ): string {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return '';
		}

		foreach ( $release['assets'] as $asset ) {
			if ( empty( $asset['name'] ) || ! preg_match( self::ASSET_PATTERN, (string) $asset['name'] ) ) {
				continue;
			}

			if ( '' !== self::token() && ! empty( $asset['url'] ) && self::is_api_url( (string) $asset['url'] ) ) {
				return (string) $asset['url'];
			}

			return (string) ( $asset['browser_download_url'] ?? '' );
		}

		return '';
	}
}
