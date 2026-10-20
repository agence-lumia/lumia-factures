<?php
/**
 * Mapping of the legacy Facture PDF Lumia settings.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Migration\LegacySettings;
use Lumia\Factures\Modules\Invoices\Settings\Schema;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * LegacySettings::map(): legacy `fpl_settings` to the lot 2 schema.
 */
class LegacySettingsTest extends TestCase {

	public function test_neonat_settings_map_cleanly(): void {
		$mapped   = LegacySettings::map( self::neonat() );
		$settings = $mapped['settings'];

		$this->assertSame( [ 'processing', 'on-hold', 'completed', 'refunded' ], $settings['general']['trigger_statuses'] );
		$this->assertSame( [ 'new_order', 'customer_completed_order', 'customer_invoice' ], $settings['general']['email_attachments'] );
		$this->assertSame( '{year}{month}{number:6}', $settings['numbering']['format'] );
		$this->assertTrue( $settings['numbering']['reset_enabled'] );
		$this->assertSame( 'yearly', $settings['numbering']['reset_period'] );
		$this->assertSame( 'inter', $settings['design']['font_body'] );
		$this->assertSame( 'inter', $settings['design']['font_heading'] );

		// Legacy French defaults become empty, so the translated default applies.
		$this->assertSame( '', $settings['content']['col_name_label'] );
		$this->assertSame( '', $settings['content']['col_sku_label'] );
		$this->assertSame( '', $settings['content']['col_quantity_label'] );
		$this->assertSame( '', $settings['content']['invoice_title'] );
		$this->assertSame( '', $settings['content']['billing_title'] );
		$this->assertSame( '', $settings['content']['shipping_title'] );
		$this->assertSame( '', $settings['content']['customer_note_label'] );

		// The message is a free text without a translated fallback: kept as is.
		$this->assertSame( 'Merci pour votre commande', $settings['content']['message_title'] );
		$this->assertSame( 'Nous vous remercions pour votre confiance.', $settings['content']['message_body'] );

		$this->assertStringNotContainsString( "\r", $settings['company']['address'] );
		$this->assertSame( "1 rue Exemple\n00000 VILLE", $settings['company']['address'] );
		$this->assertSame( 11997, $settings['company']['logo_id'] );
		$this->assertSame( 42, $settings['content']['col_name_width'] );

		// Removed keys at their default value are dropped silently.
		$this->assertArrayNotHasKey( 'retention_days', $settings['general'] );
		$this->assertArrayNotHasKey( 'legal_mentions', $settings['content'] );
		$this->assertArrayNotHasKey( 'template', $settings['design'] );
		$this->assertSame( [], $mapped['notes'] );

		$sanitized = Schema::sanitize( $settings, Schema::defaults() );
		$this->assertSame( [], $sanitized['errors'] );
		$this->assertSame( '{year}{month}{number:6}', $sanitized['settings']['numbering']['format'] );
		$this->assertSame( [ 'processing', 'on-hold', 'completed', 'refunded' ], $sanitized['settings']['general']['trigger_statuses'] );
		$this->assertSame( [ 'new_order', 'customer_completed_order', 'customer_invoice' ], $sanitized['settings']['general']['email_attachments'] );
		$this->assertSame( "1 rue Exemple\n00000 VILLE", $sanitized['settings']['company']['address'] );
	}

