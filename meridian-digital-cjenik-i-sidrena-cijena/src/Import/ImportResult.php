<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Import;

/**
 * How many CSV rows an import took, and what was wrong with the others.
 */
final class ImportResult {
	public int $imported = 0;

	/** @var array<int, string> Line number => what was wrong. */
	public array $errors = array();

	public function error( int $line, string $message ): void {
		$this->errors[ $line ] = $message;
	}
}
