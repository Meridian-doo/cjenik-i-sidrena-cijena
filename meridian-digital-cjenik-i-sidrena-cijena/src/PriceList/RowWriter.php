<?php
/**
 * @package Cjenik
 */

namespace Cjenik\PriceList;

/**
 * Serializes Price List rows to a file, one row at a time.
 */
interface RowWriter {
	public function extension(): string;

	/**
	 * @param array<string, array{label: string, type: string}> $columns Key => header and type, in file order.
	 * @param array<string, string>                             $about   Facts about the list (Outlet, Storage Number, time).
	 */
	public function open( string $path, array $columns, array $about ): void;

	/**
	 * @param array<string, string|float|null> $row Values by column key.
	 */
	public function write( array $row ): void;

	public function close(): void;
}
