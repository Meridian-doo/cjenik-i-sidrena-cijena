<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Tests\Support\Shop;

/**
 * The prices a shopper sees next to the product price.
 */
final class StorefrontPriceHtmlTest extends ShopTestCase {

	public function test_the_anchor_price_is_shown_with_its_date_even_when_it_equals_the_current_price(): void {
		$product = Shop::simple(
			array(
				'name'    => 'Vegeta',
				'regular' => '4.72',
			)
		);

		$this->clock->set( '2026-10-01 12:00' );

		$this->assertSame( array( 'Cijena na 10.9.2026.: 5,90 €' ), $this->plugin_prices( $product ) );
	}

	public function test_a_discounted_product_also_shows_the_lowest_30_day_price(): void {
		$product = Shop::simple(
			array(
				'name'    => 'Espresso',
				'regular' => '200.00',
			)
		);
		$this->change_at( '2026-09-20 09:00', $product, array( 'regular' => '180.00' ) );
		$this->change_at( '2026-10-05 00:00', $product, array( 'sale' => '160.00' ) );

		$this->clock->set( '2026-10-06 12:00' );

		$this->assertSame(
			array( 'Najniža cijena u zadnjih 30 dana: 225,00 €', 'Cijena na 10.9.2026.: 250,00 €' ),
			$this->plugin_prices( $product )
		);
	}

	public function test_each_variation_shows_its_own_anchor_when_selected(): void {
		[ $parent ] = Shop::variable(
			array( 'name' => 'Majica' ),
			array(
				'S' => array( 'regular' => '12.00' ),
				'L' => array( 'regular' => '13.60' ),
			)
		);
		$this->clock->set( '2026-10-01 12:00' );

		$shown = array_map(
			fn( $variation ) => $this->visible_plugin_text( $variation['price_html'] ),
			wc_get_product( $parent->get_id() )->get_available_variations()
		);

		$this->assertSame( array( array( 'Cijena na 10.9.2026.: 15,00 €' ), array( 'Cijena na 10.9.2026.: 17,00 €' ) ), $shown );
	}

	public function test_a_variation_shows_its_anchor_even_when_all_variations_cost_the_same(): void {
		[ $parent ] = Shop::variable(
			array( 'name' => 'Majica' ),
			array(
				'S' => array( 'regular' => '12.00' ),
				'M' => array( 'regular' => '12.00' ),
			)
		);
		$this->clock->set( '2026-10-01 12:00' );

		$variations = wc_get_product( $parent->get_id() )->get_available_variations();

		$this->assertSame( array( 'Cijena na 10.9.2026.: 15,00 €' ), $this->visible_plugin_text( $variations[0]['price_html'] ) );
	}

	public function test_a_variable_product_in_the_shop_shows_the_range_of_its_anchors(): void {
		[ $parent ] = Shop::variable(
			array( 'name' => 'Majica' ),
			array(
				'S' => array( 'regular' => '12.00' ),
				'L' => array( 'regular' => '13.60' ),
			)
		);
		$this->clock->set( '2026-10-01 12:00' );

		$this->assertSame( array( 'Cijena na 10.9.2026.: 15,00 € – 17,00 €' ), $this->plugin_prices( $parent ) );
	}

	public function test_the_labels_can_be_changed(): void {
		cjenik()->settings()->update( array( 'anchor_label' => 'Sidrena cijena ({datum}):' ) );
		$product = Shop::simple(
			array(
				'name'    => 'Vegeta',
				'regular' => '4.72',
			)
		);
		$this->clock->set( '2026-10-01 12:00' );

		$this->assertSame( array( 'Sidrena cijena (10.9.2026.): 5,90 €' ), $this->plugin_prices( $product ) );
	}

	public function test_the_display_can_be_turned_off(): void {
		cjenik()->settings()->update( array( 'display_enabled' => false ) );
		$product = Shop::simple(
			array(
				'name'    => 'Vegeta',
				'regular' => '4.72',
			)
		);

		$this->assertSame( array(), $this->plugin_prices( $product ) );
	}

	/**
	 * The plugin's lines in the product's price HTML, as a shopper reads them.
	 *
	 * @return list<string>
	 */
	private function plugin_prices( \WC_Product $product ): array {
		return $this->visible_plugin_text( wc_get_product( $product->get_id() )->get_price_html() );
	}

	/**
	 * @return list<string>
	 */
	private function visible_plugin_text( string $html ): array {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
		$lines = array();
		foreach ( ( new \DOMXPath( $dom ) )->query( '//*[contains(@class, "cjenik-sidrena") or contains(@class, "cjenik-najniza")]' ) as $node ) {
			$lines[] = trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) );
		}
		return $lines;
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
		$product->save();
	}
}
