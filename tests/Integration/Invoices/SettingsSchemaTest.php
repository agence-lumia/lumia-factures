<?php
/**
 * Invoice settings schema and sanitizer.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Documents\Register;
use Lumia\Factures\Modules\Invoices\Module;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
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

	public function test_credit_notes_defaults(): void {
		$this->assertSame(
			[
				'invoice'     => 'numbering',
				'credit_note' => 'credit_notes',
			],
			Schema::NUMBERED
		);
		$this->assertContains( 'credit_notes', Schema::GROUPS );
		$this->assertSame( Schema::GROUPS, array_keys( Schema::defaults() ) );

		$defaults = Schema::defaults()['credit_notes'];
		$this->assertSame(
			[
				'format'            => 'AV-{year}-{number:5}',
				'start_number'      => 1,
				'reset_enabled'     => true,
				'reset_period'      => 'yearly',
				'title'             => '',
				'reason_enabled'    => true,
				'email_attachments' => [ 'customer_refunded_order' ],
			],
			$defaults
		);
		// Credit notes cannot be turned off: a refund of an invoiced order always gets one.
		$this->assertArrayNotHasKey( 'enabled', $defaults );

		$fields = Schema::fields()['credit_notes'];
		$this->assertSame( 1, $fields['start_number']['min'] );
		$this->assertSame( 999999999, $fields['start_number']['max'] );
		$this->assertSame( [ 'yearly', 'monthly' ], $fields['reset_period']['options'] );

		// The defaults pass their own rules.
		$this->assertSame( [], Schema::sanitize( Schema::defaults(), Schema::defaults() )['errors'] );
	}

	public function test_credit_note_format_validated_independently(): void {
		// A broken credit note format reverts the credit note policy only.
		$result = Schema::sanitize(
			[
				'numbering'    => [ 'format' => 'INV-{year}-{number:4}' ],
				'credit_notes' => [
					'format'       => 'AV-{year}',
					'start_number' => '40',
				],
			],
			Schema::defaults()
		);
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'Credit note number', $result['errors'][0] );
		$this->assertSame( 'INV-{year}-{number:4}', $result['settings']['numbering']['format'] );
		$this->assertSame( 'AV-{year}-{number:5}', $result['settings']['credit_notes']['format'] );
		$this->assertSame( 40, $result['settings']['credit_notes']['start_number'] );

		// A broken invoice format reverts the invoice policy only.
		$result = Schema::sanitize(
			[
				'numbering'    => [ 'format' => 'FA-{year}' ],
				'credit_notes' => [ 'format' => 'CN-{year}-{number:3}' ],
			],
			Schema::defaults()
		);
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringNotContainsString( 'Credit note', $result['errors'][0] );
		$this->assertSame( 'FA-{year}-{number:5}', $result['settings']['numbering']['format'] );
		$this->assertSame( 'CN-{year}-{number:3}', $result['settings']['credit_notes']['format'] );

		// The reset policy of each type is checked against its own format.
		$result = Schema::sanitize(
			[
				'numbering'    => [
					'format'       => 'FA-{year}-{month}-{number:5}',
					'reset_period' => 'monthly',
				],
				'credit_notes' => [ 'reset_period' => 'monthly' ],
			],
			Schema::defaults()
		);
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( 'monthly', $result['settings']['numbering']['reset_period'] );
		$this->assertSame( 'yearly', $result['settings']['credit_notes']['reset_period'] );

		// A credit note policy without reset needs no {year}.
		$result = Schema::sanitize(
			[
				'credit_notes' => [
					'format'        => 'AV-{number:6}',
					'reset_enabled' => '0',
				],
			],
			Schema::defaults()
		);
		$this->assertSame( [], $result['errors'] );
		$this->assertSame( 'AV-{number:6}', $result['settings']['credit_notes']['format'] );
		$this->assertFalse( $result['settings']['credit_notes']['reset_enabled'] );
	}

	public function test_credit_note_format_must_differ_from_invoice_format(): void {
		$message = 'The credit note number format must start with a prefix that differs from the invoice number format, e.g. AV- and FA-.';

		$refused = [
			'same prefix'                    => [ 'FA-{year}-{number:5}', true ],
			'same prefix, other shape'       => [ 'FA-{number:6}', false ],
			'invoice prefix starts it'       => [ 'FA-AV-{year}-{number:5}', true ],
			'it starts the invoice prefix'   => [ 'F{year}-{number:5}', true ],
			'same prefix, other letter case' => [ 'fa-{year}-{number:5}', true ],
			'no prefix'                      => [ '{year}-AV-{number:5}', true ],
		];
		foreach ( $refused as $case => [ $format, $reset ] ) {
			$result = Schema::sanitize(
				[
					'credit_notes' => [
						'format'        => $format,
						'reset_enabled' => $reset ? '1' : '0',
					],
				],
				Schema::defaults()
			);
			$this->assertSame( [ $message ], $result['errors'], $case );
			$this->assertSame( Schema::defaults()['credit_notes'], $result['settings']['credit_notes'], $case );
			$this->assertSame( Schema::defaults()['numbering'], $result['settings']['numbering'], $case );
		}

		$accepted = [ 'AV-{year}-{number:5}', 'FAV-{year}-{number:5}', 'CN{year}{number:4}', 'A-{year}-{number:5}' ];
		foreach ( $accepted as $format ) {
			$result = Schema::sanitize( [ 'credit_notes' => [ 'format' => $format ] ], Schema::defaults() );
			$this->assertSame( [], $result['errors'], $format );
			$this->assertSame( $format, $result['settings']['credit_notes']['format'] );
		}

		// An invoice format moved onto the credit note prefix is refused too: both policies stay.
		$result = Schema::sanitize( [ 'numbering' => [ 'format' => 'AV-{year}-{number:5}' ] ], Schema::defaults() );
		$this->assertSame( [ $message ], $result['errors'] );
		$this->assertSame( 'FA-{year}-{number:5}', $result['settings']['numbering']['format'] );
		$this->assertSame( 'AV-{year}-{number:5}', $result['settings']['credit_notes']['format'] );

		// Swapping the two prefixes in one save is refused (see test_prefix_cannot_take_numbers_of_the_other_type).
		$result = Schema::sanitize(
			[
				'numbering'    => [ 'format' => 'AV-{year}-{number:5}' ],
				'credit_notes' => [ 'format' => 'FA-{year}-{number:5}' ],
			],
			Schema::defaults()
		);
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( Schema::defaults()['numbering'], $result['settings']['numbering'] );
		$this->assertSame( Schema::defaults()['credit_notes'], $result['settings']['credit_notes'] );

		// Through the module: the refused credit note format is not stored, the other fields are.
		delete_transient( 'lumia_factures_invoices_errors' );
		( new Module( 'invoices' ) )->save_settings(
			[
				'company'      => [ 'phone' => '0102' ],
				'credit_notes' => [ 'format' => 'FA-{year}-{number:5}' ],
			]
		);
		$this->assertSame( 'AV-{year}-{number:5}', Module::settings()['credit_notes']['format'] );
		$this->assertSame( '0102', Module::settings()['company']['phone'] );
		$this->assertSame( [ $message ], get_transient( 'lumia_factures_invoices_errors' ) );
	}

	public function test_prefix_cannot_take_numbers_of_the_other_type(): void {
		$message = 'A number format cannot take a prefix that the other document type uses or has already printed (swapping FA- and AV-, for example): an invoice and a credit note would share a number.';
		$year    = (string) wp_date( 'Y' );

		// Invoices FA- and credit notes AV- issued, then a swap in one save: carry_counter() would keep
		// both series going, so the next credit note would print an issued invoice number. Both stay.
		Register::import( 'invoice', 661, 'FA-' . $year . '-00011', 'invoice_' . $year, 11, time() );
		Register::import( 'credit_note', 662, 'AV-' . $year . '-00001', 'credit_note_' . $year, 1, time() );
		Sequences::raise( 'invoice_' . $year, 500 );
		Sequences::raise( 'credit_note_' . $year, 10 );
		update_option( 'lumia_factures_module_invoices', Schema::defaults() );

		delete_transient( 'lumia_factures_invoices_errors' );
		( new Module( 'invoices' ) )->save_settings(
			[
				'company'      => [ 'phone' => '0102' ],
				'numbering'    => [ 'format' => 'AV-{year}-{number:5}' ],
				'credit_notes' => [ 'format' => 'FA-{year}-{number:5}' ],
			]
		);
		$this->assertSame( [ $message ], get_transient( 'lumia_factures_invoices_errors' ) );
		$this->assertSame( 'FA-{year}-{number:5}', Module::settings()['numbering']['format'] );
		$this->assertSame( 'AV-{year}-{number:5}', Module::settings()['credit_notes']['format'] );
		$this->assertSame( '0102', Module::settings()['company']['phone'], 'The other fields are saved.' );
		$this->assertSame( 500, Sequences::current( 'invoice_' . $year ) );
		$this->assertSame( 10, Sequences::current( 'credit_note_' . $year ) );

		// Only one side moving onto the current prefix of the other is refused the same way.
		$result = Schema::sanitize(
			[
				'numbering'    => [ 'format' => 'AV-{year}-{number:5}' ],
				'credit_notes' => [ 'format' => 'CR-{year}-{number:5}' ],
			],
			Schema::defaults()
		);
		$this->assertSame( [ $message ], $result['errors'] );
		$this->assertSame( 'FA-{year}-{number:5}', $result['settings']['numbering']['format'] );
		$this->assertSame( 'CR-{year}-{number:5}', $result['settings']['credit_notes']['format'], 'The credit note side was free.' );

		// In two saves the current formats no longer see the old prefix: the register does. FA- moved
		// to FAC- for the invoices, and the credit notes cannot take FA- (any letter case).
		$current                        = Schema::defaults();
		$current['numbering']['format'] = 'FAC-{year}-{number:5}';
		$this->assertSame( [], Schema::sanitize( [], $current )['errors'] );
		foreach ( [ 'FA-{year}-{number:5}', 'fa-{year}-{number:6}' ] as $format ) {
			$result = Schema::sanitize( [ 'credit_notes' => [ 'format' => $format ] ], $current );
			$this->assertSame( [ $message ], $result['errors'], $format );
			$this->assertSame( 'AV-{year}-{number:5}', $result['settings']['credit_notes']['format'], $format );
		}

		// The same for the invoices: the credit notes moved from AV- to CR-, AV- stays theirs.
		$current                           = Schema::defaults();
		$current['credit_notes']['format'] = 'CR-{year}-{number:5}';
		$result                            = Schema::sanitize( [ 'numbering' => [ 'format' => 'AV-{year}-{number:5}' ] ], $current );
		$this->assertSame( [ $message ], $result['errors'] );
		$this->assertSame( 'FA-{year}-{number:5}', $result['settings']['numbering']['format'] );

		// An invoice format starting with a tag prints digits: refused while a credit note number
		// starts with a digit (printed by an earlier 2026-{number:5} format).
		Register::import( 'credit_note', 663, $year . '-00001', 'credit_note', 1, time() );
		$result = Schema::sanitize( [ 'numbering' => [ 'format' => '{year}{month}{number:6}' ] ], Schema::defaults() );
		$this->assertSame( [ $message ], $result['errors'] );
		$this->assertSame( 'FA-{year}-{number:5}', $result['settings']['numbering']['format'] );

		// A new prefix nothing has printed is accepted, and an unchanged prefix is never looked up.
		$result = Schema::sanitize(
			[
				'numbering'    => [ 'format' => 'INV-{year}-{number:5}' ],
				'credit_notes' => [ 'format' => 'AV-{year}{number:6}' ],
			],
			Schema::defaults()
		);
		$this->assertSame( [], $result['errors'] );
		$this->assertSame( 'INV-{year}-{number:5}', $result['settings']['numbering']['format'] );
	}

	public function test_credit_note_prefix_needs_non_digit_when_invoice_format_starts_with_tag(): void {
		// The Facture PDF Lumia default kept by Neonat: an invoice number made of digits only.
		$current                        = Schema::defaults();
		$current['numbering']['format'] = '{year}{month}{number:6}';
		$this->assertSame( [], Schema::sanitize( [], $current )['errors'] );

		foreach ( [ 'AV-{year}-{number:5}', 'A{year}{number:5}', '-{year}{number:5}' ] as $format ) {
			$result = Schema::sanitize( [ 'credit_notes' => [ 'format' => $format ] ], $current );
			$this->assertSame( [], $result['errors'], $format );
			$this->assertSame( $format, $result['settings']['credit_notes']['format'] );
		}

		// A prefix starting with a digit could be printed by the invoice tags (2026-00001).
		foreach ( [ '2026-{number:5}', '9{year}{number:5}', '00{year}-{number:5}' ] as $format ) {
			$result = Schema::sanitize(
				[
					'credit_notes' => [
						'format'        => $format,
						'reset_enabled' => '0',
					],
				],
				$current
			);
			$this->assertCount( 1, $result['errors'], $format );
			$this->assertSame( 'AV-{year}-{number:5}', $result['settings']['credit_notes']['format'], $format );
			$this->assertTrue( $result['settings']['credit_notes']['reset_enabled'], $format );
		}

		// Behind a literal invoice prefix, a credit note prefix may start with a digit.
		$result = Schema::sanitize(
			[
				'credit_notes' => [
					'format'        => '2026-{number:5}',
					'reset_enabled' => '0',
				],
			],
			Schema::defaults()
		);
		$this->assertSame( [], $result['errors'] );
	}

	public function test_existing_settings_get_credit_note_defaults_without_migration(): void {
		// Settings saved by 2.0: no credit_notes group.
		$stored                        = Schema::defaults();
		$stored['numbering']['format'] = '{year}{month}{number:6}';
		$stored['company']['phone']    = '0102';
		unset( $stored['credit_notes'] );
		update_option( 'lumia_factures_module_invoices', $stored );

		$settings = Module::settings();
		$this->assertSame( Schema::defaults()['credit_notes'], $settings['credit_notes'] );
		$this->assertSame( '{year}{month}{number:6}', $settings['numbering']['format'] );
		$this->assertSame( $stored, get_option( 'lumia_factures_module_invoices' ), 'Reading never writes.' );

		// The next save stores the group, with no error and nothing else changed.
		delete_transient( 'lumia_factures_invoices_errors' );
		( new Module( 'invoices' ) )->save_settings( [ 'company' => [ 'phone' => '0203' ] ] );
		$this->assertFalse( get_transient( 'lumia_factures_invoices_errors' ) );

		$saved = get_option( 'lumia_factures_module_invoices' );
		$this->assertSame( Schema::defaults()['credit_notes'], $saved['credit_notes'] );
		$this->assertSame( '{year}{month}{number:6}', $saved['numbering']['format'] );
		$this->assertSame( '0203', $saved['company']['phone'] );
	}

	public function test_carry_counter_runs_per_type(): void {
		$year = (string) wp_date( 'Y' );
		Sequences::raise( 'invoice_' . $year, 30 );
		Sequences::raise( 'credit_note_' . $year, 7 );
		$module = new Module( 'invoices' );

		// A credit note policy change carries the credit note counter only.
		$module->save_settings(
			[
				'credit_notes' => [
					'format'        => 'AV-{number:5}',
					'reset_enabled' => '0',
				],
			]
		);
		$this->assertSame( 7, Sequences::current( 'credit_note' ) );
		$this->assertSame( 0, Sequences::current( 'invoice' ) );
		$this->assertSame( 30, Sequences::current( 'invoice_' . $year ) );

		// An invoice policy change carries the invoice counter only.
		$module->save_settings(
			[
				'numbering' => [
					'format'        => 'FA-{number:5}',
					'reset_enabled' => '0',
				],
			]
		);
		$this->assertSame( 30, Sequences::current( 'invoice' ) );
		$this->assertSame( 7, Sequences::current( 'credit_note' ) );
		$this->assertSame( 7, Sequences::current( 'credit_note_' . $year ) );

		// A refused credit note policy carries nothing.
		$module->save_settings( [ 'credit_notes' => [ 'format' => 'FA-{number:4}' ] ] );
		$this->assertFalse( Module::settings()['credit_notes']['reset_enabled'] );
		$this->assertSame( 'AV-{number:5}', Module::settings()['credit_notes']['format'] );
		$this->assertSame( 7, Sequences::current( 'credit_note' ) );
	}
}
