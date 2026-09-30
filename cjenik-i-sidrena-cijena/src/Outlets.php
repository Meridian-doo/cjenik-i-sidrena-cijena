<?php
/**
 * @package Cjenik
 */

namespace Cjenik;

/**
 * The shop's Outlets: the webshop, plus any added with the `cjenik_outlets` filter.
 */
final class Outlets {
	public const WEBSHOP = 'webshop';

	public function __construct( private Settings $settings ) {}

	public function webshop(): Outlet {
		return new Outlet(
			self::WEBSHOP,
			(string) $this->settings->get( 'outlet_type' ),
			(string) $this->settings->get( 'outlet_address' ),
			(string) $this->settings->get( 'outlet_code' ),
		);
	}

	/**
	 * Every Outlet that publishes a Price List.
	 *
	 * @return array<string, Outlet> Keyed by Outlet id.
	 */
	public function all(): array {
		/**
		 * Registers extra Outlets, e.g. physical shops.
		 *
		 * @param array<string, mixed> $outlets Outlet objects keyed by Outlet id.
		 */
		$outlets = (array) apply_filters( 'cjenik_outlets', array( self::WEBSHOP => $this->webshop() ) );
		return array_filter( $outlets, static fn( $outlet ) => $outlet instanceof Outlet );
	}

	public function get( string $id ): ?Outlet {
		return $this->all()[ $id ] ?? null;
	}
}
