<?php
/**
 * GitHub release updater.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Core;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Admin\SaveHandler;
use Lumia\Factures\Core\Plugin;
use Lumia\Factures\Core\Updater;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * Update injection, channels, negative cache and token scoping (HTTP mocked).
 */
class UpdaterTest extends TestCase {

	private const BASENAME = 'lumia-factures/lumia-factures.php';

	/**
	 * Number of HTTP requests intercepted.
	 *
	 * @var int
	 */
	private int $requests = 0;

	/**
	 * Last request args intercepted.
	 *
	 * @var array<string, mixed>
	 */
	private array $last_args = array();

	public function set_up(): void {
		parent::set_up();
		$this->requests = 0;
		delete_transient( 'lumia_factures_github_update_stable' );
		delete_transient( 'lumia_factures_github_update_dev' );
		Plugin::instance()->settings()->set( 'global.update_channel', 'stable' );
	}

	/**
	 * Mocks the GitHub API.
	 *
	 * @param int          $code Response code.
	 * @param array<mixed> $body Decoded body.
	 */
	private function mock_http( int $code, array $body ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $code, $body ) {
				++$this->requests;
				$this->last_args = $args;
				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( $body ),
					'response' => array(
						'code'    => $code,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			2
		);
	}

	/**
	 * Builds a release payload.
	 *
	 * @param string $tag Tag name.
	 * @return array<string, mixed>
	 */
	private function release( string $tag ): array {
		return array(
			'tag_name'     => $tag,
			'html_url'     => 'https://github.com/agence-lumia/lumia-factures/releases/tag/' . $tag,
			'published_at' => '2026-10-19T10:00:00Z',
			'body_html'    => '<p>Notes</p>',
			'assets'       => array(
				array(
					'name'                 => 'lumia-factures-' . $tag . '.zip',
					'browser_download_url' => 'https://github.com/agence-lumia/lumia-factures/releases/download/' . $tag . '/lumia-factures-' . $tag . '.zip',
				),
			),
		);
	}

	private function run_check(): object {
		$transient           = new \stdClass();
		$transient->checked  = array( self::BASENAME => LUMIA_FACTURES_VERSION );
		$transient->response = array();
		return Plugin::instance()->updater()->check_update( $transient );
	}

	public function test_injects_update_when_newer_release(): void {
		$this->mock_http( 200, $this->release( '2.0.1' ) );

		$result = $this->run_check();

		$this->assertSame( '2.0.1', $result->response[ self::BASENAME ]->new_version );
		$this->assertStringEndsWith( 'lumia-factures-2.0.1.zip', $result->response[ self::BASENAME ]->package );
		$this->assertSame( 'LumiaFactures', $this->last_args['headers']['User-Agent'] );
	}

	public function test_no_update_when_remote_is_not_newer(): void {
		$this->mock_http( 200, $this->release( '1.0.0' ) );

		$result = $this->run_check();

		$this->assertArrayNotHasKey( self::BASENAME, $result->response );
		$this->assertArrayHasKey( self::BASENAME, $result->no_update );
	}

	public function test_dev_channel_picks_highest_prerelease(): void {
		Plugin::instance()->settings()->set( 'global.update_channel', 'dev' );
		$this->mock_http(
			200,
			array(
				$this->release( 'v98.0.0' ),
				$this->release( 'v99.0.0-dev.2' ),
				$this->release( 'v99.0.0-dev.1' ),
			)
		);

		$result = $this->run_check();

		$this->assertSame( '99.0.0-dev.2', $result->response[ self::BASENAME ]->new_version );
		$this->assertNotFalse( get_transient( 'lumia_factures_github_update_dev' ) );
	}

	public function test_failed_check_is_negatively_cached(): void {
		$this->mock_http( 500, array() );

		$this->run_check();
		$this->run_check();

		$this->assertSame( 1, $this->requests );
		$this->assertNotFalse( get_transient( 'lumia_factures_github_update_stable' ) );
	}

