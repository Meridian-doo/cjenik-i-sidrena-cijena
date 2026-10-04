<?php
/**
 * @package Cjenik
 */

namespace Cjenik\PriceList;

use Cjenik\Settings;
use WC_Product;

/**
 * Reads the Price List fields that WooCommerce has no fixed place for (brand,
 * barcode, unit of measure) from wherever the shop keeps them.
 */
final class FieldMapping {
	public const UNIT_META          = '_cjenik_unit';
	public const UNIT_QUANTITY_META = '_cjenik_unit_quantity';

	public function __construct( private Settings $settings ) {}

	/**
	 * Brand from a taxonomy ("taxonomy:product_brand"), a product attribute
	 * ("attribute:pa_marka"), or nowhere ("none"). Variations use the parent's.
	 */
	public function brand( WC_Product $item ): string {
		$source  = (string) $this->settings->get( 'brand_source' );
		$product = $this->parent_or_self( $item );
		if ( str_starts_with( $source, 'taxonomy:' ) ) {
			$terms = get_the_terms( $product->get_id(), substr( $source, 9 ) );
			return is_array( $terms ) && $terms ? html_entity_decode( $terms[0]->name ) : '';
		}
		if ( str_starts_with( $source, 'attribute:' ) ) {
			return trim( html_entity_decode( $product->get_attribute( substr( $source, 10 ) ) ) );
		}
		return '';
	}

	/**
	 * Barcode from WooCommerce's GTIN field ("gtin") or a meta key ("meta:_ean").
	 */
	public function barcode( WC_Product $item ): string {
		$source = (string) $this->settings->get( 'barcode_source' );
		if ( str_starts_with( $source, 'meta:' ) ) {
			return trim( (string) $item->get_meta( substr( $source, 5 ) ) );
		}
		return trim( (string) $item->get_global_unique_id( 'edit' ) );
	}

	/** The barcode currently stored in the database, before an unsaved edit. */
	public function stored_barcode( int $item_id ): string {
		$source = (string) $this->settings->get( 'barcode_source' );
		$key    = str_starts_with( $source, 'meta:' ) ? substr( $source, 5 ) : '_global_unique_id';
		return trim( (string) get_post_meta( $item_id, $key, true ) );
	}

	/** Unit of measure (e.g. "kg"), or '' when the shop doesn't use one. */
	public function unit( WC_Product $item ): string {
		switch ( $this->settings->get( 'unit_source' ) ) {
			case 'plugin':
				return $this->own_or_parent_meta( $item, self::UNIT_META );
			case 'meta':
				$key = (string) $this->settings->get( 'unit_meta_key' );
				return '' === $key ? '' : $this->own_or_parent_meta( $item, $key );
		}
		return '';
	}

	/**
	 * Net quantity in the unit of measure (e.g. 0.25 for 250 g sold by the kg).
	 */
	public function net_quantity( WC_Product $item ): ?float {
		if ( 'plugin' !== $this->settings->get( 'unit_source' ) ) {
			return null;
		}
		$quantity = str_replace( ',', '.', $this->own_or_parent_meta( $item, self::UNIT_QUANTITY_META ) );
		return is_numeric( $quantity ) && (float) $quantity > 0 ? (float) $quantity : null;
	}

	/**
	 * Unit price for a VAT-inclusive retail price: computed from the net
	 * quantity, or read from a meta key.
	 */
	public function unit_price( WC_Product $item, ?float $price ): ?float {
		switch ( $this->settings->get( 'unit_source' ) ) {
			case 'plugin':
				$quantity = $this->net_quantity( $item );
				return null === $price || null === $quantity ? null : round( $price / $quantity, 2 );
			case 'meta':
				$key   = (string) $this->settings->get( 'unit_price_meta_key' );
				$value = '' === $key ? '' : str_replace( ',', '.', $this->own_or_parent_meta( $item, $key ) );
				return is_numeric( $value ) ? round( (float) $value, 2 ) : null;
		}
		return null;
	}

	/** The item's main product category name. Variations use the parent's. */
	public function category( WC_Product $item ): string {
		$ids = $this->parent_or_self( $item )->get_category_ids();
		if ( ! $ids ) {
			return '';
		}
		$term = get_term( (int) $ids[0], 'product_cat' );
		return $term instanceof \WP_Term ? html_entity_decode( $term->name ) : '';
	}

	/**
	 * Category IDs of the item and all their ancestors.
	 *
	 * @return list<int>
	 */
	public function category_ids_with_ancestors( WC_Product $item ): array {
		$ids = array();
		foreach ( $this->parent_or_self( $item )->get_category_ids() as $id ) {
			$ids[] = (int) $id;
			foreach ( get_ancestors( (int) $id, 'product_cat', 'taxonomy' ) as $ancestor ) {
				$ids[] = (int) $ancestor;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private function parent_or_self( WC_Product $item ): WC_Product {
		if ( $item->is_type( 'variation' ) ) {
			$parent = wc_get_product( $item->get_parent_id() );
			if ( $parent ) {
				return $parent;
			}
		}
		return $item;
	}

	private function own_or_parent_meta( WC_Product $item, string $key ): string {
		$value = trim( (string) $item->get_meta( $key ) );
		if ( '' === $value && $item->get_parent_id() ) {
			$value = trim( (string) get_post_meta( $item->get_parent_id(), $key, true ) );
		}
		return $value;
	}
}
