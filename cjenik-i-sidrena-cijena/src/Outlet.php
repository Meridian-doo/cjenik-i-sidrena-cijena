<?php
/**
 * @package Cjenik
 */

namespace Cjenik;

/**
 * A prodajni objekt (Outlet) that publishes its own Price List. The free plugin
 * has one: the webshop. Pro registers more through the `cjenik_outlets` filter.
 */
final class Outlet {
	/**
	 * @param string $id      Stable identifier, used in URLs (e.g. "webshop").
	 * @param string $type    Oblik prodajnog objekta, the first part of the file name.
	 * @param string $address Adresa prodajnog objekta.
	 * @param string $code    Oznaka prodajnog objekta (e.g. "P-01").
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $type,
		public readonly string $address,
		public readonly string $code,
	) {}

	/**
	 * The Outlet as the published files describe it.
	 *
	 * @return array{oblik: string, adresa: string, oznaka: string}
	 */
	public function describe(): array {
		return array(
			'oblik'  => $this->type,
			'adresa' => $this->address,
			'oznaka' => $this->code,
		);
	}
}
