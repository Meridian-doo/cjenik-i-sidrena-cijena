<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Tests\Support\Shop;

/**
 * The Anchor Price and Anchor Date columns, as published.
 */
final class AnchorPublicationTest extends PublicationTestCase {

	public function test_an_item_on_sale_on_10_september_is_anchored_at_its_regular_price(): void {
		$this->clock->set( '2026-09-01 09:00' );
		Shop::simple(
			array(
				'name'    => 'Espresso',
				'sku'     => 'HR-ESPRESSO',
				'regular' => '200.00',
				'sale'    => '160.00',
			)
		);

		$row = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-ESPRESSO'];

		$this->assertSame( array( '250,00', '10.09.2026' ), $this->anchor( $row ) );
	}

	public function test_the_anchor_is_the_last_price_of_10_september(): void {
		$this->clock->set( '2026-09-01 09:00' );
		$product = Shop::simple(
			array(
				'name'    => 'Espresso',
				'sku'     => 'HR-ESPRESSO',
				'regular' => '200.00',
			)
		);
		$this->change_at( '2026-09-10 18:00', $product, '180.00' );
		$this->change_at( '2026-09-11 08:00', $product, '220.00' );

		$row = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-ESPRESSO'];

		$this->assertSame( array( '225,00', '10.09.2026' ), $this->anchor( $row ) );
	}

	public function test_a_product_first_listed_after_10_september_is_anchored_at_its_first_price_and_date(): void {
		$this->clock->set( '2026-09-20 11:00' );
		$product = Shop::simple(
			array(
				'name'    => 'Usisavač',
				'sku'     => 'HR-USISAVAC',
				'regular' => '96.00',
			)
		);
		$this->change_at( '2026-09-25 11:00', $product, '120.00' );

		$row = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-USISAVAC'];

		$this->assertSame( array( '120,00', '20.09.2026' ), $this->anchor( $row ) );
	}

	public function test_a_draft_is_anchored_from_the_day_it_is_published(): void {
		$this->clock->set( '2026-09-15 11:00' );
		$product = Shop::simple(
			array(
				'name'    => 'Usisavač',
				'sku'     => 'HR-USISAVAC',
				'regular' => '96.00',
				'status'  => 'draft',
			)
		);
		$this->clock->set( '2026-09-22 09:00' );
		$product->set_status( 'publish' );
		$product->save();

		$publication = $this->publish_at( '2026-10-01 05:00' );

		$this->assertSame( array( '120,00', '22.09.2026' ), $this->anchor( $this->rows_by_code( $publication )['HR-USISAVAC'] ) );
		$this->assertArrayNotHasKey( 'unconfirmed_anchor', $publication->warnings );
	}

