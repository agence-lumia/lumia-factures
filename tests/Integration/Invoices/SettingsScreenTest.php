<?php
/**
 * Invoices settings screen: form, save, counters, schema errors.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the tests fire WordPress core hooks (admin_post_*, admin_enqueue_scripts).

use Lumia\Factures\Core\Plugin;
use Lumia\Factures\Modules\Invoices\Admin\Counters;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Rendering\DocumentData;
use Lumia\Factures\Modules\Invoices\Rendering\Placeholders;
use Lumia\Factures\Modules\Invoices\Settings\Schema;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;
use WPDieException;

/**
 * The settings screen of the Invoices module.
 */
class SettingsScreenTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'lumia_factures_module_invoices' );
		delete_transient( 'lumia_factures_invoices_errors' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
	}

	public function tear_down(): void {
		$_POST    = [];
		$_REQUEST = [];
		$_GET     = [];
		delete_transient( 'lumia_factures_invoices_errors' );
		parent::tear_down();
	}

	/**
	 * Renders the Invoices tab of the plugin page.
	 */
	private function render(): string {
		$_GET['page'] = 'lumia-factures';
		$_GET['tab']  = 'module_invoices';
		ob_start();
		try {
			Plugin::instance()->admin()->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Turns wp_safe_redirect() into an exception carrying the location.
	 */
	private function catch_redirects(): void {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the test.
			}
		);
	}

	/**
	 * Fires an admin-post action and returns the redirect location.
	 *
	 * @param string               $action admin-post action.
	 * @param array<string, mixed> $post   POST payload.
	 */
	private function post( string $action, array $post ): string {
		$_POST    = $post;
		$_REQUEST = $post;
		$this->catch_redirects();
		try {
			do_action( 'admin_post_' . $action );
		} catch ( \RuntimeException $e ) {
			return substr( $e->getMessage(), strlen( 'redirect:' ) );
		}
		$this->fail( 'No redirect.' );
	}

	/**
	 * Runs the raise handler (registered on admin requests only) and returns the redirect location.
	 *
	 * @param array<string, mixed> $post POST payload.
	 */
	private function raise_post( array $post ): string {
		$_POST    = $post;
		$_REQUEST = $post;
		$this->catch_redirects();
		try {
			Counters::handle();
		} catch ( \RuntimeException $e ) {
			return substr( $e->getMessage(), strlen( 'redirect:' ) );
		}
		$this->fail( 'No redirect.' );
	}

	public function test_settings_form_posts_every_group(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'id="lumia-factures-module-form"', $html );
		$this->assertStringContainsString( 'name="lumia_factures_module_settings[numbering][format]"', $html );
		$this->assertStringContainsString( 'name="lumia_factures_tab" value="module_invoices"', $html );
		$this->assertStringContainsString( 'name="action" value="lumia_factures_save_settings"', $html );

		foreach ( [ 'general', 'company', 'numbering', 'content', 'design' ] as $tab ) {
			$this->assertStringContainsString( 'data-lumia-tab-panel="' . $tab . '"', $html, "Panel $tab." );
			$this->assertStringContainsString( 'data-lumia-tab="' . $tab . '"', $html, "Tab $tab." );
		}

		// Every field of the schema has an input.
		foreach ( Schema::fields() as $group => $fields ) {
			foreach ( array_keys( $fields ) as $key ) {
				$this->assertStringContainsString( 'name="lumia_factures_module_settings[' . $group . '][' . $key . ']', $html, "Field $group.$key." );
			}
		}
	}

	public function test_list_settings_post_an_empty_entry_first(): void {
		$html = $this->render();

		foreach ( [ 'trigger_statuses', 'email_attachments' ] as $key ) {
			$hidden = '<input type="hidden" name="lumia_factures_module_settings[general][' . $key . '][]" value="">';
			$this->assertStringContainsString( $hidden, $html, $key );
		}
	}

	public function test_unchecking_every_item_of_a_list_saves_an_empty_list(): void {
		$instance = Plugin::instance()->modules()->get_active_instances()['invoices'];
		$this->assertSame( [ 'processing', 'completed' ], Module::settings()['general']['trigger_statuses'] );

		// What the browser posts when every checkbox is off: only the hidden empty entry.
		$instance->save_settings(
			[
				'general' => [
					'trigger_statuses'  => [ '' ],
					'email_attachments' => [ '' ],
				],
			]
		);

		$this->assertSame( [], Module::settings()['general']['trigger_statuses'] );
		$this->assertSame( [], Module::settings()['general']['email_attachments'] );
	}

	public function test_saving_through_save_handler_persists(): void {
		$location = $this->post(
			'lumia_factures_save_settings',
			[
				'lumia_factures_nonce'           => wp_create_nonce( 'lumia_factures_save_settings' ),
				'action'                         => 'lumia_factures_save_settings',
				'lumia_factures_tab'             => 'module_invoices',
				'lumia_factures_module_settings' => wp_slash(
					[
						'numbering' => [
							'format'       => 'INV-{year}-{number:4}',
							'start_number' => '120',
						],
						'company'   => [
							'phone' => '0102030405',
							'siret' => '123 456',
						],
						'design'    => [ 'color_primary' => '#336699' ],
					]
				),
			]
		);

		$this->assertStringContainsString( 'tab=module_invoices', $location );
		$this->assertStringContainsString( 'lumia_factures_notice=settings_saved', $location );

		$settings = Module::settings();
		$this->assertSame( 'INV-{year}-{number:4}', $settings['numbering']['format'] );
		$this->assertSame( 120, $settings['numbering']['start_number'] );
		$this->assertSame( '0102030405', $settings['company']['phone'] );
		$this->assertSame( '#336699', $settings['design']['color_primary'] );
	}

	public function test_schema_error_is_displayed_once(): void {
		set_transient( 'lumia_factures_invoices_errors', [ 'The format cannot be empty.' ], MINUTE_IN_SECONDS );

		$first = $this->render();
		$this->assertStringContainsString( 'lumia-notice lumia-notice--error', $first );
		$this->assertStringContainsString( 'The format cannot be empty.', $first );
		$this->assertFalse( get_transient( 'lumia_factures_invoices_errors' ) );

		$second = $this->render();
		$this->assertStringNotContainsString( 'The format cannot be empty.', $second );
	}

	public function test_invalid_format_save_keeps_the_old_one_and_sets_the_transient(): void {
		$instance = Plugin::instance()->modules()->get_active_instances()['invoices'];
		$instance->save_settings( [ 'numbering' => [ 'format' => '' ] ] );

		$this->assertSame( 'FA-{year}-{number:5}', Module::settings()['numbering']['format'] );
		$this->assertNotEmpty( get_transient( 'lumia_factures_invoices_errors' ) );
	}

	public function test_raise_counter_requires_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		Sequences::next( 'invoice_2026', 1 );

		$_REQUEST['_wpnonce'] = wp_create_nonce( Counters::ACTION );
		$_POST                = [
			'sequence_key' => 'invoice_2026',
			'value'        => '50',
		];

		try {
			Counters::handle();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		$this->assertSame( 1, Sequences::current( 'invoice_2026' ) );
	}

	public function test_raise_counter_requires_a_valid_nonce(): void {
		Sequences::next( 'invoice_2026', 1 );
		$_REQUEST['_wpnonce'] = 'bad';
		$_POST                = [
			'sequence_key' => 'invoice_2026',
			'value'        => '50',
		];

		$this->expectException( WPDieException::class );
		Counters::handle();
	}

	public function test_raise_counter_refuses_lower_value(): void {
		Sequences::next( 'invoice_2026', 1 );
		Sequences::raise( 'invoice_2026', 40 );

		$this->assertSame( 'counter_not_raised', Counters::raise_for( 'invoice_2026', 10 ) );
		$this->assertSame( 'counter_not_raised', Counters::raise_for( 'invoice_2026', 40 ) );
		$this->assertSame( 40, Sequences::current( 'invoice_2026' ) );
	}

	public function test_raise_counter_raises_a_higher_value(): void {
		Sequences::next( 'invoice_2026', 1 );

		$this->assertSame( 'counter_raised', Counters::raise_for( 'invoice_2026', 99 ) );
		$this->assertSame( 99, Sequences::current( 'invoice_2026' ) );
		$this->assertSame( 100, Sequences::next( 'invoice_2026', 1 ) );
	}

	public function test_raise_counter_refuses_an_unknown_key(): void {
		$this->assertSame( 'counter_not_raised', Counters::raise_for( 'made_up_key', 99 ) );
		$this->assertSame( 0, Sequences::current( 'made_up_key' ) );
	}

	public function test_raise_handler_redirects_to_the_numbering_tab_with_a_notice(): void {
		Sequences::next( 'invoice_2026', 1 );

		$location = $this->raise_post(
			[
				'_wpnonce'     => wp_create_nonce( Counters::ACTION ),
				'sequence_key' => 'invoice_2026',
				'value'        => '25',
			]
		);

		$this->assertStringContainsString( 'tab=module_invoices', $location );
		$this->assertStringContainsString( 'lumia_factures_notice=counter_raised', $location );
		$this->assertStringContainsString( 'lumia_factures_notice_type=success', $location );
		$this->assertStringEndsWith( '#numbering', $location );
		$this->assertSame( 25, Sequences::current( 'invoice_2026' ) );

		$location = $this->raise_post(
			[
				'_wpnonce'     => wp_create_nonce( Counters::ACTION ),
				'sequence_key' => 'invoice_2026',
				'value'        => '3',
			]
		);

		$this->assertStringContainsString( 'lumia_factures_notice=counter_not_raised', $location );
		$this->assertStringContainsString( 'lumia_factures_notice_type=error', $location );
		$this->assertSame( 25, Sequences::current( 'invoice_2026' ) );
	}

	public function test_counters_table_lists_sequences_with_a_standalone_raise_form(): void {
		Sequences::next( 'invoice_2026', 1 );

		$html = $this->render();

		$this->assertStringContainsString( 'invoice_2026', $html );
		$this->assertStringContainsString( 'id="lumia-factures-raise-0"', $html );
		$this->assertStringContainsString( 'form="lumia-factures-raise-0"', $html );
		$this->assertStringContainsString( 'name="action" value="lumia_factures_invoices_raise_counter"', $html );

		// HTML forms cannot nest: the raise form opens after the module form is closed.
		$module_end = strpos( $html, '</form>' );
		$this->assertNotFalse( $module_end );
		$this->assertGreaterThan( $module_end, (int) strpos( $html, 'id="lumia-factures-raise-0"' ) );
	}

	public function test_toast_codes_are_registered(): void {
		Counters::register();
		$messages = apply_filters( 'lumia_factures_admin_notices', [] );

		$this->assertArrayHasKey( 'counter_raised', $messages );
		$this->assertArrayHasKey( 'counter_not_raised', $messages );
	}

	public function test_js_data_carries_the_number_sample(): void {
		Sequences::next( 'invoice_' . wp_date( 'Y' ), 1 );
		$data = Plugin::instance()->modules()->get_active_instances()['invoices']->get_admin_js_data();

		$this->assertSame( wp_date( 'Y' ), $data['invoices']['sample']['year'] );
		$this->assertSame( wp_date( 'm' ), $data['invoices']['sample']['month'] );
		$this->assertSame( wp_date( 'd' ), $data['invoices']['sample']['day'] );
		$this->assertSame( '14243', $data['invoices']['sample']['orderNumber'] );
		$this->assertSame( '27', $data['invoices']['sample']['customerId'] );
		$this->assertSame( 1, $data['invoices']['counters'][ 'invoice_' . wp_date( 'Y' ) ] );
		$this->assertContains( 'year', $data['invoices']['tags'] );
		$this->assertArrayHasKey( 'i18n', $data );
	}

	public function test_module_declares_its_script_and_media_dependency(): void {
		$instance = Plugin::instance()->modules()->get_active_instances()['invoices'];

		$this->assertCount( 1, $instance->get_admin_js() );
		$this->assertStringEndsWith( 'admin/js/invoices.js', $instance->get_admin_js()[0] );
		$this->assertContains( 'media-editor', $instance->get_admin_js_deps() );
	}

	public function test_every_help_tag_is_resolved(): void {
		$order = OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );
		$doc   = new DocumentData( '', 'FA-1', time() );
		$text  = '{' . implode( '} {', Placeholders::TAGS ) . '}';

		$this->assertStringNotContainsString( '{', Placeholders::resolve( $text, $order, $doc ) );
	}
}
