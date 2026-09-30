<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Tests\Support\Shop;

/**
 * Retail prices in the file include Croatian VAT, even with no customer.
 */
final class VatPublicationTest extends PublicationTestCase {

	/**
	 * @dataProvider tax_bases
	 */
	public function test_prices_entered_without_vat_get_the_croatian_rate_of_their_tax_class( string $tax_based_on ): void {
		update_option( 'woocommerce_tax_based_on', $tax_based_on );
		Shop::simple(
			array(
				'name'    => 'Vegeta',
				'sku'     => 'STANDARD',
				'regular' => '4.72',
			)
		);
		Shop::simple(
			array(
				'name'      => 'Mlijeko',
				'sku'       => 'REDUCED',
				'regular'   => '1.00',
				'tax_class' => 'reduced-rate',
			)
		);
		Shop::simple(
			array(
				'name'      => 'Knjiga',
				'sku'       => 'SUPER-REDUCED',
				'regular'   => '18.00',
				'tax_class' => 'super-reduced-rate',
			)
		);
		Shop::variable(
			array(
				'name'      => 'Pelene',
				'sku'       => 'PELENE',
				'tax_class' => 'reduced-rate',
			),
			array(
				'S' => array(
					'sku'     => 'PELENE-S',
					'regular' => '10.00',
				),
			)
		);

		$rows = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) );

		$this->assertSame(
			array(
				'STANDARD'      => '5,90',
				'REDUCED'       => '1,13',
				'SUPER-REDUCED' => '18,90',
				'PELENE-S'      => '11,30',
			),
			array_map( fn( $row ) => $row['maloprodajna cijena'], $rows )
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function tax_bases(): array {
		return array(
			'shipping address' => array( 'shipping' ),
			'billing address'  => array( 'billing' ),
			'shop base'        => array( 'base' ),
		);
	}

	public function test_prices_entered_with_vat_are_published_as_entered(): void {
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		Shop::simple(
			array(
				'name'    => 'Vegeta',
				'sku'     => 'STANDARD',
				'regular' => '5.90',
			)
		);

		$rows = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) );

		$this->assertSame( '5,90', $rows['STANDARD']['maloprodajna cijena'] );
	}

	public function test_a_shop_that_does_not_charge_vat_publishes_prices_as_entered(): void {
		update_option( 'woocommerce_calc_taxes', 'no' );
		Shop::simple(
			array(
				'name'    => 'Vegeta',
				'sku'     => 'STANDARD',
				'regular' => '4.72',
			)
		);

		$rows = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) );

		$this->assertSame( '4,72', $rows['STANDARD']['maloprodajna cijena'] );
	}
}
