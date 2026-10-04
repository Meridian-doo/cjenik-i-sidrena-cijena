<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Prices;

use Cjenik\Zagreb;
use DateTimeImmutable;

/**
 * One Price History row: the item's prices during [valid_from, valid_to).
 * An open row (no valid_to) is the item's current state.
 */
final class Observation {
	public function __construct(
		public readonly int $item_id,
		public readonly float $price,
		public readonly float $regular_price,
		public readonly bool $is_sale,
		public readonly string $sale_name,
		public readonly string $source,
		public readonly DateTimeImmutable $valid_from,
		public readonly ?DateTimeImmutable $valid_to,
	) {}

	public static function from_row( object $row ): self {
		return new self(
			(int) $row->item_id,
			(float) $row->price,
			(float) $row->regular_price,
			(bool) $row->is_sale,
			(string) $row->sale_name,
			(string) $row->source,
			Zagreb::from_db( $row->valid_from ),
			null === $row->valid_to ? null : Zagreb::from_db( $row->valid_to ),
		);
	}

	public function matches( ResolvedPrice $state ): bool {
		return $state->price === $this->price
			&& $state->regular_price === $this->regular_price
			&& $state->is_sale === $this->is_sale
			&& $state->sale_name === $this->sale_name;
	}
}
