<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Tests\Support\Shop;

/**
 * The bytes of the published file: encoding, quoting, and CSV or XML.
 */
final class FileFormatTest extends PublicationTestCase {

	public function test_the_default_csv_is_utf8_without_bom_semicolon_separated_with_crlf_and_decimal_comma(): void {
		Shop::simple(
			array(
				'name'    => 'Čokolada',
				'sku'     => 'HR-COKO',
				'regular' => '1000.00',
			)
		);

		$bytes = file_get_contents( $this->publish_at( '2026-10-01 05:00' )->path() );

		$this->assertStringStartsWith( 'naziv;šifra;marka;', $bytes );
		$this->assertStringEndsWith( "\r\nČokolada;\"HR-COKO\";;;;1250,00;NE;;1250,00;10.09.2026;;dostupno\r\n", $bytes );
	}

	public function test_formulas_are_neutralised_and_quotes_and_separators_survive(): void {
		Shop::simple(
			array(
				'name'    => '=HYPERLINK("http://example.com","klik")',
				'sku'     => 'HR-INJECT',
				'regular' => '1.00',
			)
		);
		Shop::simple(
			array(
				'name'    => 'Sir "Paški"; 1 kg, zreli',
				'sku'     => '@SIR',
				'regular' => '1.00',
			)
		);

		$rows = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) );

		$this->assertSame( '\'=HYPERLINK("http://example.com","klik")', $rows['HR-INJECT']['naziv'] );
		$this->assertSame( 'Sir "Paški"; 1 kg, zreli', $rows['@SIR']['naziv'] );
	}

	public function test_codes_are_published_unchanged_unless_they_would_run_as_a_formula(): void {
		Shop::simple(
			array(
				'name'    => 'A',
				'sku'     => '+385-1',
				'regular' => '1.00',
			)
		);
		Shop::simple(
			array(
				'name'    => 'B',
				'sku'     => '=1+1',
				'regular' => '1.00',
			)
		);

		$rows = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) );

		$this->assertSame( array( '+385-1', "'=1+1" ), array_keys( $rows ) );
	}

	public function test_line_endings_are_configurable(): void {
		cjenik()->settings()->update( array( 'csv_eol' => 'lf' ) );

		$bytes = file_get_contents( $this->publish_at( '2026-10-01 05:00' )->path() );

		$this->assertStringEndsWith( "dostupnost\n", $bytes );
	}

	public function test_barcodes_are_quoted_text_that_keeps_leading_zeros(): void {
		Shop::simple(
			array(
				'name'    => 'Uvozni sok',
				'sku'     => '000123',
				'gtin'    => '0012345678905',
				'regular' => '1.00',
			)
		);

		$publication = $this->publish_at( '2026-10-01 05:00' );

		$this->assertStringContainsString( ';"000123";', file_get_contents( $publication->path() ) );
		$this->assertStringContainsString( ';"0012345678905";', file_get_contents( $publication->path() ) );
		$this->assertSame( '0012345678905', $this->rows_by_code( $publication )['000123']['barkod'] );
	}

	public function test_delimiter_decimal_separator_and_bom_are_configurable(): void {
		Shop::simple(
			array(
				'name'    => 'Sok, jabuka',
				'sku'     => 'HR-SOK',
				'regular' => '1.00',
			)
		);
		cjenik()->settings()->update(
			array(
				'csv_delimiter' => ',',
				'csv_decimal'   => '.',
				'csv_bom'       => true,
			)
		);

		$bytes = file_get_contents( $this->publish_at( '2026-10-01 05:00' )->path() );

		$this->assertStringStartsWith( "\xEF\xBB\xBFnaziv,šifra,", $bytes );
		$this->assertStringContainsString( "\r\n\"Sok, jabuka\",\"HR-SOK\",,,,1.25,NE,,1.25,10.09.2026,,dostupno\r\n", $bytes );
	}

	public function test_columns_can_be_renamed_reordered_and_extended(): void {
		Shop::simple(
			array(
				'name'     => 'Sok',
				'sku'      => 'HR-SOK',
				'regular'  => '1.00',
				'category' => 'Pića',
			)
		);
		cjenik()->settings()->update(
			array(
				'columns' => array(
					array(
						'key'   => 'sifra',
						'label' => 'ŠIFRA PROIZVODA',
					),
					array(
						'key'   => 'naziv',
						'label' => 'NAZIV PROIZVODA',
					),
					array(
						'key'   => 'maloprodajna_cijena',
						'label' => '',
					),
					array(
						'key'   => 'redovna_cijena',
						'label' => '',
					),
					array(
						'key'   => 'kategorija',
						'label' => 'KATEGORIJA',
					),
				),
			)
		);

		$rows = $this->csv( $this->publish_at( '2026-10-01 05:00' ) );

		$this->assertSame(
			array(
				array( 'ŠIFRA PROIZVODA', 'NAZIV PROIZVODA', 'maloprodajna cijena', 'redovna cijena', 'KATEGORIJA' ),
				array( 'HR-SOK', 'Sok', '1,25', '1,25', 'Pića' ),
			),
			$rows
		);
	}

	public function test_xml_has_the_same_rows_with_a_decimal_point(): void {
		Shop::simple(
			array(
				'name'    => 'Sok & "voda"',
				'sku'     => 'HR-SOK',
				'gtin'    => '0012345678905',
				'regular' => '1.00',
				'brand'   => 'Jamnica',
			)
		);
		cjenik()->settings()->update(
			array(
				'format'         => 'xml',
				'outlet_address' => 'Ilica 150 Zagreb',
			)
		);

		$publication = $this->publish_at( '2026-10-01 05:00' );
		$xml         = simplexml_load_file( $publication->path() );

		$this->assertStringEndsWith( '.xml', $publication->file_name );
		$this->assertSame( 'Ilica 150 Zagreb', (string) $xml['adresa'] );
		$this->assertSame(
			array(
				'naziv'                         => 'Sok & "voda"',
				'sifra'                         => 'HR-SOK',
				'marka'                         => 'Jamnica',
				'jedinica_mjere'                => '',
				'cijena_za_jedinicu_mjere'      => '',
				'maloprodajna_cijena'           => '1.25',
				'poseban_oblik_prodaje'         => 'NE',
				'naziv_posebnog_oblika_prodaje' => '',
				'sidrena_cijena'                => '1.25',
				'sidreni_datum'                 => '10.09.2026',
				'barkod'                        => '0012345678905',
				'dostupnost'                    => 'dostupno',
			),
			array_map( 'strval', (array) $xml->proizvod[0] )
		);
	}

	public function test_unit_prices_come_from_the_net_quantity(): void {
		cjenik()->settings()->update( array( 'unit_source' => 'plugin' ) );
		Shop::simple(
			array(
				'name'    => 'Kava 250 g',
				'sku'     => 'HR-KAVA',
				'regular' => '4.00',
				'meta'    => array(
					'_cjenik_unit'          => 'kg',
					'_cjenik_unit_quantity' => '0,25',
				),
			)
		);

		$row = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-KAVA'];

		$this->assertSame( array( 'kg', '20,00', '5,00' ), array( $row['jedinica mjere'], $row['cijena za jedinicu mjere'], $row['maloprodajna cijena'] ) );
	}
}
