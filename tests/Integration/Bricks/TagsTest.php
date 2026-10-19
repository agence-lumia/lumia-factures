<?php
/**
 * Bricks dynamic tags and their Facture PDF Lumia aliases.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Bricks;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The hooks under test are Bricks'.

use Lumia\Factures\Core\Plugin;
use Lumia\Factures\Modules\Bricks\Tags;
use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Front\CustomerDownload;
use Lumia\Factures\Modules\Invoices\Migration\Importer;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Storage\Archive;
use Lumia\Factures\Tests\Support\LegacyFixture;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WC_Order;

/**
 * The tags are rendered through the Bricks filters, called directly: Bricks itself is not needed.
 */
class TagsTest extends TestCase {

	/**
	 * Archived files written by a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $written = [];

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		delete_option( 'lumia_factures_module_invoices' );
		wp_set_current_user( 0 );
		Tags::register();
	}

	public function tear_down(): void {
		foreach ( $this->written as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		Plugin::instance()->modules()->deactivate( 'bricks' );
		unset( $_GET['key'] );
		parent::tear_down();
	}

	public function test_tags_render_for_order_context(): void {
		$order = $this->issued_order();
		$doc   = Register::find( 'invoice', $order->get_id() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$this->assertSame( (string) $doc->number, apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_number}', $order, 'text' ) );
		$this->assertSame( (string) $doc->number, apply_filters( 'bricks/dynamic_data/render_tag', 'lumia_factures_invoice_number', $order, 'text' ) );
		$this->assertSame( 'available', apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_status}', $order, 'text' ) );
		$this->assertStringContainsString( 'lumia_factures_invoice=' . $order->get_id(), apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_url}', $order, 'text' ) );

		// Inside a larger content, through both content filters.
		$html     = '<a href="{lumia_factures_invoice_url}">N {lumia_factures_invoice_number}</a>';
		$expected = '<a href="' . CustomerDownload::url( $order ) . '">N ' . $doc->number . '</a>';
		$this->assertSame( $expected, apply_filters( 'bricks/dynamic_data/render_content', $html, $order, 'text' ) );
		$this->assertSame( $expected, apply_filters( 'bricks/frontend/render_data', $html, $order ) );

		// A tag that is not ours, and structured values, go through untouched.
		$this->assertSame( '{post_title}', apply_filters( 'bricks/dynamic_data/render_tag', '{post_title}', $order, 'text' ) );
		$this->assertSame( [ 'a' => 1 ], apply_filters( 'bricks/dynamic_data/render_tag', [ 'a' => 1 ], $order, 'text' ) );
		$this->assertSame( 'no tag', apply_filters( 'bricks/dynamic_data/render_content', 'no tag', $order, 'text' ) );

		// The picker lists our names, in their group, and not the legacy ones.
		$list  = apply_filters( 'bricks/dynamic_tags_list', [ [ 'name' => '{post_title}' ] ] );
		$names = wp_list_pluck( $list, 'name' );
		$this->assertSame( [ '{post_title}', '{lumia_factures_invoice_url}', '{lumia_factures_invoice_number}', '{lumia_factures_invoice_status}' ], $names );
		$this->assertSame( 'Lümia Factures', $list[1]['group'] );
	}

	public function test_legacy_aliases_render_same_values(): void {
		$order = $this->issued_order();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		foreach ( [ 'url', 'number', 'status' ] as $suffix ) {
			$this->assertSame(
				apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_' . $suffix . '}', $order, 'text' ),
				apply_filters( 'bricks/dynamic_data/render_tag', '{fpl_invoice_' . $suffix . '}', $order, 'text' )
			);
		}

		$this->assertSame(
			apply_filters( 'bricks/dynamic_data/render_content', '{lumia_factures_invoice_url} {lumia_factures_invoice_status}', $order, 'text' ),
			apply_filters( 'bricks/dynamic_data/render_content', '{fpl_invoice_url} {fpl_invoice_status}', $order, 'text' )
		);
		$this->assertNotSame( '', apply_filters( 'bricks/dynamic_data/render_tag', '{fpl_invoice_url}', $order, 'text' ) );
		$this->assertNotContains( '{fpl_invoice_url}', wp_list_pluck( apply_filters( 'bricks/dynamic_tags_list', [] ), 'name' ) );
	}

	public function test_url_empty_without_access(): void {
		$order = $this->issued_order();

		// A guest without the key.
		foreach ( [ 'url', 'number' ] as $suffix ) {
			$this->assertSame( '', apply_filters( 'bricks/dynamic_data/render_tag', '{fpl_invoice_' . $suffix . '}', $order, 'text' ) );
		}
		$this->assertSame( 'none', apply_filters( 'bricks/dynamic_data/render_tag', '{fpl_invoice_status}', $order, 'text' ), 'A condition on the status hides the element.' );

		// Another customer.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->assertSame( '', apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_url}', $order, 'text' ) );

		// A guest with the key of the page (order received).
		wp_set_current_user( 0 );
		$_GET['key'] = $order->get_order_key();
		$url         = apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_url}', $order, 'text' );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $url );

		// No order in the context.
		$this->assertSame( '', apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_url}', null, 'text' ) );
	}

	public function test_status_values(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$status = static fn( WC_Order $order ) => apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_status}', $order, 'text' );

		// Not in a trigger status: nothing to issue.
		$pending = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$this->assertSame( 'none', $status( $pending ) );

		// Can be issued but is not: pending. The tag itself does not issue anything.
		$pending->set_status( 'processing' );
		$this->assertSame( 'pending', $status( $pending ) );
		$this->assertNull( Register::find( 'invoice', $pending->get_id() ) );

		// Issued: available, even if the order later leaves the trigger statuses.
		$issued = $this->issued_order();
		$this->assertSame( 'available', $status( $issued ) );
		$issued->set_status( 'cancelled' );
		$this->assertSame( 'available', $status( $issued ) );
		$this->assertSame( (string) Register::find( 'invoice', $issued->get_id() )->number, apply_filters( 'bricks/dynamic_data/render_tag', '{lumia_factures_invoice_number}', $issued, 'text' ) );
	}

	public function test_importer_enables_bricks_module_when_legacy_tags_used(): void {
		$modules = Plugin::instance()->modules();
		$this->assertFalse( $modules->is_active( 'bricks' ) );

		// Without the legacy tag nothing changes.
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta(
			$page,
			'_bricks_page_content_2',
			[
				[
					'id'       => 'abc',
					'settings' => [ 'text' => '{post_title}' ],
				],
			]
		);
		$report = Importer::run( false )->to_array();
		$this->assertFalse( $modules->is_active( 'bricks' ) );
		$this->assertSame( [], $report['notes'] );

		// A dry run reports it and writes nothing.
		update_post_meta(
			$page,
			'_bricks_page_content_2',
			[
				[
					'id'       => 'abc',
					'settings' => [ 'link' => '{fpl_invoice_url}' ],
				],
			]
		);
		$dry = Importer::run( true )->to_array();
		$this->assertFalse( $modules->is_active( 'bricks' ) );
		$this->assertCount( 1, $dry['notes'] );

		$real = Importer::run( false )->to_array();
		$this->assertTrue( $modules->is_active( 'bricks' ) );
		$this->assertSame( $dry['notes'], $real['notes'] );

		// Already on: no second note.
		$this->assertSame( [], Importer::run( false )->to_array()['notes'] );
	}

	public function test_migration_run_turns_the_bricks_module_on(): void {
		$modules = Plugin::instance()->modules();
		$this->assertNotNull( $modules->get( 'bricks' ), 'The modules are registered, as after a boot.' );
		$this->assertFalse( $modules->is_active( 'bricks' ) );

		$legacy = new LegacyFixture();
		try {
			$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
			$legacy->invoice( $order, 'FA-0005', 5, 'invoice_' . wp_date( 'Y' ), (int) strtotime( '-1 day' ), null );
			$this->bricks_page( '{fpl_invoice_status}' );

			delete_option( Migration::OPTION );
			delete_transient( Migration::NO_DATA_TRANSIENT );
			Migration::prepare();
			Migration::maybe_run();
		} finally {
			$legacy->cleanup();
		}

		$state = Migration::state();
		$this->assertSame( 'done', $state['status'] );
		$this->assertTrue( $modules->is_active( 'bricks' ) );
		$this->assertCount( 1, $state['report']['notes'] );
		$this->assertStringContainsString( 'the Bricks module is turned on', $state['report']['notes'][0] );
	}

	/**
	 * Inside activate_plugin() the plugin has not booted and no module is registered: turning the
	 * Bricks module on fails, and the report must say so instead of failing silently.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bricks_module_that_cannot_be_turned_on_is_reported(): void {
		$instance = new \ReflectionProperty( Plugin::class, 'instance' );
		$instance->setValue( null, null );
		$this->assertSame( [], Plugin::instance()->modules()->get_all(), 'Fresh instance expected.' );

		// The child process fails on any output to stderr: the import log goes to a file.
		$log = (string) tempnam( sys_get_temp_dir(), 'lf-log' );
		ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test isolation, child process.

		$this->bricks_page( '{fpl_invoice_url}' );
		$report = Importer::run( false )->to_array();

		$this->assertStringContainsString( 'The Bricks module could not be turned on', (string) file_get_contents( $log ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test file.
		wp_delete_file( $log );

		$this->assertFalse( Plugin::instance()->modules()->is_active( 'bricks' ) );
		$this->assertCount( 1, $report['notes'] );
		$this->assertStringContainsString( 'could not be turned on', $report['notes'][0] );
	}

	/**
	 * A page whose Bricks content holds a tag.
	 *
	 * @param string $tag Tag.
	 */
	private function bricks_page( string $tag ): int {
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta(
			$page,
			'_bricks_page_content_2',
			[
				[
					'id'       => 'abc',
					'settings' => [ 'link' => $tag ],
				],
			]
		);

		return $page;
	}

	/**
	 * An order with an issued, archived invoice.
	 */
	private function issued_order(): WC_Order {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$order->update_status( 'processing' );
		$doc = Register::find( 'invoice', $order->get_id() );
		if ( null !== $doc && null !== $doc->file ) {
			$this->written[] = Archive::root() . '/' . $doc->file;
		}

		return wc_get_order( $order->get_id() );
	}
}
