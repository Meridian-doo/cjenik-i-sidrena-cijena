<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Publishing;

use Cjenik\Zagreb;
use DateTimeImmutable;

/**
 * One Publication Log entry: a Price List file published (or a failed attempt)
 * with its Storage Number, time, row count and SHA-256.
 */
final class Publication {
	public const SUCCESS = 'success';
	public const FAILED  = 'failed';

	/**
	 * @param array<string, array{count: int, ids: list<int>}> $warnings
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $outlet_id,
		public readonly ?int $storage_number,
		public readonly DateTimeImmutable $published_at,
		public readonly string $status,
		public readonly string $file_name,
		public readonly string $format,
		public readonly int $row_count,
		public readonly string $sha256,
		public readonly array $warnings,
		public readonly string $error,
		public readonly ?DateTimeImmutable $deleted_at,
	) {}

	public static function from_row( object $row ): self {
		$warnings = json_decode( (string) $row->warnings, true );
		return new self(
			(int) $row->id,
			(string) $row->outlet_id,
			null === $row->storage_number ? null : (int) $row->storage_number,
			Zagreb::from_db( $row->published_at ),
			(string) $row->status,
			(string) $row->file_name,
			(string) $row->format,
			(int) $row->row_count,
			(string) $row->sha256,
			is_array( $warnings ) ? $warnings : array(),
			(string) $row->error,
			null === $row->deleted_at ? null : Zagreb::from_db( $row->deleted_at ),
		);
	}

	public function succeeded(): bool {
		return self::SUCCESS === $this->status;
	}

	/** Whether the file is still published (not removed by retention). */
	public function is_available(): bool {
		return $this->succeeded() && null === $this->deleted_at;
	}

	public function path(): string {
		return Storage::dir() . '/' . $this->file_name;
	}

	/** The file's direct public URL, with the legal file name URL-encoded. */
	public function url(): string {
		return Storage::url() . '/' . rawurlencode( $this->file_name );
	}

	public function mime_type(): string {
		return 'xml' === $this->format ? 'application/xml' : 'text/csv';
	}
}
