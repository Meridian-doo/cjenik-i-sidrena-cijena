<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Tests\Support\Shop;

/**
 * Anchors and past prices imported from CSV, as they end up in the Price List.
 */
final class ImportPublicationTest extends PublicationTestCase {

	public function test_anchors_are_imported_by_sku_or_barcode(): void {
		Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '2.00',
			)
		);
		Shop::simple(
			array(
				'name'    => 'Voda',
				'sku'     => 'HR-VODA',
				'gtin'    => '3850000000011',
				'regular' => '1.00',
			)
		);

		$result = cjenik()->importer()->import_anchors(
			$this->file( "šifra;barkod;sidrena cijena;sidreni datum\r\nHR-SOK;;2,49;10.9.2026.\r\n;3850000000011;1,19;02.05.2025\r\nHR-NEMA;;1,00;10.9.2026.\r\n" )
		);
		$rows   = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) );

		$this->assertSame( 2, $result->imported );
		$this->assertSame( array( 4 ), array_keys( $result->errors ) );
		$this->assertSame( array( '2,49', '10.09.2026' ), array( $rows['HR-SOK']['sidrena cijena'], $rows['HR-SOK']['sidreni datum'] ) );
		$this->assertSame( array( '1,19', '02.05.2025' ), array( $rows['HR-VODA']['sidrena cijena'], $rows['HR-VODA']['sidreni datum'] ) );
	}

	public function test_a_hok_template_takes_the_anchor_date_from_its_header(): void {
		Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '2.00',
			)
		);

		cjenik()->importer()->import_anchors(
			$this->file( "Naziv proizvoda,Šifra,Marka,Maloprodajna cijena,Cijena 10.9.2026.,Barkod (ako ima)\nSok,HR-SOK,,\"2,50\",\"2,39\",\n" )
		);
		$row = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-SOK'];

		$this->assertSame( array( '2,39', '10.09.2026' ), array( $row['sidrena cijena'], $row['sidreni datum'] ) );
	}

	public function test_imported_past_prices_count_towards_the_lowest_30_day_price(): void {
		cjenik()->settings()->update(
			array(
				'columns' => array(
					array(
						'key'   => 'sifra',
						'label' => 'šifra',
					),
					array(
						'key'   => 'najniza_cijena_30_dana',
						'label' => 'najniža',
					),
				),
			)
		);
		$this->clock->set( '2026-09-29 09:00' ); // Installed after the prices below.
		$product = Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '2.00',
				'created' => '2025-01-01 09:00',
			)
		);
		cjenik()->history()->forget( $product->get_id() );
		cjenik()->history()->snapshot();

		$result = cjenik()->importer()->import_history(
			$this->file( "šifra;cijena;od;do\nHR-SOK;2,50;01.08.2026;15.09.2026\nHR-SOK;1,99;15.09.2026 10:00;15.09.2026 18:00\nHR-SOK;2,50;15.09.2026 18:00;\n" )
		);
		$this->change_at( '2026-10-01 00:00', $product, '1.60' );
		$row = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-SOK'];

		$this->assertSame( 3, $result->imported );
		$this->assertSame( '1,99', $row['najniža'] );
	}

	public function test_imported_prices_before_10_september_set_the_anchor(): void {
		$this->clock->set( '2026-09-29 09:00' );
		$product = Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '2.00',
				'created' => '2025-01-01 09:00',
			)
		);
		cjenik()->history()->forget( $product->get_id() );
		cjenik()->history()->snapshot();

		cjenik()->importer()->import_history( $this->file( "sku;cijena;redovna cijena;od\nHR-SOK;1,50;2,20;01.09.2026\n" ) );
		$publication = $this->publish_at( '2026-10-01 05:00' );
		$row         = $this->rows_by_code( $publication )['HR-SOK'];

		$this->assertSame( array( '2,20', '10.09.2026' ), array( $row['sidrena cijena'], $row['sidreni datum'] ) );
		$this->assertArrayNotHasKey( 'unconfirmed_anchor', $publication->warnings );
	}

	public function test_importing_the_same_past_prices_twice_changes_nothing(): void {
		$this->enable_lowest_column();
		$this->clock->set( '2026-09-29 09:00' );
		$product = Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '2.00',
				'created' => '2025-01-01 09:00',
			)
		);
		cjenik()->history()->forget( $product->get_id() );
		cjenik()->history()->snapshot();
		$csv = "šifra;cijena;od;do\nHR-SOK;1,99;15.09.2026;16.09.2026\n";
		cjenik()->importer()->import_history( $this->file( $csv ) );
		cjenik()->importer()->import_history( $this->file( str_replace( '1,99', '2,10', $csv ) ) );
		$this->change_at( '2026-10-01 00:00', $product, '1.60' );

		$row = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-SOK'];

		$this->assertSame( '2,10', $row['najniža'] );
	}

	public function test_a_price_that_ended_before_10_september_is_not_the_anchor(): void {
		$this->clock->set( '2026-09-29 09:00' );
		$product = Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '2.00',
				'created' => '2025-01-01 09:00',
			)
		);
		cjenik()->history()->forget( $product->get_id() );
		cjenik()->history()->snapshot();

		cjenik()->importer()->import_history( $this->file( "šifra;cijena;od;do\nHR-SOK;1,50;01.09.2026;05.09.2026\n" ) );
		$publication = $this->publish_at( '2026-10-01 05:00' );

		$row = $this->rows_by_code( $publication )['HR-SOK'];
		$this->assertSame( array( '2,50', '29.09.2026' ), array( $row['sidrena cijena'], $row['sidreni datum'] ) );
		$this->assertArrayHasKey( 'unconfirmed_anchor', $publication->warnings );
	}

	private function enable_lowest_column(): void {
		cjenik()->settings()->update(
			array(
				'columns' => array(
					array(
						'key'   => 'sifra',
						'label' => 'šifra',
					),
					array(
						'key'   => 'najniza_cijena_30_dana',
						'label' => 'najniža',
					),
					array(
						'key'   => 'sidreni_datum',
						'label' => 'sidreni datum',
					),
				),
			)
		);
	}

	private function file( string $contents ): string {
		$path = wp_tempnam( 'cjenik-import.csv' );
		file_put_contents( $path, $contents );
		return $path;
	}

	private function change_at( string $zagreb_time, \WC_Product $product, string $sale ): void {
		$this->clock->set( $zagreb_time );
		$product = wc_get_product( $product->get_id() );
		$product->set_sale_price( $sale );
		$product->save();
	}
}
