<?php
/**
 * Invoice settings schema and sanitizer.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Settings\Schema;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * Schema::defaults(), Schema::sanitize() and the Module wiring.
 */
class SettingsSchemaTest extends TestCase {

	public function test_defaults_cover_every_group(): void {
		$defaults = Schema::defaults();
		foreach ( [ 'general', 'company', 'numbering', 'content', 'design' ] as $group ) {
			$this->assertArrayHasKey( $group, $defaults );
		}
		$this->assertSame( 'FA-{year}-{number:5}', $defaults['numbering']['format'] );
		$this->assertSame( [ 'processing', 'completed' ], $defaults['general']['trigger_statuses'] );
		$this->assertSame( 'inter', $defaults['design']['font_body'] );
		$this->assertSame( '', $defaults['content']['invoice_title'] );
		$this->assertArrayNotHasKey( 'retention_days', $defaults['general'] );
		$this->assertArrayNotHasKey( 'legal_mentions', $defaults['content'] );
		$this->assertTrue( $defaults['content']['item_parent_name_enabled'] );
	}

	public function test_unknown_keys_are_dropped(): void {
		$result = Schema::sanitize(
			[
				'bogus'   => [ 'a' => 'b' ],
				'company' => [
					'phone'   => '0102030405',
					'unknown' => 'x',
				],
			],
			Schema::defaults()
		);
		$this->assertArrayNotHasKey( 'bogus', $result['settings'] );
		$this->assertArrayNotHasKey( 'unknown', $result['settings']['company'] );
		$this->assertSame( '0102030405', $result['settings']['company']['phone'] );
	}

	public function test_textarea_line_breaks_are_normalised_to_lf(): void {
		$result = Schema::sanitize( [ 'content' => [ 'message_body' => "a\r\nb\rc\nd" ] ], Schema::defaults() );
		$this->assertSame( "a\nb\nc\nd", $result['settings']['content']['message_body'] );
	}

	public function test_absent_key_keeps_current(): void {
		$current                     = Schema::defaults();
		$current['company']['siret'] = '123';
		$result                      = Schema::sanitize( [ 'company' => [ 'phone' => '1' ] ], $current );
		$this->assertSame( '123', $result['settings']['company']['siret'] );
	}

	public function test_invalid_color_keeps_current(): void {
		$result = Schema::sanitize( [ 'design' => [ 'color_primary' => 'not-a-color' ] ], Schema::defaults() );
		$this->assertSame( '#111111', $result['settings']['design']['color_primary'] );
		$result = Schema::sanitize( [ 'design' => [ 'color_primary' => '#abcdef' ] ], Schema::defaults() );
		$this->assertSame( '#abcdef', $result['settings']['design']['color_primary'] );
	}

	public function test_numbers_are_clamped(): void {
		$result = Schema::sanitize(
			[
				'design' => [
					'font_size'  => '99',
					'margin_top' => '-4',
				],
			],
			Schema::defaults()
		);
		$this->assertSame( 14.0, $result['settings']['design']['font_size'] );
		$this->assertSame( 5, $result['settings']['design']['margin_top'] );
	}

	public function test_unknown_status_is_filtered_out(): void {
		$result = Schema::sanitize( [ 'general' => [ 'trigger_statuses' => [ 'completed', 'bogus', '' ] ] ], Schema::defaults() );
		$this->assertSame( [ 'completed' ], $result['settings']['general']['trigger_statuses'] );
	}

	public function test_unknown_email_is_filtered_out(): void {
		$result = Schema::sanitize( [ 'general' => [ 'email_attachments' => [ 'customer_invoice', 'bogus' ] ] ], Schema::defaults() );
		$this->assertSame( [ 'customer_invoice' ], $result['settings']['general']['email_attachments'] );
	}