	public function test_fmcg_categories_keep_their_2_may_2025_anchor(): void {
		$this->clock->set( '2025-04-01 09:00' );
		$oil   = Shop::simple(
			array(
				'name'     => 'Ulje',
				'sku'      => 'HR-ULJE',
				'regular'  => '2.00',
				'category' => 'Hrana',
			)
		);
		$chair = Shop::simple(
			array(
				'name'     => 'Stolica',
				'sku'      => 'HR-STOLICA',
				'regular'  => '40.00',
				'category' => 'Namještaj',
			)
		);
		$this->change_at( '2025-06-01 09:00', $oil, '2.40' );
		$this->change_at( '2025-06-01 09:00', $chair, '48.00' );
		cjenik()->settings()->update( array( 'fmcg_2025_categories' => array( Shop::category( 'Hrana' ) ) ) );

		$rows = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) );

		$this->assertSame( array( '2,50', '02.05.2025' ), $this->anchor( $rows['HR-ULJE'] ) );
		$this->assertSame( array( '60,00', '10.09.2026' ), $this->anchor( $rows['HR-STOLICA'] ) );
	}

	public function test_a_subcategory_of_an_fmcg_category_keeps_the_2025_anchor_too(): void {
		$this->clock->set( '2025-04-01 09:00' );
		$food = Shop::category( 'Hrana' );
		wp_insert_term( 'Ulja', 'product_cat', array( 'parent' => $food ) );
		Shop::simple(
			array(
				'name'     => 'Ulje',
				'sku'      => 'HR-ULJE',
				'regular'  => '2.00',
				'category' => 'Ulja',
			)
		);
		cjenik()->settings()->update( array( 'fmcg_2025_categories' => array( $food ) ) );

		$row = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-ULJE'];

		$this->assertSame( '02.05.2025', $row['sidreni datum'] );
	}

	public function test_changing_the_sku_or_name_keeps_the_anchor(): void {
		$this->clock->set( '2026-09-01 09:00' );
		$product = Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'gtin'    => '3850000000011',
				'regular' => '2.00',
			)
		);
		$this->clock->set( '2026-09-20 09:00' );
		$product->set_sku( 'HR-SOK-NOVI' );
		$product->set_name( 'Sok od jabuke' );
		$product->set_regular_price( '2.40' );
		$product->save();

		$row = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-SOK-NOVI'];

		$this->assertSame( array( '2,50', '10.09.2026' ), $this->anchor( $row ) );
	}

	public function test_a_new_barcode_makes_it_a_new_product_with_a_new_anchor(): void {
		$this->clock->set( '2026-09-01 09:00' );
		$product = Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'gtin'    => '3850000000011',
				'regular' => '2.00',
			)
		);
		$this->clock->set( '2026-09-20 09:00' );
		$product->set_global_unique_id( '3850000000028' );
		$product->set_regular_price( '2.40' );
		$product->save();

		$row = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-SOK'];

		$this->assertSame( array( '3,00', '20.09.2026' ), $this->anchor( $row ) );
	}

	public function test_an_anchor_the_shop_owner_entered_wins(): void {
		$this->clock->set( '2026-09-01 09:00' );
		$product = Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '2.00',
			)
		);
		cjenik()->anchors()->set( $product->get_id(), 2.99, '2026-09-10', 'manual' );

		$row = $this->rows_by_code( $this->publish_at( '2026-10-01 05:00' ) )['HR-SOK'];

		$this->assertSame( array( '2,99', '10.09.2026' ), $this->anchor( $row ) );
	}

	public function test_without_history_the_earliest_known_price_is_published_and_reported(): void {
		$this->clock->set( '2026-09-01 09:00' );
		$product = Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '2.00',
				'created' => '2025-01-01 09:00',
			)
		);
		cjenik()->history()->forget( $product->get_id() );
		$this->clock->set( '2026-09-29 09:00' ); // The plugin is installed.
		cjenik()->history()->snapshot();

		$publication = $this->publish_at( '2026-10-01 05:00' );

		$this->assertSame( array( '2,50', '29.09.2026' ), $this->anchor( $this->rows_by_code( $publication )['HR-SOK'] ) );
		$this->assertSame( array( $product->get_id() ), $publication->warnings['unconfirmed_anchor']['ids'] );
	}

	public function test_an_item_the_history_has_never_seen_starts_its_history_at_publication(): void {
		$product = Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '2.00',
				'created' => '2025-01-01 09:00',
			)
		);
		cjenik()->history()->forget( $product->get_id() );

		$first  = $this->publish_at( '2026-10-01 05:00' );
		$second = $this->publish_at( '2026-10-02 05:00' );

		$this->assertSame( array( '2,50', '01.10.2026' ), $this->anchor( $this->rows_by_code( $first )['HR-SOK'] ) );
		$this->assertSame( array( '2,50', '01.10.2026' ), $this->anchor( $this->rows_by_code( $second )['HR-SOK'] ) );
		$this->assertSame( array( $product->get_id() ), $second->warnings['unconfirmed_anchor']['ids'] );
	}

	/**
	 * @param array<string, string> $row
	 * @return array{0: string, 1: string}
	 */
	private function anchor( array $row ): array {
		return array( $row['sidrena cijena'], $row['sidreni datum'] );
	}

	private function change_at( string $zagreb_time, \WC_Product $product, string $regular ): void {
		$this->clock->set( $zagreb_time );
		$product = wc_get_product( $product->get_id() );
		$product->set_regular_price( $regular );
		$product->save();
	}
}
