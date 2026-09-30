<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Tests\Support\Shop;

final class PublicationTest extends PublicationTestCase {

	public function test_a_simple_product_is_one_row_with_every_required_field(): void {
		Shop::simple(
			array(
				'name'    => 'Vegeta 250 g',
				'sku'     => 'HR-VEGETA',
				'gtin'    => '3850104000017',
				'regular' => '4.72',
				'brand'   => 'Podravka',
			)
		);

		$rows = $this->csv( $this->publish_at( '2026-10-01 05:00' ) );

		$this->assertSame(
			array(
				array( 'naziv', 'šifra', 'marka', 'jedinica mjere', 'cijena za jedinicu mjere', 'maloprodajna cijena', 'poseban oblik prodaje', 'naziv posebnog oblika prodaje', 'sidrena cijena', 'sidreni datum', 'barkod', 'dostupnost' ),
				array( 'Vegeta 250 g', 'HR-VEGETA', 'Podravka', '', '', '5,90', 'NE', '', '5,90', '10.09.2026', '3850104000017', 'dostupno' ),
			),
			$rows
		);
	}

	public function test_a_sale_that_started_overnight_is_published_at_the_sale_price_before_woocommerce_notices(): void {
		Shop::simple(
			array(
				'name'      => 'Kava mljevena 500 g',
				'sku'       => 'HR-KAVA',
				'regular'   => '8.00',
				'sale'      => '6.40',
				'sale_from' => '2026-10-01 00:00',
			)
		);

		// WooCommerce's scheduled-sales job hasn't run, so the stored _price is still 8.00.
		$row = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-KAVA'];

		$this->assertSame( '8.00', get_post_meta( wc_get_product_id_by_sku( 'HR-KAVA' ), '_price', true ) );
		$this->assertSame( array( '8,00', 'DA', 'Akcija' ), array( $row['maloprodajna cijena'], $row['poseban oblik prodaje'], $row['naziv posebnog oblika prodaje'] ) );
	}

