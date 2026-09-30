<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Prices;

/**
 * The legally relevant prices of one item at one moment. Amounts include VAT
 * and are rounded to cents.
 */
final class ResolvedPrice {
	public function __construct(
		public readonly ?float $price,
		public readonly ?float $regular_price,
		public readonly bool $is_sale,
		public readonly string $sale_name,
	) {}

	public function has_price(): bool {
		return null !== $this->price;
	}
}
