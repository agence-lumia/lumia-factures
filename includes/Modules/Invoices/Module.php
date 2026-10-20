<?php
/**
 * Invoices module (core).
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices;

defined( 'ABSPATH' ) || exit;

use Lumia\Factures\Core\AbstractModule;
use Lumia\Factures\Core\Requirements;
use Lumia\Factures\Modules\Invoices\Admin\Counters;
use Lumia\Factures\Modules\Invoices\Admin\CreditNoteNotice;
use Lumia\Factures\Modules\Invoices\Admin\Download;
use Lumia\Factures\Modules\Invoices\Admin\MigrationNotice;
use Lumia\Factures\Modules\Invoices\Admin\OrderTools;
use Lumia\Factures\Modules\Invoices\Admin\Preview;
use Lumia\Factures\Modules\Invoices\Database\Installer;
use Lumia\Factures\Modules\Invoices\Documents\CreditNoteIssuer;
use Lumia\Factures\Modules\Invoices\Documents\Issuer;
use Lumia\Factures\Modules\Invoices\Emails\Attachments;
use Lumia\Factures\Modules\Invoices\Emails\EmailLinks;
use Lumia\Factures\Modules\Invoices\Front\CustomerDownload;
use Lumia\Factures\Modules\Invoices\Front\Shortcode;
use Lumia\Factures\Modules\Invoices\Migration\Importer;
use Lumia\Factures\Modules\Invoices\Migration\Migration;
use Lumia\Factures\Modules\Invoices\Numbering\NumberFormat;
use Lumia\Factures\Modules\Invoices\Numbering\Sequences;
use Lumia\Factures\Modules\Invoices\Settings\Schema;
use Lumia\Factures\Modules\Invoices\Settings\Upgrader;

/**
 * Core module: numbering, documents, rendering, storage and emails (lot 2).
 * Always active, it cannot be deactivated.
 */
class Module extends AbstractModule {

	/**
	 * Registers the module hooks.
	 */
	public function init(): void {
		// Called directly: init() already runs inside the `init` hook at priority 10,
		// a callback added to it now would never fire. Updating a plugin does not run
		// the activation hook, so the tables are checked on every boot. The settings first: the
		// installer records its schema version, one of the signs of an existing installation.
		Upgrader::maybe_upgrade();
		Installer::maybe_upgrade();

		// The legacy import runs by itself, never during a front-end request (a visitor would wait
		// for it), nor for an anonymous visitor: see Migration::may_auto_run(). Until it is done,
		// Issuer::can_issue() refuses to number.
		Migration::boot();

		add_action( 'woocommerce_order_status_changed', [ Issuer::class, 'on_status_changed' ], 10, 4 );
		add_filter( 'woocommerce_email_attachments', [ Attachments::class, 'attach' ], 10, 4 );
		// Links to the invoice and credit notes in the customer e-mails (priority 20: after the order details).
		add_action( 'woocommerce_email_order_meta', [ EmailLinks::class, 'render' ], 20, 4 );

		// Credit notes: issued on `woocommerce_order_refunded` only, the last step of wc_create_refund()
		// (a refund the gateway refuses is deleted before it), or once the order's invoice is issued
		// (priority 20: after the invoice's own listeners).
		CreditNoteIssuer::ensure_since();
		add_action( 'woocommerce_order_refunded', [ CreditNoteIssuer::class, 'on_refunded' ], 10, 2 );
		add_action( 'lumia_factures_invoice_issued', [ CreditNoteIssuer::class, 'on_invoice_issued' ], 20, 2 );
		add_filter( 'woocommerce_pre_delete_order_refund', [ CreditNoteIssuer::class, 'block_deletion' ], 10, 3 );
		add_action( 'woocommerce_before_delete_order', [ CreditNoteIssuer::class, 'on_order_deleting' ], 10, 2 );
		add_action( 'woocommerce_delete_order', [ CreditNoteIssuer::class, 'on_order_deleted' ], 10, 1 );
		add_action( 'woocommerce_delete_order_refund', [ CreditNoteIssuer::class, 'on_refund_deleted' ], 10, 1 );
		add_action( 'woocommerce_refund_deleted', [ CreditNoteIssuer::class, 'on_refund_deleted' ], 10, 2 );
		add_action( CreditNoteIssuer::SWEEP_HOOK, [ CreditNoteIssuer::class, 'sweep' ] );
		CreditNoteIssuer::schedule_sweep();

		CustomerDownload::register();
		Shortcode::register();

		// admin-post.php requests are is_admin() too.
		if ( is_admin() ) {
			OrderTools::register();
			Download::register();
			Counters::register();
			Preview::register();
			MigrationNotice::register();
			CreditNoteNotice::register();
			add_action( 'all_admin_notices', [ CreditNoteIssuer::class, 'render_deletion_notice' ] );
			add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_media' ] );
		}

		// The WP-CLI command is registered by Core\Plugin, whatever the requirements.
	}