	public function test_token_only_sent_to_github_api(): void {
		$this->assertTrue( Updater::is_api_url( 'https://api.github.com/repos/a/b/releases/latest' ) );
		$this->assertFalse( Updater::is_api_url( 'https://api.github.com.evil.tld/x' ) );
		$this->assertFalse( Updater::is_api_url( 'https://evil.tld/?u=api.github.com' ) );
		$this->assertFalse( Updater::is_api_url( 'http://api.github.com/x' ) );
	}

	public function test_manual_check_purges_caches(): void {
		set_transient( 'lumia_factures_github_update_stable', 'lumia_factures_update_check_failed', 900 );

		Plugin::instance()->updater()->purge_caches();

		$this->assertFalse( get_transient( 'lumia_factures_github_update_stable' ) );
	}

	public function test_updater_registered_independently_of_requirements(): void {
		$this->assertNotFalse( has_filter( 'pre_set_site_transient_update_plugins', array( Plugin::instance()->updater(), 'check_update' ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_token_scoped_to_this_repository(): void {
		define( 'LUMIA_FACTURES_GITHUB_TOKEN', 'secret' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- test constant.
		$updater = Plugin::instance()->updater();

		$ours = $updater->authorize_asset_download( array(), 'https://api.github.com/repos/agence-lumia/lumia-factures/releases/latest' );
		$this->assertSame( 'Bearer secret', $ours['headers']['Authorization'] );

		$asset = $updater->authorize_asset_download( array(), 'https://api.github.com/repos/agence-lumia/lumia-factures/releases/assets/12' );
		$this->assertSame( 'application/octet-stream', $asset['headers']['Accept'] );

		$other = $updater->authorize_asset_download( array(), 'https://api.github.com/repos/agence-lumia/lumia-tools/releases/latest' );
		$this->assertArrayNotHasKey( 'headers', $other );

		$evil = $updater->authorize_asset_download( array(), 'https://api.github.com.evil.tld/repos/agence-lumia/lumia-factures/x' );
		$this->assertArrayNotHasKey( 'headers', $evil );

		$kept = $updater->authorize_asset_download( array( 'headers' => array( 'authorization' => 'token mine' ) ), 'https://api.github.com/repos/agence-lumia/lumia-factures/releases/latest' );
		$this->assertSame( array( 'authorization' => 'token mine' ), $kept['headers'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_authorization_removed_on_redirect_off_the_api(): void {
		define( 'LUMIA_FACTURES_GITHUB_TOKEN', 'secret' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- test constant.
		$updater  = Plugin::instance()->updater();
		$ours     = (object) array( 'url' => 'https://api.github.com/repos/agence-lumia/lumia-factures/releases/assets/13' );
		$location = 'https://release-assets.githubusercontent.com/x';
		$headers  = array(
			'Authorization' => 'Bearer secret',
			'Accept'        => 'application/octet-stream',
		);
		$data     = null;
		$options  = null;
		$updater->strip_token_on_redirect( $location, $headers, $data, $options, $ours );
		$this->assertSame( array( 'Accept' => 'application/octet-stream' ), $headers );

		// Redirect staying on the API: kept.
		$location = 'https://api.github.com/repos/agence-lumia/lumia-factures/releases/assets/14';
		$headers  = array( 'Authorization' => 'Bearer secret' );
		$updater->strip_token_on_redirect( $location, $headers, $data, $options, $ours );
		$this->assertArrayHasKey( 'Authorization', $headers );

		// Foreign request (other plugin, other token / other URL) redirected off the API: untouched.
		$location = 'https://api.example.com/v2/x';
		$headers  = array( 'Authorization' => 'Bearer other-plugin-token' );
		$foreign  = (object) array( 'url' => 'https://api.example.com/v1/x' );
		$updater->strip_token_on_redirect( $location, $headers, $data, $options, $foreign );
		$this->assertSame( array( 'Authorization' => 'Bearer other-plugin-token' ), $headers );

		$headers = array( 'Authorization' => 'Bearer secret' );
		$updater->strip_token_on_redirect( $location, $headers, $data, $options, $foreign );
		$this->assertArrayHasKey( 'Authorization', $headers, 'Pre-redirect URL not ours.' );

		$this->assertNotFalse( has_action( 'requests-requests.before_redirect', array( $updater, 'strip_token_on_redirect' ) ) );
	}

	public function test_channel_change_purges_update_caches(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$settings = Plugin::instance()->settings();
		$settings->set( 'global.update_channel', 'dev' );
		set_transient( 'lumia_factures_github_update_dev', array( 'version' => '9.9.9-dev.1' ), 600 );
		set_site_transient( 'update_plugins', (object) array( 'response' => array( self::BASENAME => (object) array( 'new_version' => '9.9.9-dev.1' ) ) ) );

		$_POST['lumia_factures_global'] = array( 'update_channel' => 'stable' );
		$handler                        = new SaveHandler( Plugin::instance()->modules(), $settings, 'lumia-factures', Plugin::instance()->updater() );
		$method                         = new \ReflectionMethod( $handler, 'save_global' );
		$method->setAccessible( true );
		$method->invoke( $handler );
		unset( $_POST['lumia_factures_global'] );

		$this->assertSame( 'stable', $settings->get( 'global.update_channel' ) );
		$this->assertFalse( get_transient( 'lumia_factures_github_update_dev' ) );
		$this->assertFalse( get_site_transient( 'update_plugins' ) );
	}

	public function test_check_updates_requires_nonce_and_capability(): void {
		$_POST = array();
		$this->assertDies( 'administrator', array(), 'Invalid nonce.' );

		$this->assertDies( 'editor', array( 'lumia_factures_check_nonce' => wp_create_nonce( 'lumia_factures_check_updates' ) ), 'Insufficient permissions.' );
		$_POST = array();
	}

	/**
	 * Fires the manual update check as a role and expects wp_die().
	 *
	 * @param string               $role Role of the current user.
	 * @param array<string, mixed> $post POST payload.
	 * @param string               $message Expected wp_die() message.
	 */
	private function assertDies( string $role, array $post, string $message ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		$post_with_nonce = $post;
		if ( 'administrator' !== $role ) {
			$post_with_nonce['lumia_factures_check_nonce'] = wp_create_nonce( 'lumia_factures_check_updates' );
		}
		$_POST = $post_with_nonce;
		try {
			do_action( 'admin_post_lumia_factures_check_updates' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core admin-post hook with our action suffix.
		} catch ( \WPDieException $e ) {
			$this->assertSame( $message, $e->getMessage() );
			return;
		}
		$this->fail( 'wp_die() expected for ' . $role );
	}

	public function test_auto_update_toggle_writes_site_option(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_site_option( 'auto_update_plugins' );
		// The test image may define AUTOMATIC_UPDATER_DISABLED, which turns the whole feature off.
		add_filter( 'automatic_updater_disabled', '__return_false' );

		$this->save_global_with( array( 'auto_update' => '1' ) );
		$this->assertContains( self::BASENAME, (array) get_site_option( 'auto_update_plugins', array() ) );

		$this->save_global_with(
			array(
				'auto_update_initial' => '1',
			)
		);
		$this->assertNotContains( self::BASENAME, (array) get_site_option( 'auto_update_plugins', array() ) );
	}

	/**
	 * Runs SaveHandler::save_global() with a global-settings payload.
	 *
	 * @param array<string, mixed> $payload Payload of lumia_factures_global.
	 */
	private function save_global_with( array $payload ): void {
		$_POST['lumia_factures_global'] = $payload;
		$handler                        = new SaveHandler( Plugin::instance()->modules(), Plugin::instance()->settings(), 'lumia-factures', Plugin::instance()->updater() );
		$method                         = new \ReflectionMethod( $handler, 'save_global' );
		$method->setAccessible( true );
		$method->invoke( $handler );
		unset( $_POST['lumia_factures_global'] );
	}
}