	public function test_each_enabled_variation_is_its_own_row_and_unpublished_items_are_left_out(): void {
		Shop::variable(
			array(
				'name' => 'Majica',
				'sku'  => 'HR-MAJICA',
			),
			array(
				'S'  => array(
					'sku'     => 'HR-MAJICA-S',
					'gtin'    => '3850000020011',
					'regular' => '12.00',
				),
				'M'  => array(
					'sku'     => 'HR-MAJICA-M',
					'gtin'    => '3850000020028',
					'regular' => '12.00',
					'sale'    => '9.60',
				),
				'L'  => array(
					'sku'     => 'HR-MAJICA-L',
					'regular' => '13.60',
					'stock'   => 'outofstock',
				),
				'XL' => array(
					'sku'     => 'HR-MAJICA-XL',
					'regular' => '13.60',
					'status'  => 'private',
				),
			)
		);
		Shop::simple(
			array(
				'name'    => 'Skica',
				'sku'     => 'HR-DRAFT',
				'regular' => '1.00',
				'status'  => 'draft',
			)
		);
		Shop::simple(
			array(
				'name'    => 'Interno',
				'sku'     => 'HR-PRIVATE',
				'regular' => '1.00',
				'status'  => 'private',
			)
		);
		Shop::variable(
			array(
				'name'   => 'Hlače (draft)',
				'sku'    => 'HR-HLACE',
				'status' => 'draft',
			),
			array(
				'S' => array(
					'sku'     => 'HR-HLACE-S',
					'regular' => '20.00',
				),
			)
		);

		$rows = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) );

		$this->assertSame( array( 'HR-MAJICA-S', 'HR-MAJICA-M', 'HR-MAJICA-L' ), array_keys( $rows ) );
		$this->assertSame(
			array( 'Majica - S', '15,00', 'NE', '15,00', '3850000020011', 'dostupno' ),
			array_values( array_intersect_key( $rows['HR-MAJICA-S'], array_flip( array( 'naziv', 'maloprodajna cijena', 'poseban oblik prodaje', 'sidrena cijena', 'barkod', 'dostupnost' ) ) ) )
		);
		$this->assertSame( array( '12,00', 'DA', '15,00' ), array( $rows['HR-MAJICA-M']['maloprodajna cijena'], $rows['HR-MAJICA-M']['poseban oblik prodaje'], $rows['HR-MAJICA-M']['sidrena cijena'] ) );
		$this->assertSame( array( '17,00', 'nedostupno' ), array( $rows['HR-MAJICA-L']['maloprodajna cijena'], $rows['HR-MAJICA-L']['dostupnost'] ) );
	}

	public function test_backorders_count_as_unavailable_unless_the_shop_says_otherwise(): void {
		Shop::simple(
			array(
				'name'    => 'Usisavač',
				'sku'     => 'HR-USISAVAC',
				'regular' => '100.00',
				'stock'   => 'onbackorder',
			)
		);

		$before = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-USISAVAC']['dostupnost'];
		cjenik()->settings()->update( array( 'backorder_availability' => 'dostupno' ) );
		$after = $this->rows_by_code( $this->publish_at( '2026-10-02 05:00' ) )['HR-USISAVAC']['dostupnost'];

		$this->assertSame( array( 'nedostupno', 'dostupno' ), array( $before, $after ) );
	}

	public function test_the_file_name_has_the_five_legal_parts_with_the_address_kept_as_written(): void {
		cjenik()->settings()->update(
			array(
				'outlet_type'    => 'webshop',
				'outlet_address' => 'Ulica Đure Š. Čćž 5/2, Đakovo',
				'outlet_code'    => 'P-01',
			)
		);

		$dash = $this->publish_at( '2026-10-01 05:07' );
		cjenik()->settings()->update( array( 'file_time_separator' => ':' ) );
		$colon = $this->publish_at( '2026-10-01 07:45' );

		$this->assertSame( 'webshop_Ulica Đure Š. Čćž 5-2, Đakovo_P-01_1_01.10.2026_05-07.csv', $dash->file_name );
		$this->assertSame( 'webshop_Ulica Đure Š. Čćž 5-2, Đakovo_P-01_2_01.10.2026_07:45.csv', $colon->file_name );
		$this->assertFileExists( $colon->path() );
		$this->assertStringEndsWith( '/cjenik/webshop_Ulica%20%C4%90ure%20%C5%A0.%20%C4%8C%C4%87%C5%BE%205-2%2C%20%C4%90akovo_P-01_2_01.10.2026_07%3A45.csv', $colon->url() );
	}

	public function test_the_file_name_uses_zagreb_time_in_winter(): void {
		$publication = $this->publish_at( '2026-12-01 05:00' );

		$this->assertStringEndsWith( '_01.12.2026_05-00.csv', $publication->file_name );
	}

	public function test_storage_numbers_go_up_by_one_and_a_second_publication_on_the_same_day_keeps_the_first(): void {
		$first  = $this->publish_at( '2026-10-01 05:00' );
		$second = $this->publish_at( '2026-10-01 11:30' );
		$third  = $this->publish_at( '2026-10-02 05:00' );

		$this->assertSame( array( 1, 2, 3 ), array( $first->storage_number, $second->storage_number, $third->storage_number ) );
		$this->assertFileExists( $first->path() );
		$this->assertSame(
			array( 3, 2, 1 ),
			array_map( fn( $publication ) => $publication->storage_number, cjenik()->log()->available( 'webshop' ) )
		);
	}

	public function test_a_storage_number_is_never_reused_even_if_the_log_is_emptied(): void {
		global $wpdb;
		$this->publish_at( '2026-10-01 05:00' );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}cjenik_publications" );

		$this->assertSame( 2, $this->publish_at( '2026-10-02 05:00' )->storage_number );
	}

	public function test_the_lowest_30_day_price_counts_a_price_that_was_live_for_part_of_a_day(): void {
		$this->enable_column( 'najniza_cijena_30_dana' );
		$this->clock->set( '2026-08-01 09:00' );
		$product = Shop::simple(
			array(
				'name'    => 'Čaj',
				'sku'     => 'HR-CAJ',
				'regular' => '10.00',
			)
		);
		Shop::simple(
			array(
				'name'    => 'Med',
				'sku'     => 'HR-MED',
				'regular' => '10.00',
			)
		);

		$this->change_at( '2026-08-15 09:00', $product, array( 'regular' => '6.00' ) ); // Outside the 30 days.
		$this->change_at( '2026-08-16 09:00', $product, array( 'regular' => '10.00' ) );
		$this->change_at( '2026-09-05 10:00', $product, array( 'regular' => '8.40' ) ); // Live for four hours.
		$this->change_at( '2026-09-05 14:00', $product, array( 'regular' => '10.00' ) );
		$this->change_at( '2026-09-20 00:00', $product, array( 'sale' => '7.00' ) );

		$rows = $this->rows_by_code( $this->publish_at( '2026-09-21 05:00' ) );

		$this->assertSame( array( '8,75', '10,50' ), array( $rows['HR-CAJ']['maloprodajna cijena'], $rows['HR-CAJ']['najniža cijena u posljednjih 30 dana'] ) );
		$this->assertSame( '', $rows['HR-MED']['najniža cijena u posljednjih 30 dana'] );
	}

	public function test_the_lowest_30_day_price_of_a_scheduled_sale_looks_at_the_30_days_before_it_started(): void {
		$this->enable_column( 'najniza_cijena_30_dana' );
		$this->clock->set( '2026-08-01 09:00' );
		$product = Shop::simple(
			array(
				'name'    => 'Čaj',
				'sku'     => 'HR-CAJ',
				'regular' => '10.00',
			)
		);
		$this->change_at( '2026-08-20 09:00', $product, array( 'regular' => '9.00' ) );
		$this->change_at(
			'2026-08-21 09:00',
			$product,
			array(
				'regular'   => '10.00',
				'sale'      => '7.00',
				'sale_from' => '2026-09-15 00:00',
			)
		);

		// 30 days before 15 Sep reach back to 16 Aug, so 9.00 on 20 Aug counts,
		// although it's more than 30 days before publication.
		$rows = $this->rows_by_code( $this->publish_at( '2026-09-25 05:00' ) );

		$this->assertSame( '11,25', $rows['HR-CAJ']['najniža cijena u posljednjih 30 dana'] );
	}

	private function enable_column( string $key ): void {
		$columns   = cjenik()->settings()->get( 'columns' );
		$columns[] = array(
			'key'   => $key,
			'label' => '',
		);
		cjenik()->settings()->update( array( 'columns' => $columns ) );
	}

	/**
	 * @param array<string, string> $changes
	 */
	private function change_at( string $zagreb_time, \WC_Product $product, array $changes ): void {
		$this->clock->set( $zagreb_time );
		$product = wc_get_product( $product->get_id() );
		if ( isset( $changes['regular'] ) ) {
			$product->set_regular_price( $changes['regular'] );
		}
		if ( isset( $changes['sale'] ) ) {
			$product->set_sale_price( $changes['sale'] );
		}
		if ( isset( $changes['sale_from'] ) ) {
			$product->set_date_on_sale_from( ( new \DateTimeImmutable( $changes['sale_from'], new \DateTimeZone( 'Europe/Zagreb' ) ) )->getTimestamp() );
		}
		$product->save();
	}
}
