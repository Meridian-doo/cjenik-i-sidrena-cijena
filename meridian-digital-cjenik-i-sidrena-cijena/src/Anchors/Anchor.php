<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Anchors;

/**
 * An item's Anchor Price (sidrena cijena, VAT included) and Anchor Date.
 */
final class Anchor {
	public const SOURCE_AUTO     = 'auto';
	public const SOURCE_IMPORTED = 'imported';
	public const SOURCE_MANUAL   = 'manual';

	/**
	 * @param ?string $date      Y-m-d.
	 * @param bool    $confirmed False when the plugin had to fall back to the
	 *                           earliest price it knows, because its history
	 *                           doesn't reach the reference date.
	 */
	public function __construct(
		public readonly ?float $price,
		public readonly ?string $date,
		public readonly string $source,
		public readonly bool $confirmed,
	) {}

	public function has_price(): bool {
		return null !== $this->price && null !== $this->date;
	}
}
