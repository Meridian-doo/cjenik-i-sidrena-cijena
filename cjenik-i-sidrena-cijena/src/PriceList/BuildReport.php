<?php
/**
 * @package Cjenik
 */

namespace Cjenik\PriceList;

/**
 * What happened while building a Price List: how many rows, and which items
 * were skipped or filled with defaults.
 */
final class BuildReport {
	/** Item had no price: left out. */
	public const NO_PRICE = 'no_price';
	/** Item had no anchor: its current regular price and today's date were used. */
	public const NO_ANCHOR = 'no_anchor';
	/** Anchor is the earliest recorded price, not the one on the reference date. */
	public const UNCONFIRMED_ANCHOR = 'unconfirmed_anchor';
	/** Item had no barcode: the column is empty. */
	public const NO_BARCODE = 'no_barcode';
	/** Item had no SKU: its ID was used as šifra. */
	public const NO_SKU = 'no_sku';

	private const MAX_IDS = 50;

	public int $rows = 0;

	/** @var array<string, array{count: int, ids: list<int>}> */
	private array $warnings = array();

	public function warn( string $kind, int $item_id ): void {
		$this->warnings[ $kind ] ??= array(
			'count' => 0,
			'ids'   => array(),
		);
		++$this->warnings[ $kind ]['count'];
		if ( count( $this->warnings[ $kind ]['ids'] ) < self::MAX_IDS ) {
			$this->warnings[ $kind ]['ids'][] = $item_id;
		}
	}

	/**
	 * @return array<string, array{count: int, ids: list<int>}>
	 */
	public function warnings(): array {
		return $this->warnings;
	}
}
