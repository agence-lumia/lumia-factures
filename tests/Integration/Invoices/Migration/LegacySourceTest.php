<?php
/**
 * Reading of the legacy counters: values that are not plain counters are ignored.
 *
 * @package Lumia\Factures
 */

declare( strict_types=1 );

namespace Lumia\Factures\Tests\Integration\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Modules\Invoices\Migration\LegacySource;
use Lumia\Factures\Tests\Support\LegacyFixture;
use Lumia\Factures\Tests\Support\OrderFactory;
use Lumia\Factures\Tests\Support\TestCase;

/**
 * A negative or overflowing legacy value must never reach the new counters.
 */
class LegacySourceTest extends TestCase {

	private LegacyFixture $legacy;

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		$this->legacy = new LegacyFixture();
	}

	public function tear_down(): void {
		$this->legacy->cleanup();
		parent::tear_down();
	}

	public function test_max_sequences_ignores_negative_and_overflowing_values(): void {
		$order = static fn() => OrderFactory::order( [ [ OrderFactory::simple_product( 10.0 ) ] ] );

		// Valid and invalid values of one key: only the valid ones count.
		$this->legacy->invoice( $order(), '202610000012', 12, 'invoice_2026', time(), null );
		$this->legacy->invoice( $order(), '202610000013', -5, 'invoice_2026', time(), null );
		$this->legacy->invoice( $order(), '202610000014', 0, 'invoice_2026', time(), null );
		$overflow = $order();
		$this->legacy->invoice( $overflow, '202610000015', 1, 'invoice_2026', time(), null );
		$overflow->update_meta_data( '_fpl_invoice_sequence', '99999999999999999999' );
		$overflow->save();

		// A key with invalid values only gets no counter.
		$this->legacy->invoice( $order(), '202610000016', -1, 'invoice_2025', time(), null );
		$text = $order();
		$this->legacy->invoice( $text, '202610000017', 1, 'invoice_2024', time(), null );
		$text->update_meta_data( '_fpl_invoice_sequence', '12abc' );
		$text->save();

		$this->assertSame( [ 'invoice_2026' => 12 ], LegacySource::max_sequences() );
	}

	public function test_sequences_ignores_negative_and_overflowing_values(): void {
		global $wpdb;

		$table = LegacyFixture::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture: a table that accepts what the legacy one should never hold.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$wpdb->query( "CREATE TABLE {$table} ( sequence_key VARCHAR(64) NOT NULL, current_value VARCHAR(40) NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (sequence_key) )" );
		foreach ( [
			'invoice_2026' => '160',
			'negative'     => '-5',
			'overflow'     => '99999999999999999999',
			'text'         => '12abc',
			'empty'        => '',
		] as $key => $value ) {
			$wpdb->insert(
				$table,
				[
					'sequence_key'  => $key,
					'current_value' => $value,
					'updated_at'    => '2026-10-19 08:00:00',
				]
			);
		}
		// phpcs:enable

		$this->assertSame( [ 'invoice_2026' => 160 ], LegacySource::sequences() );
	}
}