	/**
	 * Loads the media library on the plugin screen only (logo picker).
	 *
	 * @param string $hook Hook suffix of the admin page.
	 */
	public static function enqueue_media( $hook ): void {
		if ( is_string( $hook ) && str_ends_with( $hook, '_page_lumia-factures' ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Script of the settings screen.
	 *
	 * @return string[]
	 */
	public function get_admin_js(): array {
		return [ LUMIA_FACTURES_ASSETS_URL . 'admin/js/invoices.js' ];
	}

	/**
	 * Stylesheet of the settings screen.
	 *
	 * @return string[]
	 */
	public function get_admin_css(): array {
		return [ LUMIA_FACTURES_ASSETS_URL . 'admin/css/invoices.css' ];
	}

	/**
	 * The logo picker needs the media editor scripts.
	 *
	 * @return string[]
	 */
	public function get_admin_js_deps(): array {
		return [ 'media-editor' ];
	}

	/**
	 * Data of the settings screen, merged into `lumiaFacturesAdmin`.
	 *
	 * The live number samples are computed in JS from these values (they mirror `NumberFormat::preview()`):
	 * today's date in the site timezone, the sample order 14243 and customer 27, the counters by key
	 * and the tags a format may use. `documents` gives, per numbered type, the group of its fields.
	 *
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		$counters = [];
		foreach ( Sequences::all() as $sequence ) {
			$counters[ $sequence['key'] ] = $sequence['value'];
		}

		$documents = [];
		foreach ( Schema::NUMBERED as $type => $group ) {
			$documents[ $type ] = [ 'group' => $group ];
		}

		return [
			'invoices' => [
				'documents' => $documents,
				'sample'    => [
					'year'        => (string) wp_date( 'Y' ),
					'month'       => (string) wp_date( 'm' ),
					'day'         => (string) wp_date( 'd' ),
					'orderNumber' => '14243',
					'orderId'     => '14243',
					'customerId'  => '27',
				],
				'counters'  => $counters,
				'tags'      => NumberFormat::known_tags(),
				// ES modules the preview loads with import() (not enqueued); the version busts the cache.
				'pdfjs'     => [
					'lib'    => LUMIA_FACTURES_ASSETS_URL . 'vendor/pdfjs/pdf.min.js?ver=' . rawurlencode( LUMIA_FACTURES_VERSION ),
					'worker' => LUMIA_FACTURES_ASSETS_URL . 'vendor/pdfjs/pdf.worker.min.js?ver=' . rawurlencode( LUMIA_FACTURES_VERSION ),
				],
			],
			'i18n'     => [
				'logoFrameTitle'  => __( 'Choose a logo', 'lumia-factures' ),
				'logoFrameButton' => __( 'Use this image', 'lumia-factures' ),
				'sampleInvalid'   => __( 'Invalid format', 'lumia-factures' ),
				'previewFailed'   => __( 'The preview could not be generated.', 'lumia-factures' ),
				'raiseTitle'      => __( 'Raise this counter?', 'lumia-factures' ),
				'raiseConfirm'    => __( 'Raise the counter', 'lumia-factures' ),
				/* translators: 1: counter key, 2: current value, 3: new value. */
				'raiseMessage'    => __( 'Counter %1$s goes from %2$s to %3$s. The numbers in between will never be used, and a counter can never be lowered.', 'lumia-factures' ),
			],
		];
	}

	/**
	 * Creates the tables on activation, then marks the Facture PDF Lumia import pending when there
	 * is legacy data. The import itself runs on the next manager admin request, cron run or
	 * `wp lumia-factures migrate` (see Migration::on_activate()).
	 *
	 * Looking for legacy data needs WooCommerce; while a requirement fails (the legacy plugin still
	 * active) the import is planned by the first request that meets them.
	 */
	public function on_activate(): void {
		// Before the installer, which records the schema version a fresh site would then seem to have.
		Upgrader::maybe_upgrade();
		Installer::install();
		CreditNoteIssuer::ensure_since();
		Migration::forget_no_data();

		if ( [] !== Requirements::check() ) {
			return;
		}

		Migration::on_activate();
	}

