<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests\Support;

use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Tax;

/**
 * Builds a Croatian WooCommerce shop for tests: store settings, VAT rates and products.
 */
final class Shop {
	/**
	 * Store settings as in dev/seed.php: prices entered without VAT, tax based on
	 * the shipping address and geolocated, which is the combination where
	 * WooCommerce drops VAT when there is no customer.
	 */
	public static function configure(): void {
		$options = array(
			'timezone_string'                      => 'Europe/Zagreb',
			'gmt_offset'                           => '',
			'woocommerce_store_address'            => 'Ilica 150',
			'woocommerce_store_city'               => 'Zagreb',
			'woocommerce_store_postcode'           => '10000',
			'woocommerce_default_country'          => 'HR',
			'woocommerce_currency'                 => 'EUR',
			'woocommerce_currency_pos'             => 'right_space',
			'woocommerce_price_thousand_sep'       => '.',
			'woocommerce_price_decimal_sep'        => ',',
			'woocommerce_price_num_decimals'       => '2',
			'woocommerce_calc_taxes'               => 'yes',
			'woocommerce_prices_include_tax'       => 'no',
			'woocommerce_tax_based_on'             => 'shipping',
			'woocommerce_default_customer_address' => 'geolocation',
			'woocommerce_tax_display_shop'         => 'incl',
			'woocommerce_tax_display_cart'         => 'incl',
		);
		foreach ( $options as $name => $value ) {
			update_option( $name, $value );
		}

		if ( ! WC_Tax::get_tax_class_by( 'slug', 'super-reduced-rate' ) ) {
			WC_Tax::create_tax_class( 'Super-reduced rate', 'super-reduced-rate' );
		}
		$rates = array(
			''                   => '25.0000',
			'reduced-rate'       => '13.0000',
			'super-reduced-rate' => '5.0000',
		);
		foreach ( $rates as $class => $rate ) {
			WC_Tax::_insert_tax_rate(
				array(
					'tax_rate_country'  => 'HR',
					'tax_rate_state'    => '',
					'tax_rate'          => $rate,
					'tax_rate_name'     => 'PDV',
					'tax_rate_priority' => 1,
					'tax_rate_compound' => 0,
					'tax_rate_shipping' => 1,
					'tax_rate_order'    => 0,
					'tax_rate_class'    => $class,
				)
			);
		}
		WC_Tax::_delete_tax_rate( 0 ); // Clears WC_Tax's static caches.

		// Cron and REST requests have no customer.
		WC()->customer = null;
	}

	/**
	 * @param array<string, mixed> $spec name, sku, gtin, regular, sale, sale_from, sale_to,
	 *                                   stock, status, tax_class, category, brand, created.
	 */
	public static function simple( array $spec ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		self::apply( $product, $spec );
		$product->save();
		self::set_terms( $product->get_id(), $spec );
		return $product;
	}

	/**
	 * @param array<string, mixed>                $spec       Parent fields.
	 * @param array<string, array<string, mixed>> $variations Option value => fields.
	 * @return array{0: WC_Product_Variable, 1: array<string, WC_Product_Variation>}
	 */
	public static function variable( array $spec, array $variations ): array {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Veličina' );
		$attribute->set_options( array_keys( $variations ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_attributes( array( $attribute ) );
		self::apply( $parent, $spec );
		$parent->save();
		self::set_terms( $parent->get_id(), $spec );

		$children = array();
		foreach ( $variations as $option => $fields ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( sanitize_title( 'Veličina' ) => $option ) );
			self::apply( $variation, $fields );
			$variation->save();
			$children[ $option ] = $variation;
		}
		WC_Product_Variable::sync( $parent->get_id() );

		return array( wc_get_product( $parent->get_id() ), $children );
	}

	public static function category( string $name ): int {
		$term = term_exists( $name, 'product_cat' );
		if ( ! $term ) {
			$term = wp_insert_term( $name, 'product_cat' );
		}
		return (int) $term['term_id'];
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private static function apply( WC_Product $product, array $spec ): void {
		$product->set_name( $spec['name'] ?? 'Proizvod' );
		$product->set_status( $spec['status'] ?? 'publish' );
		if ( isset( $spec['sku'] ) ) {
			$product->set_sku( $spec['sku'] );
		}
		if ( isset( $spec['gtin'] ) ) {
			$product->set_global_unique_id( $spec['gtin'] );
		}
		if ( isset( $spec['regular'] ) ) {
			$product->set_regular_price( $spec['regular'] );
		}
		if ( isset( $spec['sale'] ) ) {
			$product->set_sale_price( $spec['sale'] );
		}
		if ( isset( $spec['sale_from'] ) ) {
			$product->set_date_on_sale_from( self::zagreb_timestamp( $spec['sale_from'] ) );
		}
		if ( isset( $spec['sale_to'] ) ) {
			$product->set_date_on_sale_to( self::zagreb_timestamp( $spec['sale_to'] ) );
		}
		if ( isset( $spec['stock'] ) ) {
			$product->set_stock_status( $spec['stock'] );
		}
		if ( isset( $spec['tax_class'] ) ) {
			$product->set_tax_class( $spec['tax_class'] );
		}
		// Products are created "now" on the test Clock unless told otherwise, but
		// never in the real future, where WordPress would schedule instead of publish.
		$product->set_date_created(
			min( time(), isset( $spec['created'] ) ? self::zagreb_timestamp( $spec['created'] ) : cjenik()->clock()->now()->getTimestamp() )
		);
		if ( isset( $spec['category'] ) && ! $product instanceof WC_Product_Variation ) {
			$product->set_category_ids( array( self::category( $spec['category'] ) ) );
		}
		foreach ( $spec['meta'] ?? array() as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private static function set_terms( int $product_id, array $spec ): void {
		if ( isset( $spec['brand'] ) ) {
			wp_set_object_terms( $product_id, $spec['brand'], 'product_brand' );
		}
	}

	private static function zagreb_timestamp( string $zagreb_time ): int {
		return ( new \DateTimeImmutable( $zagreb_time, new \DateTimeZone( 'Europe/Zagreb' ) ) )->getTimestamp();
	}
}
