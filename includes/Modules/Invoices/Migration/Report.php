<?php
/**
 * Outcome of a legacy import run.
 *
 * @package Lumia\Factures
 */

namespace Lumia\Factures\Modules\Invoices\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Counters filled by Importer::run(). A dry run fills the same counters with what a real run would do.
 */
final class Report {

	/**
	 * Settings outcome: `imported`, `skipped_existing` (the user's settings are kept) or `none` (no legacy settings).
	 *
	 * @var string
	 */
	public string $settings = 'none';

	/**
	 * Legacy invoices recorded in the register by this run.
	 *
	 * @var int
	 */
	public int $invoices_imported = 0;

	/**
	 * Legacy invoices already in the register with the same number (an earlier run).
	 *
	 * @var int
	 */
	public int $invoices_already = 0;

	/**
	 * Legacy PDFs copied into the archive.
	 *
	 * @var int
	 */
	public int $files_copied = 0;

	/**
	 * Imported invoices whose legacy PDF is gone (retention): built on first need.
	 *
	 * @var int
	 */
	public int $files_missing = 0;

	/**
	 * Imported invoices whose legacy PDF was refused (not a PDF, outside the legacy folder, unreadable).
	 *
	 * @var int
	 */
	public int $files_invalid = 0;

	/**
	 * Skipped legacy invoices.
	 *
	 * @var array<int, array{order_id: int, number: string, code: string, reason: string}>
	 */
	public array $conflicts = [];

	/**
	 * Counters raised, keyed by sequence key.
	 *
	 * @var array<string, int>
	 */
	public array $sequences = [];

	/**
	 * Things the user should know (dropped settings, fallback dates, refused files).
	 *
	 * @var string[]
	 */
	public array $notes = [];

	/**
	 * Records a skipped legacy invoice.
	 *
	 * @param int    $order_id Order id.
	 * @param string $number   Legacy number.
	 * @param string $code     `other_invoice`, `number_used` or `database_error`.
	 * @param string $reason   Why it was skipped (translated).
	 */
	public function conflict( int $order_id, string $number, string $code, string $reason ): void {
		$this->conflicts[] = [
			'order_id' => $order_id,
			'number'   => $number,
			'code'     => $code,
			'reason'   => $reason,
		];
	}

	/**
	 * Whether a legacy invoice was skipped.
	 */
	public function has_conflicts(): bool {
		return [] !== $this->conflicts;
	}

	/**
	 * Plain array, for the stored state and the WP-CLI output.
	 *
	 * @return array{settings: string, invoices_imported: int, invoices_already: int, files_copied: int, files_missing: int, files_invalid: int, conflicts: array<int, array{order_id: int, number: string, code: string, reason: string}>, sequences: array<string, int>, notes: string[]}
	 */
	public function to_array(): array {
		return [
			'settings'          => $this->settings,
			'invoices_imported' => $this->invoices_imported,
			'invoices_already'  => $this->invoices_already,
			'files_copied'      => $this->files_copied,
			'files_missing'     => $this->files_missing,
			'files_invalid'     => $this->files_invalid,
			'conflicts'         => $this->conflicts,
			'sequences'         => $this->sequences,
			'notes'             => $this->notes,
		];
	}
}