	public function test_removed_keys_with_values_are_reported(): void {
		$legacy                                      = self::neonat();
		$legacy['general']['retention_days']         = 30;
		$legacy['design']['custom_css']              = '.x { color: red; }';
		$legacy['design']['template']                = 'modern';
		$legacy['content']['legal_mentions']         = [
			[
				'enabled'   => true,
				'title'     => 'A',
				'body'      => 'First',
				'placement' => 'footer',
			],
			[
				'enabled'   => false,
				'title'     => 'B',
				'body'      => 'Off',
				'placement' => 'footer',
			],
			[
				'enabled'   => true,
				'title'     => 'C',
				'body'      => 'Second',
				'placement' => 'totals',
			],
		];
		$legacy['content']['total_refunded_enabled'] = false;

		$mapped = LegacySettings::map( $legacy );

		$this->assertArrayNotHasKey( 'custom_css', $mapped['settings']['design'] );
		$this->assertArrayNotHasKey( 'legal_mentions', $mapped['settings']['content'] );
		$this->assertContains( 'content.legal_mentions had 2 entries: re-enter them in "Legal text".', $mapped['notes'] );
		$this->assertContains( 'design.custom_css was set and is no longer supported: it was not imported.', $mapped['notes'] );
		$this->assertContains( 'design.template was set and is no longer supported: it was not imported.', $mapped['notes'] );
		$this->assertContains( 'general.retention_days was set and is no longer supported: it was not imported.', $mapped['notes'] );
		$this->assertContains( 'content.total_refunded_enabled was set and is no longer supported: it was not imported.', $mapped['notes'] );
		$this->assertCount( 5, $mapped['notes'] );
	}

	public function test_secondary_color_is_dropped_with_a_note_only_when_customized(): void {
		$legacy                              = self::neonat();
		$legacy['design']['color_secondary'] = '#6B6B6B';
		$this->assertSame( [], LegacySettings::map( $legacy )['notes'], 'The default, whatever its case.' );

		$legacy['design']['color_secondary'] = '#ff0000';
		$mapped                              = LegacySettings::map( $legacy );
		$this->assertArrayNotHasKey( 'color_secondary', $mapped['settings']['design'] );
		$this->assertSame( [ 'design.color_secondary was set and is no longer supported: it was not imported.' ], $mapped['notes'] );
	}

	public function test_unknown_font_falls_back_with_note(): void {
		$legacy                           = self::neonat();
		$legacy['design']['font_heading'] = 'PlayfairDisplay';
		$legacy['design']['font_body']    = 'Inter';

		$mapped = LegacySettings::map( $legacy );

		$this->assertSame( 'inter', $mapped['settings']['design']['font_heading'] );
		$this->assertSame( 'inter', $mapped['settings']['design']['font_body'] );
		$this->assertSame( [ 'design.font_heading "PlayfairDisplay" is not available: replaced with Inter.' ], $mapped['notes'] );
	}

	public function test_custom_label_is_kept(): void {
		$legacy                                = self::neonat();
		$legacy['content']['col_name_label']   = 'Article';
		$legacy['content']['invoice_title']    = 'Facture acquittee';
		$legacy['content']['billing_title']    = "  Facturer \u{00e0} :  ";
		$legacy['design']['color_primary']     = '#AABBCC';
		$legacy['general']['trigger_statuses'] = 'completed';

		$settings = LegacySettings::map( $legacy )['settings'];

		$this->assertSame( 'Article', $settings['content']['col_name_label'] );
		$this->assertSame( 'Facture acquittee', $settings['content']['invoice_title'] );
		// Surrounding spaces do not make a default a customization.
		$this->assertSame( '', $settings['content']['billing_title'] );
		$this->assertSame( '#aabbcc', $settings['design']['color_primary'] );
		$this->assertSame( [], $settings['general']['trigger_statuses'] );
	}

	public function test_missing_groups_and_garbage_are_ignored(): void {
		$mapped = LegacySettings::map(
			[
				'numbering' => 'nope',
				'preview'   => [ 'x' => 1 ],
				'company'   => [ 'display_name' => 'Shop' ],
			]
		);

		$this->assertSame( [ 'company' => [ 'display_name' => 'Shop' ] ], $mapped['settings'] );
		$this->assertSame( [], Schema::sanitize( $mapped['settings'], Schema::defaults() )['errors'] );
	}

	/**
	 * The production `fpl_settings` of the Neonat shop, identity replaced by placeholders.
	 *
	 * @return array<string, mixed>
	 */
	public static function neonat(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$data = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/legacy-settings-neonat.json' ), true );

		return is_array( $data ) ? $data : [];
	}
}
