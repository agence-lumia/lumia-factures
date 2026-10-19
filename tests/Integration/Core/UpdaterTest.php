<?php
/**
 * GitHub release updater.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Core;

defined( 'ABSPATH' ) || exit;

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
				$this->release( 'v2.0.0' ),
				$this->release( 'v2.0.1-dev.2' ),
				$this->release( 'v2.0.1-dev.1' ),
			)
		);

		$result = $this->run_check();

		$this->assertSame( '2.0.1-dev.2', $result->response[ self::BASENAME ]->new_version );
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
}