	public function test_unchecked_toggle_saves_false(): void {
		$result = Schema::sanitize( [ 'company' => [ 'phone_enabled' => '0' ] ], Schema::defaults() );
		$this->assertFalse( $result['settings']['company']['phone_enabled'] );
		$result = Schema::sanitize( [ 'company' => [ 'phone_enabled' => '1' ] ], Schema::defaults() );
		$this->assertTrue( $result['settings']['company']['phone_enabled'] );
	}

	public function test_invalid_number_format_keeps_current_and_reports_error(): void {
		delete_transient( 'lumia_factures_invoices_errors' );
		$result = Schema::sanitize( [ 'numbering' => [ 'format' => 'FA-{year}' ] ], Schema::defaults() );
		$this->assertSame( 'FA-{year}-{number:5}', $result['settings']['numbering']['format'] );
		$this->assertNotEmpty( $result['errors'] );

		$module = new Module( 'invoices' );
		$module->save_settings( [ 'numbering' => [ 'format' => 'FA-{year}' ] ] );
		$errors = get_transient( 'lumia_factures_invoices_errors' );
		$this->assertIsArray( $errors );
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'FA-{year}-{number:5}', Module::settings()['numbering']['format'] );
	}

	public function test_yearly_reset_without_year_is_an_error(): void {
		$result = Schema::sanitize( [ 'numbering' => [ 'format' => 'FA-{number}' ] ], Schema::defaults() );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_invalid_format_also_reverts_the_reset_policy(): void {
		$current                               = Schema::defaults();
		$current['numbering']['format']        = 'FA-{number}';
		$current['numbering']['reset_enabled'] = false;
		$current['numbering']['reset_period']  = 'yearly';

		// A broken format posted together with a new policy: the whole numbering policy is kept.
		$result = Schema::sanitize(
			[
				'numbering' => [
					'format'        => 'FA-{year}-{month}',
					'reset_enabled' => '1',
					'reset_period'  => 'monthly',
				],
			],
			$current
		);
		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( 'FA-{number}', $result['settings']['numbering']['format'] );
		$this->assertFalse( $result['settings']['numbering']['reset_enabled'] );
		$this->assertSame( 'yearly', $result['settings']['numbering']['reset_period'] );

		// A policy alone that the kept format cannot carry (a reset without {year}) is refused too.
		$result = Schema::sanitize( [ 'numbering' => [ 'reset_enabled' => '1' ] ], $current );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertFalse( $result['settings']['numbering']['reset_enabled'] );
		$this->assertSame( 'FA-{number}', $result['settings']['numbering']['format'] );

		// Through the module: nothing of the refused policy is stored.
		update_option( 'lumia_factures_module_invoices', $current );
		( new Module( 'invoices' ) )->save_settings(
			[
				'numbering' => [
					'format'        => 'FA-{year}-{month}',
					'reset_enabled' => '1',
					'reset_period'  => 'monthly',
				],
			]
		);
		$stored = Module::settings()['numbering'];
		$this->assertSame( 'FA-{number}', $stored['format'] );
		$this->assertFalse( $stored['reset_enabled'] );
		$this->assertSame( 'yearly', $stored['reset_period'] );
	}

	public function test_multiline_fields_keep_line_breaks(): void {
		$result = Schema::sanitize( [ 'company' => [ 'address' => "1 rue X\n<b>75000</b> Paris" ] ], Schema::defaults() );
		$this->assertSame( "1 rue X\n75000 Paris", $result['settings']['company']['address'] );
	}

	public function test_module_settings_merge_stored_over_defaults(): void {
		update_option( 'lumia_factures_module_invoices', [ 'company' => [ 'phone' => '0102' ] ] );
		$settings = Module::settings();
		$this->assertSame( '0102', $settings['company']['phone'] );
		$this->assertTrue( $settings['company']['siret_enabled'] );
		$this->assertSame( 'FA-{year}-{number:5}', $settings['numbering']['format'] );
		$this->assertSame( $settings, ( new Module( 'invoices' ) )->get_settings() );
	}
}