	/**
	 * Data owned by the module, removed on uninstall only when
	 * LUMIA_FACTURES_REMOVE_ALL_DATA is true. The storage folder is never deleted.
	 *
	 * @return array{options?: string[], meta?: string[], user_meta?: string[], post_type?: string[], taxonomy?: string[], tables?: string[], cron?: string[]}
	 */
	public static function get_uninstall_keys(): array {
		return [
			'options'   => [
				'lumia_factures_module_invoices',
				Installer::OPTION,
				Migration::OPTION,
				Migration::LOCK,
				CreditNoteIssuer::SINCE_OPTION,
				CreditNoteIssuer::SWEEP_CURSOR_OPTION,
				Upgrader::OPTION,
			],
			'meta'      => [
				'_lumia_factures_invoice_number',
				'_lumia_factures_invoice_date',
				Issuer::GATE_FLAG,
				Importer::NOTED_META,
				CreditNoteIssuer::NUMBER_META,
				CreditNoteIssuer::DATE_META,
				CreditNoteIssuer::PENDING_META,
				CreditNoteIssuer::DELETED_META,
			],
			'user_meta' => [ MigrationNotice::DISMISSED_META ],
			'tables'    => [ 'lumia_factures_sequences', 'lumia_factures_documents' ],
			// Also unscheduled on deactivation (Core\Deactivator).
			'cron'      => [ CreditNoteIssuer::SWEEP_HOOK ],
		];
	}

	/**
	 * Default settings, keyed by group.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_defaults(): array {
		return Schema::defaults();
	}

	/**
	 * Invoice settings: the stored values merged recursively over the defaults.
	 * Every other class of the module reads its settings through this helper.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		$stored = get_option( 'lumia_factures_module_invoices', [] );

		return self::merge_defaults( Schema::defaults(), is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Sanitizes and saves the settings posted by the form.
	 *
	 * An invalid number format is replaced by the current one; the errors are
	 * kept 60 seconds in a transient for the admin notice.
	 *
	 * @param array<string, mixed> $settings Raw payload (unslashed).
	 */
	public function save_settings( array $settings ): bool {
		$current = self::settings();
		$result  = Schema::sanitize( $settings, $current );

		foreach ( Schema::NUMBERED as $type => $group ) {
			self::carry_counter( $type, $current[ $group ], $result['settings'][ $group ] );
		}

		if ( [] !== $result['errors'] ) {
			set_transient( 'lumia_factures_invoices_errors', $result['errors'], MINUTE_IN_SECONDS );
		} else {
			delete_transient( 'lumia_factures_invoices_errors' );
		}

		return $this->save_module_settings( $result['settings'] );
	}

	/**
	 * Keeps a document series going when its numbering policy changes.
	 *
	 * The reset policy chooses the counter key (`invoice`, `invoice_2026`, `invoice_2026_10`; the
	 * same with `credit_note`). A new policy moves to another key that starts at the start number,
	 * behind the numbers already issued: they would collide, or the series would restart within the
	 * period. The counter the new policy uses for the current period is raised to the current value
	 * of the old one (never lowered). Each type has its own counters, so only its own move.
	 *
	 * @param string               $type   Document type, a key of Schema::NUMBERED.
	 * @param array<string, mixed> $before Numbering policy of the type before the save.
	 * @param array<string, mixed> $after  Numbering policy of the type about to be saved.
	 */
	private static function carry_counter( string $type, array $before, array $after ): void {
		$changed = false;
		foreach ( [ 'format', 'reset_enabled', 'reset_period' ] as $field ) {
			if ( ( $before[ $field ] ?? null ) !== ( $after[ $field ] ?? null ) ) {
				$changed = true;
			}
		}

		if ( ! $changed ) {
			return;
		}

		$now     = time();
		$old_key = Sequences::key( $type, $now, $before );
		$new_key = Sequences::key( $type, $now, $after );
		$value   = Sequences::current( $old_key );

		if ( $old_key === $new_key || $value <= 0 ) {
			return;
		}

		if ( Sequences::raise( $new_key, $value ) ) {
			error_log( '[lumia-factures] Numbering policy changed: counter ' . $new_key . ' raised to ' . $value . ', the current value of ' . $old_key . ', so the series goes on.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational log, the project convention.
		}
	}
}
